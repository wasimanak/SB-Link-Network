<?php
$f = 'operator/dealers.php';
$c = file_get_contents($f);
if(preg_match('/\$nasStmt\s*=\s*\$pdo->prepare.*?\$all_nas\s*=\s*\$nasStmt->fetchAll/is', $c, $matches)) {
    echo "Found \$all_nas fetch logic.\n";
} else {
    echo "\$all_nas fetch logic NOT FOUND in dealers.php!\n";
}

// Find where the modal is
$modal_pos = strpos($c, 'id="addDealerModal"');
$query_pos = strpos($c, '$all_nas = $nasStmt->fetchAll');

echo "Modal Position: " . $modal_pos . "\n";
echo "Query Position: " . $query_pos . "\n";
?>
