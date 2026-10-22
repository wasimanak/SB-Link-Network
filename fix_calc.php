<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

$pattern = '/\$total_users = .*?\$hotspot_users = \$pdo->query\("[^"]+"\)->fetchColumn\(\);/s';

$newBlock = <<<'EOD'
$total_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id")->fetchColumn();

// Base metrics for cards
// Expired: Date is strictly in the past, excluding zero-dates
$expired = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date < NOW() AND expiry_date > '2000-01-01'")->fetchColumn();
// Active: Status is not disabled, and date is in future OR is null/zero
$active_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) != 'disabled' AND (expiry_date >= NOW() OR expiry_date IS NULL OR expiry_date < '2000-01-01')")->fetchColumn();
$disabled_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) = 'disabled'")->fetchColumn();

// Online Users
$online_users = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND username IN (SELECT username FROM subscribers WHERE client_id = $client_id)")->fetchColumn();
$offline_users = max(0, $total_users - $online_users);

// Mutually Exclusive Slices for the Doughnut Chart
$chart_online = $online_users;
$chart_expired = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date < NOW() AND expiry_date > '2000-01-01' AND username NOT IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)")->fetchColumn();
$chart_disabled = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) = 'disabled' AND username NOT IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)")->fetchColumn();
$chart_active = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) != 'disabled' AND (expiry_date >= NOW() OR expiry_date IS NULL OR expiry_date < '2000-01-01') AND username NOT IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)")->fetchColumn();
$chart_others = max(0, $total_users - ($chart_online + $chart_expired + $chart_disabled + $chart_active));

// Expirations (Detailed)
$expiring_1d = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)")->fetchColumn();
$expiring_3d = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetchColumn();
$expiring_1w = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$expiring_2w = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)")->fetchColumn();

// Services
$pppoe_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(service_type) = 'pppoe'")->fetchColumn();
$hotspot_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(service_type) = 'hotspot'")->fetchColumn();
EOD;

$c = preg_replace($pattern, $newBlock, $c);

// Note: I also changed service_type check to be case-insensitive using LOWER() just in case the db has 'PPPoE' or 'HotSpot'.
// Because PPPoE users in the screenshot was 770 out of 794, so it was actually counting most of them, but maybe not all if there was case sensitivity.

file_put_contents($f, $c);
echo "Replaced calculation block.\n";
?>
