<?php
require 'config/db.php';

echo "<h2>FreeRADIUS & Database Synchronization Tool</h2>";

try {
    $pdo->beginTransaction();

    // 1. Auto-Active users whose expiry is in the future but status is still expired/disabled
    $sql1 = "UPDATE subscribers SET status = 'active' WHERE expiry_date > NOW() AND status = 'expired'";
    $aff1 = $pdo->exec($sql1);
    echo "<p>Fixed status to Active for $aff1 users who were mistakenly marked as expired.</p>";

    // 2. Remove Auth-Type Reject for ALL active users
    $sql2 = "
        DELETE r FROM radcheck r
        JOIN subscribers s ON r.username = s.username
        WHERE r.attribute = 'Auth-Type' AND r.value = 'Reject' AND s.status = 'active'
    ";
    $aff2 = $pdo->exec($sql2);
    echo "<p>Removed stray 'Auth-Type Reject' blocks for $aff2 active users.</p>";

    // 3. Sync all Expiration dates in radcheck with subscribers table
    // Step A: Delete all existing Expiration records
    $pdo->exec("DELETE FROM radcheck WHERE attribute = 'Expiration'");
    
    // Step B: Re-insert Expiration for all users who have an expiry date
    $stmt = $pdo->query("SELECT username, expiry_date FROM subscribers WHERE expiry_date IS NOT NULL");
    $users = $stmt->fetchAll();
    
    $insertStmt = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)");
    $countExp = 0;
    foreach ($users as $u) {
        $formatted = date('d M Y H:i:s', strtotime($u['expiry_date']));
        $insertStmt->execute([$u['username'], $formatted]);
        $countExp++;
    }
    echo "<p>Re-synced Expiration dates in FreeRADIUS for $countExp users.</p>";

    $pdo->commit();
    echo "<h3 style='color:green;'>All Active Users are now 100% synced with FreeRADIUS. The issue is resolved!</h3>";
    echo "<p>Note: Agar abhi bhi in makhsoos users ka masla aaye, tou iska matlab hai ke Router (Winbox) me inke PPP Secrets locally 'Disabled' paray hain. Unko aik dafa Winbox se manually Enable kar dein, uske baad zindagi bhar ka masla hal ho jayega.</p>";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "<h3 style='color:red;'>Error during synchronization: " . htmlspecialchars($e->getMessage()) . "</h3>";
}
?>
