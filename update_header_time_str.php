<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

$old_js = <<<'JS'
      let routerTimeOffset = 0; // Difference in milliseconds

      function fetchRouterTime() {
          fetch('api_router_time.php')
              .then(response => response.json())
              .then(data => {
                  if (data.timestamp) {
                      // Calculate offset between local browser time and router time
                      let routerMs = data.timestamp * 1000;
                      let localMs = Date.now();
                      routerTimeOffset = routerMs - localMs;
                  }
              })
              .catch(err => console.error("Router time sync failed", err));
      }
JS;

$new_js = <<<'JS'
      let routerTimeOffset = 0; // Difference in milliseconds

      function fetchRouterTime() {
          fetch('api_router_time.php')
              .then(response => response.json())
              .then(data => {
                  if (data.time_str) {
                      // Parse exact string from router as local time to avoid timezone shifts
                      // e.g. "2026-09-28T09:38:40"
                      let routerDate = new Date(data.time_str);
                      if (!isNaN(routerDate.getTime())) {
                          let routerMs = routerDate.getTime();
                          let localMs = Date.now();
                          routerTimeOffset = routerMs - localMs;
                      }
                  }
              })
              .catch(err => console.error("Router time sync failed", err));
      }
JS;

$content = str_replace($old_js, $new_js, $content);

file_put_contents($file, $content);
echo "Updated header.php JS to parse time_str.\n";
?>
