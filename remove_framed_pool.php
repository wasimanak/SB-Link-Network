<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. Delete all 'Framed-Pool' entries from radgroupreply
$pdo->exec("DELETE FROM radgroupreply WHERE attribute='Framed-Pool'");

// 2. Remove from operator/api_mikrotik_sync.php
$api_sync = 'C:/xampp/htdocs/SB Link Network/operator/api_mikrotik_sync.php';
$content = file_get_contents($api_sync);
$content = str_replace("\$pdo->prepare(\"INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', 'pool1')\")\n                    ->execute([\$name]);", '', $content);
file_put_contents($api_sync, $content);

// 3. Remove from superadmin/operator_edit.php
$op_edit = 'C:/xampp/htdocs/SB Link Network/superadmin/operator_edit.php';
$content = file_get_contents($op_edit);
$content = str_replace("\$pdo->prepare(\"INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', 'pool1')\")->execute([\$name]);", '', $content);
file_put_contents($op_edit, $content);

// 4. Remove from operator/packages.php
$packages = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($packages);
$content = str_replace("\$pdo->prepare(\"INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', 'pool1')\")->execute([\$name]);", '', $content);
file_put_contents($packages, $content);

echo "Removed 'Framed-Pool' from database and PHP scripts.\n";
?>
