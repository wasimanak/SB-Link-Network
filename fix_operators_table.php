<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// Fix table name from operators to clients
$oldSql = 'SELECT n.*, o.full_name as operator_name FROM nas n LEFT JOIN operators o ON n.client_id = o.id ORDER BY n.id DESC';
$newSql = 'SELECT n.*, c.company_name as operator_name FROM nas n LEFT JOIN clients c ON n.client_id = c.id ORDER BY n.id DESC';

$c = str_replace($oldSql, $newSql, $c);

file_put_contents($f, $c);
echo "Fixed operators table reference to clients table.\n";
?>
