<?php
$f = __DIR__ . '/recoveryman/dashboard.php';
if (file_exists($f)) {
    $c = file_get_contents($f);
    $find = "\$pdo->prepare(\"DELETE FROM radcheck WHERE username = ? AND attribute = 'Expiration'\")->execute([\$sub_username]);";
    $replace = "\$pdo->prepare(\"DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')\")->execute([\$sub_username]);";
    $c = str_replace($find, $replace, $c);
    file_put_contents($f, $c);
    echo "<h3>1. Fixed recoveryman/dashboard.php logic.</h3>\n";
}

// Self-healing query for VPS
if (file_exists(__DIR__ . '/config/db.php')) {
    require __DIR__ . '/config/db.php';
    try {
        $sql = "
            DELETE r FROM radcheck r
            JOIN subscribers s ON r.username = s.username
            WHERE r.attribute = 'Auth-Type' 
            AND r.value = 'Reject'
            AND s.status = 'active'
        ";
        $affected = $pdo->exec($sql);
        echo "<h3>2. Self-healing complete. Fixed Auth-Type for $affected active users.</h3>\n";
        echo "<p>Sab maslay hal ho gaye hain! Users ka net fauran chal jayega.</p>";
    } catch (Exception $e) {
        // Ignore local DB connection errors
    }
}
?>
