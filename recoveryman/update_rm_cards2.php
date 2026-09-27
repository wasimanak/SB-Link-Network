<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

$oldCard = '<div class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($u[\'full_name\']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u[\'username\']) ?></span></h6>
                                <div class="text-secondary small">
                                    <i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($u[\'package_name\']) ?> | 
                                    <i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($u[\'phone\'] ?: \'N/A\') ?>
                                </div>
                                <div class="text-secondary small mt-1">
                                    <i class="fa-solid fa-wallet text-success me-1"></i> Balance: <strong class="<?= $u[\'balance\'] < 0 ? \'text-danger\' : \'text-success\' ?>">Rs. <?= $u[\'balance\'] ?></strong>
                                </div>
                            </div>
                            <button class="btn btn-danger btn-sm px-3 fw-bold shadow-sm" onclick="openCollectModal(\'<?= $u[\'username\'] ?>\', <?= $u[\'id\'] ?>, \'<?= addslashes($u[\'full_name\']) ?>\', \'<?= $u[\'balance\'] ?>\')">
                                <i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect
                            </button>
                        </div>';

$newCard = '<div class="list-group-item p-3 border border-secondary border-opacity-25 rounded-3 mb-2 shadow-sm">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($u[\'full_name\']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u[\'username\']) ?></span></h6>
                                    <div class="text-secondary small fw-bold"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($u[\'package_name\']) ?></div>
                                </div>
                                <div>
                                    <?php if($u[\'live_ip\']): ?>
                                        <span class="badge bg-success rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">Offline</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row g-2 text-secondary small mb-3">
                                <div class="col-6">
                                    <i class="fa-solid fa-calendar-xmark text-danger me-1"></i> Expiry: <strong class="text-dark"><?= $u[\'expiry_date\'] ? date(\'d M Y\', strtotime($u[\'expiry_date\'])) : \'N/A\' ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-phone text-primary me-1"></i> Phone: <strong class="text-dark"><?= htmlspecialchars($u[\'phone\'] ?: \'N/A\') ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-down text-success me-1"></i> Download: <strong class="text-dark"><?= formatBytes($u[\'download_bytes\']??0) ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-up text-primary me-1"></i> Upload: <strong class="text-dark"><?= formatBytes($u[\'upload_bytes\']??0) ?></strong>
                                </div>
                                <div class="col-12 mt-1">
                                    <i class="fa-solid fa-location-dot text-warning me-1"></i> Address: <?= htmlspecialchars($u[\'address\'] ?: \'N/A\') ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong class="fs-6 <?= $u[\'balance\'] < 0 ? \'text-danger\' : \'text-success\' ?>">Rs. <?= number_format($u[\'balance\'], 2) ?></strong>
                                </div>
                                <button class="btn btn-danger btn-sm px-4 fw-bold shadow-sm" onclick="openCollectModal(\'<?= $u[\'username\'] ?>\', <?= $u[\'id\'] ?>, \'<?= addslashes($u[\'full_name\']) ?>\', \'<?= $u[\'balance\'] ?>\')">
                                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect Payment
                                </button>
                            </div>
                        </div>';

// Sometimes whitespace differs, let's just do a regex replace for the entire `foreach($search_results as $u): ... endforeach` block to be safe.
$pattern = '/<\?php foreach\(\$search_results as \$u\):\s*\?>.*?<\?php endforeach;\s*\?>/s';
$replacement = '<?php foreach($search_results as $u): ?>' . "\n" . $newCard . "\n" . '<?php endforeach; ?>';

$content = preg_replace($pattern, $replacement, $content);
file_put_contents($file, $content);
echo "Cards replaced safely.\n";
?>
