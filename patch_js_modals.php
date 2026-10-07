<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

$oldJs = 'function openPaymentModal(userId, username) {
    $(\'#single_payment_sub_id\').val(userId);
    $(\'#singlePaymentModalTitle\').html(\'<i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment for \' + username);
    $(\'#single_payment_amount\').val(\'\');
    $(\'#singlePaymentModal\').modal(\'show\');
}

function openRenewModal(userId) {
    $(\'#renew_user_id\').val(userId).trigger(\'change\');
    $(\'#renewUserModal\').modal(\'show\');
}';

$newJs = 'function openPaymentModal(userId, username) {
    $(\'#single_payment_sub_id\').val(userId);
    $(\'#singlePaymentModalTitle\').html(\'<i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment for \' + username);
    $(\'#single_payment_amount\').val(\'\');
    var m = bootstrap.Modal.getOrCreateInstance(document.getElementById(\'singlePaymentModal\'));
    m.show();
}

function openRenewModal(userId) {
    $(\'#renew_user_id\').val(userId).trigger(\'change\');
    var m = bootstrap.Modal.getOrCreateInstance(document.getElementById(\'renewUserModal\'));
    m.show();
}';

if (strpos($c, '$(\'#singlePaymentModal\').modal(\'show\')') !== false) {
    $c = str_replace($oldJs, $newJs, $c);
    file_put_contents($f, $c);
    echo "JavaScript patched successfully to use Bootstrap 5 native JS.\n";
} else {
    echo "Old JS not found.\n";
}
?>
