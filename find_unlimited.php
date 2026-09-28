<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$queries = [
    "SELECT * FROM radreply WHERE value LIKE '%unlimited%'",
    "SELECT * FROM radcheck WHERE value LIKE '%unlimited%'",
    "SELECT * FROM radgroupreply WHERE value LIKE '%unlimited%'",
    "SELECT * FROM radgroupcheck WHERE value LIKE '%unlimited%'"
];

foreach ($queries as $q) {
    echo "Query: $q\n";
    $rows = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
}
?>
