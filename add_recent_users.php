<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// Find where to inject the "Recently Created Users" section.
// A good place is right after the Search Results (or if no search is active, below the search box).
$inject_marker = '<!-- Metrics (Mobile/Desktop) -->';
$pos_inject = strpos($c, $inject_marker);

if ($pos_inject !== false) {
    $new_section = <<<'HTML'
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

<?php if (empty($search_results) && !empty($recent_creations)): ?>
<div class="col-md-12 mb-4">
    <h6 class="fw-bold text-secondary mb-3"><i class="fa-solid fa-clock-rotate-left me-2"></i> Recently Created by You</h6>
    <div class="row g-3">
        <?php foreach($recent_creations as $rc): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="background: #f8fafc;">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-primary rounded-pill"><i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($rc['username']) ?></span>
                        <span class="small text-muted" style="font-size: 0.75rem;"><?= date('d M, h:i A', strtotime($rc['created_at'])) ?></span>
                    </div>
                    <div class="small text-secondary" style="font-size: 0.8rem; line-height: 1.2;">
                        <?= htmlspecialchars($rc['activity']) ?>
                    </div>
                    <div class="mt-2 text-end">
                        <a href="dashboard.php?search_query=<?= urlencode($rc['username']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1" style="font-size: 0.75rem fw-bold;">Manage User</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Metrics (Mobile/Desktop) -->
HTML;

    $c = substr_replace($c, $new_section, $pos_inject, strlen($inject_marker));
    file_put_contents($f, $c);
    echo "Added 'Recently Created' section to RM Dashboard.\n";
} else {
    echo "Could not find inject marker.\n";
}
?>
