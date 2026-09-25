<?php
$host = '127.0.0.1';
$db   = 'radius_admin';
$user = 'root';
$pass = ''; // Adjust to your MySQL root password if not empty
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Fail gracefully and quietly rather than leaking credentials, although in development we might want to see the error.
    die("Database connection failed. Please check your database configuration.");
}