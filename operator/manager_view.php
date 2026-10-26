<?php
require_once 'header.php';
require_once __DIR__ . '/staff_salary_helper.php';
init_staff_salary_schema($pdo);



$client_id = (int)$_SESSION['operator_id'];
$manager_id = (int)($_GET['id'] ?? 0);
$is_logged_manager = !empty($_SESSION['is_manager']);
$logged_manager_id = (int)($_SESSION['manager_id'] ?? 0);

// Manager Access Control: Manager can view their own profile and recoveries, but not other managers
if ($is_logged_manager) {
    if ($manager_id <= 0) {
        $manager_id = $logged_manager_id;
    }
    if ($manager_id !== $logged_manager_id) {
        echo "<div class='container py-5'>
                <div class='alert alert-danger shadow-sm'>
                    <i class='fa-solid fa-shield-halved me-2'></i> Access Denied: You are only authorized to view your own manager profile and recovery records.
                </div>
                <a href='manager_view.php?id={$logged_manager_id}' class='btn btn-primary'><i class='fa-solid fa-user me-1'></i> Go to My Profile</a>
                <a href='dashboard' class='btn btn-outline-secondary ms-2'><i class='fa-solid fa-arrow-left me-1'></i> Return to Dashboard</a>
              </div>";
        require_once 'footer.php';
        exit;
    }
} else {
    if ($manager_id <= 0) {
        echo "<div class='container py-5'><div class='alert alert-warning'>Invalid Manager ID specified.</div><a href='managers' class='btn btn-primary'>Back to Managers</a></div>";
        require_once 'footer.php';
        exit;
    }
}

// Fetch Manager Record
$stmt = $pdo->prepare("SELECT * FROM managers WHERE id = ? AND client_id = ?");
$stmt->execute([$manager_id, $client_id]);
$mgr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mgr) {
    echo "<div class='container py-5'>
            <div class='alert alert-warning shadow-sm'>
                <i class='fa-solid fa-triangle-exclamation me-2'></i> Manager #{$manager_id} not found or access unauthorized.
            </div>
            <a href='managers' class='btn btn-primary'><i class='fa-solid fa-arrow-left me-1'></i> Return to Managers</a>
          </div>";
    require_once 'footer.php';
    exit;
}

// Auto-upgrade managers table schema for cash_in_hand
try {
    $col = $pdo->query("SHOW COLUMNS FROM managers LIKE 'cash_in_hand'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE managers ADD COLUMN cash_in_hand DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER status");
    }
} catch (Exception $e) {}

