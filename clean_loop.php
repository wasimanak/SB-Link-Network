<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// Find everything from <?php foreach($sessions as $sess): ?> up to <?php endforeach; ?>
$startStr = "<?php foreach(\$sessions as \$sess):";
$endStr = "<?php endforeach; ?>";

$startPos = strpos($c, $startStr);
$endPos = strpos($c, $endStr, $startPos);

if ($startPos !== false && $endPos !== false) {
    // Include the end tag in the replacement block length
    $length = $endPos - $startPos + strlen($endStr);
    
    $cleanLoop = <<<'EOD'
<?php foreach($sessions as $sess): 
        $start_time = is_numeric($sess['acctstarttime']) ? $sess['acctstarttime'] : strtotime($sess['acctstarttime']);
        $upTime = time() - $start_time;
        $hrs = floor($upTime / 3600);
        $mins = floor(($upTime % 3600) / 60);
        $dl = round($sess['acctoutputoctets'] / 1048576, 2) . " MB";
        $ul = round($sess['acctinputoctets'] / 1048576, 2) . " MB";
        
        // Random avatar color based on username
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'];
        $color = $colors[crc32($sess['username']) % count($colors)];
    ?>
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
    <?php endforeach; ?>
EOD;

    $c = substr_replace($c, $cleanLoop, $startPos, $length);
    file_put_contents($f, $c);
    echo "Cleaned up the loop successfully.\n";
} else {
    echo "Could not find the loop bounds.\n";
}
?>
