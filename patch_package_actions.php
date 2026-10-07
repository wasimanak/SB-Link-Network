<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

// 1. Database Duplicate Fix
$dbFix = '
try {
    $pdo->exec("DELETE t1 FROM dealer_packages t1 INNER JOIN dealer_packages t2 WHERE t1.id > t2.id AND t1.dealer_id = t2.dealer_id AND t1.package_id = t2.package_id");
    $pdo->exec("ALTER TABLE dealer_packages ADD UNIQUE KEY unique_dealer_pkg (dealer_id, package_id)");
} catch(Exception $e) {}
';
if (strpos($c, 'unique_dealer_pkg') === false) {
    $c = preg_replace('/(require_once \'header.php\';)/i', "$1\n$dbFix", $c);
}

// 2. Delete POST handler
$deleteLogic = '
// Handle Delete Package
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'action\']) && $_POST[\'action\'] === \'delete_package\') {
    $pkg_id = (int)$_POST[\'package_id\'];
    try {
        $pdo->prepare("DELETE FROM dealer_packages WHERE dealer_id = ? AND package_id = ?")->execute([$dealer_id, $pkg_id]);
        echo "<script>alert(\'Package removed from dealer successfully!\'); window.location.href=\'dealer_view.php?id=$dealer_id\';</script>";
    } catch(PDOException $e) {
        echo "<script>alert(\'Error: " . addslashes($e->getMessage()) . "\');</script>";
    }
}
';
if (strpos($c, 'delete_package') === false) {
    $c = str_replace('// Handle Set New Package', $deleteLogic . "\n// Handle Set New Package", $c);
}

// 3. Update Fetch Query
$oldQuery = '$assignedPkgStmt = $pdo->prepare("
    SELECT dp.dealer_price, dp.dealer_profit, p.name 
    FROM dealer_packages dp
    JOIN packages p ON dp.package_id = p.id
    WHERE dp.dealer_id = ?
    ORDER BY p.name ASC
");';
$newQuery = '$assignedPkgStmt = $pdo->prepare("
    SELECT dp.package_id, dp.dealer_price, dp.dealer_profit, p.name 
    FROM dealer_packages dp
    JOIN packages p ON dp.package_id = p.id
    WHERE dp.dealer_id = ?
    ORDER BY p.name ASC
");';
$c = str_replace($oldQuery, $newQuery, $c);

// 4. Update Table HTML
$oldTable = '<table class="table table-hover table-borderless w-100" id="dealerPackagesTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-bold text-secondary ps-3">Package</th>
                                    <th class="fw-bold text-secondary">Dealer Price</th>
                                    <th class="fw-bold text-secondary">Dealer Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dealer_assigned_packages as $ap): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td class="py-3 ps-3">
                                        <i class="fa-solid fa-box text-success me-2" style="font-size: 1.1rem; vertical-align: middle;"></i> 
                                        <span class="text-secondary fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($ap[\'name\']) ?></span>
                                    </td>
                                    <td class="py-3 fw-bold text-dark">Rs. <?= number_format($ap[\'dealer_price\'], 2) ?></td>
                                    <td class="py-3 fw-bold text-success">Rs. <?= number_format($ap[\'dealer_profit\'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>';

$newTable = '<table class="table table-hover table-borderless w-100" id="dealerPackagesTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-bold text-secondary ps-3">Package</th>
                                    <th class="fw-bold text-secondary">Dealer Price</th>
                                    <th class="fw-bold text-secondary">Dealer Profit</th>
                                    <th class="fw-bold text-secondary text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dealer_assigned_packages as $ap): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td class="py-3 ps-3">
                                        <i class="fa-solid fa-box text-success me-2" style="font-size: 1.1rem; vertical-align: middle;"></i> 
                                        <span class="text-secondary fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($ap[\'name\']) ?></span>
                                    </td>
                                    <td class="py-3 fw-bold text-dark">Rs. <?= number_format($ap[\'dealer_price\'], 2) ?></td>
                                    <td class="py-3 fw-bold text-success">Rs. <?= number_format($ap[\'dealer_profit\'], 2) ?></td>
                                    <td class="py-3 text-end pe-3">
                                        <button class="btn btn-sm btn-light text-primary me-1" onclick="editPackage(<?= $ap[\'package_id\'] ?>, <?= $ap[\'dealer_price\'] ?>, <?= $ap[\'dealer_profit\'] ?>)" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm(\'Are you sure you want to remove this package from the dealer?\');">
                                            <input type="hidden" name="action" value="delete_package">
                                            <input type="hidden" name="package_id" value="<?= $ap[\'package_id\'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light text-danger" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>';

$c = str_replace($oldTable, $newTable, $c);

// 5. Add JS Function for Edit
$jsFunction = '
<script>
function editPackage(pkgId, price, profit) {
    $(\'#package_id\').val(pkgId).trigger(\'change\');
    $(\'input[name="dealer_price"]\').val(price);
    $(\'input[name="dealer_profit"]\').val(profit);
    $(\'#setPackageModal\').modal(\'show\');
}
</script>
';
$c = str_replace('</body>', $jsFunction . "\n</body>", $c);

file_put_contents($f, $c);
echo "operator/dealer_view.php patched to add package Edit/Delete actions.\n";
?>
