<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    exit('Unauthorized');
}

$client_id = $_SESSION['operator_id'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_backup_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'Username', 'Full Name', 'Package ID', 'Status', 'Expiry Date (YYYY-MM-DD HH:MM:SS)']);

$stmt = $pdo->prepare("SELECT id, username, full_name, package_id, status, expiry_date FROM subscribers WHERE client_id = ? ORDER BY id DESC");
$stmt->execute([$client_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['username'],
        $row['full_name'],
        $row['package_id'],
        $row['status'],
        $row['expiry_date']
    ]);
}
fclose($output);
exit;
