<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// Update the Offline card link
$pattern = '/(<div class="stat-box bg-grey cursor-pointer"[^>]*onclick="window\.location\.href=[\'\"])(subscribers\.php)([\'\"][^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-large-slash"><\/i> Offline<\/div>)/i';
$replacement = '$1subscribers.php?filter=offline$3'."\n".'                    $4';

$c = preg_replace($pattern, $replacement, $c);

file_put_contents($f, $c);
echo "Updated Offline card link.\n";
?>
