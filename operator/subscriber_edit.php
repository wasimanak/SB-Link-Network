<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$id = (int)$_GET['id'];

$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = trim($_POST['password']);
    $package_id = (int)$_POST['package_id'];
    $expiry = $_POST['expiry_date'] ?: null;

    $p = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ? AND client_id = ?");
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
        
        $pdo->prepare("UPDATE subscribers SET password=?, package_id=?, expiry_date=?, status=? WHERE id=?")->execute([$password, $package_id, $expiry, $status, $id]);
        $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $u]);
        $pdo->prepare("UPDATE radreply SET value=? WHERE username=? AND attribute='Mikrotik-Rate-Limit'")->execute([$pkg['rate_limit'], $u]);
        
        // Fix RADIUS Expiration and Auth-Type rules
        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
        if ($expiry) {
            $formatted_expiry = date('d M Y H:i:s', strtotime($expiry));
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
        }
        
        if ($status === 'expired') {
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
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
            <div class="mb-3"><label>Expiry Date</label><input type="date" name="expiry_date" class="form-control" value="<?= $sub['expiry_date'] ?>"></div>
            <button class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>
<?php require_once 'footer.php'; ?>
