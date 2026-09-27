<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    print_r($pdo->query('SHOW COLUMNS FROM support_tickets')->fetchAll(PDO::FETCH_ASSOC));
    print_r($pdo->query('SHOW COLUMNS FROM ticket_messages')->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo $e->getMessage();
}
