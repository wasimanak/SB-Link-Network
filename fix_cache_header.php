<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

$old_js = <<<'JS'
      function fetchRouterTime() {
          fetch('api_router_time.php')
JS;

$new_js = <<<'JS'
      function fetchRouterTime() {
          fetch('api_router_time.php?t=' + Date.now(), { cache: "no-store" })
JS;

$content = str_replace($old_js, $new_js, $content);
file_put_contents($file, $content);
echo "Added cache buster to header.php.\n";
?>
