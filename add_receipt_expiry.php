<?php
$f = 'recoveryman/print_receipt.php';
$c = file_get_contents($f);

// 1. Update SQL query to include expiry_date
$c = str_replace(
    'SELECT s.username, s.full_name, s.balance, p.name as package_name, p.price as package_price',
    'SELECT s.username, s.full_name, s.balance, s.expiry_date, p.name as package_name, p.price as package_price',
    $c
);

// 2. Add Expiry Date to the receipt HTML
$find_html = <<<'HTML'
        <?php if($sub['package_name']): ?>
        <tr>
            <td>Package:</td>
            <td class="text-right"><?= htmlspecialchars($sub['package_name']) ?></td>
        </tr>
        <?php endif; ?>
HTML;

$replace_html = <<<'HTML'
        <?php if($sub['package_name']): ?>
        <tr>
            <td>Package:</td>
            <td class="text-right"><?= htmlspecialchars($sub['package_name']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if(!empty($sub['expiry_date'])): ?>
        <tr>
            <td>Expiry:</td>
            <td class="text-right"><?= date('d M Y', strtotime($sub['expiry_date'])) ?></td>
        </tr>
        <?php endif; ?>
HTML;

$c = str_replace($find_html, $replace_html, $c);

file_put_contents($f, $c);
echo "Added Expiry Date to receipt.\n";
?>
