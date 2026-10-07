<?php
$c = file_get_contents('dealer/users.php');
if (preg_match('/<div class="modal fade" id="editUserModal".*?<\/div>\s*<\/div>\s*<\/div>/is', $c, $matches)) {
    echo $matches[0];
} else {
    echo "editUserModal not found";
}
?>
