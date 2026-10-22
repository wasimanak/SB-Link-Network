<?php
$f = 'superadmin/router_add.php';
$c = file_get_contents($f);

$warningHTML = <<<HTML
        <?php if(\$success): ?>
        <div class="alert alert-success"><?= htmlspecialchars(\$success) ?></div>
        <div class="alert alert-danger shadow-sm border-danger border-2">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> CRITICAL REQUIRED STEP:</strong><br>
            FreeRADIUS caches router IPs in its memory. It will <b>IGNORE</b> this new router and give <b>"Radius Timeout"</b> to users unless you restart the FreeRADIUS service.<br><br>
            <i>Please go to your VPS Hosting Panel and <b>Restart/Reboot</b> the server right now!</i>
        </div>
        <?php endif; ?>
HTML;

$c = str_replace('<?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>', $warningHTML, $c);

file_put_contents($f, $c);
echo "Added restart warning to router_add.php\n";
?>
