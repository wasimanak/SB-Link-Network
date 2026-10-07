<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
if (preg_match('/\$nasStmt\s*=\s*\$pdo->prepare.*?\$all_nas\s*=\s*\$nasStmt->fetchAll/is', $c, $matches)) {
    echo "Found \$all_nas fetch logic.\n";
} else {
    echo "\$all_nas fetch logic NOT FOUND in dealer_view.php!\n";
}

$query_pos = strpos($c, '$all_nas =');
$modal_pos = strpos($c, 'id="edit_routers_select"');

echo "Query Position: " . ($query_pos !== false ? $query_pos : 'Not found') . "\n";
echo "Modal Position: " . ($modal_pos !== false ? $modal_pos : 'Not found') . "\n";
?>
