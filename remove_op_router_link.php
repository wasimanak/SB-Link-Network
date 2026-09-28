<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$content = file_get_contents($file);

// Remove the link to mikrotik_connect.php
$content = str_replace(
    '<a href="mikrotik_connect.php" class="<?= $p===\'mikrotik_connect.php\' ? \'active\' : \'\' ?>">Connection Settings</a>',
    '<!-- Connection Settings Moved to Super Admin -->',
    $content
);

file_put_contents($file, $content);
echo "Operator header updated to hide Connection Settings.\n";
?>
