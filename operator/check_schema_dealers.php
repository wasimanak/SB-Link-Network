<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    print_r($pdo->query('SHOW COLUMNS FROM clients')->fetchAll(PDO::FETCH_ASSOC));
    print_r($pdo->query('SHOW COLUMNS FROM subscribers')->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo $e->getMessage();
}
