<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

$oldCheck = '<input class="form-check-input" type="checkbox" name="assigned_routers[]" value="<?= $n[\'id\'] ?>" id="nas_edit_<?= $n[\'id\'] ?>" <?= in_array($n[\'id\'], $curr_routers) ? \'checked\' : \'\' ?>>';
$newCheck = '<input class="form-check-input" type="checkbox" name="assigned_routers[]" value="<?= $n[\'id\'] ?>" id="nas_edit_<?= $n[\'id\'] ?>" <?= (in_array($n[\'id\'], $curr_routers) || in_array($n[\'nasname\'], $curr_routers)) ? \'checked\' : \'\' ?>>';

if (strpos($c, 'in_array($n[\'nasname\']') === false) {
    $c = str_replace($oldCheck, $newCheck, $c);
    file_put_contents($f, $c);
    echo "operator/dealer_view.php patched to support both ID and IP gracefully\n";
} else {
    echo "Already patched\n";
}
?>
