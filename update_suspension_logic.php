<?php
$root = 'C:/xampp/htdocs/SB Link Network';

// 1. Update superadmin/operators.php
$op_file = $root . '/superadmin/operators.php';
$op_content = file_get_contents($op_file);

$old_toggle = <<<'PHP'
    if (isset($_POST['toggle_status_id'])) {
        $id = (int)$_POST['toggle_status_id'];
        $newStatus = $_POST['new_status'];
        $stmt = $pdo->prepare("UPDATE clients SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $newStatus, 'id' => $id]);
        echo "<div class='alert alert-success'>Operator status updated.</div>";
    }
PHP;

$new_toggle = <<<'PHP'
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
PHP;

$op_content = str_replace($old_toggle, $new_toggle, $op_content);
file_put_contents($op_file, $op_content);

// 2. Update login.php to check operator status for sub-accounts
$login_file = $root . '/login.php';
$login_content = file_get_contents($login_file);

// Replace Dealer query
$old_d = 'SELECT * FROM dealers WHERE username = ? AND status = \'active\'';
$new_d = 'SELECT d.*, c.status as op_status FROM dealers d JOIN clients c ON d.client_id = c.id WHERE d.username = ? AND d.status = \'active\' AND c.status = \'active\'';
$login_content = str_replace($old_d, $new_d, $login_content);

// Replace Lineman query
$old_l = 'SELECT * FROM linemen WHERE username = ? AND status = \'active\'';
$new_l = 'SELECT l.*, c.status as op_status FROM linemen l JOIN clients c ON l.client_id = c.id WHERE l.username = ? AND l.status = \'active\' AND c.status = \'active\'';
$login_content = str_replace($old_l, $new_l, $login_content);

// Replace Recoveryman query
$old_r = 'SELECT * FROM recovery_men WHERE username = ? AND status = \'active\'';
$new_r = 'SELECT r.*, c.status as op_status FROM recovery_men r JOIN clients c ON r.client_id = c.id WHERE r.username = ? AND r.status = \'active\' AND c.status = \'active\'';
$login_content = str_replace($old_r, $new_r, $login_content);

// Update error message for sub-accounts
$login_content = str_replace("'Invalid Dealer Username or inactive account.'", "'Invalid Dealer credentials, or Operator is suspended.'", $login_content);
$login_content = str_replace("'Invalid Line Man Username or inactive account.'", "'Invalid Line Man credentials, or Operator is suspended.'", $login_content);
$login_content = str_replace("'Invalid Recovery Man Username or inactive account.'", "'Invalid Recovery Man credentials, or Operator is suspended.'", $login_content);

file_put_contents($login_file, $login_content);

echo "Suspension logic updated.\n";
?>
