<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Safe default values
$client_id = (int)($_SESSION['client_id'] ?? 0);
$rm_id = (int)($_SESSION['rm_id'] ?? 0);
$rm_name = $_SESSION['rm_name'] ?? 'Recovery Agent';
$return_url = !empty($_GET['return']) ? $_GET['return'] : 'dashboard.php';
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : 0;
$ledger_id = isset($_GET['ledger_id']) ? (int)$_GET['ledger_id'] : 0;
if ($ledger_id <= 0) {
    if (!empty($_GET['inv'])) {
        $ledger_id = (int)preg_replace('/[^0-9]/', '', $_GET['inv']);
    } elseif (!empty($_GET['invoice_no'])) {
        $ledger_id = (int)preg_replace('/[^0-9]/', '', $_GET['invoice_no']);
    }
}
$username = isset($_GET['username']) ? trim($_GET['username']) : '';
$transaction_desc = '';
$custom_date = '';
$paid_amount = isset($_GET['paid']) ? (float)$_GET['paid'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'renew'; // renew or collect

// Authentication Check: RecoveryMan, Operator, or Admin
$is_authenticated = false;
if ($rm_id > 0) {
    $is_authenticated = true;
} elseif (!empty($_SESSION['operator_logged_in']) || !empty($_SESSION['operator_id'])) {
    $client_id = (int)$_SESSION['operator_id'];
    if (!empty($_SESSION['is_manager'])) {
        $rm_name = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] . ' (Manager)' : 'Manager';
    } else {
        $rm_name = !empty($_SESSION['operator_name']) ? $_SESSION['operator_name'] . ' (Admin)' : 'Admin';
    }
    $is_authenticated = true;
} elseif (!empty($_SESSION['admin_logged_in'])) {
    $rm_name = 'Admin';
    $is_authenticated = true;
}

if (!$is_authenticated) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Unauthorized</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Unauthorized Access</h5>";
    echo "<p class='text-muted small mb-3'>Please log in to your account to view this receipt.</p>";
    echo "<a href='login.php' class='btn btn-primary rounded-pill px-4'>Go to Login</a>";
    echo "</div></body></html>";
    exit;
}

// If ledger_id is provided, fetch exact transaction record
$invoice_id = 0;
if ($ledger_id > 0) {
    try {
        $lStmt = $pdo->prepare("SELECT * FROM user_ledger WHERE id = ?");
        $lStmt->execute([$ledger_id]);
        $ledger_entry = $lStmt->fetch(PDO::FETCH_ASSOC);
        if ($ledger_entry) {
            $invoice_id = (int)$ledger_entry['id'];
            $custom_date = date('d M Y h:i A', strtotime($ledger_entry['created_at']));
            $transaction_desc = $ledger_entry['description'];

            if ($ledger_entry['type'] === 'debit') {
                $type = 'renew';
                // Check if there was a payment (credit) made during this same renewal (within 5 minutes)
                $target_user = !empty($ledger_entry['username']) ? $ledger_entry['username'] : $username;
                $target_sub = !empty($ledger_entry['subscriber_id']) ? (int)$ledger_entry['subscriber_id'] : $sub_id;
                $cStmt = $pdo->prepare("
                    SELECT amount, description FROM user_ledger 
                    WHERE (subscriber_id = ? OR username = ?) 
                      AND type = 'credit' 
                      AND (created_at >= DATE_SUB(?, INTERVAL 5 MINUTE) AND created_at <= DATE_ADD(?, INTERVAL 5 MINUTE))
                    ORDER BY id DESC LIMIT 1
                ");
                $cStmt->execute([$target_sub, $target_user, $ledger_entry['created_at'], $ledger_entry['created_at']]);
                $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    $paid_amount = (float)$cRow['amount'];
                } else {
                    $paid_amount = 0; // Unpaid renewal on credit
                }
            } else {
                $paid_amount = (float)$ledger_entry['amount'];
                if (stripos($ledger_entry['description'], 'renew') !== false) {
                    $type = 'renew';
                } else {
                    $type = 'collect';
                }
            }

            if (preg_match('/by\s+([A-Za-z0-9_\-\s\(\)]+)/i', $ledger_entry['description'], $m_by)) {
                $rm_name = trim($m_by[1]);
            } elseif (preg_match('/collected\s+by\s+([A-Za-z0-9_\-\s\(\)]+)/i', $ledger_entry['description'], $m_col)) {
                $rm_name = trim($m_col[1]);
            }

            if ($sub_id <= 0 && !empty($ledger_entry['subscriber_id'])) {
                $sub_id = (int)$ledger_entry['subscriber_id'];
            }
            if ($sub_id <= 0 && !empty($ledger_entry['username'])) {
                $username = $ledger_entry['username'];
            }
        }
    } catch (Exception $e) {}
}

// Lookup subscriber by username if sub_id not provided
if ($sub_id <= 0 && !empty($username)) {
    try {
        if ($client_id > 0) {
            $subLookup = $pdo->prepare("SELECT id FROM subscribers WHERE username = ? AND client_id = ? LIMIT 1");
            $subLookup->execute([$username, $client_id]);
            $sub_id = (int)$subLookup->fetchColumn();
        }
        if ($sub_id <= 0) {
            $subLookup = $pdo->prepare("SELECT id FROM subscribers WHERE username = ? LIMIT 1");
            $subLookup->execute([$username]);
            $sub_id = (int)$subLookup->fetchColumn();
        }
    } catch (Exception $e) {}
}

if ($sub_id <= 0) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Invalid Request</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Invalid Request</h5>";
    echo "<p class='text-muted small mb-3'>No subscriber ID was provided.</p>";
    echo "<a href='" . htmlspecialchars($return_url) . "' class='btn btn-secondary rounded-pill px-4'>Back to Dashboard</a>";
    echo "</div></body></html>";
    exit;
}

// 1. Fetch Subscriber Details
$sub = null;
try {
    if ($client_id > 0) {
        $subStmt = $pdo->prepare("
            SELECT s.id, s.client_id, s.username, s.full_name, s.balance, s.expiry_date, s.status, s.created_at, s.mobile, s.phone, s.address, 
                   p.name as package_name, p.price as package_price 
            FROM subscribers s 
            LEFT JOIN packages p ON s.package_id = p.id 
            WHERE s.id = ? AND s.client_id = ?
        ");
        $subStmt->execute([$sub_id, $client_id]);
        $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$sub) {
        $subStmt = $pdo->prepare("
            SELECT s.id, s.client_id, s.username, s.full_name, s.balance, s.expiry_date, s.status, s.created_at, s.mobile, s.phone, s.address, 
                   p.name as package_name, p.price as package_price 
            FROM subscribers s 
            LEFT JOIN packages p ON s.package_id = p.id 
            WHERE s.id = ?
        ");
        $subStmt->execute([$sub_id]);
        $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($sub && $client_id === 0) {
            $client_id = (int)($sub['client_id'] ?? 0);
        }
    }
} catch (Exception $e) {
    $sub = null;
}

if (!$sub) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Subscriber Not Found</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Subscriber Not Found</h5>";
    echo "<p class='text-muted small mb-3'>The subscriber record (ID: {$sub_id}) was not found in database.</p>";
    echo "<a href='" . htmlspecialchars($return_url) . "' class='btn btn-secondary rounded-pill px-4'>Back to Dashboard</a>";
    echo "</div></body></html>";
    exit;
}

// 2. Fetch Recovery Agent Name if available
if ($rm_id > 0) {
    try {
        $rmStmt = $pdo->prepare("SELECT full_name, phone FROM recovery_men WHERE id = ?");
        $rmStmt->execute([$rm_id]);
        $rmData = $rmStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($rmData['full_name'])) {
            $rm_name = $rmData['full_name'];
        }
    } catch (Exception $e) {
        if (!empty($_SESSION['rm_name'])) {
            $rm_name = $_SESSION['rm_name'];
        }
    }
}

