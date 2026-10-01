<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

// 1. Update the isAccMenu array to include invoices.php
$content = str_replace(
    "\$isAccMenu = in_array(\$p, ['packages.php', 'fund_requests.php']);",
    "\$isAccMenu = in_array(\$p, ['packages.php', 'fund_requests.php', 'invoices.php']);",
    $content
);

// 2. Update the Invoices link
$content = str_replace(
    '<a href="#">Invoices</a>',
    '<a href="invoices.php" class="<?= $p===\'invoices.php\' ? \'active\' : \'\' ?>">Invoices / Receipts</a>',
    $content
);

file_put_contents($file, $content);
echo "Updated header.php to link invoices.php.\n";
?>
