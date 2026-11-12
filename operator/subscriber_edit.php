<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$id = (int)$_GET['id'];

if (!empty($_SESSION['is_manager'])) {
    $chkStmt = $pdo->prepare("SELECT dealer_id FROM subscribers WHERE id = ? AND client_id = ?");
    $chkStmt->execute([$id, $client_id]);
    $chkDealerId = $chkStmt->fetchColumn();
    if (!empty($chkDealerId)) {
        echo "<script>alert('Permission Denied: Managers cannot edit dealer subscribers.'); window.location='subscribers.php';</script>";
        exit;
    }
}

$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

$opRoutersStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE client_id = ? ORDER BY id ASC");
$opRoutersStmt->execute([$client_id]);
$operator_routers = $opRoutersStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password']);
    $package_id = (int)$_POST['package_id'];
        $is_mgr = !empty($_SESSION['is_manager']);
    if ($is_mgr) {
        $curExpStmt = $pdo->prepare("SELECT expiry_date FROM subscribers WHERE id = ? AND client_id = ?");
        $curExpStmt->execute([$id, $client_id]);
        $expiry = $curExpStmt->fetchColumn() ?: null;
    } else {
        $expiry = $_POST['expiry_date'] ?: null;
    }

    $p = $pdo->prepare("SELECT p.name, p.rate_limit, p.wan_provider, p.pool_id, pl.pool_name 
                        FROM packages p 
                        LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                        WHERE p.id = ? AND p.client_id = ?");
    $p->execute([$package_id, $client_id]);
    $pkg = $p->fetch();

    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
    $uStmt->execute([$id, $client_id]);
    $u = $uStmt->fetchColumn();

    if ($pkg && $u) {
        $pdo->beginTransaction();
        
        // Auto-determine status based on expiry
        $status = 'active';
        if ($expiry && strtotime($expiry) < time()) {
            $status = 'expired';
        }
        
        $router_nasname = trim($_POST['router_nasname'] ?? '');

        $pdo->prepare("UPDATE subscribers SET password=?, package_id=?, expiry_date=?, status=?, nasname=? WHERE id=?")->execute([$password, $package_id, $expiry, $status, $router_nasname, $id]);
        $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $u]);

        if (!empty($router_nasname)) {
            $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute='NAS-IP-Address'")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'NAS-IP-Address', '==', ?)")->execute([$u, $router_nasname]);
        }
        
        $is_user_expired = ($status === 'expired') || (!empty($expiry) && strtotime($expiry) <= time());

        if ($status === 'disabled') {
            $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Rate-Limit', 'Mikrotik-Group', 'Framed-Pool')")->execute([$u]);
        } elseif ($is_user_expired) {
            // Expired User: Walled Garden with 10k/10k rate limit so they can connect but cannot use package internet
            $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Rate-Limit', 'Mikrotik-Group', 'Framed-Pool')")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', '=', 'EXPIRED_USERS')")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', '10k/10k')")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', 'expired')")->execute([$u]);
            try { $pdo->prepare("DELETE FROM radusergroup WHERE username=?")->execute([$u]); } catch (Exception $e) {}
        } else {
            // Active User: Normal package rate-limit and profile
            try {
                $pdo->prepare("DELETE FROM radusergroup WHERE username=?")->execute([$u]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$u, $pkg['name']]);
            } catch (Exception $e) {}
            
            $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Rate-Limit', 'Mikrotik-Group', 'Framed-Pool')")->execute([$u]);
            if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'Unlimited' && $pkg['rate_limit'] !== 'No Limit') {
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
            }

            $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
            if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                    $target_profile = $m_prof[1] . 'Mbps';
                }
            }
            if (!empty($target_profile)) {
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
            }
            
            $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
        }

        $pdo->commit();
        header("Location: subscribers.php");
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM subscribers WHERE id = ? AND client_id = ?");
$stmt->execute([$id, $client_id]);
$sub = $stmt->fetch();
?>
<div class="mb-3"><a href="subscribers.php" class="btn btn-secondary">Back</a></div>
<div class="card shadow-sm w-75">
    <div class="card-header">Edit Subscriber: <?= htmlspecialchars($sub['username']) ?></div>
    <div class="card-body">
        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6"><label>Password</label><input type="text" name="password" class="form-control" value="<?= htmlspecialchars($sub['password']) ?>" required></div>
                <div class="col-md-6">
                    <label>Package</label>
                    <select name="package_id" class="form-select" required>
                        <?php foreach($packages as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $p['id']==$sub['package_id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
                        <?php if (empty($_SESSION['is_manager'])): ?>
            <div class="mb-3"><label>Expiry Date</label><input type="date" name="expiry_date" class="form-control" value="<?= $sub['expiry_date'] ?>"></div>
            <?php else: ?>
            <div class="mb-3"><label>Expiry Date</label><input type="text" class="form-control bg-light text-muted" value="<?= !empty($sub['expiry_date']) ? date('d M Y, h:i A', strtotime($sub['expiry_date'])) : 'N/A' ?>" readonly disabled><small class="text-muted"><i class="fa-solid fa-lock text-secondary me-1"></i> Custom Expiry locked for Manager.</small></div>
            <?php endif; ?>
            <div class="mb-3">
                <label>MikroTik Router (NAS) <span class="text-danger">*</span></label>
                <select name="router_nasname" class="form-select" required>
                    <?php foreach($operator_routers as $r): ?>
                        <option value="<?= htmlspecialchars($r['nasname']) ?>" <?= (($sub['nasname'] ?? '') === $r['nasname']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['shortname'] ?: 'Router') ?> (<?= htmlspecialchars($r['nasname']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted" style="font-size: 0.75rem;">User will ONLY authenticate on this MikroTik router.</small>
            </div>
            <button class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>
<?php require_once 'footer.php'; ?>