// 3. Fetch Operator / Company Details
$operator = null;
if ($client_id > 0) {
    try {
        $opStmt = $pdo->prepare("SELECT company_name, phone FROM clients WHERE id = ?");
        $opStmt->execute([$client_id]);
        $operator = $opStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $operator = null;
    }
}

$company_name = !empty($operator['company_name']) ? $operator['company_name'] : (defined('BRAND_NAME') ? BRAND_NAME : 'SB Link Network');
$company_phone = !empty($operator['phone']) ? $operator['phone'] : 'N/A';
$date = !empty($custom_date) ? $custom_date : date('d M Y h:i A');

// Clean up any fake auto-generated "Official Billing Invoice" records from user_ledger
try {
    $pdo->exec("DELETE FROM user_ledger WHERE description LIKE 'Official Billing Invoice%'");
} catch (Exception $e) {}

// Check if subscriber is currently expired or awaiting renewal
$is_user_expired = (
    (!empty($sub['status']) && $sub['status'] === 'expired') ||
    (!empty($sub['expiry_date']) && strtotime($sub['expiry_date']) <= time())
);

// 4. Fetch Transaction Record in user_ledger for Invoice#
if (empty($invoice_id)) {
    try {
        // Fetch recent ledger transactions for this subscriber (excluding any auto-generated placeholders)
        $invStmt = $pdo->prepare("
            SELECT id, created_at, amount, description, type, balance_after 
            FROM user_ledger 
            WHERE (subscriber_id = ? OR username = ?) 
              AND description NOT LIKE 'Official Billing Invoice%'
            ORDER BY id DESC LIMIT 10
        ");
        $invStmt->execute([$sub_id, $sub['username']]);
        $recent_entries = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($recent_entries)) {
            $latest = $recent_entries[0];
            $invoice_id = (int)$latest['id'];
            if (empty($custom_date) && !empty($latest['created_at'])) {
                $custom_date = date('d M Y h:i A', strtotime($latest['created_at']));
                $date = $custom_date;
            }
            if (empty($transaction_desc) && !empty($latest['description'])) {
                $transaction_desc = $latest['description'];
            }

            // Determine type and paid_amount when not explicitly passed in URL
            if (!isset($_GET['paid'])) {
                if ($is_user_expired) {
                    // CRITICAL: Subscriber is currently in expired status and has NOT been renewed/activated!
                    // Amount received MUST be 0!
                    $paid_amount = 0;
                    $type = 'pending_renewal';
                } elseif ($latest['type'] === 'credit') {
                    // Latest entry is a payment received
                    $paid_amount = (float)$latest['amount'];
                    if (stripos($latest['description'], 'renew') !== false) {
                        $type = 'renew';
                    } else {
                        // Check if a renewal debit exists around the same time (within 5 minutes)
                        $has_renew_debit = false;
                        foreach ($recent_entries as $re) {
                            if ($re['type'] === 'debit' && (stripos($re['description'], 'renew') !== false || stripos($re['description'], 'package') !== false || stripos($re['description'], 'expiry') !== false)) {
                                if (abs(strtotime($re['created_at']) - strtotime($latest['created_at'])) <= 300) {
                                    $has_renew_debit = true;
                                    break;
                                }
                            }
                        }
                        $type = $has_renew_debit ? 'renew' : 'collect';
                    }
                } else {
                    // Latest entry is a debit
                    $type = 'renew';
                    $found_credit = false;
                    foreach ($recent_entries as $re) {
                        if ($re['type'] === 'credit') {
                            if (stripos($re['description'], 'renew') !== false || abs(strtotime($re['created_at']) - strtotime($latest['created_at'])) <= 300) {
                                $paid_amount = (float)$re['amount'];
                                $transaction_desc = $re['description'];
                                $found_credit = true;
                                break;
                            }
                        }
                    }
                    if (!$found_credit) {
                        $paid_amount = 0;
                    }
                }
            }

            // Extract collector / agent name from description
            if (!empty($transaction_desc)) {
                if (preg_match('/by\s+([A-Za-z0-9_\-\s\(\)]+)/i', $transaction_desc, $m_by)) {
                    $rm_name = trim($m_by[1]);
                } elseif (preg_match('/collected\s+by\s+([A-Za-z0-9_\-\s\(\)]+)/i', $transaction_desc, $m_col)) {
                    $rm_name = trim($m_col[1]);
                }
            }
        } else {
            // No ledger entry exists yet in DB (e.g. newly created user in expired status awaiting renewal)
            // DO NOT insert fake credit into user_ledger!
            $invoice_id = $sub_id;
            $type = 'pending_renewal';
            $paid_amount = 0; // Unpaid / awaiting renewal -> 0 RECEIVED!
            $transaction_desc = 'New Account Created (Awaiting Renewal & Payment)';
            if (empty($custom_date) && !empty($sub['created_at'])) {
                $custom_date = date('d M Y h:i A', strtotime($sub['created_at']));
                $date = $custom_date;
            }
        }
    } catch (Exception $e) {
        $invoice_id = $sub_id; // Safe permanent fallback: use subscriber ID
    }
}

if (!$invoice_id) {
    $invoice_id = $sub_id; // Guarantee permanent consistency
}
$invoice_number = "INV-" . str_pad($invoice_id, 6, "0", STR_PAD_LEFT);

// Financial amounts strictly from subscriber account balance
$package_price = (float)($sub['package_price'] ?? 0);
$due_amount = $sub['balance'] < 0 ? abs((float)$sub['balance']) : 0;
$adv_amount = $sub['balance'] > 0 ? (float)$sub['balance'] : 0;

