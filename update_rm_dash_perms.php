<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Fetch Permission
$c = str_replace('SELECT status FROM recovery_men', 'SELECT status, can_create_user FROM recovery_men', $c);

// 2. Wrap Button
$button_html = '<button type="button" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>';
$wrapped_button = '<?php if(isset($rm_data["can_create_user"]) && $rm_data["can_create_user"] === "yes"): ?>' . "\n" . $button_html . "\n" . '<?php endif; ?>';
$c = str_replace($button_html, $wrapped_button, $c);

// 3. Block Backend Logic
$logic_find = "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'create_user') {";
$logic_replace = "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'create_user') {\n    if(!isset(\$rm_data['can_create_user']) || \$rm_data['can_create_user'] !== 'yes') { die('Permission Denied.'); }\n";
$c = str_replace($logic_find, $logic_replace, $c);

file_put_contents($f, $c);
echo "Updated Dashboard Permissions.\n";
?>
