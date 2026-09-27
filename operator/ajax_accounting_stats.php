<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['operator_logged_in'])) {
    exit('<div class="col-12 text-danger text-center py-4">Unauthorized access</div>');
}

$client_id = $_SESSION['operator_id'];

function getStats($pdo, $client_id, $type) {
    if ($type === 'withdraw') {
        return [
            'yesterday' => 0, 'today' => 0, 'last_week' => 0, 'current_week' => 0,
            'last_month' => 0, 'current_month' => 0, 'last_year' => 0, 'current_year' => 0, 'total' => 0
        ];
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() - INTERVAL 1 DAY THEN amount ELSE 0 END), 0) as yesterday,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) as today,
            COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1) THEN amount ELSE 0 END), 0) as last_week,
            COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) THEN amount ELSE 0 END), 0) as current_week,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH) THEN amount ELSE 0 END), 0) as last_month,
            COALESCE(SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) as current_month,
            COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) - 1 THEN amount ELSE 0 END), 0) as last_year,
            COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) THEN amount ELSE 0 END), 0) as current_year,
            COALESCE(SUM(amount), 0) as total
        FROM user_ledger
        WHERE client_id = :client_id AND type = :type
    ");
    $stmt->execute(['client_id' => $client_id, 'type' => $type]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$payments = getStats($pdo, $client_id, 'credit');
$sales = getStats($pdo, $client_id, 'debit');
$withdraws = getStats($pdo, $client_id, 'withdraw');

function getPerc($val, $base) {
    if ($base == 0) return '0.00%';
    return number_format(($val / $base) * 100, 2) . '%';
}

function renderPanel($title, $data) {
    $cards = [
        ['label' => 'Yesterday', 'key' => 'yesterday', 'bg' => '#f3f4f6', 'base' => 'current_month'],
        ['label' => 'Today', 'key' => 'today', 'bg' => '#fef3c7', 'base' => 'current_month'],
        ['label' => 'Last Week', 'key' => 'last_week', 'bg' => '#f3f4f6', 'base' => 'current_month'],
        ['label' => 'Current Week', 'key' => 'current_week', 'bg' => '#fef3c7', 'base' => 'current_month'],
        ['label' => 'Last Month', 'key' => 'last_month', 'bg' => '#f3f4f6', 'base' => 'current_year'],
        ['label' => 'Current Month', 'key' => 'current_month', 'bg' => '#fef3c7', 'base' => 'current_year'],
        ['label' => 'Last Year', 'key' => 'last_year', 'bg' => '#f3f4f6', 'base' => 'total'],
        ['label' => 'Current Year', 'key' => 'current_year', 'bg' => '#fef3c7', 'base' => 'total'],
        ['label' => 'Total', 'key' => 'total', 'bg' => '#fee2e2', 'base' => 'total'],
    ];
    
    $html = '
    <div class="col-lg-4 mb-4">
        <h6 class="fw-bold text-dark mb-3 border-start border-3 border-primary ps-2">'.htmlspecialchars($title).'</h6>
        <div class="row g-2">';
        
    foreach ($cards as $c) {
        $val = $data[$c['key']] ?? 0;
        $baseVal = $data[$c['base']] ?? 0;
        
        $perc = getPerc($val, $baseVal);
        // Special case: Total doesn't show a percentage in the same way, or it shows 100%
        if ($c['key'] === 'total') $perc = '100.00%';
        
        $html .= '
            <div class="col-4">
                <div class="p-2 rounded h-100 d-flex flex-column justify-content-center" style="background-color: '.$c['bg'].';">
                    <div class="text-muted mb-1 text-nowrap" style="font-size: 0.7rem;"><i class="fa-regular fa-calendar me-1"></i> '.$c['label'].'</div>
                    <div class="d-flex align-items-baseline flex-wrap">
                        <span class="fw-bold text-dark me-1" style="font-size: 1rem;">'.number_format($val, 2).'</span>
                        <span class="text-muted text-nowrap" style="font-size: 0.6rem;">'.$perc.'</span>
                    </div>
                </div>
            </div>';
    }
        
    $html .= '
        </div>
    </div>';
    
    return $html;
}
?>

<div class="col-12">
    <div class="row">
        <?= renderPanel('Payments Statistics', $payments) ?>
        <?= renderPanel('Withdraw Statistics', $withdraws) ?>
        <?= renderPanel('Sales Statistics', $sales) ?>
    </div>
</div>
