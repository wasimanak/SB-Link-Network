<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

// 1. Add the query for Expired Users
$old_php = "// Fetch Today's Collection for this RM";
$new_php = <<<PHP
// Fetch Expired Users
\$expiredStmt = \$pdo->prepare("
    SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name, p.price as package_price,
           (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
           (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.client_id = ? AND s.expiry_date < NOW()
    ORDER BY s.expiry_date DESC
    LIMIT 100
");
\$expiredStmt->execute([\$client_id]);
\$expired_users = \$expiredStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Today's Collection for this RM
PHP;
$content = str_replace($old_php, $new_php, $content);

// 2. Add the HTML for Expired Users after the search results
$old_html = "            <?php endif; ?>\n        </div>\n    </div>\n</div>\n\n<!-- Collect Payment Modal -->";
$new_html = <<<HTML
            <?php endif; ?>
            
            <!-- Expired Users List -->
            <?php if(!isset(\$_GET['search_query']) || empty(trim(\$_GET['search_query']))): ?>
                <h5 class="fw-bold text-danger mt-5 border-bottom pb-2 mb-3"><i class="fa-solid fa-clock text-danger me-2"></i> Expired Users</h5>
                <?php if(\$expired_users): ?>
                    <div class="list-group border-0 mb-4">
                        <?php foreach(\$expired_users as \$u): ?>
                        <div class="list-group-item p-3 border border-danger border-opacity-25 rounded-3 mb-2 shadow-sm bg-white">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars(\$u['full_name']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars(\$u['username']) ?></span></h6>
                                    <div class="text-secondary small fw-bold"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars(\$u['package_name']) ?> (Rs. <?= number_format(\$u['package_price'], 0) ?>)</div>
                                </div>
                                <div>
                                    <?php if(\$u['live_ip']): ?>
                                        <span class="badge bg-success rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill">Expired</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row g-2 text-secondary small mb-3">
                                <div class="col-6">
                                    <i class="fa-solid fa-calendar-xmark text-danger me-1"></i> Expiry: <strong class="text-danger"><?= \$u['expiry_date'] ? date('d M Y', strtotime(\$u['expiry_date'])) : 'N/A' ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-phone text-primary me-1"></i> Phone: <strong class="text-dark"><?= htmlspecialchars(\$u['phone'] ?: 'N/A') ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-down text-success me-1"></i> Download: <strong class="text-dark"><?= formatBytes(\$u['download_bytes']??0) ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-up text-primary me-1"></i> Upload: <strong class="text-dark"><?= formatBytes(\$u['upload_bytes']??0) ?></strong>
                                </div>
                                <div class="col-12 mt-1">
                                    <i class="fa-solid fa-location-dot text-warning me-1"></i> Address: <?= htmlspecialchars(\$u['address'] ?: 'N/A') ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong class="fs-6 <?= \$u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format(\$u['balance'], 2) ?></strong>
                                </div>
                                <button class="btn btn-danger btn-sm px-4 fw-bold shadow-sm" onclick="openCollectModal('<?= \$u['username'] ?>', <?= \$u['id'] ?>, '<?= addslashes(\$u['full_name']) ?>', '<?= \$u['balance'] ?>')">
                                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect Payment
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success fw-bold shadow-sm"><i class="fa-solid fa-check-circle me-2"></i> Great! There are no expired users currently.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Collect Payment Modal -->
HTML;

$content = str_replace($old_html, $new_html, $content);

file_put_contents($file, $content);
echo "Added expired users list to Recovery Man dashboard.\n";
?>
