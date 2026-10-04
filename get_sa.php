<?php
require 'config/db.php';

$stmt = $pdo->query("SELECT * FROM super_admins");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$admins) {
    echo "No super admins found in the database.\n";
} else {
    foreach($admins as $a) {
        echo "ID: " . $a['id'] . "\n";
        echo "Name: " . $a['name'] . "\n";
        echo "Username: " . $a['username'] . "\n";
        echo "Email: " . $a['email'] . "\n";
        echo "--------------------------\n";
    }
}
?>
