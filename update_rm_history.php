<?php
$f = 'recoveryman/history.php';
$c = file_get_contents($f);

// 1. Update the PHP queries at the top
$php_query_pattern = '/\/\/ Fetch Cash Submitted to Operator.*?\$submissions = \$submissionStmt->fetchAll\(PDO::FETCH_ASSOC\);/s';
$new_php_queries = <<<'PHP'
// Fetch Cash Submitted to Operator
$submissionStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND against_role = 'RecoveryMan' AND against_to = ? AND activity LIKE 'Collected Cash Rs.%'
    ORDER BY created_at DESC 
    LIMIT 100
");
$submissionStmt->execute([$client_id, $rm_name]);
$submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Activities (Including User Creations & Renewals)
$activityStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND by_role = 'RecoveryMan' AND by_user = ? 
    ORDER BY created_at DESC 
    LIMIT 150
");
// Ensure we use the same format saved in dashboard ('RM: ' . $rm_name or similar)
// Wait, in dashboard.php I used: 'RM: ' . $_SESSION['rm_username'] for by_user? No, I used $rm_name.
// Let's check dashboard.php: by_user is $rm_name.
$activityStmt->execute([$client_id, $rm_name]);
$activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
PHP;
$c = preg_replace($php_query_pattern, $new_php_queries, $c);

// 2. Update Tabs Navigation
$tabs_pattern = '/<ul class="nav nav-pills w-100".*?<\/ul>/s';
$new_tabs = <<<'HTML'
<ul class="nav nav-pills w-100" id="statementTabs" role="tablist">
            <li class="nav-item w-33 text-center" style="width: 33.33%;" role="presentation">
                <button class="nav-link active w-100" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" style="font-size: 0.85rem;"><i class="fa-solid fa-money-bill me-1"></i> Received</button>
            </li>
            <li class="nav-item w-33 text-center" style="width: 33.33%;" role="presentation">
                <button class="nav-link w-100" id="operator-tab" data-bs-toggle="pill" data-bs-target="#operator" type="button" role="tab" style="font-size: 0.85rem;"><i class="fa-solid fa-building-columns me-1"></i> Submitted</button>
            </li>
            <li class="nav-item w-33 text-center" style="width: 33.33%;" role="presentation">
                <button class="nav-link w-100" id="logs-tab" data-bs-toggle="pill" data-bs-target="#logs" type="button" role="tab" style="font-size: 0.85rem;"><i class="fa-solid fa-clock-rotate-left me-1"></i> Logs</button>
            </li>
        </ul>
HTML;
$c = preg_replace($tabs_pattern, $new_tabs, $c);

// 3. Inject new Tab Content (Activities)
$content_end = '<!-- Back to Dashboard -->';
$new_tab_content = <<<'HTML'
<!-- Tab 3: Full Activity Logs (Creations & Renewals) -->
        <div class="tab-pane fade" id="logs" role="tabpanel">
            <?php if(!empty($activities)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($activities as $act): ?>
                    <div class="card tx-card">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-primary bg-opacity-10 text-primary me-3">
                                <i class="fa-solid fa-bolt"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-user text-muted small me-1"></i> Target: <?= htmlspecialchars($act['against_to']) ?>
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

<!-- Back to Dashboard -->
HTML;
$c = str_replace('    </div>'."\n".'</div>'."\n\n".'<!-- Back to Dashboard -->', $new_tab_content, $c);

file_put_contents($f, $c);
echo "Injected Logs Tab.\n";
?>
