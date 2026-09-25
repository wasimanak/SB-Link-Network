<?php require_once 'header.php'; 

// Fetch available packages for this operator
$stmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$stmt->execute([$client_id]);
$packages = $stmt->fetchAll();

// Check if request pending
$reqStmt = $pdo->prepare("SELECT COUNT(*) FROM package_requests WHERE subscriber_id = ? AND status = 'pending'");
$reqStmt->execute([$current_user['id']]);
$has_pending = $reqStmt->fetchColumn() > 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="fa-solid fa-box-open text-accent"></i> Available Packages</h4>
</div>

<?php if ($has_pending): ?>
    <div class="alert alert-warning border-warning bg-transparent text-warning">
        <i class="fa-solid fa-triangle-exclamation"></i> You already have a pending package request. Please wait for operator approval before making another request.
    </div>
<?php endif; ?>

<div class="row">
    <?php foreach($packages as $p): ?>
    <div class="col-lg-4 col-md-6 mb-4">
        <div class="card-ui p-4 text-center h-100 d-flex flex-column border-<?= $current_user['package_id'] == $p['id'] ? 'primary' : 'secondary' ?>">
            <?php if($current_user['package_id'] == $p['id']): ?>
                <div class="badge bg-primary position-absolute top-0 end-0 mt-3 me-3">Current Plan</div>
            <?php endif; ?>
            <h4 class="fw-bold mt-3"><?= htmlspecialchars($p['name']) ?></h4>
            <h2 class="text-accent my-3">Rs <?= number_format($p['price'], 2) ?></h2>
            
            <ul class="list-unstyled text-secondary my-4">
                <li class="mb-2"><i class="fa-solid fa-gauge me-2"></i> <?= htmlspecialchars($p['rate_limit'] ?: 'Unlimited Speed') ?></li>
                <li class="mb-2"><i class="fa-regular fa-calendar me-2"></i> <?= htmlspecialchars($p['validity_days']) ?> Days Validity</li>
                <li><i class="fa-solid fa-infinity me-2"></i> Unlimited Data volume</li>
            </ul>
            
            <div class="mt-auto pt-3 border-top border-secondary">
                <?php if ($has_pending): ?>
                    <button class="btn btn-secondary w-100" disabled>Request Pending</button>
                <?php else: ?>
                    <form action="request_action.php" method="POST" onsubmit="return confirm('Are you sure you want to request this package?');">
                        <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-accent w-100">
                            <?= $current_user['package_id'] == $p['id'] ? 'Request Renewal' : 'Request Upgrade' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once 'footer.php'; ?>
