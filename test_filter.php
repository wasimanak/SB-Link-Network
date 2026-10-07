<?php
$_POST['assigned_routers'] = ['1', '2', ''];
$arr = isset($_POST['assigned_routers']) ? $_POST['assigned_routers'] : [];
$arr = array_filter($arr, function($v) { return trim($v) !== ''; });
$assigned_routers = implode(',', $arr);
var_dump($assigned_routers);
?>
