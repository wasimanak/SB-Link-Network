<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Add PHP to fetch cash_in_hand
$phpInjection = '
// Fetch RM Cash in Hand
$cashStmt = $pdo->prepare("SELECT cash_in_hand FROM recovery_men WHERE id = ?");
$cashStmt->execute([$rm_id]);
$rm_cash_in_hand = $cashStmt->fetchColumn() ?: 0;
';

if (strpos($c, '$rm_cash_in_hand =') === false) {
    $c = preg_replace('/\$today_collection = \$collStmt->fetchColumn\(\) \?: 0;/', '$today_collection = $collStmt->fetchColumn() ?: 0;' . "\n" . $phpInjection, $c);
}

// 2. Change the HTML to show 2 cards instead of 1
$newHtml = '<!-- Collection & Balance Stats -->
        <div class="col-6 mb-3">
            <div class="card card-custom bg-danger text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">
                    <h6 class="mb-1 text-white-50 fw-bold small">Today\'s Collection</h6>
                    <h4 class="mb-0 fw-bold">Rs. <?= number_format($today_collection) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-6 mb-3">
            <div class="card card-custom bg-success text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">
                    <h6 class="mb-1 text-white-50 fw-bold small">Pending Cash</h6>
                    <h4 class="mb-0 fw-bold">Rs. <?= number_format($rm_cash_in_hand) ?></h4>
                </div>
            </div>
        </div>';

if (strpos($c, 'Pending Cash') === false) {
    $c = preg_replace('/<!-- Collection Stat -->.*?<\/div>\s*<\/div>\s*<\/div>/s', $newHtml, $c);
    file_put_contents($f, $c);
    echo "Added Cash in Hand to recoveryman/dashboard.php successfully!\n";
} else {
    echo "Already updated!\n";
}
?>
