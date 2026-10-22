<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// We need to replace my previously injected broken layout with a stable Bootstrap Grid layout.
$pattern = '/<div class="col-12 session-card-container".*?<\/div>\s*<\/div>\s*<\/div>/is';

$replacement = <<<'EOD'
<div class="col-12 session-card-container mb-2" data-username="<?= htmlspecialchars($sess['username']) ?>">
            <div class="card border-0 shadow-sm live-card" style="border-radius: 8px;">
                <div class="card-body p-2 p-md-3">
                    <div class="row align-items-center g-3">
                        <!-- User Info -->
                        <div class="col-12 col-xl-4 col-md-5 d-flex align-items-center gap-3">
                            <div class="text-white fw-bold d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width: 45px; height: 45px; border-radius: 10px; background-color: <?= $color ?>; font-size: 1.2rem;">
                                <?= strtoupper(substr($sess['username'], 0, 1)) ?>
                            </div>
                            <div style="min-width: 0;">
                                <div class="fw-bold text-dark lh-1 mb-1 text-truncate" style="font-size: 1.1rem;"><?= htmlspecialchars($sess['username']) ?></div>
                                <div class="text-muted small text-truncate"><i class="fa-solid fa-box text-secondary"></i> <?= htmlspecialchars($sess['package_name'] ?? 'Custom Package') ?></div>
                            </div>
                        </div>

                        <!-- IP & MAC -->
                        <div class="col-12 col-xl-2 col-md-3">
                            <div class="text-dark fw-bold font-monospace small mb-1"><i class="fa-solid fa-server text-muted me-1"></i><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?></div>
                            <div class="text-secondary font-monospace small"><i class="fa-solid fa-microchip text-muted me-1"></i><?= htmlspecialchars($sess['callingstationid'] ?? 'N/A') ?></div>
                        </div>

                        <!-- Stats -->
                        <div class="col-12 col-xl-4 col-md-4">
                            <div class="row g-2 text-center">
                                <div class="col-4">
                                    <div class="p-1 rounded h-100 d-flex flex-column justify-content-center" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                                        <div class="text-primary fw-bold" style="font-size:0.65rem; letter-spacing:0.5px;">UPTIME</div>
                                        <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= formatMikroTikUptime($upTime) ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="p-1 rounded h-100 d-flex flex-column justify-content-center" style="background: #ecfeff; border: 1px solid #a5f3fc;">
                                        <div class="text-info fw-bold" style="font-size:0.65rem; letter-spacing:0.5px;">DOWN</div>
                                        <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= $dl ?></div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="p-1 rounded h-100 d-flex flex-column justify-content-center" style="background: #fffbeb; border: 1px solid #fde68a;">
                                        <div class="text-warning fw-bold" style="font-size:0.65rem; letter-spacing:0.5px;">UP</div>
                                        <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= $ul ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action -->
                        <div class="col-12 col-xl-2 col-md-12 text-xl-end text-center mt-3 mt-xl-0">
                            <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Are you sure you want to kick this user?');">
                                <input type="hidden" name="action" value="kick">
                                <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                                <button class="btn btn-danger btn-sm fw-bold shadow-sm w-100 py-2"><i class="fa-solid fa-power-off me-1"></i> Kick</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
EOD;

$c = preg_replace($pattern, $replacement, $c);
file_put_contents($f, $c);
echo "Fixed card layout.\n";
?>
