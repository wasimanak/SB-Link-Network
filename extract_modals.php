<?php
$c = file_get_contents('dealer/users.php');
if (preg_match('/<!-- Add User Modal -->.*?<!-- Edit User Modal -->.*?<div class="modal fade" id="renewUserModal"/is', $c, $matches)) {
    echo $matches[0];
} else if (preg_match('/<!-- Add User Modal -->.*?<\/script>/is', $c, $matches)) {
    echo $matches[0];
}
?>
