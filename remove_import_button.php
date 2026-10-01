<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/dashboard.php';
$content = file_get_contents($file);

// Remove the Import Users button
$content = str_replace(
    '<a href="mikrotik_sync.php" class="quick-btn"><i class="fa-solid fa-file-import"></i><span>Import Users</span></a>',
    '',
    $content
);

file_put_contents($file, $content);
echo "Removed broken mikrotik_sync.php link from dashboard.php.\n";
?>
