<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM nas WHERE id = :id");
        $stmt->execute(['id' => $delId]);
        
        // Disconnect FreeRADIUS from this NAS by forcing a reload if possible,
        // but for now we ensure it's deleted from DB.
        
        header("Location: routers.php?msg=deleted");
        exit;
    } catch (Exception $e) {
        die("<div class='alert alert-danger'>Error deleting router: " . htmlspecialchars($e->getMessage()) . "</div>");
    }
}

$msg = $_GET['msg'] ?? '';
if ($msg === 'deleted') {
    echo "<div class='alert alert-success'>Router (MikroTik) deleted successfully from the database.</div>";
}

try {
    $stmt = $pdo->query("SELECT nas.*, clients.company_name FROM nas JOIN clients ON nas.client_id = clients.id ORDER BY nas.id DESC");
    $routers = $stmt->fetchAll();
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Error loading routers.</div>");
}
?>

<div class="d-flex justify-content-between mb-3">
    <h4>Manage Routers (NAS)</h4>
    <a href="router_add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add New Router</a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Operator</th>
                        <th>Name (Shortname)</th>
                        <th>NAS IP</th>
                        <th>Secret</th>
                        <th>API Port</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($routers)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No routers found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($routers as $r): ?>
                        <tr>
                            <td><?= $r['id'] ?></td>
                            <td><?= htmlspecialchars($r['company_name']) ?></td>
                            <td><?= htmlspecialchars($r['shortname']) ?></td>
                            <td><span class="font-monospace"><?= htmlspecialchars($r['nasname']) ?></span></td>
                            <td>
                                <span class="badge bg-secondary cursor-pointer" onclick="alert('Secret: <?= htmlspecialchars($r['secret']) ?>')">Show Secret</span>
                            </td>
                            <td><?= $r['api_port'] ?></td>
                            <td>
                                <!-- Mock ping / health check -->
                                <span class="badge bg-success"><i class="fa-solid fa-circle-check"></i> Online</span>
                            </td>
                            <td class="text-end">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this router from FreeRADIUS/System?');">
                                    <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
