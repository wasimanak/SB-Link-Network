<?php
$f = 'operator/statement.php';
$c = file_get_contents($f);

$oldJoin = 'LEFT JOIN user_ledger l ON s.username = l.username AND s.client_id = l.client_id';
$newJoin = 'LEFT JOIN user_ledger l ON s.username = l.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id';

if (strpos($c, 'COLLATE') === false) {
    $c = str_replace($oldJoin, $newJoin, $c);
    file_put_contents($f, $c);
    echo "SQL collation fixed in operator/statement.php\n";
} else {
    echo "Collation already fixed.\n";
}
?>
