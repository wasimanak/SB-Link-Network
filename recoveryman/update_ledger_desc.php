<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

// 1. Replace the description logic
$old_desc = '$desc = "Cash collected by RM: {$rm_name}. " . ($note ? " Note: $note" : "");';
$new_desc = '$desc = "Cash collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");';
$content = str_replace($old_desc, $new_desc, $content);

// 2. Replace the today's collection query logic
$old_coll = '$collStmt->execute([$client_id, "Cash collected by RM: {$rm_name}%"]);';
$new_coll = '$collStmt->execute([$client_id, "Cash collected by {$rm_name} RM%"]);';
$content = str_replace($old_coll, $new_coll, $content);

file_put_contents($file, $content);
echo "Updated ledger description format.\n";
?>
