<?php
$f = 'superadmin/dashboard.php';
$c = file_get_contents($f);

// 1. Add PHP handler at the top
$phpHandler = <<<'PHP'
<?php
// Handle FreeRADIUS Restart
$restartMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restart_radius'])) {
    // Attempt to restart FreeRADIUS
    exec("sudo systemctl restart freeradius 2>&1", $outSudo, $retSudo);
    if ($retSudo === 0) {
        $restartMsg = "<div class='alert alert-success mt-2'><i class='fa-solid fa-check-circle'></i> FreeRADIUS successfully restarted! New routers are now active.</div>";
    } else {
        exec("systemctl restart freeradius 2>&1", $outNorm, $retNorm);
        if ($retNorm === 0) {
            $restartMsg = "<div class='alert alert-success mt-2'><i class='fa-solid fa-check-circle'></i> FreeRADIUS successfully restarted! New routers are now active.</div>";
        } else {
            // Failed
            $restartMsg = "<div class='alert alert-warning mt-2'><i class='fa-solid fa-triangle-exclamation'></i> <b>Restart Failed (Permission Denied):</b> Your VPS does not allow PHP to run root commands. You must restart the server manually from your Hostinger Panel.<br><i>Error log: " . htmlspecialchars(implode(" ", $outSudo)) . "</i></div>";
        }
    }
}
?>
PHP;

if (strpos($c, '// Handle FreeRADIUS Restart') === false) {
    // Insert after require_once 'header.php';
    $c = preg_replace('/require_once \'header.php\';/', "require_once 'header.php';\n" . $phpHandler, $c, 1);
}

// 2. Add the Warning & Button HTML
$htmlBlock = <<<'HTML'
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-danger border-2" style="border-radius: 12px; background-color: #fff5f5;">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="text-danger fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> FreeRADIUS Sync Required</h5>
                    <p class="text-dark mb-0">Whenever you add a <b>New Operator</b> or <b>New MikroTik Router</b>, you MUST restart FreeRADIUS. Without a restart, the new routers will be ignored (Radius Timeout) because FreeRADIUS caches IPs in memory.</p>
                </div>
                <div class="ms-4 text-end" style="min-width: 200px;">
                    <form method="POST">
                        <button type="submit" name="restart_radius" class="btn btn-danger shadow-sm px-4 py-2 fw-bold" onclick="return confirm('Are you sure you want to restart FreeRADIUS? All connections will be paused for 2 seconds.');">
                            <i class="fa-solid fa-rotate me-2"></i> Restart FreeRADIUS
                        </button>
                    </form>
                </div>
            </div>
            <?php if(!empty($restartMsg)) echo "<div class='px-4 pb-3'>" . $restartMsg . "</div>"; ?>
        </div>
    </div>
</div>
HTML;

$searchStr = '<p class="text-secondary">Overview of your complete network and tenant operations.</p>
    </div>
</div>';
if (strpos($c, 'FreeRADIUS Sync Required') === false) {
    $c = str_replace($searchStr, $searchStr . "\n\n" . $htmlBlock, $c);
}

file_put_contents($f, $c);
echo "Added warning and restart button to superadmin/dashboard.php\n";
?>
