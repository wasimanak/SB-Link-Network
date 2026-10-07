<?php
$f = file_get_contents('dealer/users.php');
$html_start = strpos($f, '<div class="d-flex justify-content-between align-items-center mb-4">');
if ($html_start !== false) {
    echo substr($f, $html_start, 2000);
} else {
    echo "Could not find HTML block.\n";
}
?>
