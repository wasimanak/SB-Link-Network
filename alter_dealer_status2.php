<?php
$host = '127.0.0.1';
$db   = 'radius';
$user = 'radius';
$pass = 'Wasi1234';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("ALTER TABLE dealers ADD COLUMN status ENUM('active', 'disabled') DEFAULT 'active'");
    echo "Added 'status' column to dealers table.\n";
} catch (Exception $e) {
    echo "Column might already exist or error: " . $e->getMessage() . "\n";
}
?>
