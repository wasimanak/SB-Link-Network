<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// 1. Remove all instances of the clock blocks
// We will use regex to remove everything from `<div class="d-flex align-items-center bg-white px-4` up to `</script>`
$pattern = '/<div class="d-flex align-items-center bg-white px-4 py-2 rounded-pill shadow-sm".*?<\/script>/s';
$c = preg_replace($pattern, '', $c);

// 2. Add EXACTLY ONE clock back
$header_pattern = '/<h4 class="m-0 text-secondary fw-bold mb-3 mb-md-0"><i class="fa-solid fa-gauge-high text-primary me-2"><\/i>\s*Operator Dashboard<\/h4>/';
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
(function() {
    var tz = "<?= date_default_timezone_get() ?>";
    
    function updateClock() {
        var now = new Date();
        
        // Format time in the exact timezone
        var timeOptions = { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        var timeString = now.toLocaleTimeString('en-US', timeOptions);
        
        // Format date in the exact timezone
        var dateOptions = { timeZone: tz, day: 'numeric', month: 'short', year: 'numeric' };
        var dateString = now.toLocaleDateString('en-GB', dateOptions);
        
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

$c = preg_replace($header_pattern, $clock_html, $c, 1);

file_put_contents($f, $c);
echo "Cleaned up duplicates and fixed timezone JS!\n";
?>
