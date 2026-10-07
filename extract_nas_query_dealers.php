<?php
$f = 'operator/dealers.php';
$c = file_get_contents($f);
if (preg_match('/\$nasStmt\s*=\s*\$pdo->prepare.*?\$all_nas\s*=\s*\$nasStmt->fetchAll.*?;/is', $c, $matches)) {
    echo $matches[0];
}
?>
