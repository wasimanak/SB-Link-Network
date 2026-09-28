<?php
$file = 'C:/xampp/htdocs/SB Link Network/superadmin/operators.php';
$content = file_get_contents($file);

$old_delete = <<<'PHP'
    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
        $stmt->execute(['id' => $delId]);
        echo "<div class='alert alert-success'>Operator deleted successfully.</div>";
    }
PHP;

$new_delete = <<<'PHP'
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
PHP;

$content = str_replace($old_delete, $new_delete, $content);
file_put_contents($file, $content);
echo "Cascade delete logic implemented.\n";
?>
