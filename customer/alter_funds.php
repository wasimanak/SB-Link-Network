<?php
$pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
try {
    $pdo->exec("ALTER TABLE fund_requests ADD COLUMN dealer_id INT DEFAULT NULL AFTER subscriber_id");
    // Make subscriber_id nullable so dealer requests don't fail
    $pdo->exec("ALTER TABLE fund_requests MODIFY subscriber_id INT NULL");
    echo "Modified fund_requests to support dealers.\n";
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
}
?>
