<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
$html_start = strpos($c, '?>', strpos($c, 'if ($_SERVER[\'REQUEST_METHOD\']')) + 2;
// Find the first HTML tag after all PHP logic
$html = substr($c, $html_start);
echo substr($html, 0, 2000);
?>
