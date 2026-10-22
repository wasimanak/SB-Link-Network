<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// Revert the wildcard filter so ALL routers in the database show up accurately
$old_query = "SELECT n.*, c.company_name as operator_name FROM nas n LEFT JOIN clients c ON n.client_id = c.id WHERE n.nasname NOT IN ('0.0.0.0/0', '223.123.73.0/24') ORDER BY n.id DESC";
$new_query = "SELECT n.*, c.company_name as operator_name FROM nas n LEFT JOIN clients c ON n.client_id = c.id ORDER BY n.id DESC";

$c = str_replace($old_query, $new_query, $c);

file_put_contents($f, $c);
echo "Restored original live_routers.php query (Wildcards removed from filter).\n";
?>
