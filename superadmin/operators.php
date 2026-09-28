<?php
require_once 'header.php';

// Handle Delete or Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        try {
            $pdo->beginTransaction();

            // 1. Fetch all subscribers of this operator to remove from RADIUS
            $subStmt = $pdo->prepare("SELECT username FROM subscribers WHERE client_id = ?");
            $subStmt->execute([$delId]);
            $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subs as $sub) {
                $u = $sub['username'];
                // Delete from FreeRADIUS tables
                $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
                $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$u]);
            }

            // 2. Fetch all packages to remove from RADIUS (radgroupreply, etc)
            $pkgStmt = $pdo->prepare("SELECT name FROM packages WHERE client_id = ?");
            $pkgStmt->execute([$delId]);
            $pkgs = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pkgs as $pkg) {
                $p = $pkg['name'];
                $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")->execute([$p]);
                $pdo->prepare("DELETE FROM radgroupcheck WHERE groupname = ?")->execute([$p]);
            }

            // 3. Delete from application tables
            // Delete dealers and their notes
            $dealerStmt = $pdo->prepare("SELECT id FROM dealers WHERE client_id = ?");
            $dealerStmt->execute([$delId]);
            $dealers = $dealerStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dealers as $d) {
                $pdo->prepare("DELETE FROM dealer_notes WHERE dealer_id = ?")->execute([$d['id']]);
            }
            
            // Now cascade delete by client_id
            $tables_to_clean = [
                'subscribers',
                'packages',
                'dealers',
                'linemen',
                'recovery_men',
                'nas',
                'user_ledger',
                'activity_logs',
                'fund_requests'
            ];

            foreach ($tables_to_clean as $tbl) {
                // Ignore errors if table doesn't have client_id for some reason, though they all should.
                try {
                    $pdo->prepare("DELETE FROM `$tbl` WHERE client_id = ?")->execute([$delId]);
                } catch(Exception $e) {}
            }

            // Finally, delete the operator (client)
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
            $stmt->execute(['id' => $delId]);

            $pdo->commit();
            echo "<div class='alert alert-success'>Operator and all associated records (Users, Radius data, Dealers, etc.) deleted successfully.</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='alert alert-danger'>Error deleting operator: " . $e->getMessage() . "</div>";
        }
    }
    if (isset($_POST['toggle_status_id'])) {
        $id = (int)$_POST['toggle_status_id'];
        $newStatus = $_POST['new_status'];
        try {
            $pdo->beginTransaction();
            
            // Update operator status
            $stmt = $pdo->prepare("UPDATE clients SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $id]);
            
            if ($newStatus === 'suspended') {
                // Block all users in RADIUS
                $subs = $pdo->prepare("SELECT username FROM subscribers WHERE client_id = ?");
                $subs->execute([$id]);
                foreach ($subs->fetchAll() as $sub) {
                    $u = $sub['username'];
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
                }
                echo "<div class='alert alert-warning'>Operator suspended. All users and sub-portals blocked.</div>";
            } else {
                // Unblock users in RADIUS (except those individually disabled by operator)
                $subs = $pdo->prepare("SELECT username FROM subscribers WHERE client_id = ? AND status != 'disabled'");
                $subs->execute([$id]);
                foreach ($subs->fetchAll() as $sub) {
                    $u = $sub['username'];
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$u]);
                }
                echo "<div class='alert alert-success'>Operator activated. Sub-portals and active users restored.</div>";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    $operators = $stmt->fetchAll();
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Error loading operators.</div>");
}
?>

<div class="d-flex justify-content-between mb-3">
    <h4>Manage Operators</h4>
    <a href="operator_add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add New Operator</a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Limits (Routers/Users)</th>
                        <th>Expiry Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($operators)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No operators found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($operators as $op): ?>
                        <tr>
                            <td><?= $op['id'] ?></td>
                            <td><?= htmlspecialchars($op['company_name']) ?></td>
                            <td><?= htmlspecialchars($op['email']) ?></td>
                            <td>
                                <?php if($op['status'] === 'active'): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php elseif($op['status'] === 'suspended'): ?>
                                    <span class="badge bg-warning text-dark">Suspended</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Expired</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $op['max_routers'] ?> / <?= $op['max_subscribers'] ?></td>
                            <td><?= $op['expiry_date'] ? htmlspecialchars($op['expiry_date']) : 'N/A' ?></td>
                            <td class="text-end">
                                <!-- Impersonate Form -->
                                <form method="POST" action="impersonate.php" class="d-inline">
                                    <input type="hidden" name="client_id" value="<?= $op['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-info" title="Impersonate"><i class="fa-solid fa-user-secret"></i></button>
                                </form>

                                <!-- Edit Button -->
                                <a href="operator_edit.php?id=<?= $op['id'] ?>" class="btn btn-sm btn-secondary" title="Edit"><i class="fa-solid fa-pen"></i></a>

                                <!-- Toggle Status -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="toggle_status_id" value="<?= $op['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $op['status'] === 'active' ? 'suspended' : 'active' ?>">
                                    <button type="submit" class="btn btn-sm btn-warning" title="Toggle Status"><i class="fa-solid fa-power-off"></i></button>
                                </form>

                                <!-- Delete Form -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this operator? All associated routers will be deleted.');">
                                    <input type="hidden" name="delete_id" value="<?= $op['id'] ?>">
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
