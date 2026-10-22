<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// Fix Today's Collection
$c = str_replace('<h6 class="mb-1 text-white-50 fw-bold small">Today\'s Collection</div>', '<h6 class="mb-1 text-white-50 fw-bold small">Today\'s Collection</h6>', $c);
$c = str_replace('<h5 class="mb-0 fw-bold">Rs. <?= number_format($today_collection) ?></h4>', '<h5 class="mb-0 fw-bold">Rs. <?= number_format($today_collection) ?></h5>', $c);

// Fix Pending Cash
$c = str_replace('<h6 class="mb-1 text-white-50 fw-bold small">Pending Cash</div>', '<h6 class="mb-1 text-white-50 fw-bold small">Pending Cash</h6>', $c);
$c = str_replace('<h5 class="mb-0 fw-bold">Rs. <?= number_format($rm_cash_in_hand) ?></h4>', '<h5 class="mb-0 fw-bold">Rs. <?= number_format($rm_cash_in_hand) ?></h5>', $c);

file_put_contents($f, $c);
echo "Fixed top cards mismatched tags.\n";
?>
