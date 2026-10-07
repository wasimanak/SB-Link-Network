<?php
$assigned_nas_ips = ['119.156.28.215'];
$in = str_repeat('?,', count($assigned_nas_ips) - 1) . '?';
echo "Query: SELECT id, nasname, shortname FROM nas WHERE id IN ($in) OR nasname IN ($in)\n";
$params = array_merge($assigned_nas_ips, $assigned_nas_ips);
print_r($params);
?>
