<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
try {
    $pdo->exec("UPDATE nas SET nasname = '192.168.20.1' WHERE id = 4");
    echo "Updated nasname back to 192.168.20.1 successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
