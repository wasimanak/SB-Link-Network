<?php
$f = 'customer/dashboard.php';
$c = file_get_contents($f);

$oldForm = '<form action="fund_action.php" method="POST">
            <input type="hidden" name="action" value="buy_package">';
            
$newForm = '<form action="request_action.php" method="POST">
            <input type="hidden" name="action" value="buy_package">';

if (strpos($c, 'action="request_action.php"') === false) {
    $c = str_replace($oldForm, $newForm, $c);
    file_put_contents($f, $c);
    echo "Fixed Buy Now form action.\n";
} else {
    echo "Already fixed.\n";
}
?>
