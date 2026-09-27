<?php
$content = file_get_contents('dashboard.php');
$open = substr_count($content, '<div');
$close = substr_count($content, '</div');
echo "Open divs: $open\n";
echo "Close divs: $close\n";
