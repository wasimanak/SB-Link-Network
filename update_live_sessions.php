<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// 1. Add formatMikroTikUptime function at the top after <?php
$uptimeFunc = '
function formatMikroTikUptime($seconds) {
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    
    $out = "";
    if ($d > 0) $out .= $d . "d";
    if ($h > 0 || $d > 0) $out .= $h . "h";
    $out .= $m . "m";
    return $out;
}
';
$c = preg_replace('/(\$client_id = \$_SESSION\[\'operator_id\'\];)/', "$1\n$uptimeFunc", $c);

// 2. Add Search Box in Header
$searchBox = '<div class="input-group shadow-sm" style="max-width: 250px;">
            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search text-muted"></i></span>
            <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search user...">
        </div>';

// Replace <div class="d-flex gap-3 align-items-center"> ... </div> (assuming it's there)
$c = preg_replace('/(<div class="d-flex gap-3 align-items-center">)/', "$1\n        $searchBox", $c);

// 3. Add data-username and class to the column div
$oldCol = '<div class="col-xl-4 col-lg-6 col-md-6">';
$newCol = '<div class="col-xl-4 col-lg-6 col-md-6 session-card-container" data-username="<?= htmlspecialchars($sess[\'username\']) ?>">';
$c = str_replace($oldCol, $newCol, $c);

// 4. Change Uptime Format
$oldUptime = '<?= sprintf("%02d:%02d", $hrs, $mins) ?>h';
$newUptime = '<?= formatMikroTikUptime($upTime) ?>';
$c = str_replace($oldUptime, $newUptime, $c);

// 5. Add JavaScript at the end
$js = '
<script>
document.getElementById(\'searchInput\').addEventListener(\'keyup\', function() {
    let filter = this.value.toLowerCase();
    let cards = document.querySelectorAll(\'.session-card-container\');
    cards.forEach(card => {
        let username = card.getAttribute(\'data-username\').toLowerCase();
        if (username.includes(filter)) {
            card.style.display = \'\';
        } else {
            card.style.display = \'none\';
        }
    });
});
</script>
';
$c = str_replace('</body>', $js . "\n</body>", $c);

file_put_contents($f, $c);
echo "operator/live_sessions.php updated successfully.\n";
?>
