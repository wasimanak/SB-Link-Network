<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
$oldQuery = '$nasStmt = $pdo->prepare("SELECT nasname, shortname FROM nas WHERE client_id = ?");';
$newQuery = '$nasStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE client_id = ?");';
if (strpos($c, $oldQuery) !== false) {
    $c = str_replace($oldQuery, $newQuery, $c);
    file_put_contents($f, $c);
    echo "operator/dealer_view.php patched to select 'id' from nas table.\n";
} else {
    echo "Query not found or already patched.\n";
}
?>
