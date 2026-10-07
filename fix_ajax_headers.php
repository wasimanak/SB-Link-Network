<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// We need to extract the AJAX block and put it at the very top, before header.php is included.
$pattern = '/\/\/ Handle AJAX Kick Request.*?exit;\s*\}/s';

if (preg_match($pattern, $c, $matches)) {
    $ajax_block = $matches[0];
    
    // Remove the ajax block from its current position
    $c = str_replace($ajax_block, '', $c);
    
    // Remove the original require_once 'header.php';
    $c = str_replace("require_once 'header.php';\nrequire_once '../config/routeros_api.class.php';", "", $c);
    
    // Construct the new top part
    $newTop = "<?php
require_once 'auth_check.php';
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

" . $ajax_block . "

require_once 'header.php';
";

    // Replace the opening <?php with the new top
    $c = preg_replace('/<\?php/', $newTop, $c, 1);
    
    // Remove ob_clean() as it might cause notices if buffering is off
    $c = str_replace('ob_clean(); // Ensure pure JSON response', '', $c);
    
    file_put_contents($f, $c);
    echo "Fixed headers already sent issue in live_routers.php\n";
} else {
    echo "Could not find AJAX block.\n";
}
?>
