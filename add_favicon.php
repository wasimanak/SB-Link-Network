<?php
$files = [
    'customer/header.php',
    'operator/header.php',
    'superadmin/header.php',
    'dealer/header.php',
    'lineman/header.php',
    'recoveryman/header.php',
    'login.php'
];

$link = '<link rel="icon" type="image/svg+xml" href="/favicon.svg">';

foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        if (strpos($c, '/favicon.svg') === false) {
            $c = str_replace('<head>', "<head>\n    $link", $c);
            file_put_contents($f, $c);
            echo "Updated $f\n";
        } else {
            echo "Already updated $f\n";
        }
    }
}
echo "Done.";
?>
