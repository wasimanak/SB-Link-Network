<?php
require 'config/db.php';
try {
    // 1. Delete the problematic Expiration attribute from ALL users
    $aff = $pdo->exec("DELETE FROM radcheck WHERE attribute = 'Expiration'");
    echo "<h3>Removed 'Expiration' attribute from $aff users in radcheck!</h3>";
    echo "<p>Please ask shafaaqat16 and the others to connect now. They should connect instantly.</p>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
