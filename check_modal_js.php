<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
if (strpos($c, 'id="singlePaymentModal"') !== false) {
    echo "singlePaymentModal EXISTS in file.\n";
} else {
    echo "singlePaymentModal IS MISSING.\n";
}

if (strpos($c, 'function openPaymentModal') !== false) {
    echo "JS Function EXISTS in file.\n";
} else {
    echo "JS Function IS MISSING.\n";
}

// Check the buttons
preg_match_all('/<a href="[^"]*".*?Payment<\/a>/is', $c, $m);
echo "Buttons found:\n";
print_r($m[0]);
?>
