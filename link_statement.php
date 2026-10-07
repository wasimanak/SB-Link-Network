<?php
$f = 'operator/profile.php';
$c = file_get_contents($f);

$oldBlock = '<div class="metric-card-sm py-4">
                    <div class="metric-icon-sm bg-light-grey" style="background:#f1f5f9; color:#475569;"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data-sm">
                        <h6>Total Advance / Balance</h6>
                        <h4 class="<?= $total_advance < 0 ? \'text-danger\' : \'text-success\' ?>">Rs. <?= number_format($total_advance, 2) ?></h4>
                    </div>
                </div>';

$newBlock = '<a href="statement.php" class="text-decoration-none">
                <div class="metric-card-sm py-4" style="transition: all 0.2s; cursor: pointer;" onmouseover="this.style.transform=\'translateY(-3px)\'; this.style.boxShadow=\'0 5px 15px rgba(0,0,0,0.1)\';" onmouseout="this.style.transform=\'none\'; this.style.boxShadow=\'none\';">
                    <div class="metric-icon-sm bg-light-grey" style="background:#f1f5f9; color:#475569;"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data-sm">
                        <h6 class="text-dark">Total Advance / Balance</h6>
                        <h4 class="<?= $total_advance < 0 ? \'text-danger\' : \'text-success\' ?>">Rs. <?= number_format($total_advance, 2) ?></h4>
                        <small class="text-muted"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Statement</small>
                    </div>
                </div>
            </a>';

if (strpos($c, 'statement.php') === false) {
    $c = str_replace($oldBlock, $newBlock, $c);
    file_put_contents($f, $c);
    echo "Modified operator/profile.php to link to statement.\n";
} else {
    echo "Link already exists.\n";
}
?>
