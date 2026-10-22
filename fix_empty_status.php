<?php
require 'config/db.php';

echo "<h2>Final Ultimate Fixer & Diagnostic</h2>";

try {
    $pdo->beginTransaction();

    // 1. Fix Empty Statuses
    echo "<h3>1. Fixing Empty Statuses in Subscribers Table</h3>";
    $stmt = $pdo->query("SELECT id, username, status, expiry_date FROM subscribers WHERE status = '' OR status IS NULL OR status NOT IN ('active', 'expired', 'disabled')");
    $empty_status_users = $stmt->fetchAll();
    
    if (count($empty_status_users) > 0) {
        echo "<ul>";
        foreach ($empty_status_users as $u) {
            $new_status = (strtotime($u['expiry_date']) > time()) ? 'active' : 'expired';
            $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$new_status, $u['id']]);
            echo "<li><strong>{$u['username']}</strong>: Status was empty. Changed to <strong>{$new_status}</strong> based on expiry ({$u['expiry_date']}).</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No users with empty status found (already fixed?).</p>";
    }

    // 2. Aggressively fix FreeRADIUS Auth-Type based strictly on Expiry Date
    echo "<h3>2. Aggressive FreeRADIUS Fix (Ignoring Status)</h3>";
    // Delete Auth-Type Reject for ANY user who has future expiry
    $sql_delete_reject = "
        DELETE r FROM radcheck r
        JOIN subscribers s ON r.username = s.username
        WHERE r.attribute = 'Auth-Type' AND r.value = 'Reject' AND s.expiry_date > NOW()
    ";
    $aff = $pdo->exec($sql_delete_reject);
    echo "<p>Removed stray 'Auth-Type Reject' from <strong>$aff</strong> users whose expiry is in the future.</p>";

    $pdo->commit();

    // 3. Diagnostic Dump for the problematic users
    echo "<h3>3. Current FreeRADIUS State for the Problematic Users</h3>";
    $specific_users = ['shafaaqat16', 'tahaawan', 'kohnoor', 'umar16', 'gullamfareed'];
    $in = str_repeat('?,', count($specific_users) - 1) . '?';
    
    // Check Subscribers table again
    echo "<h4>Subscribers Table:</h4><table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Username</th><th>Status</th><th>Expiry Date</th></tr>";
    $sub_stmt = $pdo->prepare("SELECT username, status, expiry_date FROM subscribers WHERE username IN ($in)");
    $sub_stmt->execute($specific_users);
    foreach ($sub_stmt->fetchAll() as $row) {
        echo "<tr><td>{$row['username']}</td><td>{$row['status']}</td><td>{$row['expiry_date']}</td></tr>";
    }
    echo "</table>";

    // Check radcheck table
    echo "<h4>radcheck Table (FreeRADIUS what it actually sees):</h4><table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Username</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    $rad_stmt = $pdo->prepare("SELECT username, attribute, op, value FROM radcheck WHERE username IN ($in)");
    $rad_stmt->execute($specific_users);
    $rad_rows = $rad_stmt->fetchAll();
    if (count($rad_rows) > 0) {
        foreach ($rad_rows as $row) {
            echo "<tr><td>{$row['username']}</td><td>{$row['attribute']}</td><td>{$row['op']}</td><td>{$row['value']}</td></tr>";
        }
    } else {
        echo "<tr><td colspan='4'>NO RECORDS FOUND IN RADCHECK FOR THESE USERS! (This would mean they have no passwords!)</td></tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
