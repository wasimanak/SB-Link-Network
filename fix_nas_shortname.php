<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
try {
    $pdo->exec("UPDATE nas SET shortname = 'mikrotik' WHERE id = 4");
    echo "Updated shortname successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
