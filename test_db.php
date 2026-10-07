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
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $stmt = $pdo->query("SELECT id, username, assigned_routers FROM dealers");
    $dealers = $stmt->fetchAll();
    print_r($dealers);
    
    $nasStmt = $pdo->query("SELECT id, nasname, shortname FROM nas");
    $nas = $nasStmt->fetchAll();
    echo "\nNAS:\n";
    print_r($nas);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}
?>
