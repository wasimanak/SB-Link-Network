<?php
$file = 'C:/xampp/htdocs/SB Link Network/config/db.php';
$content = file_get_contents($file);

// Replace the old IP with the new one
$content = str_replace("10.133.13.68", "10.133.13.69", $content);

file_put_contents($file, $content);
echo "Updated config/db.php to new IP: 10.133.13.69\n";
?>
