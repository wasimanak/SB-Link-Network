<?php
$f = 'recoveryman/print_receipt.php';
$c = file_get_contents($f);

// 1. Add PHP logic for Invoice ID
$php_inject = <<<'PHP'
$company_name = $operator['company_name'] ?? 'ISP Provider';
$company_phone = $operator['phone'] ?? 'N/A';
$date = date('d M Y h:i A');

// Fetch Latest Transaction ID for Invoice#
$invStmt = $pdo->prepare("SELECT id FROM user_ledger WHERE username = ? AND client_id = ? ORDER BY id DESC LIMIT 1");
$invStmt->execute([$sub['username'], $client_id]);
$invoice_id = $invStmt->fetchColumn() ?: rand(10000, 99999);
$invoice_number = "INV-" . str_pad($invoice_id, 6, "0", STR_PAD_LEFT);
PHP;

$c = preg_replace('/\$company_name = .*?\$date = date\(\'d M Y h:i A\'\);/s', $php_inject, $c);

// 2. Also inject the invoice number into the WhatsApp text receipt
$c = str_replace(
    '  $wa_text .= "Date: {$date}\n";',
    '  $wa_text .= "Invoice: {$invoice_number}\n";
  $wa_text .= "Date: {$date}\n";',
    $c
);

// 3. Inject Invoice Number in HTML
$html_inject = <<<'HTML'
    <table class="mb-2 border-bottom">
        <tr>
            <td style="width: 35%;">Date:</td>
            <td class="text-right"><?= $date ?></td>
        </tr>
        <tr>
            <td>Invoice#:</td>
            <td class="text-right fw-bold"><?= $invoice_number ?></td>
        </tr>
HTML;

$c = str_replace(
    '<table class="mb-2 border-bottom">
        <tr>
            <td style="width: 35%;">Date:</td>
            <td class="text-right"><?= $date ?></td>
        </tr>',
    $html_inject,
    $c
);

file_put_contents($f, $c);
echo "Added Invoice Number to receipt.\n";
?>
