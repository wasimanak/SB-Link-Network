<?php
$file = 'C:/xampp/htdocs/SB Link Network/dealer/header.php';
$content = file_get_contents($file);

$oldCode = <<<'PHP'
// Global Gateway Config for Recharge
$stmt = $pdo->prepare("SELECT gateway_display_name, gateway_account_name FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_conf = $stmt->fetch();
$gateway_display_name = $client_conf['gateway_display_name'] ?? 'Bank Account';
$gateway_account_name = $client_conf['gateway_account_name'] ?? 'Account Holder';
PHP;

$newCode = <<<'PHP'
// Global Gateway Config for Recharge
$gwStmt = $pdo->prepare("SELECT gateway_name, account_name FROM payment_gateways WHERE client_id = ? AND status = 'active' LIMIT 1");
$gwStmt->execute([$client_id]);
$active_gateway = $gwStmt->fetch();

$opStmt = $pdo->prepare("SELECT company_name FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator_info = $opStmt->fetch();

$gateway_display_name = $active_gateway ? $active_gateway['gateway_name'] : 'Meezan Bank';
$gateway_account_name = ($active_gateway && !empty($active_gateway['account_name'])) ? $active_gateway['account_name'] : ($operator_info['company_name'] ?: 'SB-Link Network');
PHP;

$content = str_replace($oldCode, $newCode, $content);
file_put_contents($file, $content);
echo "Fixed dealer/header.php database columns error.\n";
?>
