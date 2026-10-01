<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    exit('Unauthorized');
}

$client_id = $_SESSION['operator_id'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_full_backup_' . date('Ymd_His') . '.csv"');

$output = fopen('php://output', 'w');
// Write Headers
fputcsv($output, [
    'ID', 'Username', 'Password', 'Full Name', 'Service Type', 
    'Package Name', 'Dealer Username', 'Mobile', 'Phone', 'National ID', 
    'City', 'Subarea', 'Address', 'GPS Lat', 'GPS Lng', 'Notes', 
    'Balance', 'Status', 'Expiry Date', 'Total Download (MB)', 'Total Upload (MB)'
]);

$stmt = $pdo->prepare("
    SELECT s.*, 
           p.name as package_name, 
           d.username as dealer_username
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    WHERE s.client_id = ?
    ORDER BY s.id DESC
");
$stmt->execute([$client_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    
    // Calculate usage from radacct
    $acctStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as rx, SUM(acctoutputoctets) as tx FROM radacct WHERE username = ?");
    $acctStmt->execute([$row['username']]);
    $usage = $acctStmt->fetch();
    
    // Radacct rx is upload (from user to NAS), tx is download (NAS to user)
    $upload_mb = round(($usage['rx'] ?? 0) / 1048576, 2);
    $download_mb = round(($usage['tx'] ?? 0) / 1048576, 2);

    fputcsv($output, [
        $row['id'],
        $row['username'],
        $row['password'],
        $row['full_name'],
        $row['service_type'],
        $row['package_name'],
        $row['dealer_username'],
        $row['mobile'],
        $row['phone'],
        $row['national_id'],
        $row['city'],
        $row['subarea'],
        $row['address'],
        $row['gps_lat'],
        $row['gps_lng'],
        $row['notes'],
        $row['balance'],
        $row['status'],
        $row['expiry_date'],
        $download_mb,
        $upload_mb
    ]);
}
fclose($output);
exit;