// If paid was explicitly supplied in GET query string, honor it directly
if (isset($_GET['paid'])) {
    $paid_amount = (float)$_GET['paid'];
} elseif ($ledger_id <= 0 && (!isset($paid_amount) || $paid_amount <= 0) && !$is_user_expired) {
    // Only search previous credit for active users, never for expired unrenewed users
    try {
        $lastCreditStmt = $pdo->prepare("
            SELECT id, amount, description, created_at 
            FROM user_ledger 
            WHERE (subscriber_id = ? OR username = ?) 
              AND type = 'credit' 
              AND description NOT LIKE 'Official Billing Invoice%'
            ORDER BY id DESC LIMIT 1
        ");
        $lastCreditStmt->execute([$sub_id, $sub['username']]);
        $lastCredit = $lastCreditStmt->fetch(PDO::FETCH_ASSOC);
        if ($lastCredit && isset($lastCredit['amount'])) {
            $paid_amount = (float)$lastCredit['amount'];
            if (empty($transaction_desc)) {
                $transaction_desc = $lastCredit['description'];
            }
        }
    } catch (Exception $e) {}
}

// Final guarantee: If user is expired / new unrenewed and no payment was passed, paid amount MUST be 0!
if ($is_user_expired && !isset($_GET['paid']) && $ledger_id <= 0) {
    $paid_amount = 0;
}

// Customer Phone Logic
$cust_phone = !empty($sub['mobile']) ? $sub['mobile'] : ($sub['phone'] ?? '');
$wa_number = '';
if (!empty($cust_phone)) {
    $clean = preg_replace('/[^0-9]/', '', $cust_phone);
    if (strpos($clean, '0092') === 0) {
        $wa_number = substr($clean, 2);
    } elseif (strpos($clean, '92') === 0) {
        $wa_number = $clean;
    } elseif (strpos($clean, '0') === 0) {
        $wa_number = '92' . substr($clean, 1);
    } elseif (strlen($clean) === 10 && strpos($clean, '3') === 0) {
        $wa_number = '92' . $clean;
    } else {
        $wa_number = $clean;
    }
}

// WhatsApp Text Receipt
$wa_text = "*PAYMENT RECEIPT*\n";
$wa_text .= "--------------------------------\n";
$wa_text .= "*{$company_name}*\n";
if (!empty($company_phone) && $company_phone !== 'N/A') {
    $wa_text .= "Helpline: {$company_phone}\n";
}
$wa_text .= "--------------------------------\n";
$wa_text .= "Invoice#: {$invoice_number}\n";
$wa_text .= "Date: {$date}\n";
$wa_text .= "User: {$sub['username']}\n";
if (!empty($sub['full_name'])) {
    $wa_text .= "Name: {$sub['full_name']}\n";
}
if (!empty($sub['package_name'])) {
    $wa_text .= "Plan: {$sub['package_name']}\n";
}
if (!empty($sub['expiry_date'])) {
    $wa_text .= "New Expiry: " . date('d M Y', strtotime($sub['expiry_date'])) . "\n";
}
$wa_text .= "Amount Paid: Rs. " . number_format($paid_amount, 0) . "\n";
if ($due_amount > 0) {
    $wa_text .= "Remaining Due: Rs. -(" . number_format($due_amount, 0) . ")\n";
} elseif ($adv_amount > 0) {
    $wa_text .= "Adv Balance: Rs. " . number_format($adv_amount, 0) . "\n";
}
$wa_text .= "Agent: {$rm_name}\n";
$wa_text .= "--------------------------------\n";
$wa_text .= "Thank you for your payment!";
$wa_text_encoded = urlencode($wa_text);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt_80mm - <?= htmlspecialchars($sub['username']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =========================================================
           80mm Thermal Receipt Specifications
           Total Paper Roll: 80mm
           Printable Area: 72mm
           Safe Side Margins: 4mm (Left & Right)
           ========================================================= */
        @page {
            size: 80mm auto;
            margin: 0;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            font-family: 'Consolas', 'Courier New', Courier, monospace, 'Segoe UI', Tahoma, sans-serif;
            color: #000000;
            font-size: 16px;
            font-weight: 500;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        #receipt-content {
            color: #000000 !important;
            font-weight: 500 !important;
            font-size: 16px !important;
            line-height: 1.35;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        #receipt-content * {
            color: #000000 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: 600 !important; }
        .font-mono { font-family: 'Consolas', 'Courier New', monospace; font-weight: 500 !important; }

        /* Brand Typography */
        .brand-title {
            font-size: 24px !important;
            font-weight: 700 !important;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            line-height: 1.2;
            color: #000000 !important;
        }
        .brand-sub {
            font-size: 14px !important;
            letter-spacing: 1px;
            color: #000000 !important;
            font-weight: 500 !important;
            text-transform: uppercase;
            margin-top: 3px;
        }
        .brand-phone {
            font-size: 15px !important;
            margin-top: 3px;
            color: #000000 !important;
            font-weight: 500 !important;
        }

        /* Receipt Title Typography */
        .receipt-title {
            font-size: 17px !important;
            font-weight: 700 !important;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #000000 !important;
        }
        .receipt-subtitle {
            font-size: 14px !important;
            color: #000000 !important;
            font-weight: 500 !important;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* Thermal Divider Lines */
        .divider-dashed {
            border-bottom: 1.5px dashed #000000 !important;
            margin: 6px 0;
            height: 1px;
        }
        .divider-double {
            border-bottom: 3px double #000000 !important;
            margin: 7px 0;
            height: 3px;
        }
        .divider-solid {
            border-bottom: 1.5px solid #000000 !important;
            margin: 6px 0;
            height: 1px;
        }

        /* Key-Value Flex Grid Rows */
        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 3px 0;
            font-size: 16px;
            line-height: 1.35;
            width: 100%;
            box-sizing: border-box;
        }
        .receipt-row .label {
            color: #000000 !important;
            font-weight: 500 !important;
            flex-shrink: 0;
            margin-right: 6px;
            font-size: 16px;
            max-width: 50%;
        }
        .receipt-row .val {
            text-align: right;
            word-break: break-word;
            overflow-wrap: anywhere;
            color: #000000 !important;
            font-size: 16px;
            font-weight: 500 !important;
            flex: 1;
        }

        /* Highlighted rows: New Expiry & Amount Received */
        .receipt-row-highlight {
            font-size: 18px !important;
            padding: 3.5px 0 !important;
        }
        .receipt-row-highlight .label {
            font-size: 17px !important;
            font-weight: 600 !important;
        }
        .receipt-row-highlight .val {
            font-size: 19px !important;
            font-weight: 700 !important;
        }

        /* Due Amount Row: Prominent & Clear */
        .receipt-row-due {
            font-size: 18.5px !important;
            padding: 3.5px 0 !important;
        }
        .receipt-row-due .label {
            font-size: 17.5px !important;
            font-weight: 600 !important;
        }
        .receipt-row-due .val {
            font-size: 20px !important;
            font-weight: 700 !important;
        }

        /* Section Subheading */
        .section-tag {
            font-size: 15px !important;
            font-weight: 600 !important;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #000000 !important;
            border-bottom: 1.5px solid #000000;
            padding-bottom: 2px;
            margin-bottom: 4px;
            margin-top: 4px;
            display: block;
            width: 100%;
        }

        /* Screen Simulation / Interactive Toolbar */
        @media screen {
            body {
                background: #f1f5f9;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 24px 12px;
            }

            .alert-top {
                width: 360px;
                max-width: 95vw;
                background: #ecfdf5;
                color: #065f46;
                border: 1px solid #a7f3d0;
                padding: 10px 14px;
                border-radius: 12px;
                font-weight: 700;
                font-size: 0.88rem;
                text-align: center;
                margin-bottom: 14px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            }

            .no-print-toolbar {
                width: 360px;
                max-width: 95vw;
                margin-bottom: 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .btn-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                padding: 10px 12px;
                border-radius: 10px;
                font-weight: 700;
                font-size: 0.85rem;
                text-decoration: none;
                cursor: pointer;
                border: none;
                box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                transition: transform 0.1s ease, box-shadow 0.1s ease;
            }
            .btn-action:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.14);
            }
            .btn-action:active {
                transform: translateY(1px);
            }

            .btn-print { background: #0f172a; color: #ffffff; }
            .btn-gallery { background: #2563eb; color: #ffffff; }
            .btn-pdf { background: #e11d48; color: #ffffff; }
            .btn-whatsapp { background: #16a34a; color: #ffffff; }
            .btn-back { grid-column: span 2; background: #475569; color: #ffffff; }

            .receipt-wrapper {
                background: #ffffff;
                width: 360px;
                max-width: 95vw;
                padding: 22px 20px;
                border-radius: 14px;
                border: 1px solid #cbd5e1;
                box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.12), 0 6px 12px -4px rgba(0, 0, 0, 0.06);
                position: relative;
            }


        }

        /* 80mm Print Rules */
        @media print {
            .no-print, .no-print-toolbar, .alert-top {
                display: none !important;
            }

            html, body {
                background: #fff !important;
                width: 72mm !important;
                max-width: 72mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
            }

            .receipt-wrapper {
                width: 70mm !important;
                max-width: 70mm !important;
                margin: 0 auto !important;
                padding: 1mm 1.5mm 5mm 1.5mm !important;
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                box-sizing: border-box !important;
            }

            #receipt-content {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 auto !important;
                padding: 0 !important;
                box-sizing: border-box !important;
            }


        }

        /* Highlight Boxes */
        .amount-box {
            border: 2px solid #000000 !important;
            font-weight: 800 !important;
            padding: 4px 6px;
            margin: 6px 0;
            background: #ffffff !important;
            box-sizing: border-box;
            width: 100%;
        }
        @media print {
            .amount-box {
                background: #ffffff !important;
                border: 2px solid #000000 !important;
            }
        }

        .barcode-section {
            margin-top: 8px;
            padding: 3px 0;
            text-align: center;
            width: 100%;
            box-sizing: border-box;
        }
        #receipt-barcode {
            max-width: 95%;
            height: auto;
            display: inline-block;
            margin: 0 auto;
        }

        /* 80mm Thermal Printer Image Export Styles (Clean & High Legibility) */
        .thermal-export-canvas {
            position: fixed !important;
            left: -9999px !important;
            top: 0 !important;
            width: 576px !important;
            min-width: 576px !important;
            max-width: 576px !important;
            height: auto !important;
            background-color: #ffffff !important;
            padding: 16px 14px 24px 14px !important;
            box-sizing: border-box !important;
            font-family: 'Consolas', 'Courier New', Courier, monospace, sans-serif !important;
            z-index: -9999 !important;
            color: #000000 !important;
            font-weight: 500 !important;
            font-size: 18px !important;
        }

        .thermal-export-canvas * {
            color: #000000 !important;
            border-color: #000000 !important;
            background: transparent !important;
            background-color: transparent !important;
            box-shadow: none !important;
            text-shadow: none !important;
        }

        .thermal-export-canvas .brand-title {
            font-size: 28px !important;
            font-weight: 700 !important;
            letter-spacing: 0.8px !important;
            text-transform: uppercase !important;
            line-height: 1.25 !important;
        }

        .thermal-export-canvas .brand-sub {
            font-size: 16px !important;
            letter-spacing: 1px !important;
            font-weight: 500 !important;
            text-transform: uppercase !important;
            margin-top: 3px !important;
        }

        .thermal-export-canvas .brand-phone {
            font-size: 16.5px !important;
            font-weight: 500 !important;
            margin-top: 3px !important;
        }

        .thermal-export-canvas .receipt-title {
            font-size: 19px !important;
            font-weight: 700 !important;
            letter-spacing: 1px !important;
        }

        .thermal-export-canvas .receipt-subtitle {
            font-size: 15.5px !important;
            font-weight: 500 !important;
            letter-spacing: 0.5px !important;
        }

        .thermal-export-canvas .amount-box {
            border: 2px solid #000000 !important;
            background-color: #ffffff !important;
            padding: 8px 12px !important;
            margin: 8px 0 !important;
        }

        .thermal-export-canvas .divider-dashed {
            border-bottom: 2px dashed #000000 !important;
            margin: 8px 0 !important;
            height: 2px !important;
        }

        .thermal-export-canvas .divider-double {
            border-bottom: 4px double #000000 !important;
            margin: 10px 0 !important;
            height: 4px !important;
        }

        .thermal-export-canvas .divider-solid {
            border-bottom: 2px solid #000000 !important;
            margin: 8px 0 !important;
            height: 2px !important;
        }

        .thermal-export-canvas .section-tag {
            font-size: 16.5px !important;
            font-weight: 600 !important;
            letter-spacing: 1px !important;
            border-bottom: 2px solid #000000 !important;
            margin-bottom: 5px !important;
            padding-bottom: 2px !important;
            display: block !important;
        }

        .thermal-export-canvas .receipt-row {
            font-size: 17.5px !important;
            padding: 3.5px 0 !important;
            line-height: 1.35 !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .thermal-export-canvas .receipt-row .label {
            font-size: 17.5px !important;
            font-weight: 500 !important;
            max-width: 50% !important;
        }

        .thermal-export-canvas .receipt-row .val {
            font-size: 17.5px !important;
            font-weight: 500 !important;
            word-break: break-word !important;
            overflow-wrap: anywhere !important;
            flex: 1 !important;
        }

        .thermal-export-canvas .receipt-row-highlight {
            font-size: 19.5px !important;
        }
        .thermal-export-canvas .receipt-row-highlight .label {
            font-size: 18.5px !important;
            font-weight: 600 !important;
        }
        .thermal-export-canvas .receipt-row-highlight .val {
            font-size: 20.5px !important;
            font-weight: 700 !important;
        }

        .thermal-export-canvas .receipt-row-due {
            font-size: 20px !important;
        }
        .thermal-export-canvas .receipt-row-due .label {
            font-size: 19px !important;
            font-weight: 600 !important;
        }
        .thermal-export-canvas .receipt-row-due .val {
            font-size: 21.5px !important;
            font-weight: 700 !important;
        }

        .thermal-export-canvas .barcode-section {
            margin-top: 10px !important;
            padding: 4px 0 !important;
            text-align: center !important;
            width: 100% !important;
        }

        .thermal-export-canvas #receipt-barcode {
            width: 380px !important;
            max-width: 95% !important;
            height: auto !important;
            display: inline-block !important;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
    <script>
        if (typeof JsBarcode === 'undefined') {
            document.write('<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"><\/script>');
        }
    </script>
</head>
<body>

    <!-- Top Success Confirmation -->
    <div class="alert-top no-print">
        <i class="fa-solid <?= ($paid_amount > 0) ? 'fa-circle-check text-success' : 'fa-clock text-warning' ?> me-1"></i>
        <?= !empty($ledger_id) ? 'Official Receipt (Transaction Copy)' : (($paid_amount > 0) ? (($type === 'renew') ? 'Package Renewed &amp; Activated!' : 'Payment Collected Successfully!') : 'Account Created (Pending Renewal &amp; Activation)') ?>
    </div>

    <!-- Screen Action Toolbar -->
    <div class="no-print-toolbar">
        <button type="button" class="btn-action btn-print" id="btnPrintMain" onclick="handlePrintClick()">
            <i class="fa-solid fa-print"></i> Print (80mm)
        </button>
        <button type="button" class="btn-action btn-gallery" id="btnDownloadImg" onclick="downloadImage()">
            <i class="fa-solid fa-images"></i> Download to Gallery
        </button>
        <button type="button" class="btn-action btn-pdf" id="btnDownloadPdf" onclick="downloadPDF()">
            <i class="fa-solid fa-file-pdf"></i> Download PDF
        </button>
        <button type="button" class="btn-action btn-whatsapp" id="btnShareWa" onclick="shareWhatsApp()">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp Share
        </button>
        <a href="<?= htmlspecialchars($return_url) ?>" class="btn-action btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <div style="grid-column: span 2; text-align: center; margin-top: 3px;" class="no-print">
            <a href="javascript:void(0)" onclick="handleDirectTextPrintClick()" id="btnDirectTextLink" style="font-size: 11px; color: #059669; text-decoration: underline; font-weight: 700; margin-right: 8px;">
                <i class="fa-solid fa-bolt me-1"></i> Direct Hardware Text Print (Ultra-Fast)
            </a>
            |
            <a href="javascript:void(0)" onclick="window.print()" style="font-size: 11px; color: #475569; text-decoration: underline; font-weight: 700; margin-left: 8px;">
                <i class="fa-solid fa-desktop me-1"></i> Browser Print Dialog
            </a>
            <span id="bleForgetLink" style="display:none; margin-left: 10px;">
                | <a href="javascript:void(0)" onclick="forgetBlePrinter()" style="font-size: 11px; color: #dc2626; text-decoration: underline; font-weight: 700;">
                    <i class="fa-solid fa-link-slash me-1"></i> Disconnect Bluetooth
                </a>
            </span>
        </div>
    </div>

    <!-- Bluetooth Status Banner -->
    <div id="bleStatusBanner" class="no-print" style="display:none; width: 360px; max-width: 95vw; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; font-size:12px; font-weight:700; padding:8px 12px; border-radius:10px; margin-bottom:12px; text-align:center;">
        <i class="fa-brands fa-bluetooth-b me-1"></i> <span id="bleStatusText">Connecting to Bluetooth Printer...</span>
    </div>

    <!-- 80mm Thermal Receipt Wrapper -->
    <div class="receipt-wrapper">
        <div id="receipt-content">

            <!-- Company Brand Header -->
            <div class="text-center" style="padding-top: 2px;">
                <div class="brand-title">
                    <?= htmlspecialchars($company_name) ?>
                </div>
                <div class="brand-sub">
                    BROADBAND &amp; NETWORK SERVICES
                </div>
                <?php if(!empty($company_phone)): ?>
                <div class="brand-phone">
                    <strong>Helpline:</strong> <?= htmlspecialchars($company_phone) ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="divider-dashed"></div>

            <!-- Receipt Title -->
            <div class="text-center" style="padding: 2px 0;">
                <div class="receipt-title">
                    <?= ($paid_amount > 0) ? (($type === 'renew') ? 'PACKAGE RENEWAL RECEIPT' : 'PAYMENT RECEIPT') : 'BILLING INVOICE / AWAITING PAYMENT' ?>
                </div>
                <div class="receipt-subtitle">*** OFFICIAL CUSTOMER COPY ***</div>
            </div>

            <div class="divider-dashed"></div>

            <!-- Transaction Metadata -->
            <div class="section-tag">TRANSACTION DETAILS</div>
            <div class="receipt-row">
                <span class="label">Invoice No:</span>
                <span class="val fw-bold font-mono"><?= $invoice_number ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Date &amp; Time:</span>
                <span class="val"><?= $date ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Collected By:</span>
                <span class="val"><?= htmlspecialchars($rm_name) ?></span>
            </div>
            <?php if (!empty($transaction_desc)): ?>
            <div class="receipt-row">
                <span class="label">Particulars:</span>
                <span class="val" style="word-break: break-word; overflow-wrap: anywhere; flex: 1;"><?= htmlspecialchars($transaction_desc) ?></span>
            </div>
            <?php endif; ?>
            <div style="display:none;">
            </div>

            <div class="divider-dashed"></div>

            <!-- Subscriber Information -->
            <div class="section-tag">CUSTOMER INFO</div>
            <div class="receipt-row">
                <span class="label">Username:</span>
                <span class="val fw-bold font-mono text-uppercase"><?= htmlspecialchars($sub['username']) ?></span>
            </div>
            <?php if(!empty($sub['full_name'])): ?>
            <div class="receipt-row">
                <span class="label">Full Name:</span>
                <span class="val fw-bold"><?= htmlspecialchars($sub['full_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if(!empty($cust_phone)): ?>
            <div class="receipt-row">
                <span class="label">Phone / Mobile:</span>
                <span class="val"><?= htmlspecialchars($cust_phone) ?></span>
            </div>
            <?php endif; ?>
            <?php if(!empty($sub['package_name'])): ?>
            <div class="receipt-row">
                <span class="label">Internet Plan:</span>
                <span class="val fw-bold"><?= htmlspecialchars($sub['package_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if(!empty($sub['expiry_date'])): ?>
            <div class="receipt-row receipt-row-highlight">
                <span class="label">New Expiry:</span>
                <span class="val font-mono">
                    <?= date('d M Y', strtotime($sub['expiry_date'])) ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="divider-dashed"></div>

            <!-- Payment Breakdown -->
            <div class="section-tag">PAYMENT SUMMARY</div>
            <?php if($type === 'renew' && $package_price > 0): ?>
            <div class="receipt-row">
                <span class="label">Plan Monthly Rate:</span>
                <span class="val">Rs. <?= number_format($package_price, 0) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span class="label">Payment Mode:</span>
                <span class="val">Cash Collection</span>
            </div>

            <!-- Amount Highlight Box -->
            <div class="amount-box">
                <div class="receipt-row receipt-row-highlight">
                    <span class="label">AMOUNT RECEIVED:</span>
                    <span class="val font-mono">Rs. <?= number_format($paid_amount, 0) ?></span>
                </div>
            </div>

            <!-- Balance Status -->
            <div style="padding-top: 2px;">
                <?php if($due_amount > 0): ?>
                <div class="receipt-row receipt-row-due">
                    <span class="label">Remaining Due:</span>
                    <span class="val font-mono">Rs. -(<?= number_format($due_amount, 0) ?>)</span>
                </div>
                <div style="font-size: 14.5px; color: #000000; font-weight: 500; margin-top: 2px;">
                    * Kindly clear pending due at your earliest.
                </div>
                <?php elseif($adv_amount > 0): ?>
                <div class="receipt-row">
                    <span class="label fw-bold">Advance Credit:</span>
                    <span class="val fw-bold font-mono">Rs. <?= number_format($adv_amount, 0) ?></span>
                </div>
                <div style="font-size: 14.5px; color: #000000; font-weight: 500; margin-top: 2px;">
                    * Balance credited to your advance wallet.
                </div>
                <?php else: ?>
                <div class="receipt-row">
                    <span class="label fw-bold">Account Balance:</span>
                    <span class="val fw-bold">Rs. 0.00 (All Clear)</span>
                </div>
                <div style="font-size: 14.5px; color: #000000; font-weight: 500; margin-top: 2px;">
                    * No outstanding dues pending on this account.
                </div>
                <?php endif; ?>
            </div>

            <div class="divider-double"></div>

            <!-- Barcode Section (Invoice Number) -->
            <div class="barcode-section text-center">
                <svg id="receipt-barcode"></svg>
            </div>

            <!-- Footer Notes -->
            <div class="text-center" style="margin-top: 8px;">
                <div class="fw-bold" style="font-size: 16px; margin-bottom: 2px;">Thank you for your business!</div>
                <div style="font-size: 13.5px; color: #000000; font-weight: 500; line-height: 1.35;">
                    This is a computer generated thermal receipt.<br>Valid without physical signature or stamp.
                </div>
                <div style="font-size: 14px; margin-top: 5px; color: #000000; font-weight: 600;">
                    Powered by <strong><?= defined('BRAND_POWERED_BY') ? BRAND_POWERED_BY : 'SB Link Network' ?></strong>
                </div>
            </div>

        </div>
    </div>

    <script>
        function getPDFOptions(filename) {
            var element = document.getElementById('receipt-content');
            var pxHeight = element.offsetHeight;
            // 80mm thermal paper height calculation with safety margin
            var mmHeight = Math.ceil(pxHeight * 0.264583) + 20;
            if (mmHeight < 110) mmHeight = 110;

            return {
                margin: [4, 4, 6, 4], // Safe 4mm side margins on 80mm paper
                filename: filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 3, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: [80, mmHeight], orientation: 'portrait' }
            };
        }

        function cleanWaNumber(num) {
            if (!num) return '';
            var clean = String(num).replace(/[^0-9]/g, '');
            if (clean.indexOf('0092') === 0) {
                return clean.substring(2);
            }
            if (clean.indexOf('92') === 0) {
                return clean;
            }
            if (clean.indexOf('0') === 0) {
                return '92' + clean.substring(1);
            }
            if (clean.length === 10 && clean.indexOf('3') === 0) {
                return '92' + clean;
            }
            return clean;
        }

        function downloadPDF() {
            var element = document.getElementById('receipt-content');
            var filename = <?= json_encode('Receipt_' . ($sub['username'] ?? 'User') . '_80mm.pdf') ?>;
            var opt = getPDFOptions(filename);
            var btn = document.getElementById('btnDownloadPdf');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
                btn.disabled = true;
            }

            html2pdf().set(opt).from(element).save().then(function() {
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
            }).catch(function(err) {
                console.error(err);
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                alert('PDF generation error: ' + err);
            });
        }

        function shareWhatsApp() {
            var element = document.getElementById('receipt-content');
            var filename = <?= json_encode('Receipt_' . ($sub['username'] ?? 'User') . '_80mm.pdf') ?>;
            var opt = getPDFOptions(filename);
            var btn = document.getElementById('btnShareWa');
            var origHtml = btn ? btn.innerHTML : '';

            var waNum = <?= json_encode($wa_number) ?>;
            var waText = <?= json_encode($wa_text) ?>;

            if (!waNum) {
                var promptNum = prompt("Customer ka WhatsApp number darj karein (e.g. 03001234567):");
                if (!promptNum) {
                    return;
                }
                waNum = cleanWaNumber(promptNum);
                if (!waNum || waNum.length < 10) {
                    alert("Baraye meharbani durust mobile number darj karein.");
                    return;
                }
            }

            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing...';
                btn.disabled = true;
            }

            var waUrl = "https://api.whatsapp.com/send?phone=" + encodeURIComponent(waNum) + "&text=" + encodeURIComponent(waText);

            // Step 1: Save PDF to device downloads
            // Step 2: Open customer direct WhatsApp chat with pre-filled receipt & public PDF link
            html2pdf().set(opt).from(element).save().then(function() {
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                var win = window.open(waUrl, '_blank');
                if (!win || win.closed || typeof win.closed === 'undefined') {
                    window.location.href = waUrl;
                }
            }).catch(function(err) {
                console.error("PDF generation note:", err);
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                var win = window.open(waUrl, '_blank');
                if (!win || win.closed || typeof win.closed === 'undefined') {
                    window.location.href = waUrl;
                }
            });
        }

        // 1. Offscreen canvas renderer for 80mm thermal operations
        // scale = 1: native 576 dots ESC/POS Bluetooth raster
        // scale = 2: HD 1152px for Gallery Download / Crisp Mobile View
        function renderReceiptCanvas(scale) {
            scale = scale || 1;
            return new Promise(function(resolve, reject) {
                var element = document.getElementById('receipt-content');
                if (!element) return reject(new Error("Receipt element not found"));

                var clone = element.cloneNode(true);
                clone.id = 'receipt-content-thermal-clone';
                clone.className = 'thermal-export-canvas';

                var cloneBarcode = clone.querySelector('#receipt-barcode');
                if (cloneBarcode && typeof JsBarcode === 'function') {
                    try {
                        JsBarcode(cloneBarcode, <?= json_encode($invoice_number) ?>, {
                            format: "CODE128",
                            lineColor: "#000000",
                            width: 2.2,
                            height: 50,
                            displayValue: true,
                            font: "monospace",
                            fontOptions: "bold",
                            fontSize: 16,
                            textMargin: 5,
                            margin: 0
                        });
                    } catch (e) {
                        console.warn("Clone barcode error:", e);
                    }
                }

                document.body.appendChild(clone);

                setTimeout(function() {
                    html2canvas(clone, {
                        scale: scale, // 1 for native 576 dots, 2 for HD 1152px gallery
                        backgroundColor: '#ffffff',
                        useCORS: true,
                        allowTaint: true,
                        logging: false,
                        width: 576,
                        windowWidth: 576
                    }).then(function(canvas) {
                        if (clone && clone.parentNode) {
                            clone.parentNode.removeChild(clone);
                        }
                        resolve(canvas);
                    }).catch(function(err) {
                        if (clone && clone.parentNode) {
                            clone.parentNode.removeChild(clone);
                        }
                        reject(err);
                    });
                }, 60);
            });
        }

        // 2. Download 80mm Thermal Image to Gallery (High Definition 1152px)
        function downloadImage() {
            var btn = document.getElementById('btnDownloadImg');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving HD...';
                btn.disabled = true;
            }

            renderReceiptCanvas(2).then(function(canvas) { // 2x Scale (1152px HD)
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }

                var filename = <?= json_encode('Receipt_' . ($sub['username'] ?? 'User') . '_80mm_HD.png') ?>;

                canvas.toBlob(function(blob) {
                    if (!blob) {
                        var dataUrl = canvas.toDataURL('image/png');
                        var a = document.createElement('a');
                        a.href = dataUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        alert('HD Receipt image downloaded to Gallery / Downloads!');
                        return;
                    }

                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);

                    setTimeout(function() {
                        URL.revokeObjectURL(url);
                    }, 3000);

                    alert('HD Receipt image saved to Gallery / Downloads!');
                }, 'image/png', 1.0);
            }).catch(function(err) {
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                console.error('html2canvas error:', err);
                alert('Error creating receipt image: ' + err);
            });
        }

        // 3. Web Bluetooth ESC/POS 80mm Thermal Printing
        var BLE_PRINTER_SERVICES = [
            '000018f0-0000-1000-8000-00805f9b34fb', // Standard POS Print Service
            '0000ff00-0000-1000-8000-00805f9b34fb', // Generic Chinese / Xprinter / Goojprt
            '0000ffe0-0000-1000-8000-00805f9b34fb', // HM-10 / MPT-II UART
            '0000fee7-0000-1000-8000-00805f9b34fb', // Tencent / POS BLE
            '0000ae30-0000-1000-8000-00805f9b34fb', // Mini POS / Peripage
            '0000fff0-0000-1000-8000-00805f9b34fb', // FFF0 Generic Serial
            '49535343-fe7d-4ae5-8fa9-9fafd205e455', // ISSC / Microchip Transparent UART
            'e7810a71-73ae-499d-8c15-faa9aef0c3f2', // POS UART
            '6e400001-b5a3-f393-e0a9-e50e24dcca9e', // Nordic UART Service
            0x18f0,
            0xff00,
            0xffe0,
            0xfee7,
            0xae30,
            0xfff0
        ];

        var cachedBleDevice = null;
        var cachedBleCharacteristic = null;

        function showBleStatus(msg, isError) {
            var banner = document.getElementById('bleStatusBanner');
            var text = document.getElementById('bleStatusText');
            if (!banner || !text) return;
            text.innerText = msg;
            banner.style.display = 'block';
            if (isError) {
                banner.style.background = '#fef2f2';
                banner.style.color = '#991b1b';
                banner.style.borderColor = '#fecaca';
            } else {
                banner.style.background = '#eff6ff';
                banner.style.color = '#1e40af';
                banner.style.borderColor = '#bfdbfe';
            }
        }

        function hideBleStatus() {
            var banner = document.getElementById('bleStatusBanner');
            if (banner) banner.style.display = 'none';
        }

        function forgetBlePrinter() {
            if (cachedBleDevice && cachedBleDevice.gatt && cachedBleDevice.gatt.connected) {
                cachedBleDevice.gatt.disconnect();
            }
            cachedBleDevice = null;
            cachedBleCharacteristic = null;
            var forgetLink = document.getElementById('bleForgetLink');
            if (forgetLink) forgetLink.style.display = 'none';
            showBleStatus("Bluetooth printer disconnected.", false);
            setTimeout(hideBleStatus, 2500);
        }

        // Convert 576px canvas to ESC/POS 80mm Raster Bit Image bands (GS v 0)
        function convertCanvasToEscPosBands(canvas) {
            var ctx = canvas.getContext('2d');
            var width = canvas.width; // 576 dots
            var totalHeight = canvas.height;
            var imgData = ctx.getImageData(0, 0, width, totalHeight);
            var pixels = imgData.data;

            var stride = Math.ceil(width / 8); // 72 bytes
            var SLICE_HEIGHT = 128; // safe line slice height to prevent printer buffer overflow
            var allBytes = [];

            // 1. Initialize printer: ESC @ (0x1B, 0x40)
            allBytes.push(0x1B, 0x40);

            // 2. Set line spacing to 0: ESC 3 0 (0x1B, 0x33, 0x00)
            allBytes.push(0x1B, 0x33, 0x00);

            // 3. Process slices
            for (var sliceStart = 0; sliceStart < totalHeight; sliceStart += SLICE_HEIGHT) {
                var sliceH = Math.min(SLICE_HEIGHT, totalHeight - sliceStart);
                var xL = stride % 256;
                var xH = Math.floor(stride / 256);
                var yL = sliceH % 256;
                var yH = Math.floor(sliceH / 256);

                // Header: GS v 0 0 xL xH yL yH
                allBytes.push(0x1D, 0x76, 0x30, 0x00, xL, xH, yL, yH);

                for (var y = sliceStart; y < sliceStart + sliceH; y++) {
                    for (var xByte = 0; xByte < stride; xByte++) {
                        var byteVal = 0;
                        for (var bit = 0; bit < 8; bit++) {
                            var x = xByte * 8 + bit;
                            if (x < width) {
                                var idx = (y * width + x) * 4;
                                var r = pixels[idx];
                                var g = pixels[idx + 1];
                                var b = pixels[idx + 2];
                                var a = pixels[idx + 3];

                                var lum = 0.299 * r + 0.587 * g + 0.114 * b;
                                // High-contrast thermal dot threshold (captures antialiased text edges)
                                if (lum < 192 && a > 64) {
                                    byteVal |= (1 << (7 - bit));
                                }
                            }
                        }
                        allBytes.push(byteVal);
                    }
                }
            }

            // 4. Reset line spacing: ESC 2 (0x1B, 0x32)
            allBytes.push(0x1B, 0x32);

            // 5. Feed 4 lines so it clears tear bar: ESC d 4 (0x1B, 0x64, 0x04)
            allBytes.push(0x1B, 0x64, 0x04);

            // 6. Partial cut: GS V 66 0 (0x1D, 0x56, 0x42, 0x00)
            allBytes.push(0x1D, 0x56, 0x42, 0x00);

            return new Uint8Array(allBytes);
        }

        async function getBleWriteCharacteristic() {
            if (cachedBleDevice && cachedBleDevice.gatt && cachedBleDevice.gatt.connected && cachedBleCharacteristic) {
                return cachedBleCharacteristic;
            }

            showBleStatus("Searching for Bluetooth Thermal Printer... Select your 80mm printer.", false);

            var device = await navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: BLE_PRINTER_SERVICES
            });

            cachedBleDevice = device;
            device.addEventListener('gattserverdisconnected', function() {
                cachedBleCharacteristic = null;
                var forgetLink = document.getElementById('bleForgetLink');
                if (forgetLink) forgetLink.style.display = 'none';
                showBleStatus("Bluetooth Printer Disconnected", false);
                setTimeout(hideBleStatus, 2000);
            });

            showBleStatus("Connecting to " + (device.name || "Bluetooth Printer") + "...", false);
            var server = await device.gatt.connect();

            var foundChar = null;

            // 1. Loop through primary services
            try {
                var services = await server.getPrimaryServices();
                for (var i = 0; i < services.length; i++) {
                    try {
                        var chars = await services[i].getCharacteristics();
                        for (var j = 0; j < chars.length; j++) {
                            var ch = chars[j];
                            if (ch.properties.write || ch.properties.writeWithoutResponse) {
                                foundChar = ch;
                                break;
                            }
                        }
                    } catch(e) {}
                    if (foundChar) break;
                }
            } catch(e) {}

            // 2. Fallback: try known service UUIDs individually
            if (!foundChar) {
                for (var k = 0; k < BLE_PRINTER_SERVICES.length; k++) {
                    try {
                        var s = await server.getPrimaryService(BLE_PRINTER_SERVICES[k]);
                        var sChars = await s.getCharacteristics();
                        for (var m = 0; m < sChars.length; m++) {
                            var c = sChars[m];
                            if (c.properties.write || c.properties.writeWithoutResponse) {
                                foundChar = c;
                                break;
                            }
                        }
                    } catch(e) {}
                    if (foundChar) break;
                }
            }

            if (!foundChar) {
                throw new Error("Could not find a writable channel on this Bluetooth printer.");
            }

            cachedBleCharacteristic = foundChar;
            var forgetLink = document.getElementById('bleForgetLink');
            if (forgetLink) forgetLink.style.display = 'inline';

            return foundChar;
        }

        async function sendToBleCharacteristic(char, data, onProgress) {
            var CHUNK_SIZE = 100; // Safe for BLE MTU
            var total = data.length;

            for (var i = 0; i < total; i += CHUNK_SIZE) {
                var chunk = data.subarray(i, Math.min(i + CHUNK_SIZE, total));
                if (char.properties.writeWithoutResponse) {
                    await char.writeValueWithoutResponse(chunk);
                } else {
                    await char.writeValue(chunk);
                }
                if (onProgress) {
                    onProgress(Math.round(((i + chunk.length) / total) * 100));
                }
                await new Promise(function(r) { setTimeout(r, 12); });
            }
        }

        async function handlePrintClick() {
            var btn = document.getElementById('btnPrintMain');
            var origHtml = btn ? btn.innerHTML : '';

            // Check if Web Bluetooth API is supported and in secure context
            if (!navigator.bluetooth) {
                // If not supported on this browser/context, fall back directly to browser print dialog
                window.print();
                return;
            }

            try {
                if (btn) {
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Bluetooth...';
                    btn.disabled = true;
                }

                var writeChar = await getBleWriteCharacteristic();

                showBleStatus("Rendering 80mm high-contrast thermal receipt...", false);
                if (btn) {
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing...';
                }

                var canvas = await renderReceiptCanvas();
                var escposBytes = convertCanvasToEscPosBands(canvas);

                showBleStatus("Streaming receipt to printer (0%)...", false);
                if (btn) {
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Printing (0%)...';
                }

                await sendToBleCharacteristic(writeChar, escposBytes, function(pct) {
                    showBleStatus("Streaming receipt to printer (" + pct + "%)...", false);
                    if (btn) {
                        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Printing (' + pct + '%)...';
                    }
                });

                showBleStatus("Receipt printed successfully via Bluetooth!", false);
                if (btn) {
                    btn.innerHTML = '<i class="fa-solid fa-check text-success"></i> Printed!';
                    btn.disabled = false;
                    setTimeout(function() {
                        btn.innerHTML = origHtml;
                    }, 3000);
                }
                setTimeout(hideBleStatus, 4000);

            } catch (err) {
                console.warn('Bluetooth print cancelled or error:', err);
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                hideBleStatus();

                // If user simply closed or cancelled the device chooser popup
                if (err.name === 'NotFoundError' || (err.message && (err.message.includes('User cancelled') || err.message.includes('cancelled')))) {
                    return;
                }

                // If error occurred (e.g. bluetooth disabled or device connection failed)
                var useFallback = confirm("Bluetooth print complete nahi ho saka (" + (err.message || err) + ").\n\nKya aap standard browser Print dialog kholna chahty hain?");
                if (useFallback) {
                    window.print();
                }
            }
        }

        // Helper to generate 192x64 centered WiFi logo bit image for Direct Text print
        function buildEscPosLogoBytes() {
            try {
                var c = document.createElement('canvas');
                c.width = 192; // 24 bytes per row, centered on 80mm
                c.height = 64;
                var ctx = c.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, c.width, c.height);
                ctx.fillStyle = '#000000';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '900 38px "Font Awesome 6 Free", "FontAwesome", sans-serif';
                ctx.fillText('\uf1eb', 96, 32); // FontAwesome wifi glyph

                var imgData = ctx.getImageData(0, 0, c.width, c.height);
                var pixels = imgData.data;
                var stride = 24;
                var bytes = [];

                // Center align: ESC a 1
                bytes.push(0x1B, 0x61, 0x01);
                // Raster header: GS v 0 0 xL xH yL yH
                bytes.push(0x1D, 0x76, 0x30, 0x00, stride, 0x00, 64, 0x00);

                for (var y = 0; y < 64; y++) {
                    for (var xb = 0; xb < stride; xb++) {
                        var b = 0;
                        for (var bit = 0; bit < 8; bit++) {
                            var x = xb * 8 + bit;
                            var idx = (y * 192 + x) * 4;
                            var lum = 0.299 * pixels[idx] + 0.587 * pixels[idx+1] + 0.114 * pixels[idx+2];
                            if (lum < 192 && pixels[idx+3] > 64) {
                                b |= (1 << (7 - bit));
                            }
                        }
                        bytes.push(b);
                    }
                }
                return bytes;
            } catch (e) {
                return [];
            }
        }

        // Build native ESC/POS text buffer (48 columns Font A)
        function buildEscPosTextBuffer() {
            var LINE_WIDTH = 48;

            function padLine(left, right) {
                left = String(left || '');
                right = String(right || '');
                var total = left.length + right.length;
                if (total >= LINE_WIDTH) {
                    return left + " " + right + "\n";
                }
                var spaces = " ".repeat(LINE_WIDTH - total);
                return left + spaces + right + "\n";
            }

            var bytes = [];
            function pushStr(str) {
                for (var i = 0; i < str.length; i++) {
                    var code = str.charCodeAt(i);
                    bytes.push(code < 128 ? code : 63);
                }
            }

            // 1. Initialize: ESC @
            bytes.push(0x1B, 0x40);

            // 2. Center Align: ESC a 1
            bytes.push(0x1B, 0x61, 0x01);

            // Double Height & Double Width Bold: ESC ! 0x38
            bytes.push(0x1B, 0x21, 0x38);
            pushStr(<?= json_encode($company_name) ?> + "\n");

            // Normal Font: ESC ! 0x00
            bytes.push(0x1B, 0x21, 0x00);
            pushStr("BROADBAND & NETWORK SERVICES\n");
            <?php if(!empty($company_phone)): ?>
            pushStr("Helpline: " + <?= json_encode($company_phone) ?> + "\n");
            <?php endif; ?>

            pushStr("-".repeat(LINE_WIDTH) + "\n");

            // Receipt Title (Bold: ESC E 1)
            bytes.push(0x1B, 0x45, 0x01);
            pushStr(<?= json_encode(($paid_amount > 0) ? (($type === 'renew') ? 'PACKAGE RENEWAL RECEIPT' : 'PAYMENT RECEIPT') : 'BILLING INVOICE / AWAITING PAYMENT') ?> + "\n");
            pushStr("*** OFFICIAL CUSTOMER COPY ***\n");
            bytes.push(0x1B, 0x45, 0x00);

            pushStr("-".repeat(LINE_WIDTH) + "\n");

            // Left Align: ESC a 0
            bytes.push(0x1B, 0x61, 0x00);

            // Transaction Details
            pushStr(padLine("Invoice No:", <?= json_encode($invoice_number) ?>));
            pushStr(padLine("Date & Time:", <?= json_encode($date) ?>));
            pushStr(padLine("Collected By:", <?= json_encode($rm_name) ?>));
            <?php if(!empty($transaction_desc)): ?>
            pushStr(padLine("Particulars:", <?= json_encode($transaction_desc) ?>));
            <?php endif; ?>

            pushStr("-".repeat(LINE_WIDTH) + "\n");

            // Customer Info
            bytes.push(0x1B, 0x45, 0x01);
            pushStr("CUSTOMER INFO\n");
            bytes.push(0x1B, 0x45, 0x00);
            pushStr(padLine("Username:", <?= json_encode(strtoupper($sub['username'] ?? '')) ?>));
            <?php if(!empty($sub['full_name'])): ?>
            pushStr(padLine("Full Name:", <?= json_encode($sub['full_name']) ?>));
            <?php endif; ?>
            <?php if(!empty($cust_phone)): ?>
            pushStr(padLine("Phone / Mobile:", <?= json_encode($cust_phone) ?>));
            <?php endif; ?>
            <?php if(!empty($sub['package_name'])): ?>
            pushStr(padLine("Internet Plan:", <?= json_encode($sub['package_name']) ?>));
            <?php endif; ?>
            <?php if(!empty($sub['expiry_date'])): ?>
            bytes.push(0x1B, 0x45, 0x01);
            pushStr(padLine("New Expiry:", <?= json_encode(date('d M Y', strtotime($sub['expiry_date']))) ?>));
            bytes.push(0x1B, 0x45, 0x00);
            <?php endif; ?>

            pushStr("-".repeat(LINE_WIDTH) + "\n");

            // Payment Summary
            bytes.push(0x1B, 0x45, 0x01);
            pushStr("PAYMENT SUMMARY\n");
            bytes.push(0x1B, 0x45, 0x00);
            <?php if($type === 'renew' && $package_price > 0): ?>
            pushStr(padLine("Plan Monthly Rate:", <?= json_encode('Rs. ' . number_format($package_price, 0)) ?>));
            <?php endif; ?>
            pushStr(padLine("Payment Mode:", "Cash Collection"));
            pushStr("-".repeat(LINE_WIDTH) + "\n");

            // Amount Received (Double Width Bold: ESC ! 0x20, ESC E 1)
            bytes.push(0x1B, 0x21, 0x20, 0x1B, 0x45, 0x01);
            var aLbl = "AMOUNT RECVD:";
            var aVal = <?= json_encode('Rs. ' . number_format($paid_amount, 0)) ?>;
            var aSpc = " ".repeat(Math.max(1, 24 - aLbl.length - aVal.length));
            pushStr(aLbl + aSpc + aVal + "\n");
            bytes.push(0x1B, 0x21, 0x00, 0x1B, 0x45, 0x00); // Reset

            // Balance
            <?php if($due_amount > 0): ?>
            bytes.push(0x1B, 0x45, 0x01);
            pushStr(padLine("Remaining Due:", <?= json_encode('Rs. -(' . number_format($due_amount, 0) . ')') ?>));
            bytes.push(0x1B, 0x45, 0x00);
            pushStr("* Kindly clear pending due at your earliest.\n");
            <?php elseif($adv_amount > 0): ?>
            pushStr(padLine("Advance Credit:", <?= json_encode('Rs. ' . number_format($adv_amount, 0)) ?>));
            pushStr("* Balance credited to your advance wallet.\n");
            <?php else: ?>
            pushStr(padLine("Account Balance:", "Rs. 0.00 (All Clear)\n"));
            <?php endif; ?>

            pushStr("=".repeat(LINE_WIDTH) + "\n");

            // Barcode Section: Center Align: ESC a 1
            bytes.push(0x1B, 0x61, 0x01);
            // Set barcode height 50 dots: GS h 50 (0x1D, 0x68, 0x32)
            bytes.push(0x1D, 0x68, 0x32);
            // Set barcode width module 2: GS w 2 (0x1D, 0x77, 0x02)
            bytes.push(0x1D, 0x77, 0x02);
            // Text position below: GS H 2 (0x1D, 0x48, 0x02)
            bytes.push(0x1D, 0x48, 0x02);
            // Code 128 command: GS k 73 <length> <data>
            var inv = <?= json_encode($invoice_number) ?>;
            bytes.push(0x1D, 0x6B, 0x49, inv.length);
            pushStr(inv);
            pushStr("\n\n");

            // Footer
            pushStr("Thank you for your business!\n");
            pushStr("Computer generated thermal receipt.\n");
            pushStr("Powered by " + <?= json_encode(defined('BRAND_POWERED_BY') ? BRAND_POWERED_BY : 'SB Link Network') ?> + "\n\n\n");

            // Feed 4 lines: ESC d 4
            bytes.push(0x1B, 0x64, 0x04);
            // Cut: GS V 66 0
            bytes.push(0x1D, 0x56, 0x42, 0x00);

            return new Uint8Array(bytes);
        }

        // Direct Hardware Text Mode Bluetooth print handler
        async function handleDirectTextPrintClick() {
            var link = document.getElementById('btnDirectTextLink');
            var origText = link ? link.innerHTML : '';

            if (!navigator.bluetooth) {
                alert("Direct Bluetooth text print is only supported on Google Chrome / Edge with Bluetooth turned on.");
                return;
            }

            try {
                if (link) {
                    link.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Connecting...';
                }

                var writeChar = await getBleWriteCharacteristic();

                showBleStatus("Sending Direct Hardware Text & Logo to printer...", false);
                if (link) {
                    link.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Printing text...';
                }

                var escposBytes = buildEscPosTextBuffer();

                await sendToBleCharacteristic(writeChar, escposBytes, function(pct) {
                    showBleStatus("Streaming hardware text (" + pct + "%)...", false);
                });

                showBleStatus("Receipt printed instantly with hardware fonts!", false);
                if (link) {
                    link.innerHTML = '<i class="fa-solid fa-check text-success"></i> Printed!';
                    setTimeout(function() {
                        link.innerHTML = origText;
                    }, 3000);
                }
                setTimeout(hideBleStatus, 4000);

            } catch (err) {
                console.warn('Direct text print error:', err);
                if (link) link.innerHTML = origText;
                hideBleStatus();
                if (err.name === 'NotFoundError' || (err.message && err.message.includes('cancelled'))) {
                    return;
                }
                alert('Direct text print error: ' + (err.message || err));
            }
        }

        function initBarcode() {
            try {
                if (typeof JsBarcode === 'function') {
                    JsBarcode("#receipt-barcode", <?= json_encode($invoice_number) ?>, {
                        format: "CODE128",
                        lineColor: "#000000",
                        width: 1.8,
                        height: 42,
                        displayValue: true,
                        font: "monospace",
                        fontOptions: "bold",
                        fontSize: 14,
                        textMargin: 4,
                        margin: 0
                    });
                }
            } catch (e) {
                console.warn("Barcode error:", e);
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initBarcode);
        } else {
            initBarcode();
        }
        window.addEventListener('load', initBarcode);
        setTimeout(initBarcode, 300);
        setTimeout(initBarcode, 1000);
    </script>
</body>
</html>