// One-time data cleanup: isolate manager logs and restore role on any mis-stamped activity logs
$adminIdentifiers = ['Admin', 'admin', 'Operator', 'operator', 'System'];
try {
    // 1. Fetch Admin info for this tenant
    $cStmt = $pdo->prepare("SELECT company_name, email, full_name, username FROM clients WHERE id = ?");
    $cStmt->execute([$client_id]);
    $adminRow = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($adminRow['company_name'])) $adminIdentifiers[] = $adminRow['company_name'];
    if (!empty($adminRow['email'])) $adminIdentifiers[] = $adminRow['email'];
    if (!empty($adminRow['full_name'])) $adminIdentifiers[] = $adminRow['full_name'];
    if (!empty($adminRow['username'])) $adminIdentifiers[] = $adminRow['username'];
    $adminIdentifiers = array_unique(array_filter($adminIdentifiers));

    // Clear manager_id and set by_role = 'Admin' on all logs done by Admin identifiers
    if (!empty($adminIdentifiers)) {
        $inClause = implode(',', array_fill(0, count($adminIdentifiers), '?'));
        $adminParams = array_merge([$client_id], $adminIdentifiers);
        $pdo->prepare("UPDATE activity_logs SET manager_id = NULL, by_role = 'Admin' WHERE client_id = ? AND by_user IN ($inClause)")
            ->execute($adminParams);
    }

    // Clear manager_id from all logs done by staff roles
    $pdo->exec("UPDATE activity_logs SET manager_id = NULL WHERE client_id = {$client_id} AND by_role IN ('RecoveryMan', 'Lineman', 'Dealer', 'System')");

    // Also clear manager_id from all logs where by_user matches any RecoveryMan
    $pdo->exec("UPDATE activity_logs a JOIN recovery_men r ON a.client_id = r.client_id AND a.by_user = r.full_name 
                SET a.manager_id = NULL, a.by_role = 'RecoveryMan' WHERE a.client_id = {$client_id}");

    // Also clear manager_id from all logs where by_user matches any Lineman
    $pdo->exec("UPDATE activity_logs a JOIN linemen l ON a.client_id = l.client_id AND a.by_user = l.full_name 
                SET a.manager_id = NULL, a.by_role = 'Lineman' WHERE a.client_id = {$client_id}");

    // Clear manager_id on any log where by_role is 'Admin' or by_user LIKE 'Admin%' or 'Operator%'
    $pdo->exec("UPDATE activity_logs SET manager_id = NULL, by_role = 'Admin' 
                WHERE client_id = {$client_id} AND (by_user LIKE 'Admin%' OR by_user LIKE 'Operator%' OR by_role = 'Admin' OR by_role IS NULL OR by_role = '')");

    // If an invalid/dummy manager row exists with empty name/username, reset its cash_in_hand to 0
    $pdo->exec("UPDATE managers SET cash_in_hand = 0 WHERE client_id = {$client_id} AND (full_name IS NULL OR full_name = '' OR username IS NULL OR username = '')");
} catch (Exception $e) {}

// Handle Direct Quick Actions (Toggle permissions, profile edit, collect cash, delete manager, or reset password)
$flash_msg = '';
$flash_type = 'success';
$activeTabToOpen = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle Admin Deleting a Manager (e.g. ghost account)
    if ($action === 'delete_manager') {
        $del_id = (int)($_POST['id'] ?? 0);
        if ($del_id === $manager_id) {
            $pdo->prepare("DELETE FROM managers WHERE id = ? AND client_id = ?")->execute([$manager_id, $client_id]);
            echo "<script>alert('Manager account deleted successfully.'); window.location='managers.php';</script>";
            exit;
        }
    }

    // Handle Admin Collecting Cash in Hand from this Manager
    if ($action === 'collect_cash') {
        if ($is_logged_manager) {
            $flash_msg = "Access Denied: Managers cannot collect cash from themselves. Only the Admin can confirm cash received.";
            $flash_type = "danger";
        } else {
            $amount = (float)($_POST['amount'] ?? 0);
        $current_cash = (float)($mgr['cash_in_hand'] ?? 0);

        if ($amount > 0 && $amount <= $current_cash) {
            try {
                $pdo->beginTransaction();
                // Deduct from manager's cash in hand
                $pdo->prepare("UPDATE managers SET cash_in_hand = cash_in_hand - ? WHERE id = ? AND client_id = ?")
                    ->execute([$amount, $manager_id, $client_id]);

                // Log the activity
                $log_msg = "Collected Cash Rs. " . number_format($amount, 0) . " from Manager";
                $admin_user = $_SESSION['operator_username'] ?? ($_SESSION['operator_name'] ?? 'Admin');
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'Admin', ?, 'Manager', ?)")
                    ->execute([$client_id, $admin_user, $mgr['full_name'], $log_msg]);

                $pdo->commit();
                $flash_msg = "Successfully collected Rs. " . number_format($amount, 0) . " from Manager {$mgr['full_name']}!";
                $flash_type = "success";
                $activeTabToOpen = 'submissions';

                // Re-fetch manager record
                $stmt = $pdo->prepare("SELECT * FROM managers WHERE id = ? AND client_id = ?");
                $stmt->execute([$manager_id, $client_id]);
                $mgr = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $pdo->rollBack();
                $flash_msg = "Error collecting cash: " . $e->getMessage();
                $flash_type = "danger";
            }
        } else {
            $flash_msg = "Invalid collection amount. Must be greater than 0 and not exceed Pending Cash in Hand (Rs. " . number_format($current_cash, 0) . ").";
            $flash_type = "danger";
        }
        }
    }
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['record_staff_payment', 'delete_staff_transaction', 'edit_manager_profile'])) {
        $activeTabToOpen = 'salary';
    }

    if ($action === 'edit_manager_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        if ($is_logged_manager) {
            // Manager cannot alter their own salary or status
            $monthly_salary = (int)($mgr['monthly_salary'] ?? 0);
            $status = $mgr['status'] ?? 'active';
        } else {
            $monthly_salary = (int)($_POST['monthly_salary'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        }

        if (!empty($full_name)) {
            $upd = $pdo->prepare("
                UPDATE managers 
                SET full_name = ?, phone = ?, email = ?, city = ?, address = ?, monthly_salary = ?, status = ?
                WHERE id = ? AND client_id = ?
            ");
            $upd->execute([$full_name, $phone, $email, $city, $address, $monthly_salary, $status, $manager_id, $client_id]);

            if (!$is_logged_manager) {
                sync_staff_monthly_salary($pdo, $client_id, 'manager', $manager_id, $full_name, $monthly_salary);
            }

            log_staff_activity($pdo, "Updated Profile Details for Manager #{$manager_id}", $full_name, "Manager");
            $flash_msg = "Profile updated successfully!";
            $flash_type = "success";
            
            // Refresh in-memory mgr
            $mgr['full_name'] = $full_name;
            $mgr['phone'] = $phone;
            $mgr['email'] = $email;
            $mgr['city'] = $city;
            $mgr['address'] = $address;
        } else {
            $flash_msg = "Full Name cannot be empty.";
            $flash_type = "danger";
        }
    }

    if ($action === 'toggle_permission') {
        if ($is_logged_manager) {
            $flash_msg = "Permission Denied: Only Admin can modify permissions.";
            $flash_type = "danger";
        } else {
        $field = $_POST['permission'] ?? '';
        $val = ($_POST['value'] ?? '') === 'yes' ? 'yes' : 'no';

        if (in_array($field, ['can_manage_packages', 'can_collect_recovery'])) {
            $pdo->prepare("UPDATE managers SET {$field} = ? WHERE id = ? AND client_id = ?")->execute([$val, $manager_id, $client_id]);
            $permLabel = ($field === 'can_manage_packages') ? 'Package Management' : 'Recovery Collection';
            log_staff_activity($pdo, "Changed {$permLabel} permission to '{$val}' for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
            $flash_msg = "Permission updated successfully!";
        }
        }
    }

    if ($action === 'toggle_status') {
        if ($is_logged_manager) {
            $flash_msg = "Permission Denied: Only Admin can modify account status.";
            $flash_type = "danger";
        } else {
        $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $pdo->prepare("UPDATE managers SET status = ? WHERE id = ? AND client_id = ?")->execute([$new_status, $manager_id, $client_id]);
        log_staff_activity($pdo, "Changed status to '{$new_status}' for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
        $flash_msg = "Account status updated to {$new_status}!";
        }
    }

    if ($action === 'change_password') {
        $new_pwd = trim($_POST['new_password'] ?? '');
        if (!empty($new_pwd)) {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE managers SET password = ? WHERE id = ? AND client_id = ?")->execute([$hash, $manager_id, $client_id]);
            log_staff_activity($pdo, "Reset password for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
            $flash_msg = "Password reset successfully!";
        }
    }
}

// Manager record already loaded above

// 1. Maintain activity retention:
// Login activities: strictly 24 hours retention
// All other actions (renewals, billing, edits, financial events): 2 Years (730 days) retention
try {
    $pdo->exec("DELETE FROM activity_logs 
                WHERE (activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') 
                  AND created_at < NOW() - INTERVAL 24 HOUR");
    $pdo->exec("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 730 DAY");
} catch (Exception $e) {}

// Fetch Manager Activity History:
// Login activities: retained for 24 Hours only.
// Administrative, Financial & Audit actions: retained for 2 Years (730 Days).
$mgr_full_safe = trim($mgr['full_name'] ?? '');
$mgr_user_safe = trim($mgr['username'] ?? '');
$admin_comp_safe = $adminRow['company_name'] ?? 'Admin';

$activities = [];
$raw_recoveries = [];
$raw_ledgers = [];

// Only query activities and ledger if this manager has a valid name or username
if (!empty($mgr_full_safe) || !empty($mgr_user_safe)) {
    $actStmt = $pdo->prepare("
        SELECT * 
        FROM activity_logs 
        WHERE client_id = ? 
          AND by_role = 'Manager'
          AND (
              manager_id = ? 
              OR by_user = ? 
              OR by_user = ?
              OR by_user = ?
          )
          AND by_user NOT IN ('Admin', 'Operator', 'System', ?)
          AND by_user NOT LIKE 'Admin%'
          AND by_user NOT LIKE 'Operator%'
          AND (
              ((activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') AND created_at >= NOW() - INTERVAL 24 HOUR)
              OR
              ((activity NOT LIKE '%Login%' AND activity NOT LIKE '%Logged in%' AND (against_role != 'Login' OR against_role IS NULL)) AND created_at >= NOW() - INTERVAL 730 DAY)
          )
        ORDER BY id DESC
    ");
    $actStmt->execute([
        $client_id, 
        $manager_id, 
        $mgr_user_safe, 
        $mgr_full_safe,
        'Manager: ' . $mgr_user_safe,
        $admin_comp_safe
    ]);
    $activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Activity Breakdown stats
$total_acts = count($activities);
$renew_count = 0;
$user_create_count = 0;
$login_count = 0;

foreach ($activities as $act) {
    $txt = strtolower($act['activity']);
    if (strpos($txt, 'renew') !== false) $renew_count++;
    if (strpos($txt, 'created new user') !== false || strpos($txt, 'create user') !== false) $user_create_count++;
    if (strpos($txt, 'login') !== false || strpos($txt, 'logged in') !== false) $login_count++;
}

// Only fetch recoveries and ledgers if manager has valid name/username
if (!empty($mgr_full_safe) || !empty($mgr_user_safe)) {
    // Fetch All Recoveries, Renewals & Payment Collections for this Manager (Up to 2 Years)
    $recStmt = $pdo->prepare("
        SELECT 
            a.id as act_id,
            a.created_at,
            a.activity,
            a.against_to as username,
            a.against_role,
            a.station_ip,
            s.id as sub_id,
            s.full_name as sub_fullname,
            s.mobile as sub_mobile,
            s.phone as sub_phone,
            s.balance as sub_balance,
            s.status as sub_status,
            s.expiry_date as sub_expiry,
            p.id as package_id,
            p.name as package_name,
            p.price as package_price,
            p.rate_limit as package_speed
        FROM activity_logs a
        LEFT JOIN subscribers s ON a.against_to = s.username COLLATE utf8mb4_general_ci AND s.client_id = a.client_id
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE a.client_id = ?
          AND a.by_role = 'Manager'
          AND (
              a.manager_id = ? 
              OR a.by_user = ? 
              OR a.by_user = ? 
              OR a.by_user = ?
          )
          AND a.by_user NOT IN ('Admin', 'Operator', 'System', ?)
          AND a.by_user NOT LIKE 'Admin%'
          AND a.by_user NOT LIKE 'Operator%'
          AND a.created_at >= NOW() - INTERVAL 730 DAY
          AND (
              (a.activity LIKE '%Renew%' AND a.activity NOT LIKE '%Ready for renewal%')
              OR a.activity LIKE '%Collected%'
              OR a.activity LIKE '%Balance%'
              OR a.activity LIKE '%Payment%'
              OR a.activity LIKE '%Charged%'
          )
        ORDER BY a.created_at DESC
    ");
    $recStmt->execute([
        $client_id, 
        $manager_id, 
        $mgr_user_safe, 
        $mgr_full_safe,
        'Manager: ' . $mgr_user_safe,
        $admin_comp_safe
    ]);
    $raw_recoveries = $recStmt->fetchAll(PDO::FETCH_ASSOC);

    // Also fetch user_ledger records if manager name or username is in description
    $ledStmt = $pdo->prepare("
        SELECT 
            l.id as ledger_id,
            l.created_at,
            l.description,
            l.username,
            l.type,
            l.amount,
            l.balance_after,
            s.id as sub_id,
            s.full_name as sub_fullname,
            s.mobile as sub_mobile,
            s.phone as sub_phone,
            s.balance as sub_balance,
            s.status as sub_status,
            s.expiry_date as sub_expiry,
            p.id as package_id,
            p.name as package_name,
            p.price as package_price,
            p.rate_limit as package_speed
        FROM user_ledger l
        LEFT JOIN subscribers s ON l.username = s.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE l.client_id = ?
          AND l.created_at >= NOW() - INTERVAL 730 DAY
          AND l.description LIKE '%Manager%'
          AND l.description NOT LIKE '%Admin%'
          AND l.description NOT LIKE '%RecoveryMan%'
          AND l.description NOT LIKE '%RM:%'
          AND l.description NOT LIKE '%Lineman%'
          AND l.description NOT LIKE '%Dealer%'
          AND (
              l.description LIKE ? 
              OR l.description LIKE ? 
              OR l.description LIKE ?
          )
        ORDER BY l.created_at DESC
    ");

    $search_name = !empty($mgr_full_safe) ? "%{$mgr_full_safe}%" : "___NO_MATCH___";
    $search_user = !empty($mgr_user_safe) ? "%{$mgr_user_safe}%" : "___NO_MATCH___";

    $ledStmt->execute([
        $client_id,
        $search_name,
        $search_user,
        "%Manager #{$manager_id}%"
    ]);
    $raw_ledgers = $ledStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build Unified All Recoveries List
$all_recoveries = [];
$total_recovered_amount = 0;
$total_renewals_count = 0;
$total_collections_count = 0;
$unique_subs = [];

foreach ($raw_recoveries as $rec) {
    $act_text = $rec['activity'];
    $sub_username = $rec['username'] ?: 'N/A';
    if ($sub_username !== 'N/A') {
        $unique_subs[$sub_username] = true;
    }

    $charged = 0;
    $collected = 0;
    $type = 'other';

    if (stripos($act_text, 'Renew') !== false) {
        $type = 'renewal';
        $total_renewals_count++;
        $charged = (float)($rec['package_price'] ?? 0);
    } elseif (stripos($act_text, 'Collected') !== false || stripos($act_text, 'Payment') !== false) {
        $type = 'collection';
        $total_collections_count++;
    } elseif (stripos($act_text, 'Added Balance') !== false || stripos($act_text, 'Funds added') !== false) {
        $type = 'balance_add';
        $total_collections_count++;
    }

    if (preg_match('/Charged(?:: Rs\.| Rs\.)\s*([\d,.]+)/i', $act_text, $m)) {
        $charged = (float)str_replace(',', '', $m[1]);
    }
    if (preg_match('/(?:Collected(?:\s+Cash)?|Added Balance|Funds added|Payment received|Payment Collected)(?:: Rs\.| Rs\.)\s*([\d,.]+)/i', $act_text, $m)) {
        $collected = (float)str_replace(',', '', $m[1]);
    }

    if ($type === 'renewal' && $collected == 0 && $charged > 0) {
        $collected = $charged;
    }

    $total_recovered_amount += $collected;

    $all_recoveries[] = [
        'id'               => $rec['act_id'],
        'created_at'       => $rec['created_at'],
        'username'         => $sub_username,
        'sub_id'           => $rec['sub_id'],
        'full_name'        => $rec['sub_fullname'] ?: $sub_username,
        'mobile'           => $rec['sub_mobile'] ?: ($rec['sub_phone'] ?: 'N/A'),
        'package_name'     => $rec['package_name'] ?: 'N/A',
        'package_price'    => (float)($rec['package_price'] ?? 0),
        'package_speed'    => $rec['package_speed'] ?: 'N/A',
        'expiry_date'      => $rec['sub_expiry'] ?: '',
        'current_balance'  => (float)($rec['sub_balance'] ?? 0),
        'charged_amount'   => $charged,
        'collected_amount' => $collected,
        'type'             => $type,
        'activity_text'    => $act_text,
        'station_ip'       => $rec['station_ip'] ?: ''
    ];
}

foreach ($raw_ledgers as $led) {
    $led_time = strtotime($led['created_at']);
    $already = false;
    foreach ($raw_recoveries as $rec) {
        if ($rec['username'] === $led['username'] && abs($led_time - strtotime($rec['created_at'])) <= 30) {
            $already = true;
            break;
        }
    }

    if (!$already) {
        $u = $led['username'] ?: 'N/A';
        if ($u !== 'N/A') $unique_subs[$u] = true;

        $amt = (float)$led['amount'];
        $collected = ($led['type'] === 'credit') ? $amt : 0;
        $charged   = ($led['type'] === 'debit') ? $amt : 0;
        $total_recovered_amount += $collected;
        if ($collected > 0) $total_collections_count++;

        $all_recoveries[] = [
            'id'               => 'L' . $led['ledger_id'],
            'created_at'       => $led['created_at'],
            'username'         => $u,
            'sub_id'           => $led['sub_id'],
            'full_name'        => $led['sub_fullname'] ?: $u,
            'mobile'           => $led['sub_mobile'] ?: ($led['sub_phone'] ?: 'N/A'),
            'package_name'     => $led['package_name'] ?: 'N/A',
            'package_price'    => (float)($led['package_price'] ?? 0),
            'package_speed'    => $led['package_speed'] ?: 'N/A',
            'expiry_date'      => $led['sub_expiry'] ?: '',
            'current_balance'  => (float)($led['sub_balance'] ?? ($led['balance_after'] ?? 0)),
            'charged_amount'   => $charged,
            'collected_amount' => $collected,
            'type'             => ($led['type'] === 'credit' ? 'collection' : 'renewal'),
            'activity_text'    => $led['description'],
            'station_ip'       => 'System'
        ];
    }
}

usort($all_recoveries, function($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

$unique_subscribers_count = count($unique_subs);

// Fetch Submissions to Admin (Cash Collected from this Manager)
$submissionStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? 
      AND against_role = 'Manager' 
      AND (against_to = ? OR against_to = ? OR against_to = ?) 
      AND activity LIKE 'Collected Cash Rs.%'
    ORDER BY created_at DESC 
    LIMIT 200
");
$submissionStmt->execute([
    $client_id, 
    $mgr['full_name'], 
    $mgr['username'], 
    "Manager #{$manager_id}"
]);
$submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);

$total_submitted_to_admin = 0;
foreach ($submissions as $sub) {
    if (preg_match('/Collected Cash Rs\.\s*([\d,.]+)/i', $sub['activity'], $m)) {
        $total_submitted_to_admin += (float)str_replace(',', '', $m[1]);
    }
}

// Auto-sync verified cash_in_hand for this manager (clearing any previous cross-contaminated balances)
$pending_difference = max(0, $total_recovered_amount - $total_submitted_to_admin);
$pdo->prepare("UPDATE managers SET cash_in_hand = ? WHERE id = ? AND client_id = ?")->execute([$pending_difference, $manager_id, $client_id]);
$mgr['cash_in_hand'] = $pending_difference;
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
.profile-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}
.profile-banner {
    height: 90px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6, #d946ef);
}
.profile-avatar-wrap {
    margin-top: -45px;
    padding: 0 24px;
}
.profile-avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: #fff;
    border: 4px solid #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    font-weight: 800;
    color: #7c3aed;
}
.perm-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    border: 1px solid #e2e8f0;
    height: 100%;
}
.mini-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 14px 18px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    display: flex;
    align-items: center;
    gap: 14px;
}
.mini-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.badge-soft-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-soft-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-soft-warning { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-soft-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.dt-buttons .btn {
    background: #475569;
    color: #fff;
    border: none;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 0.8rem;
    margin-right: 4px;
}
.dt-buttons .btn:hover { background: #334155; }
.nav-tabs .nav-link {
    font-weight: 600;
    color: #64748b;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 12px 22px;
    font-size: 0.95rem;
    transition: all 0.2s ease;
}
.nav-tabs .nav-link:hover {
    color: #0f172a;
    border-bottom-color: #cbd5e1;
}
.nav-tabs .nav-link.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
    background: transparent;
}
.table-custom-ui th {
    background-color: #f8fafc !important;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}
.table-custom-ui td {
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    color: #475569;
    font-size: 0.9rem;
}
.table-custom-ui tbody tr:hover {
    background-color: #f8fafc;
}
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Flash Message -->
    <?php if ($flash_msg): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> <?= $flash_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb & Back button -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <?php if ($is_logged_manager): ?>
            <a href="dashboard" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
            </a>
            <div class="text-muted small">
                <i class="fa-solid fa-user-shield text-primary me-1"></i> My Manager Profile &amp; Recovery Dashboard
            </div>
        <?php else: ?>
            <a href="managers" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Managers
            </a>
            <div class="text-muted small">
                <i class="fa-solid fa-shield-halved text-primary me-1"></i> Operator Governance Panel
            </div>
        <?php endif; ?>
    </div>

    <!-- Manager Profile Overview -->
    <div class="profile-card mb-4">
        <div class="profile-banner"></div>
        <div class="profile-avatar-wrap d-flex flex-wrap justify-content-between align-items-end gap-3 pb-3">
            <div class="d-flex align-items-end gap-3">
                <div class="profile-avatar">
                    <?= !empty($mgr_full_safe) ? strtoupper(substr($mgr_full_safe, 0, 1)) : '<i class="fa-solid fa-user-gear"></i>' ?>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-dark"><?= !empty($mgr_full_safe) ? htmlspecialchars($mgr_full_safe) : "Manager #{$manager_id}" ?></h4>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span class="badge bg-purple-subtle text-primary border rounded-pill px-2 py-1 font-monospace" style="background:#ede9fe; color:#7c3aed !important; border-color:#ddd6fe !important;">
                            <i class="fa-solid fa-user-shield me-1"></i> <?= !empty($mgr_user_safe) ? htmlspecialchars($mgr_user_safe) : "no-username" ?>
                        </span>
                        <?php if ($mgr['status'] === 'active'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Inactive</span>
                        <?php endif; ?>
                        <span class="text-muted small ms-2"><i class="fa-regular fa-calendar me-1"></i> Joined <?= (!empty($mgr['created_at']) && strtotime($mgr['created_at']) > 0 && date('Y', strtotime($mgr['created_at'])) > 1970) ? date('d M Y', strtotime($mgr['created_at'])) : 'N/A' ?></span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <!-- Edit Profile & Salary Button removed as requested -->
                <!-- Change Password Button -->
                <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fa-solid fa-key me-1"></i> Reset Password
                </button>
                <?php if (!$is_logged_manager): ?>
                <!-- Status Toggle (Admin Only) -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="status" value="<?= $mgr['status'] === 'active' ? 'inactive' : 'active' ?>">
                    <?php if ($mgr['status'] === 'active'): ?>
                        <button type="submit" class="btn btn-outline-warning btn-sm rounded-pill px-3" onclick="return confirm('Suspend this Manager account?');">
                            <i class="fa-solid fa-pause me-1"></i> Deactivate
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-success btn-sm rounded-pill px-3">
                            <i class="fa-solid fa-play me-1"></i> Activate Account
                        </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-4 pt-2 border-top">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Monthly Salary</div>
                    <div class="fw-bold text-success mt-1"><i class="fa-solid fa-money-bill-wave me-1 small"></i> Rs. <?= number_format($mgr['monthly_salary'] ?? 0) ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Phone Number</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-solid fa-phone text-muted me-1 small"></i> <?= htmlspecialchars($mgr['phone'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Email Address</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-regular fa-envelope text-muted me-1 small"></i> <?= htmlspecialchars($mgr['email'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">City / Region</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-solid fa-city text-muted me-1 small"></i> <?= htmlspecialchars($mgr['city'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Address</div>
                    <div class="fw-bold text-dark mt-1 text-truncate" title="<?= htmlspecialchars($mgr['address'] ?: 'N/A') ?>"><i class="fa-solid fa-location-dot text-muted me-1 small"></i> <?= htmlspecialchars($mgr['address'] ?: 'N/A') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ghost Account Alert & Quick Delete (if account is empty/corrupted) -->
    <?php if (empty($mgr_full_safe) && empty($mgr_user_safe)): ?>
    <div class="alert alert-warning border border-warning-subtle rounded-4 p-3 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm">
        <div>
            <i class="fa-solid fa-triangle-exclamation text-warning fs-5 me-2 align-middle"></i>
            <strong>Ghost / Unconfigured Manager Account:</strong> This Manager account (#<?= $manager_id ?>) has no name or username. All financial recoveries are strictly isolated to <strong>Rs. 0</strong>.
        </div>
        <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this unused ghost manager account?');" class="m-0">
            <input type="hidden" name="action" value="delete_manager">
            <input type="hidden" name="id" value="<?= $manager_id ?>">
            <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-trash me-1"></i> Delete Ghost Manager #<?= $manager_id ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Operator-Controlled Permissions & Real-time Toggles -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="perm-card d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fa-solid fa-box-open text-primary me-2"></i> Package &amp; Pricing Management
                    </h6>
                    <p class="text-muted small mb-0">Allow manager to create new packages, edit existing packages, and change rates.</p>
                </div>
                <?php if (!$is_logged_manager): ?>
                <form method="POST" class="ms-3 flex-shrink-0">
                    <input type="hidden" name="action" value="toggle_permission">
                    <input type="hidden" name="permission" value="can_manage_packages">
                    <input type="hidden" name="value" value="<?= $mgr['can_manage_packages'] === 'yes' ? 'no' : 'yes' ?>">
                    <?php if ($mgr['can_manage_packages'] === 'yes'): ?>
                        <button type="submit" class="btn btn-success rounded-pill px-3 py-1 btn-sm fw-semibold shadow-sm" title="Click to Revoke">
                            <i class="fa-solid fa-check-circle me-1"></i> Permitted
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" title="Click to Grant Permission">
                            <i class="fa-solid fa-lock me-1"></i> Locked
                        </button>
                    <?php endif; ?>
                </form>
                <?php else: ?>
                    <div class="ms-3 flex-shrink-0">
                        <?php if ($mgr['can_manage_packages'] === 'yes'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 fw-semibold"><i class="fa-solid fa-check-circle me-1"></i> Permitted</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-2 fw-semibold"><i class="fa-solid fa-lock me-1"></i> Locked</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-md-6">
            <div class="perm-card d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fa-solid fa-hand-holding-dollar text-warning me-2"></i> Operator Cash Collection
                    </h6>
                    <p class="text-muted small mb-0">Allow manager to receive and collect cash in hand from field Operators.</p>
                </div>
                <?php if (!$is_logged_manager): ?>
                <form method="POST" class="ms-3 flex-shrink-0">
                    <input type="hidden" name="action" value="toggle_permission">
                    <input type="hidden" name="permission" value="can_collect_recovery">
                    <input type="hidden" name="value" value="<?= $mgr['can_collect_recovery'] === 'yes' ? 'no' : 'yes' ?>">
                    <?php if ($mgr['can_collect_recovery'] === 'yes'): ?>
                        <button type="submit" class="btn btn-success rounded-pill px-3 py-1 btn-sm fw-semibold shadow-sm" title="Click to Revoke">
                            <i class="fa-solid fa-check-circle me-1"></i> Permitted
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" title="Click to Grant Permission">
                            <i class="fa-solid fa-lock me-1"></i> Locked
                        </button>
                    <?php endif; ?>
                </form>
                <?php else: ?>
                    <div class="ms-3 flex-shrink-0">
                        <?php if ($mgr['can_collect_recovery'] === 'yes'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 fw-semibold"><i class="fa-solid fa-check-circle me-1"></i> Permitted</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-2 fw-semibold"><i class="fa-solid fa-lock me-1"></i> Locked</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Activity & Financial Audit Stat Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $total_acts ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Total Actions (2-Yr Audit)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $renew_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Package Renewals</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(14, 165, 233, 0.1); color: #0ea5e9;">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $user_create_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Users Created</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                    <i class="fa-solid fa-right-to-bracket"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $login_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Logins (Last 7 Days)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial Overview: Pending Cash in Hand & Collections -->
    <div class="row g-3 mb-4">
        <!-- Total Recoveries Card -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-white p-4 text-center d-flex flex-column justify-content-center">
                <div class="text-secondary small fw-bold text-uppercase tracking-wider mb-2">Total Recoveries (2-Yr)</div>
                <h2 class="display-6 fw-bold text-primary mb-1">
                    Rs. <?= number_format($total_recovered_amount, 0) ?>
                </h2>
                <div class="text-muted small mt-1">
                    <i class="fa-solid fa-hand-holding-dollar text-success me-1"></i> From <?= number_format(count($all_recoveries)) ?> Transactions
                </div>
            </div>
        </div>
        
        <!-- Pending Cash in Hand & Admin Collection Card -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 h-100 <?= ($mgr['cash_in_hand'] > 0) ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : 'bg-white' ?> p-4">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center text-center text-sm-start gap-3 h-100">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1 justify-content-center justify-content-sm-start">
                            <span class="badge bg-success text-white px-2.5 py-1 rounded-pill small fw-bold">Cash in Hand</span>
                            <span class="text-muted small fw-bold text-uppercase">Pending Cash to Collect</span>
                        </div>
                        <h1 class="display-5 fw-bold <?= ($mgr['cash_in_hand'] > 0) ? 'text-success' : 'text-dark' ?> mb-1">
                            Rs. <?= number_format($mgr['cash_in_hand'], 0) ?>
                        </h1>
                        <div class="text-muted small">
                            Already Submitted to Admin: <strong class="text-dark">Rs. <?= number_format($total_submitted_to_admin, 0) ?></strong>
                        </div>
                    </div>
                    
                    <div class="text-center flex-shrink-0">
                        <?php if($mgr['cash_in_hand'] > 0): ?>
                            <?php if (!$is_logged_manager): ?>
                                <button class="btn btn-success btn-lg rounded-pill px-4 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#collectManagerCashModal">
                                    <i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Cash
                                </button>
                            <?php else: ?>
                                <div class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle p-2.5 px-3 rounded-pill fs-6 fw-bold">
                                    <i class="fa-solid fa-clock-rotate-left me-1"></i> Pending Handover to Admin
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="badge bg-secondary p-2.5 px-3 rounded-pill fs-6"><i class="fa-solid fa-check-circle me-1"></i> Accounts Cleared</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manager Governance Tabs Container -->
    <div class="card card-custom bg-white mb-4 border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white border-bottom p-0 pt-3 px-3">
            <ul class="nav nav-tabs border-bottom-0" id="managerTabs" role="tablist">
                <!-- Tab 1: All Recovery & Renewals -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="recovery-tab" data-bs-toggle="tab" data-bs-target="#tab-recovery" type="button" role="tab">
                        <i class="fa-solid fa-hand-holding-dollar text-success me-2"></i> All Recovery &amp; Renewals
                        <span class="badge bg-success ms-1"><?= count($all_recoveries) ?></span>
                    </button>
                </li>
                <!-- Tab 2: Activity Audit Trail -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="activity-tab" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab">
                        <i class="fa-solid fa-list-check text-primary me-2"></i> Activity Audit Trail
                        <span class="badge bg-primary ms-1"><?= count($activities) ?></span>
                    </button>
                </li>
                <!-- Tab 3: Salary & Advances -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="salary-tab" data-bs-toggle="tab" data-bs-target="#tab-salary" type="button" role="tab">
                        <i class="fa-solid fa-wallet text-warning me-2"></i> Salary &amp; Advances
                    </button>
                </li>
                <!-- Tab 4: Submissions to Admin -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="submissions-tab" data-bs-toggle="tab" data-bs-target="#tab-submissions" type="button" role="tab">
                        <i class="fa-solid fa-building-columns text-danger me-2"></i> Submissions to Admin
                        <span class="badge bg-danger ms-1"><?= count($submissions) ?></span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-3 p-md-4">
            <div class="tab-content" id="managerTabsContent">

                <!-- Tab 1 Content: All Recovery & Renewals -->
                <div class="tab-pane fade show active" id="tab-recovery" role="tabpanel">
                    
                    <!-- Recovery Mini Stat Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 text-center">
                                <div class="text-success small fw-bold text-uppercase">Total Cash Recovered</div>
                                <h3 class="mb-0 fw-bold text-success mt-1">Rs. <?= number_format($total_recovered_amount, 0) ?></h3>
                                <div class="text-muted small mt-1"><i class="fa-solid fa-shield-halved me-1"></i> 2-Year Audit Preserved</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 text-center">
                                <div class="text-primary small fw-bold text-uppercase">Package Renewals</div>
                                <h3 class="mb-0 fw-bold text-primary mt-1"><?= number_format($total_renewals_count) ?></h3>
                                <div class="text-muted small mt-1">Subscriber Extensions</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-info bg-opacity-10 border border-info border-opacity-25 rounded-3 text-center">
                                <div class="text-info-emphasis small fw-bold text-uppercase">Unique Subscribers</div>
                                <h3 class="mb-0 fw-bold text-dark mt-1"><?= number_format($unique_subscribers_count) ?></h3>
                                <div class="text-muted small mt-1">Customers Served</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 text-center">
                                <div class="text-warning-emphasis small fw-bold text-uppercase">Total Transactions</div>
                                <h3 class="mb-0 fw-bold text-dark mt-1"><?= number_format(count($all_recoveries)) ?></h3>
                                <div class="text-muted small mt-1">Lifetime Events Logged</div>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($all_recoveries)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-hand-holding-dollar fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            <h6 class="fw-bold text-dark">No Recovery or Renewal Records Found</h6>
                            <p class="small text-secondary mb-0">Whenever this Manager renews subscriber packages, collects payments, or adds balances, full records with username, package, and expiry will automatically appear here.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="managerRecoveryTable" class="table table-hover table-custom-ui align-middle w-100">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Subscriber</th>
                                    <th>Package Detail</th>
                                    <th>Expiry Date</th>
                                    <th>Recovered (Paid)</th>
                                    <th>Fee Charged</th>
                                    <th>Current Balance</th>
                                    <th>Recovery Type &amp; Details</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_recoveries as $rec): 
                                    $exp_text = '<span class="text-muted small">N/A</span>';
                                    if (!empty($rec['expiry_date'])) {
                                        $exp_time = strtotime($rec['expiry_date']);
                                        if ($exp_time > 0) {
                                            $formatted_date = date('d M Y, h:i A', $exp_time);
                                            $diff_days = ceil(($exp_time - time()) / 86400);
                                            if ($diff_days < 0) {
                                                $badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0 ms-1 small">Expired</span>';
                                            } elseif ($diff_days <= 3) {
                                                $badge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0 ms-1 small">' . $diff_days . 'd left</span>';
                                            } else {
                                                $badge = '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0 ms-1 small">' . $diff_days . 'd left</span>';
                                            }
                                            $exp_text = '<div class="fw-semibold text-dark" style="white-space:nowrap;">' . $formatted_date . '</div>' . $badge;
                                        }
                                    }
                                    $bal = (float)$rec['current_balance'];
                                ?>
                                <tr>
                                    <td class="text-secondary small fw-bold" style="white-space: nowrap;">
                                        <div class="text-dark"><?= date('d M Y', strtotime($rec['created_at'])) ?></div>
                                        <div class="text-muted small font-monospace"><?= date('h:i:s A', strtotime($rec['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($rec['sub_id'])): ?>
                                            <a href="subscriber_view.php?id=<?= $rec['sub_id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                <i class="fa-solid fa-user me-1 small"></i><?= htmlspecialchars($rec['username']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($rec['username']) ?></span>
                                        <?php endif; ?>
                                        <div class="small text-secondary"><?= htmlspecialchars($rec['full_name']) ?></div>
                                        <?php if (!empty($rec['mobile']) && $rec['mobile'] !== 'N/A'): ?>
                                            <div class="small text-muted font-monospace"><i class="fa-solid fa-phone me-1 text-success"></i><?= htmlspecialchars($rec['mobile']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($rec['package_name']) && $rec['package_name'] !== 'N/A'): ?>
                                            <span class="badge bg-light text-dark border px-2 py-1 fw-bold">
                                                <i class="fa-solid fa-box-open text-primary me-1"></i><?= htmlspecialchars($rec['package_name']) ?>
                                            </span>
                                            <?php if ($rec['package_price'] > 0): ?>
                                                <div class="small text-muted mt-1">Rate: Rs. <?= number_format($rec['package_price'], 0) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $exp_text ?>
                                    </td>
                                    <td>
                                        <?php if ($rec['collected_amount'] > 0): ?>
                                            <span class="text-success fw-bold fs-6" style="white-space:nowrap;">
                                                + Rs. <?= number_format($rec['collected_amount'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fw-bold">Rs. 0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rec['charged_amount'] > 0): ?>
                                            <span class="text-dark fw-semibold" style="white-space:nowrap;">
                                                Rs. <?= number_format($rec['charged_amount'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($bal < 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold px-2 py-1" style="white-space:nowrap;">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> - Rs. <?= number_format(abs($bal), 0) ?>
                                            </span>
                                        <?php elseif ($bal > 0): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-1" style="white-space:nowrap;">
                                                <i class="fa-solid fa-check me-1"></i> + Rs. <?= number_format($bal, 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border fw-bold px-2 py-1">
                                                Rs. 0 (Cleared)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small" style="max-width: 250px;">
                                        <?php if ($rec['type'] === 'renewal'): ?>
                                            <span class="badge badge-soft-success rounded-pill px-2 py-1 me-1"><i class="fa-solid fa-rotate me-1"></i>Renew</span>
                                        <?php elseif ($rec['type'] === 'collection'): ?>
                                            <span class="badge badge-soft-info rounded-pill px-2 py-1 me-1"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Collected</span>
                                        <?php elseif ($rec['type'] === 'balance_add'): ?>
                                            <span class="badge badge-soft-warning rounded-pill px-2 py-1 me-1"><i class="fa-solid fa-plus-circle me-1"></i>Funds Added</span>
                                        <?php endif; ?>
                                        <span class="text-muted"><?= htmlspecialchars($rec['activity_text']) ?></span>
                                    </td>
                                    <td class="text-center" style="white-space:nowrap;">
                                        <?php if (!empty($rec['sub_id'])): ?>
                                            <a href="subscriber_view.php?id=<?= $rec['sub_id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="View Full Subscriber Profile">
                                                <i class="fa-solid fa-eye me-1"></i> View
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- Tab 2 Content: Activity Audit Trail -->
                <div class="tab-pane fade" id="tab-activity" role="tabpanel">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <div>
                            <h6 class="fw-bold mb-1 text-dark">
                                <i class="fa-solid fa-list-check text-primary me-2"></i> Manager Activity Audit Trail
                            </h6>
                            <div class="text-muted small">
                                Login activities are preserved for 7 days, and all other administrative &amp; financial actions are retained for 2 years (730 days).
                            </div>
                        </div>
                        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill font-monospace">
                            <?= count($activities) ?> Recorded Events
                        </span>
                    </div>

                    <?php if (empty($activities)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-circle-check fs-1 text-success opacity-50 mb-3 d-block"></i>
                            <h6 class="fw-bold text-dark">No Activities Recorded Yet</h6>
                            <p class="small text-secondary mb-0">Activities performed by this Manager will appear here automatically.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="managerLogsTable" class="table table-hover table-custom-ui align-middle w-100">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#ID</th>
                                    <th>Datetime</th>
                                    <th>Activity Description</th>
                                    <th>Against To</th>
                                    <th>Against Role</th>
                                    <th>Station IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?= $act['id'] ?></td>
                                        <td style="white-space: nowrap;">
                                            <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($act['created_at'])) ?></div>
                                            <div class="text-muted small"><?= date('h:i:s A', strtotime($act['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                $act_text = htmlspecialchars($act['activity']);
                                                if (stripos($act_text, 'renew') !== false) {
                                                    echo "<span class='badge badge-soft-success px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-arrows-rotate me-1'></i>Renew</span> " . $act_text;
                                                } elseif (stripos($act_text, 'create') !== false) {
                                                    echo "<span class='badge badge-soft-info px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-plus-circle me-1'></i>Create</span> " . $act_text;
                                                } elseif (stripos($act_text, 'login') !== false) {
                                                    echo "<span class='badge badge-soft-secondary px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-arrow-right-to-bracket me-1'></i>Login</span> " . $act_text;
                                                } else {
                                                    echo "<span class='fw-medium text-dark'>" . $act_text . "</span>";
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($act['against_to'] && $act['against_to'] !== 'N/A'): ?>
                                                <span class="badge bg-light text-dark border px-2 py-1 rounded-pill">
                                                    <?= htmlspecialchars($act['against_to']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1">
                                                <?= htmlspecialchars($act['against_role'] ?: 'System') ?>
                                            </span>
                                        </td>
                                        <td class="font-monospace text-muted small">
                                            <?= htmlspecialchars($act['station_ip'] ?: '127.0.0.1') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 3 Content: Salary & Advances -->
                <div class="tab-pane fade" id="tab-salary" role="tabpanel">
                    <?php
                    $staff_type = 'manager';
                    $staff_id = (int)$mgr['id'];
                    $staff_name = $mgr['full_name'];
                    $monthly_sal = (int)($mgr['monthly_salary'] ?? 0);
                    $joined_date = $mgr['created_at'];
                    require __DIR__ . '/staff_salary_view.php';
                    ?>
                </div>

                <!-- Tab 4 Content: Submissions to Admin -->
                <div class="tab-pane fade" id="tab-submissions" role="tabpanel">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <div>
                            <h6 class="fw-bold mb-1 text-dark">
                                <i class="fa-solid fa-building-columns text-danger me-2"></i> Cash Submissions to Admin
                            </h6>
                            <div class="text-muted small">
                                Complete audit log of all cash handovers and collections received by Admin from Manager <strong><?= htmlspecialchars($mgr['full_name']) ?></strong>.
                            </div>
                        </div>
                        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill font-monospace">
                            Total Collected: Rs. <?= number_format($total_submitted_to_admin, 0) ?>
                        </span>
                    </div>

                    <?php if (empty($submissions)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-building-columns fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            <h6 class="fw-bold text-dark">No Cash Submissions Yet</h6>
                            <p class="small text-secondary mb-0">Whenever Admin collects pending cash in hand from this Manager, full payment audit records will appear here.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="managerSubmissionsTable" class="table table-hover table-custom-ui align-middle w-100">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Collected By</th>
                                    <th>Amount Collected</th>
                                    <th>Audit Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($submissions as $sub): ?>
                                <?php
                                    $amt = "N/A";
                                    if (preg_match('/Rs\.\s*([\d,.]+)/', $sub['activity'], $m)) {
                                        $amt = $m[1];
                                    }
                                ?>
                                <tr>
                                    <td class="text-secondary small fw-bold" style="white-space: nowrap;">
                                        <div class="text-dark"><?= date('d M Y', strtotime($sub['created_at'])) ?></div>
                                        <div class="text-muted small font-monospace"><?= date('h:i:s A', strtotime($sub['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-dark px-2.5 py-1">
                                            <i class="fa-solid fa-user-shield me-1"></i> <?= htmlspecialchars($sub['by_user']) ?> (Admin)
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-danger fw-bold fs-6">Rs. <?= $amt ?></span>
                                    </td>
                                    <td class="small text-muted">
                                        <?= htmlspecialchars($sub['activity']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Collect Cash from Manager Modal -->
<div class="modal fade" id="collectManagerCashModal" tabindex="-1" aria-labelledby="collectManagerCashModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
      <form method="POST">
        <input type="hidden" name="action" value="collect_cash">
        <div class="modal-header bg-success text-white p-3 px-4">
          <h5 class="modal-title fw-bold" id="collectManagerCashModalLabel">
            <i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Cash from Manager
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4 text-center">
            <div class="text-muted small fw-semibold text-uppercase mb-1">Pending Cash in Hand</div>
            <h2 class="display-6 fw-bold text-success mb-3">Rs. <?= number_format($mgr['cash_in_hand'], 0) ?></h2>
            <p class="text-muted small mb-4">Manager: <strong><?= htmlspecialchars($mgr['full_name']) ?> (@<?= htmlspecialchars($mgr['username']) ?>)</strong></p>
            
            <div class="mb-3 text-start">
                <label class="form-label fw-bold small text-secondary">Amount to Receive (Rs) <span class="text-danger">*</span></label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text bg-light fw-bold text-secondary">Rs.</span>
                    <input type="number" step="1" min="1" max="<?= (float)$mgr['cash_in_hand'] ?>" name="amount" class="form-control bg-light fw-bold text-dark" placeholder="Enter amount..." value="<?= (float)$mgr['cash_in_hand'] ?>" required>
                </div>
                <div class="form-text small">Maximum available: Rs. <?= number_format($mgr['cash_in_hand'], 0) ?></div>
            </div>
        </div>
        <div class="modal-footer border-top p-3 bg-light d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="return confirm('Confirm cash collection from manager?');">
            <i class="fa-solid fa-check me-1"></i> Confirm Received
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Manager Profile & Salary Modal -->
<div class="modal fade" id="editManagerProfileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Manager Profile &amp; Salary
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="edit_manager_profile">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($mgr['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Username</label>
                            <input type="text" class="form-control font-monospace bg-light" value="<?= htmlspecialchars($mgr['username']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Monthly Salary (Rs)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="0" name="monthly_salary" class="form-control <?= $is_logged_manager ? 'bg-light' : '' ?>" placeholder="e.g. 35000" value="<?= (int)($mgr['monthly_salary'] ?? 0) ?>" <?= $is_logged_manager ? 'readonly' : '' ?>>
                            </div>
                            <div class="text-muted small mt-1"><?= $is_logged_manager ? 'Salary is configured and managed by Admin.' : 'Updates staff ledger auto-accruals and lifetime ledger balance.' ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Account Status</label>
                            <?php if (!$is_logged_manager): ?>
                            <select name="status" class="form-select">
                                <option value="active" <?= ($mgr['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($mgr['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive / Suspended</option>
                            </select>
                            <?php else: ?>
                            <input type="text" class="form-control bg-light" value="<?= ucfirst($mgr['status'] ?? 'active') ?>" readonly>
                            <div class="text-muted small mt-1">Account status is managed by Admin.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($mgr['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($mgr['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">City</label>
                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($mgr['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Office / Physical Address</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($mgr['address'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-key text-warning me-2"></i> Reset Manager Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">Enter a new secure password for <strong><?= htmlspecialchars($mgr['full_name']) ?></strong> (<?= htmlspecialchars($mgr['username']) ?>).</p>
                    <label class="form-label fw-semibold text-secondary small">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required minlength="6">
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Save New Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    if ($('#managerRecoveryTable').length) {
        $('#managerRecoveryTable').DataTable({
            dom: "<'row mb-3'<'col-sm-6'B><'col-sm-6 text-end'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-5'i><'col-sm-7'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn btn-sm btn-outline-success' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv me-1"></i> CSV', className: 'btn btn-sm btn-outline-primary' },
                { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn btn-sm btn-outline-dark' }
            ],
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search recoveries, username, package..."
            }
        });
    }

    if ($('#managerLogsTable').length) {
        $('#managerLogsTable').DataTable({
            dom: "<'row mb-3'<'col-sm-6'B><'col-sm-6 text-end'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-5'i><'col-sm-7'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn btn-sm btn-outline-success' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv me-1"></i> CSV', className: 'btn btn-sm btn-outline-primary' },
                { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn btn-sm btn-outline-dark' }
            ],
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search activities..."
            }
        });
    }

    if ($('#managerSubmissionsTable').length) {
        $('#managerSubmissionsTable').DataTable({
            dom: "<'row mb-3'<'col-sm-6'B><'col-sm-6 text-end'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-5'i><'col-sm-7'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn btn-sm btn-outline-success' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv me-1"></i> CSV', className: 'btn btn-sm btn-outline-primary' },
                { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn btn-sm btn-outline-dark' }
            ],
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search submissions..."
            }
        });
    }

    // Auto-adjust DataTables on tab switch
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        $($.fn.dataTable.tables(true)).DataTable().columns.adjust();
    });

    // Handle deep-link and state retention for tabs
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabParam = urlParams.get('tab') || window.location.hash.replace('#', '') || '<?= $activeTabToOpen ?>';
    if (activeTabParam === 'salary' || activeTabParam === 'tab-salary') {
        const triggerEl = document.querySelector('#salary-tab');
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    } else if (activeTabParam === 'activity' || activeTabParam === 'tab-activity' || activeTabParam === 'activityLogs') {
        const triggerEl = document.querySelector('#activity-tab');
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    } else if (activeTabParam === 'submissions' || activeTabParam === 'tab-submissions') {
        const triggerEl = document.querySelector('#submissions-tab');
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    }
});
</script>

<?php require_once 'footer.php'; ?>
