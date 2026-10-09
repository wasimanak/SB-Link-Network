<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// We will use a reliable regex to inject the clock right after the h4 tag.
$pattern = '/<h4 class="m-0 text-secondary fw-bold mb-3 mb-md-0"><i class="fa-solid fa-gauge-high text-primary me-2"><\/i>\s*Operator Dashboard<\/h4>/';

$clock_html = <<<'HTML'
<h4 class="m-0 text-secondary fw-bold mb-3 mb-md-0"><i class="fa-solid fa-gauge-high text-primary me-2"></i> Operator Dashboard</h4>
    
    <div class="d-flex align-items-center bg-white px-4 py-2 rounded-pill shadow-sm" style="border: 1px solid #e2e8f0;">
        <i class="fa-regular fa-clock fs-5 text-primary me-3"></i>
        <div>
            <div class="text-muted" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">Live System Time (<?= date_default_timezone_get() ?>)</div>
            <div id="live-server-clock" class="fw-bold text-dark" style="font-size: 1.1rem; font-family: monospace;">Loading...</div>
        </div>
    </div>

<script>
// Live Clock Script (Synced roughly with server time via JS offset)
(function() {
    var serverTimeMs = <?= time() * 1000 ?>;
    var localTimeMs = new Date().getTime();
    var timeDiff = serverTimeMs - localTimeMs;

    function updateClock() {
        var now = new Date(new Date().getTime() + timeDiff);
        var hours = now.getHours();
        var minutes = now.getMinutes();
        var seconds = now.getSeconds();
        var ampm = hours >= 12 ? 'PM' : 'AM';
        
        hours = hours % 12;
        hours = hours ? hours : 12;
        
        minutes = minutes < 10 ? '0' + minutes : minutes;
        seconds = seconds < 10 ? '0' + seconds : seconds;
        
        var timeString = hours + ':' + minutes + ':' + seconds + ' ' + ampm;
        
        var day = now.getDate();
        var monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        var month = monthNames[now.getMonth()];
        var year = now.getFullYear();
        
        var dateString = day + ' ' + month + ' ' + year;
        
        var clockEl = document.getElementById('live-server-clock');
        if(clockEl) {
            clockEl.innerHTML = dateString + ' &nbsp;|&nbsp; <span class="text-primary">' + timeString + '</span>';
        }
    }
    
    updateClock();
    setInterval(updateClock, 1000);
})();
</script>
HTML;

$c = preg_replace($pattern, $clock_html, $c);

file_put_contents($f, $c);
echo "Successfully injected Live Clock via regex.\n";
?>
