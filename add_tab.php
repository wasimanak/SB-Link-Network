<?php
$f = 'recoveryman/history.php';
$c = file_get_contents($f);

// 1. Add PHP Query
$new_query = <<<'PHP'
// Fetch Users Created by this RM
$createdUsersStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND by_role = 'RecoveryMan' AND by_user = ? AND activity LIKE 'Created New User%'
    ORDER BY created_at DESC 
    LIMIT 100
");
$createdUsersStmt->execute([$client_id, $rm_name]);
$created_users = $createdUsersStmt->fetchAll(PDO::FETCH_ASSOC);

?>
PHP;
$c = str_replace('?>', $new_query, $c);

// 2. Update Tabs
$tabs_pattern = '/<ul class="nav nav-pills w-100" id="statementTabs" role="tablist">.*?<\/ul>/s';
$new_tabs = <<<'HTML'
<ul class="nav nav-pills w-100" id="statementTabs" role="tablist">
            <li class="nav-item w-25 text-center" style="width: 25%;" role="presentation">
                <button class="nav-link active w-100" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-money-bill d-block mb-1 fs-5"></i> Received</button>
            </li>
            <li class="nav-item w-25 text-center" style="width: 25%;" role="presentation">
                <button class="nav-link w-100" id="operator-tab" data-bs-toggle="pill" data-bs-target="#operator" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-building-columns d-block mb-1 fs-5"></i> Submitted</button>
            </li>
            <li class="nav-item w-25 text-center" style="width: 25%;" role="presentation">
                <button class="nav-link w-100" id="created-tab" data-bs-toggle="pill" data-bs-target="#created" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-user-plus d-block mb-1 fs-5"></i> Created</button>
            </li>
            <li class="nav-item w-25 text-center" style="width: 25%;" role="presentation">
                <button class="nav-link w-100" id="logs-tab" data-bs-toggle="pill" data-bs-target="#logs" type="button" role="tab" style="font-size: 0.75rem;"><i class="fa-solid fa-list d-block mb-1 fs-5"></i> Logs</button>
            </li>
        </ul>
HTML;
$c = preg_replace($tabs_pattern, $new_tabs, $c);

// 3. Inject new Content Pane
$content_pane = <<<'HTML'
<!-- Tab 4: Created Users -->
        <div class="tab-pane fade" id="created" role="tabpanel">
            <?php if(!empty($created_users)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($created_users as $cu): ?>
                    <div class="card tx-card border-start border-4 border-success">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-success bg-opacity-10 text-success me-3">
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

    </div>
</div>
HTML;
$c = str_replace('    </div>'."\n".'</div>', $content_pane, $c);

file_put_contents($f, $c);
echo "Added 4th tab.\n";
?>
