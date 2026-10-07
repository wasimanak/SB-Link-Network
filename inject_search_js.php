<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// The JS code that was supposed to be added
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

// Check if it exists, if not, put it before footer
if (strpos($c, 'searchInput') !== false && strpos($c, 'addEventListener') === false) {
    $c = str_replace('<?php require_once \'footer.php\'; ?>', $js . "\n<?php require_once 'footer.php'; ?>", $c);
    file_put_contents($f, $c);
    echo "JavaScript for live search successfully injected.\n";
} else {
    echo "JS already exists or search box not found.\n";
}
?>
