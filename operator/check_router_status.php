<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

if (!isset($_SESSION['operator_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$client_id = $_SESSION['operator_id'];
$nas_id = (int)($_POST['nas_id'] ?? 0);

$stmt = $pdo->prepare("SELECT nasname, api_user, api_password FROM nas WHERE id = ? AND client_id = ?");
$stmt->execute([$nas_id, $client_id]);
$nas = $stmt->fetch();

if (!$nas) {
    echo json_encode(['status' => 'error', 'message' => 'Router not found.']);
    exit;
}

$API = new RouterosAPI();
$API->timeout_delay = 3; // Fast timeout
error_reporting(0);

if ($API->connect($nas['nasname'], $nas['api_user'], $nas['api_password'])) {
    $API->disconnect();
    echo json_encode(['status' => 'success', 'message' => 'Connected']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Timeout / Auth Failed']);
}
?>