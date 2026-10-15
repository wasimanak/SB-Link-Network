<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['operator_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config/db.php';
require_once '../config/mikrotik_sync_helper.php';

$client_id = (int)$_SESSION['operator_id'];
$res = sync_operator_mikrotik_pools($pdo, $client_id);

echo json_encode($res);
