<?php
require 'config/db.php';

echo "<h2>Universal Space-Immunity System</h2>";

try {
    $pdo->beginTransaction();

    // 1. Create duplicate Space-Aliases in radcheck for ALL existing users
    $sql1 = "
        INSERT IGNORE INTO radcheck (username, attribute, op, value)
        SELECT CONCAT(username, ' '), attribute, op, value
        FROM radcheck
        WHERE username NOT LIKE '% '
    ";
    $count1 = $pdo->exec($sql1);
    echo "<p style='color:green;'>1. radcheck: Created <strong>$count1</strong> Space-Aliases for all users.</p>";

    // 2. Create duplicate Space-Aliases in radusergroup for ALL existing users
    $sql2 = "
        INSERT IGNORE INTO radusergroup (username, groupname, priority)
        SELECT CONCAT(username, ' '), groupname, priority
        FROM radusergroup
        WHERE username NOT LIKE '% '
    ";
    $count2 = $pdo->exec($sql2);
    echo "<p style='color:green;'>2. radusergroup: Created <strong>$count2</strong> Space-Aliases for all users.</p>";

    // 3. Create duplicate Space-Aliases in radreply for ALL existing users
    $sql3 = "
        INSERT IGNORE INTO radreply (username, attribute, op, value)
        SELECT CONCAT(username, ' '), attribute, op, value
        FROM radreply
        WHERE username NOT LIKE '% '
    ";
    $count3 = $pdo->exec($sql3);
    echo "<p style='color:green;'>3. radreply: Created <strong>$count3</strong> Space-Aliases for all users.</p>";

    $pdo->commit();
    echo "<h3 style='color:green;'>System 100% Protected! Ab poore network me kisi bhi user ke router me agar ghalti se aage space dali hogi, wo tab bhi baghair kisi maslay ke connect ho jayega!</h3>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h3 style='color:red;'>Error: " . $e->getMessage() . "</h3>";
}
?>
