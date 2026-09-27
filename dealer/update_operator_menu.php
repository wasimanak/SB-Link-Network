<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

// 1. Define $isTeamMenu at the top
$isTeamMenuCode = "\$isUserMenu = in_array(\$p, ['subscribers.php', 'live_sessions.php', 'user_sessions.php']);\n\$isTeamMenu = in_array(\$p, ['dealers.php', 'dealer_view.php', 'recovery_man.php', 'line_man.php']);";
$content = str_replace("\$isUserMenu = in_array(\$p, ['subscribers.php', 'live_sessions.php', 'user_sessions.php']);", $isTeamMenuCode, $content);

// 2. Replace Team Menu HTML
$oldTeamMenu = <<<'HTML'
        <!-- Team -->
        <a href="#teamMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $p==='dealers.php' ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $p==='dealers.php' ? 'true' : 'false' ?>">
            <i class="fa-solid fa-sitemap menu-icon"></i> Team
        </a>
        <div class="collapse submenu <?= $p==='dealers.php' ? 'show' : '' ?>" id="teamMenu">
            <a href="dealers.php" class="<?= $p==='dealers.php' ? 'active' : '' ?>">Dealer</a>
            <a href="#">Add Member</a>
        </div>
HTML;

$newTeamMenu = <<<'HTML'
        <!-- Team -->
        <a href="#teamMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isTeamMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTeamMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-sitemap menu-icon"></i> Team
        </a>
        <div class="collapse submenu <?= $isTeamMenu ? 'show' : '' ?>" id="teamMenu">
            <a href="dealers.php" class="<?= $p==='dealers.php' || $p==='dealer_view.php' ? 'active' : '' ?>">Dealer</a>
            <a href="recovery_man.php" class="<?= $p==='recovery_man.php' ? 'active' : '' ?>">Recovery Man</a>
            <a href="line_man.php" class="<?= $p==='line_man.php' ? 'active' : '' ?>">Line Man</a>
        </div>
HTML;

// Handle edge case where Add Member is still there
if (strpos($content, '<a href="#">Add Member</a>') !== false) {
    $content = str_replace($oldTeamMenu, $newTeamMenu, $content);
}

// Write it back
file_put_contents($file, $content);
echo "Updated Team Menu in header.php\n";
?>
