<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// We need to match the entire <div class="col-xl-4... "> to the closing </div>
// Because regex over large HTML blocks can be tricky, I'll use a precise preg_replace.

$pattern = '/<div class="col-xl-4 col-lg-6 col-md-6 session-card-container".*?<\/div>\s*<\/div>\s*<\/div>/is';

$replacement = <<<'EOD'
<div class="col-12 session-card-container" data-username="<?= htmlspecialchars($sess['username']) ?>">
        <div class="card border-0 shadow-sm live-card mb-1" style="border-radius: 12px; transition: all 0.2s;">
            <div class="card-body p-3 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                
                <div class="d-flex align-items-center gap-3 w-100" style="max-width: 300px;">
                    <div class="text-white fw-bold d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" 
                         style="width: 50px; height: 50px; border-radius: 12px; background-color: <?= $color ?>; font-size: 1.4rem;">
                        <?= strtoupper(substr($sess['username'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="fw-bold fs-5 text-dark lh-1 mb-1"><?= htmlspecialchars($sess['username']) ?></div>
                        <div class="text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-box text-secondary me-1"></i> <?= htmlspecialchars($sess['package_name'] ?? 'Custom Package') ?></div>
                    </div>
                </div>

                <div class="d-flex flex-column w-100" style="max-width: 200px;">
                    <div class="d-flex align-items-center justify-content-between justify-content-md-start gap-md-2 mb-1">
                        <i class="fa-solid fa-server text-muted" style="width:16px;"></i>
                        <span class="font-monospace fw-bold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between justify-content-md-start gap-md-2">
                        <i class="fa-solid fa-microchip text-muted" style="width:16px;"></i>
                        <span class="font-monospace text-secondary" style="font-size: 0.8rem;"><?= htmlspecialchars($sess['callingstationid'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <div class="d-flex gap-2 w-100 justify-content-between justify-content-md-center" style="max-width: 350px;">
                    <div class="stat-box border-primary text-center px-3 py-2 w-100 rounded" style="background: #eff6ff; border: 1px solid rgba(59, 130, 246, 0.2);">
                        <div class="text-primary fw-bold" style="font-size: 0.75rem; text-transform:uppercase;"><i class="fa-regular fa-clock me-1"></i>Uptime</div>
                        <div class="text-dark fw-bold mt-1" style="font-size: 0.9rem;"><?= formatMikroTikUptime($upTime) ?></div>
                    </div>
                    <div class="stat-box border-info text-center px-3 py-2 w-100 rounded" style="background: #ecfeff; border: 1px solid rgba(6, 182, 212, 0.2);">
                        <div class="text-info fw-bold" style="font-size: 0.75rem; text-transform:uppercase;"><i class="fa-solid fa-arrow-down me-1"></i>Down</div>
                        <div class="text-dark fw-bold mt-1" style="font-size: 0.9rem;"><?= $dl ?></div>
                    </div>
                    <div class="stat-box border-warning text-center px-3 py-2 w-100 rounded" style="background: #fffbeb; border: 1px solid rgba(245, 158, 11, 0.2);">
                        <div class="text-warning fw-bold" style="font-size: 0.75rem; text-transform:uppercase;"><i class="fa-solid fa-arrow-up me-1"></i>Up</div>
                        <div class="text-dark fw-bold mt-1" style="font-size: 0.9rem;"><?= $ul ?></div>
                    </div>
                </div>

                <div class="d-flex justify-content-md-end ms-md-auto">
                    <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Are you sure you want to kick this user from the router?');" class="w-100">
                        <input type="hidden" name="action" value="kick">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                        <button class="btn btn-danger fw-bold shadow-sm w-100" style="border-radius: 8px; padding: 10px 20px;">
                            <i class="fa-solid fa-power-off me-2"></i> Kick User
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
EOD;

$c = preg_replace($pattern, $replacement, $c);

// We should also check the top styling, in case stat-box class in live_sessions.php CSS differs.
// The new layout uses inline styles mostly, so it should render beautifully.

file_put_contents($f, $c);
echo "Updated layout to 1 row per user.\n";
?>
