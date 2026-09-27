<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/dealer_view.php';
$content = file_get_contents($file);

// 1. Add the query for dealer_assigned_packages
$fetchAssignedPackagesCode = <<<'PHP'
// Fetch all operator packages
$pkgStmt = $pdo->prepare("SELECT id, name FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgStmt->execute([$client_id]);
$operator_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch assigned dealer packages
$assignedPkgStmt = $pdo->prepare("
    SELECT dp.dealer_price, dp.dealer_profit, p.name 
    FROM dealer_packages dp
    JOIN packages p ON dp.package_id = p.id
    WHERE dp.dealer_id = ?
    ORDER BY p.name ASC
");
$assignedPkgStmt->execute([$dealer_id]);
$dealer_assigned_packages = $assignedPkgStmt->fetchAll(PDO::FETCH_ASSOC);
PHP;

$content = preg_replace('/\/\/ Fetch all operator packages.*?\$operator_packages = \$pkgStmt->fetchAll\(PDO::FETCH_ASSOC\);/s', $fetchAssignedPackagesCode, $content);

// 2. Remove the Settings button from the accordion header
$content = preg_replace(
    '/<button class="btn btn-warning-custom" onclick="event.stopPropagation\(\);" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="fa-solid fa-gear me-1"><\/i> Settings<\/button>/', 
    '', 
    $content
);
$content = preg_replace(
    '/<button class="btn btn-warning-custom" onclick="event.stopPropagation\(\);"><i class="fa-solid fa-gear me-1"><\/i> Settings<\/button>/', 
    '', 
    $content
);

// 3. Update the packages table to show assigned packages with prices
$oldTableHtml = <<<'HTML'
                        <table class="table table-hover table-borderless w-100" id="dealerPackagesTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-bold text-secondary ps-3">Package</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($operator_packages as $p): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td class="py-3 ps-3">
                                        <i class="fa-solid fa-circle-plus text-success me-2" style="cursor:pointer; font-size: 1.1rem; vertical-align: middle;" title="Assign to Dealer"></i> 
                                        <span class="text-secondary fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($p['name']) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
HTML;

$newTableHtml = <<<'HTML'
                        <table class="table table-hover table-borderless w-100" id="dealerPackagesTable">
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
                                        <span class="text-secondary fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($ap['name']) ?></span>
                                    </td>
                                    <td class="py-3 fw-bold text-dark">Rs. <?= number_format($ap['dealer_price'], 2) ?></td>
                                    <td class="py-3 fw-bold text-success">Rs. <?= number_format($ap['dealer_profit'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
HTML;

$content = str_replace($oldTableHtml, $newTableHtml, $content);

file_put_contents($file, $content);
echo "Updated Packages functionality in dealer_view.php";
