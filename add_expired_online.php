<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// 1. Calculate Expired Online metric
$onlinePattern = '/\$online_users = \$pdo->query\([^)]+\)->fetchColumn\(\);/s';
if (preg_match($onlinePattern, $c, $matches)) {
    $expiredOnlineCalc = "\n// Expired Online Users\n\$expired_online_users = \$pdo->query(\"SELECT COUNT(DISTINCT r.username) FROM radacct r JOIN subscribers s ON r.username = s.username WHERE r.acctstoptime IS NULL AND s.client_id = \$client_id AND s.expiry_date < CURDATE()\")->fetchColumn();\n";
    $c = str_replace($matches[0], $matches[0] . $expiredOnlineCalc, $c);
}

// 2. Update the Expired Online stat card HTML and link
$cardPattern = '/<div class="stat-box bg-yellow cursor-pointer" onclick="window\.location\.href=\'live_sessions\.php\'">\s*<div class="title"><i class="fa-solid fa-user-clock"><\/i> Expired Online<\/div>\s*<div class="value">0 <span class="pct">0\.00%<\/span><\/div>\s*<\/div>/is';

$newCard = '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'live_sessions.php?filter=expired_online\'" title="View users who are online but their package has expired">
                    <div class="title"><i class="fa-solid fa-user-clock"></i> Expired Online</div>
                    <div class="value"><?= number_format($expired_online_users) ?> <span class="pct"><?= $pct($expired_online_users) ?></span></div>
                </div>';

$c = preg_replace($cardPattern, $newCard, $c);

// Also try fallback replacement if the exact HTML differs slightly
if (strpos($c, '<?= number_format($expired_online_users) ?>') === false) {
    $fallbackPattern = '/(<div class="stat-box bg-yellow cursor-pointer"[^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-clock"><\/i> Expired Online<\/div>)\s*<div class="value">0 <span class="pct">0\.00%<\/span><\/div>/is';
    $fallbackReplacement = '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'live_sessions.php?filter=expired_online\'">'."\n".'                    $2'."\n".'                    <div class="value"><?= number_format($expired_online_users) ?> <span class="pct"><?= $pct($expired_online_users) ?></span></div>';
    $c = preg_replace($fallbackPattern, $fallbackReplacement, $c);
}

file_put_contents($f, $c);
echo "Updated operator/dashboard.php for expired online metrics.\n";

$f2 = 'operator/live_sessions.php';
$c2 = file_get_contents($f2);

// Add filter to live_sessions.php
$sqlTarget = 'WHERE s.client_id = ?
        ORDER BY r.acctstarttime DESC';
        
$sqlReplacement = 'WHERE s.client_id = ?" . (isset($_GET[\'filter\']) && $_GET[\'filter\'] === \'expired_online\' ? " AND s.expiry_date < CURDATE()" : "") . "
        ORDER BY r.acctstarttime DESC';
        
if (strpos($c2, '$_GET[\'filter\']') === false) {
    // In live_sessions.php it's $sql = "SELECT ... WHERE s.client_id = ? ORDER BY r.acctstarttime DESC";
    $c2 = str_replace($sqlTarget, $sqlReplacement, $c2);
    
    // Update the UI Header if filter is applied
    $headerTarget = '<h4 class="fw-bold mb-1"><i class="fa-solid fa-satellite-dish text-primary me-2"></i>Live Sessions</h4>';
    $headerReplacement = '<h4 class="fw-bold mb-1"><i class="fa-solid fa-satellite-dish text-primary me-2"></i><?= isset($_GET[\'filter\']) && $_GET[\'filter\'] === \'expired_online\' ? \'<span class="text-danger">Expired</span> Live Sessions\' : \'Live Sessions\' ?></h4>';
    $c2 = str_replace($headerTarget, $headerReplacement, $c2);
    
    file_put_contents($f2, $c2);
    echo "Added filter logic to operator/live_sessions.php\n";
} else {
    echo "Filter logic already in live_sessions.php\n";
}
?>
