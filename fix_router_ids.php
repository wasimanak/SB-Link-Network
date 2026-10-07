<?php
// 1. Update operator/dealers.php
$f1 = 'operator/dealers.php';
$c1 = file_get_contents($f1);

// We need to change the checkbox values from nasname to id
$c1 = preg_replace('/value="<\?= \$n\[\'nasname\'\] \?>"/i', 'value="<?= $n[\'id\'] ?>"', $c1);
// Update the HTML ID attributes to use the numeric ID for uniqueness
$c1 = preg_replace('/id="nas_add_<\?= md5\(\$n\[\'nasname\'\]\) \?>"/i', 'id="nas_add_<?= $n[\'id\'] ?>"', $c1);
$c1 = preg_replace('/for="nas_add_<\?= md5\(\$n\[\'nasname\'\]\) \?>"/i', 'for="nas_add_<?= $n[\'id\'] ?>"', $c1);
file_put_contents($f1, $c1);

// 2. Update operator/dealer_view.php
$f2 = 'operator/dealer_view.php';
$c2 = file_get_contents($f2);

$c2 = preg_replace('/value="<\?= \$n\[\'nasname\'\] \?>"/i', 'value="<?= $n[\'id\'] ?>"', $c2);
$c2 = preg_replace('/in_array\(\$n\[\'nasname\'\], \$curr_routers\)/i', 'in_array($n[\'id\'], $curr_routers)', $c2);
$c2 = preg_replace('/id="nas_edit_<\?= md5\(\$n\[\'nasname\'\]\) \?>"/i', 'id="nas_edit_<?= $n[\'id\'] ?>"', $c2);
$c2 = preg_replace('/for="nas_edit_<\?= md5\(\$n\[\'nasname\'\]\) \?>"/i', 'for="nas_edit_<?= $n[\'id\'] ?>"', $c2);
file_put_contents($f2, $c2);

// 3. Update dealer/users.php
$f3 = 'dealer/users.php';
$c3 = file_get_contents($f3);

$oldQuery = '$rStmt = $pdo->prepare("SELECT nasname, shortname FROM nas WHERE nasname IN ($in)");';
$newQuery = '$rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in)");';

if (strpos($c3, 'WHERE id IN ($in)') === false) {
    $c3 = str_replace($oldQuery, $newQuery, $c3);
    file_put_contents($f3, $c3);
}

echo "Successfully switched router assignments to use unique Database IDs instead of IPs.\n";
?>
