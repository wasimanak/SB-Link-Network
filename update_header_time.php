<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

$old_js = <<<'JS'
      <script>
      function updateSidebarClock() {
          var now = new Date();
          var h = String(now.getHours()).padStart(2, '0');
          var m = String(now.getMinutes()).padStart(2, '0');
          var s = String(now.getSeconds()).padStart(2, '0');
          var timeStr = h + ':' + m + ':' + s;
          
          var y = now.getFullYear();
          var m = String(now.getMonth() + 1).padStart(2, '0');
          var d = String(now.getDate()).padStart(2, '0');
          var dateStr = y + '-' + m + '-' + d;
          
          var timeSpan = document.getElementById('sb_time');
          var dateSpan = document.getElementById('sb_date');
          if(timeSpan) timeSpan.innerText = timeStr;
          if(dateSpan) dateSpan.innerText = dateStr;
      }
      setInterval(updateSidebarClock, 1000);
      document.addEventListener("DOMContentLoaded", updateSidebarClock);
      </script>
JS;

$new_js = <<<'JS'
      <script>
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

      function updateSidebarClock() {
          // Adjust local time by the router offset
          var now = new Date(Date.now() + routerTimeOffset);
          var h = String(now.getHours()).padStart(2, '0');
          var min = String(now.getMinutes()).padStart(2, '0');
          var s = String(now.getSeconds()).padStart(2, '0');
          var timeStr = h + ':' + min + ':' + s;
          
          var y = now.getFullYear();
          var m = String(now.getMonth() + 1).padStart(2, '0');
          var d = String(now.getDate()).padStart(2, '0');
          var dateStr = y + '-' + m + '-' + d;
          
          var timeSpan = document.getElementById('sb_time');
          var dateSpan = document.getElementById('sb_date');
          if(timeSpan) timeSpan.innerText = timeStr;
          if(dateSpan) dateSpan.innerText = dateStr;
      }
      
      setInterval(updateSidebarClock, 1000);
      document.addEventListener("DOMContentLoaded", () => {
          updateSidebarClock();
          fetchRouterTime(); // Sync time with router once on load
      });
      </script>
JS;

$content = str_replace($old_js, $new_js, $content);

file_put_contents($file, $content);
echo "Updated header.php to sync router time.\n";
?>
