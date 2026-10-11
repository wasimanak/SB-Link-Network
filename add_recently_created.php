<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

$search_results_marker = '<!-- Search Results -->';

$recently_created_block = <<<'HTML'
<?php
// Fetch recently created users by this RM from activity_logs
$recentStmt = $pdo->prepare("
    SELECT against_to as username, created_at, activity 
    FROM activity_logs 
    WHERE client_id = ? AND by_role = 'RecoveryMan' AND by_user = ? AND activity LIKE 'Created New User%' 
    ORDER BY id DESC LIMIT 5
");
$recentStmt->execute([$client_id, $rm_name]);
$recent_creations = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if (empty($_GET['search_query']) && !empty($recent_creations)): ?>
<div class="mb-4">
    <h6 class="fw-bold text-secondary mb-3"><i class="fa-solid fa-clock-rotate-left me-2"></i> Created by You (Recently)</h6>
    <div class="row g-3">
        <?php foreach($recent_creations as $rc): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="background: #ffffff; border-left: 4px solid #0d6efd !important;">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-primary rounded-pill px-3 py-2"><i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($rc['username']) ?></span>
                        <span class="small text-muted fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock"></i> <?= date('d M, h:i A', strtotime($rc['created_at'])) ?></span>
                    </div>
                    <div class="small text-secondary mb-3" style="font-size: 0.8rem; line-height: 1.4;">
                        <?= htmlspecialchars($rc['activity']) ?>
                    </div>
                    <div class="text-end">
                        <a href="dashboard.php?search_query=<?= urlencode($rc['username']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-4 fw-bold">Manage</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Search Results -->
HTML;

$c = str_replace($search_results_marker, $recently_created_block, $c);

file_put_contents($f, $c);
echo "Injected Recently Created list.\n";
?>
