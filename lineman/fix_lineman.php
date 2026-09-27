<?php
$pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
// Update lineman to the correct client_id
$pdo->exec("UPDATE linemen SET client_id = 2 WHERE username = 'lineman1'");
echo "Updated dummy lineman client_id to 2.\n";

// Update the packages query in lineman/dashboard.php
$file = 'C:/xampp/htdocs/SB Link Network/lineman/dashboard.php';
$content = file_get_contents($file);
$content = str_replace(
    'SELECT id, name FROM packages WHERE client_id = ? ORDER BY name ASC', 
    'SELECT id, name FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC', 
    $content
);
file_put_contents($file, $content);
echo "Updated packages query in lineman dashboard.\n";
?>
