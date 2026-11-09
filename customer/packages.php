<?php require_once 'header.php'; 

$dealer_id = (int)($current_user['dealer_id'] ?? 0);

if ($dealer_id > 0) {
    // 1. Dealer's Customer: Show ONLY packages assigned to this dealer
    // Price = dealer_price + dealer_profit (what the dealer was allocated + their profit margin)
    $stmt = $pdo->prepare("
        SELECT p.*, 
               dp.dealer_price, 
               dp.dealer_profit,
               CASE 
                   WHEN (dp.dealer_price + dp.dealer_profit) > 0 THEN (dp.dealer_price + dp.dealer_profit)
                   WHEN dp.dealer_price > 0 THEN dp.dealer_price
                   ELSE p.price 
               END as effective_price
        FROM dealer_packages dp
        JOIN packages p ON dp.package_id = p.id
        WHERE dp.dealer_id = ?
        ORDER BY p.name ASC
    ");
    $stmt->execute([$dealer_id]);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($packages as &$pkg) {
        $pkg['price'] = (float)$pkg['effective_price'];
    }
    unset($pkg);
} else {
    // 2. Direct Admin Customer: Show packages made for customers (hide dealer-only packages)
    $stmt = $pdo->prepare("
        SELECT * 
        FROM packages 
        WHERE (client_id = ? OR client_id = 0)
          AND (package_target = 'customer' OR package_target = 'both' OR package_target IS NULL OR package_target = '')
          AND (package_target != 'dealer')
        ORDER BY name ASC
    ");
    $stmt->execute([$client_id]);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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
            <h2 class="text-accent my-3">Rs <?= number_format($p['price'], 0) ?></h2>
            
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
