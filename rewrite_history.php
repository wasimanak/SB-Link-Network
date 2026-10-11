<?php
$f = 'recoveryman/history.php';
$c = file_get_contents($f);

// Split at <!DOCTYPE html>
$parts = explode('<!DOCTYPE html>', $c, 2);
$php_logic = $parts[0];

$new_html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>My History - Recovery Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: linear-gradient(135deg, #1e293b, #0f172a); color: white; padding: 18px 20px; border-bottom: 4px solid #ef4444; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .icon-circle { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .custom-tabs-container { background: white; padding: 6px; border-radius: 50px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .nav-pills .nav-link { color: #64748b; font-weight: 600; border-radius: 50px; padding: 12px 0; transition: all 0.3s ease; border: none; }
        .nav-pills .nav-link:hover { color: #0f172a; }
        .nav-pills .nav-link.active { background: #ef4444; color: white; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3); }
        .tx-card { border: none; border-radius: 16px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s ease, box-shadow 0.2s ease; margin-bottom: 15px; }
        .tx-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    </style>
</head>
<body>

<div class="top-navbar sticky-top">
    <div class="d-flex align-items-center">
        <a href="dashboard.php" class="text-white text-decoration-none me-3 fs-4 transition-transform hover-scale"><i class="fa-solid fa-arrow-left"></i></a>
        <h4 class="mb-0 fw-bold tracking-wide"><i class="fa-solid fa-clock-rotate-left text-danger me-2"></i> My History</h4>
    </div>
</div>

<div class="container mt-4 pb-5">
    
    <!-- Tab Navigation -->
    <div class="custom-tabs-container mb-4 mx-auto" style="max-width: 500px;">
        <ul class="nav nav-pills w-100" id="statementTabs" role="tablist">
            <li class="nav-item text-center" style="width: 25%;" role="presentation">
                <button class="nav-link active w-100" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-money-bill d-block mb-1 fs-5"></i> Received</button>
            </li>
            <li class="nav-item text-center" style="width: 25%;" role="presentation">
                <button class="nav-link w-100" id="operator-tab" data-bs-toggle="pill" data-bs-target="#operator" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-building-columns d-block mb-1 fs-5"></i> Submitted</button>
            </li>
            <li class="nav-item text-center" style="width: 25%;" role="presentation">
                <button class="nav-link w-100" id="created-tab" data-bs-toggle="pill" data-bs-target="#created" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-user-plus d-block mb-1 fs-5"></i> Created</button>
            </li>
            <li class="nav-item text-center" style="width: 25%;" role="presentation">
                <button class="nav-link w-100" id="logs-tab" data-bs-toggle="pill" data-bs-target="#logs" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-list d-block mb-1 fs-5"></i> Logs</button>
            </li>
        </ul>
    </div>

    <!-- Tab Content -->
    <div class="tab-content" id="statementTabsContent">
        
        <!-- Tab 1: User Collections (Received) -->
        <div class="tab-pane fade show active" id="users" role="tabpanel">
            <?php if(!empty($user_collections)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($user_collections as $uc): ?>
                    <div class="card tx-card border-start border-4 border-success">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-success bg-opacity-10 text-success me-3">
                                <i class="fa-solid fa-arrow-down"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-user text-muted small me-1"></i> <?= htmlspecialchars($uc['username']) ?>
                                </h6>
                                <div class="text-muted small text-truncate mb-1"><?= htmlspecialchars($uc['description']) ?></div>
                                <div class="text-secondary small fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($uc['created_at'])) ?></div>
                            </div>
                            <div class="text-end ms-2">
                                <h5 class="fw-bold text-success mb-0">+ Rs. <br class="d-md-none"><span class="fs-4"><?= number_format($uc['amount']) ?></span></h5>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5 mt-4">
                    <i class="fa-solid fa-receipt fs-1 mb-3 opacity-25"></i>
                    <h6>No Collections Found</h6>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 2: Submitted to Operator -->
        <div class="tab-pane fade" id="operator" role="tabpanel">
            <?php if(!empty($submissions)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($submissions as $sub): 
                    preg_match('/Rs\. (\d+)/', $sub['activity'], $matches);
                    $amount_text = isset($matches[1]) ? number_format($matches[1]) : '0';
                ?>
                    <div class="card tx-card border-start border-4 border-danger">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-danger bg-opacity-10 text-danger me-3">
                                <i class="fa-solid fa-arrow-up"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-building-columns text-muted small me-1"></i> Cash Submitted
                                </h6>
                                <div class="text-muted small text-truncate mb-1"><i class="fa-solid fa-user-shield me-1"></i> Received by <?= htmlspecialchars($sub['by_user']) ?></div>
                                <div class="text-secondary small fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($sub['created_at'])) ?></div>
                            </div>
                            <div class="text-end ms-2">
                                <h5 class="fw-bold text-danger mb-0">- Rs. <br class="d-md-none"><span class="fs-4"><?= $amount_text ?></span></h5>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5 mt-4">
                    <i class="fa-solid fa-box-open fs-1 mb-3 opacity-25"></i>
                    <h6>No Submissions Yet</h6>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 3: Created Users -->
        <div class="tab-pane fade" id="created" role="tabpanel">
            <?php if(!empty($created_users)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($created_users as $cu): ?>
                    <div class="card tx-card border-start border-4 border-primary">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-primary bg-opacity-10 text-primary me-3">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-user text-muted small me-1"></i> <?= htmlspecialchars($cu['against_to']) ?>
                                </h6>
                                <div class="text-muted small mb-1" style="white-space: normal; line-height: 1.3;"><?= htmlspecialchars($cu['activity']) ?></div>
                                <div class="text-secondary small fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($cu['created_at'])) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5 mt-4">
                    <i class="fa-solid fa-user-slash fs-1 mb-3 opacity-25"></i>
                    <h6>No Created Users Found</h6>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 4: Logs (All Activities) -->
        <div class="tab-pane fade" id="logs" role="tabpanel">
            <?php if(!empty($activities)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($activities as $act): ?>
                    <div class="card tx-card border-start border-4 border-warning">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-warning bg-opacity-10 text-warning me-3">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-circle-info text-muted small me-1"></i> Target: <?= htmlspecialchars($act['against_to']) ?>
                                </h6>
                                <div class="text-muted small mb-1" style="white-space: normal; line-height: 1.3;"><?= htmlspecialchars($act['activity']) ?></div>
                                <div class="text-secondary small fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($act['created_at'])) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5 mt-4">
                    <i class="fa-solid fa-receipt fs-1 mb-3 opacity-25"></i>
                    <h6>No Activity Logs Found</h6>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;

file_put_contents($f, $php_logic . $new_html);
echo "Rewrote History HTML completely.\n";
?>
