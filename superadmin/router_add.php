<?php
require_once 'header.php';

$error = '';
$success = '';

// Fetch clients for dropdown
$clientsStmt = $pdo->query("SELECT id, company_name FROM clients WHERE status = 'active'");
$clients = $clientsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = (int)$_POST['client_id'];
    $nasname = trim($_POST['nasname'] ?? '');
    $shortname = trim($_POST['shortname'] ?? '');
    $secret = trim($_POST['secret'] ?? '');
    $api_port = (int)($_POST['api_port'] ?? 8728);
    $api_user = trim($_POST['api_user'] ?? '');
    $api_password = trim($_POST['api_password'] ?? '');

    if (empty($client_id) || empty($nasname) || empty($secret)) {
        $error = "Operator, NAS IP, and RADIUS Secret are required.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (:cid, :nas, :short, :sec, :apip, :apiu, :apipass)");
            $stmt->execute([
                'cid' => $client_id,
                'nas' => $nasname,
                'short' => $shortname,
                'sec' => $secret,
                'apip' => $api_port,
                'apiu' => $api_user,
                'apipass' => $api_password
            ]);
            $success = "Router successfully mapped to Operator!";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>

<div class="mb-3">
    <a href="routers.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div class="card shadow-sm w-75">
    <div class="card-header">
        <h5 class="mb-0">Add New Router (NAS)</h5>
    </div>
    <div class="card-body">
        <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <div class="alert alert-danger shadow-sm border-danger border-2">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> CRITICAL REQUIRED STEP:</strong><br>
            FreeRADIUS caches router IPs in its memory. It will <b>IGNORE</b> this new router and give <b>"Radius Timeout"</b> to users unless you restart the FreeRADIUS service.<br><br>
            <i>Please go to your VPS Hosting Panel and <b>Restart/Reboot</b> the server right now!</i>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-12">
                    <label class="form-label">Assign to Operator</label>
                    <select name="client_id" class="form-select" required>
                        <option value="">-- Select Operator --</option>
                        <?php foreach($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">NAS IP Address</label>
                    <input type="text" name="nasname" class="form-control" placeholder="e.g. 192.168.88.1" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Router Name (Shortname)</label>
                    <input type="text" name="shortname" class="form-control" placeholder="MikroTik Core">
                </div>
                <div class="col-md-4">
                    <label class="form-label">RADIUS Secret</label>
                    <input type="text" name="secret" class="form-control" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">API Port (MikroTik)</label>
                    <input type="number" name="api_port" class="form-control" value="8728">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API User</label>
                    <input type="text" name="api_user" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Password</label>
                    <input type="password" name="api_password" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Router</button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
