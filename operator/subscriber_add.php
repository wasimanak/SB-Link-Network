<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Get packages for dropdown
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $package_id = (int)$_POST['package_id'];
    $service = $_POST['service_type'];

    // Ensure within quota
    $clientStmt = $pdo->prepare("SELECT max_subscribers FROM clients WHERE id = ?");
    $clientStmt->execute([$client_id]);
    $max_sub = $clientStmt->fetchColumn();

    $curStmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ?");
    $curStmt->execute([$client_id]);
    if ($curStmt->fetchColumn() >= $max_sub) {
        $error = "Subscriber Quota Exceeded!";
    } else {
        // Fetch package details
        $p = $pdo->prepare("SELECT p.name, p.rate_limit, p.wan_provider, p.pool_id, pl.pool_name 
                            FROM packages p 
                            LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                            WHERE p.id = ? AND p.client_id = ?");
        $p->execute([$package_id, $client_id]);
        $pkg = $p->fetch();

        if ($pkg) {
            try {
                $pdo->beginTransaction();
                $now_expiry = date('Y-m-d H:i:s', time() - 60);
                $status = 'expired';

                $is_mgr = !empty($_SESSION['is_manager']);
                $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
                $creator_user = $is_mgr ? ($_SESSION['manager_name'] ?? 'Manager') : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $creator_role = $is_mgr ? 'Manager' : 'Admin';
                $created_by_val = $creator_user . ($creator_role !== 'Admin' ? " ($creator_role)" : "");

                $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, status, expiry_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $package_id, $username, $password, $service, $status, $now_expiry, $created_by_val]);

                
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
                    ->execute([$username, $password]);
                
                // Walled Garden with 10k/10k rate limit for newly created user (Default: Expired awaiting renewal)
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Rate-Limit', 'Mikrotik-Group', 'Framed-Pool')")->execute([$username]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', '=', 'EXPIRED_USERS')")->execute([$username]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', '10k/10k')")->execute([$username]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', 'expired')")->execute([$username]);
                try { $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]); } catch (Exception $e) {}

                // Activity Log
                $is_mgr = !empty($_SESSION['is_manager']);
                $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
                $creator_user = $is_mgr ? ($_SESSION['manager_name'] ?? 'Manager') : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $creator_role = $is_mgr ? 'Manager' : 'Admin';

                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Created New User (Default: Expired - Awaiting Renewal)')")
                    ->execute([$client_id, $mgr_id, $creator_user, $creator_role, $username]);
                
                $pdo->commit();
                header("Location: subscribers.php");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Username already exists or database error.";
            }
        }
    }
}
?>

<div class="mb-3"><a href="subscribers.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a></div>
<div class="card shadow-sm w-75">
    <div class="card-header">Add Subscriber</div>
    <div class="card-body">
        <?php if(isset($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        
        <div class="alert alert-warning border border-warning-subtle rounded-3 py-2 px-3 mb-3 d-flex align-items-center gap-2" style="background-color: #fffbeb;">
            <i class="fa-solid fa-clock-rotate-left text-warning-emphasis fs-5"></i>
            <div class="small text-dark">
                <strong>Default Status: Expired</strong> — Naya user by default <strong>Expired</strong> banega. User add karne ke baad subscribers list se <strong>Renew</strong> karein ta k package charge ho aur financial ledger record generate ho sake.
            </div>
        </div>

        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6"><label>Username (PPPoE/Hotspot)</label><input type="text" name="username" class="form-control" required></div>
                <div class="col-md-6"><label>Password</label><input type="text" name="password" class="form-control" required></div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label>Package</label>
                    <select name="package_id" class="form-select" required>
                        <option value="">-- Select Package --</option>
                        <?php foreach($packages as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['rate_limit'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label>Service Type</label>
                    <select name="service_type" class="form-select">
                        <option value="pppoe">PPPoE</option>
                        <option value="hotspot">Hotspot</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Subscriber</button>
        </form>
    </div>
</div>
<?php require_once 'footer.php'; ?>
