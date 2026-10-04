<?php
$files = [
    'dealer/header.php',
    'dealer/logout.php'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        $c = str_replace('Location: login.php', 'Location: ../login.php', $c);
        file_put_contents($f, $c);
        echo "Updated $f\n";
    }
}
echo "Done.";
?>
