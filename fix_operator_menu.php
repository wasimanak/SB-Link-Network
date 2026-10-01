<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

// 1. Fix AccMenu array
$content = str_replace(
    "\$isAccMenu = in_array(\$p, ['packages.php']);",
    "\$isAccMenu = in_array(\$p, ['packages.php', 'fund_requests.php']);",
    $content
);

// 2. Fix the Payments link inside Accounting
$content = str_replace(
    '<a href="#">Payments</a>',
    '<a href="fund_requests.php" class="<?= $p===\'fund_requests.php\' ? \'active\' : \'\' ?>">Payments / Fund Requests</a>',
    $content
);

// 3. Add the Support & Requests menu if it doesn't exist
if (strpos($content, 'ticketMenu') === false || strpos($content, 'ticketMenu') === strrpos($content, 'ticketMenu')) {
    // It's only defined in PHP, not in HTML
    $support_menu = <<<'HTML'

        <!-- Requests & Support -->
        <a href="#ticketMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isTicketMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTicketMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-headset menu-icon"></i> Support & Requests
            <?php if($total_pending_requests > 0): ?>
                <span class="badge bg-danger ms-auto rounded-pill"><?= $total_pending_requests ?></span>
            <?php endif; ?>
        </a>
        <div class="collapse submenu <?= $isTicketMenu ? 'show' : '' ?>" id="ticketMenu">
            <a href="requests.php" class="<?= $p==='requests.php' ? 'active' : '' ?>">Package Requests
                <?php if($pending_pkgs > 0): ?><span class="badge bg-danger float-end rounded-pill"><?= $pending_pkgs ?></span><?php endif; ?>
            </a>
            <a href="support_tickets.php" class="<?= $p==='support_tickets.php' || $p==='view_ticket.php' ? 'active' : '' ?>">Support Tickets
                <?php if($open_tickets > 0): ?><span class="badge bg-danger float-end rounded-pill"><?= $open_tickets ?></span><?php endif; ?>
            </a>
        </div>
HTML;
    
    // Insert it after Logs & Reports
    $content = str_replace(
        '<a href="payment_gateways.php"',
        $support_menu . "\n        <a href=\"payment_gateways.php\"",
        $content
    );
}

file_put_contents($file, $content);
echo "Updated header.php successfully.\n";
?>
