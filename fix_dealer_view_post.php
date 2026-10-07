<?php
$f2 = 'operator/dealer_view.php';
$c2 = file_get_contents($f2);

$badPattern = '/\$assigned_routers\s*=\s*isset\(\$_POST\[\'assigned_routers\'\]\)\s*\?\s*implode\(\',\',\s*\$_POST\[\'assigned_routers\'\]\)\s*:\s*\'\';/';

$goodCode = '$arr = isset($_POST[\'assigned_routers\']) ? $_POST[\'assigned_routers\'] : [];
      $arr = array_filter($arr, function($v) { return trim($v) !== \'\'; });
      $assigned_routers = implode(\',\', $arr);';

$c2 = preg_replace($badPattern, $goodCode, $c2);
file_put_contents($f2, $c2);
echo "operator/dealer_view.php POST logic patched.\n";

$f1 = 'operator/dealers.php';
$c1 = file_get_contents($f1);
$c1 = preg_replace($badPattern, $goodCode, $c1);
file_put_contents($f1, $c1);
echo "operator/dealers.php POST logic patched.\n";
?>
