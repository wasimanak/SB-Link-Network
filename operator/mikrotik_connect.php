<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nasname = trim($_POST['nasname'] ?? '');
    $secret = trim($_POST['secret'] ?? '');
    $api_port = (int)($_POST['api_port'] ?? 8728);
    $api_user = trim($_POST['api_user'] ?? '');
    $api_password = trim($_POST['api_password'] ?? '');

    if (empty($nasname) || empty($secret)) {
        $error = "IP Address and RADIUS Secret are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM nas WHERE client_id = ?");
            $stmt->execute([$client_id]);
            if ($stmt->fetch()) {
                // Update
                $up = $pdo->prepare("UPDATE nas SET nasname=?, secret=?, api_port=?, api_user=?, api_password=? WHERE client_id=?");
                $up->execute([$nasname, $secret, $api_port, $api_user, $api_password, $client_id]);
            } else {
                // Insert
                $in = $pdo->prepare("INSERT INTO nas (client_id, nasname, secret, api_port, api_user, api_password) VALUES (?, ?, ?, ?, ?, ?)");
                $in->execute([$client_id, $nasname, $secret, $api_port, $api_user, $api_password]);
            }
            $success = "MikroTik API & RADIUS settings saved.";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
$stmt->execute([$client_id]);
$nas = $stmt->fetch();
?>

<div class="card w-75 shadow-sm">
    <div class="card-header">MikroTik Connectivity Settings</div>
    <div class="card-body">
        <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label>Router IP / Hostname (NAS IP)</label>
                    <input type="text" name="nasname" class="form-control" value="<?= htmlspecialchars($nas['nasname'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label>RADIUS Shared Secret</label>
                    <input type="text" name="secret" class="form-control" value="<?= htmlspecialchars($nas['secret'] ?? '') ?>" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label>API Port</label>
                    <input type="number" name="api_port" class="form-control" value="<?= $nas['api_port'] ?? 8728 ?>">
                </div>
                <div class="col-md-4">
                    <label>API Username</label>
                    <input type="text" name="api_user" class="form-control" value="<?= htmlspecialchars($nas['api_user'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label>API Password</label>
                    <input type="password" name="api_password" class="form-control" value="<?= htmlspecialchars($nas['api_password'] ?? '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Configuration</button>
            <?php if($nas): ?>
                <a href="mikrotik_sync.php" class="btn btn-outline-info ms-2"><i class="fa-solid fa-rotate"></i> Sync Profiles & Secrets</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
