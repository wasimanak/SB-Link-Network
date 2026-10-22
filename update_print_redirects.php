<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Update collect_only redirect
$find_collect = "echo \"<script>alert('Payment Collected Successfully!'); window.location='dashboard.php';</script>\";";
$replace_collect = <<<'PHP'
echo "<script>
    alert('Payment Collected Successfully!');
    var pw = window.open('print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=collect', '_blank');
    if(pw){ window.location='dashboard.php'; } else { window.location='print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=collect'; }
</script>";
PHP;
$c = str_replace($find_collect, $replace_collect, $c);

// 2. Update receive_payment redirect
$find_renew = "echo \"<script>alert('\$alert_msg'); window.location='dashboard.php?search_query=\" . urlencode(\$_GET['search_query']??'') . \"';</script>\";";
$replace_renew = <<<'PHP'
$sq = urlencode($_GET['search_query']??'');
echo "<script>
    alert('$alert_msg');
    var pw = window.open('print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=renew', '_blank');
    if(pw){ window.location='dashboard.php?search_query=$sq'; } else { window.location='print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=renew'; }
</script>";
PHP;
$c = str_replace($find_renew, $replace_renew, $c);

file_put_contents($f, $c);
echo "Updated Dashboard Redirects.\n";
?>
