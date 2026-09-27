<?php
$pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
try {
    $pdo->exec("ALTER TABLE activity_logs ADD COLUMN dealer_id INT DEFAULT NULL");
    echo "Added dealer_id to activity_logs.\n";
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
}
?>
