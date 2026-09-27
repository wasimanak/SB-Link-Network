<?php
session_start();
require_once '../config/db.php';
if (!isset($_SESSION['rm_id']) || !isset($_GET['username'])) exit;

$username = $_GET['username'];
$client_id = $_SESSION['client_id'];

$stmt = $pdo->prepare("SELECT * FROM user_ledger WHERE username = ? AND client_id = ? ORDER BY id DESC LIMIT 5");
$stmt->execute([$username, $client_id]);
$ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$ledger) {
    echo "<div class='text-muted small'>No recent transactions found.</div>";
    exit;
}

echo '<ul class="list-group text-start shadow-sm border-0">';
foreach ($ledger as $l) {
    $color = $l['type'] === 'credit' ? 'text-success' : 'text-danger';
    $sign = $l['type'] === 'credit' ? '+' : '-';
    $icon = $l['type'] === 'credit' ? 'fa-arrow-down' : 'fa-arrow-up';
    $date = date('d M Y, h:i A', strtotime($l['created_at']));
    
    echo "<li class='list-group-item d-flex justify-content-between align-items-center p-3'>
            <div>
                <div class='fw-bold text-dark small'>{$l['description']}</div>
                <div class='text-muted' style='font-size:0.75rem;'>{$date}</div>
            </div>
            <div class='text-end'>
                <div class='fw-bold {$color}'><i class='fa-solid {$icon} me-1'></i> {$sign} Rs. {$l['amount']}</div>
                <div class='text-secondary' style='font-size:0.75rem;'>Bal: Rs. {$l['balance_after']}</div>
            </div>
          </li>";
}
echo '</ul>';