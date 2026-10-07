<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// 1. Replace Payment button
$c = preg_replace(
    '/<a href="subscriber_view\.php\?id=<\?= \$s\[\'id\'\] \?>" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"><\/i> Payment<\/a>/',
    '<a href="#" onclick="openPaymentModal(<?= $s[\'id\'] ?>, \'<?= addslashes(htmlspecialchars($s[\'username\'])) ?>\'); return false;" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>',
    $c
);

// 2. Replace Renew button
$c = preg_replace(
    '/<a href="subscriber_view\.php\?id=<\?= \$s\[\'id\'\] \?>" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"><\/i> Renew<\/a>/',
    '<a href="#" onclick="openRenewModal(<?= $s[\'id\'] ?>); return false;" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"></i> Renew</a>',
    $c
);

file_put_contents($f, $c);
echo "Buttons patched with regex.\n";
?>
