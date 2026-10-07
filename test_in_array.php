<?php
$n_id = 5;
$n_nasname = '119.156.28.215';

$dealer_assigned = ''; // Unchecked and saved
$curr_routers = explode(',', $dealer_assigned);

var_dump($curr_routers);
var_dump(in_array($n_id, $curr_routers));
var_dump(in_array($n_nasname, $curr_routers));

$dealer_assigned2 = '5,7';
$curr_routers2 = explode(',', $dealer_assigned2);
var_dump(in_array($n_id, $curr_routers2));
?>
