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
                $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$client_id, $package_id, $username, $password, $service]);
                
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
                    ->execute([$username, $password]);
                
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
                    ->execute([$username, $pkg['name']]);
                
                // Always inject fail-safe rate limit in radreply with op '='
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                        ->execute([$username, $pkg['rate_limit']]);
                }

                // NEVER inject Mikrotik-Address-List (prevents 700 dynamic entries and WAN hijacking)
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$username]);

                // Determine profile name (e.g. 4Mbps)
                $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                    if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                        $target_profile = $m_prof[1] . 'Mbps';
                    }
                }
                
                if (!empty($target_profile)) {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$username, $target_profile]);
                }
                
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
