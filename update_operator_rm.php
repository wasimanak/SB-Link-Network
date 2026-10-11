<?php
$f = 'operator/recovery_man.php';
$c = file_get_contents($f);

// 1. ALTER TABLE
$alter_query = <<<'PHP'
try {
    $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN `can_create_user` ENUM('yes', 'no') DEFAULT 'yes'");
} catch (PDOException $e) {}
PHP;
$c = str_replace('// Auto-create table if not exists', $alter_query . "\n\n// Auto-create table if not exists", $c);

// 2. Add Member Logic
$add_logic_find = '$status = $_POST[\'status\'] ?? \'active\';';
$add_logic_replace = <<<'PHP'
$status = $_POST['status'] ?? 'active';
            $can_create_user = $_POST['can_create_user'] ?? 'yes';
PHP;
$c = str_replace($add_logic_find, $add_logic_replace, $c);

$add_sql_find = 'INSERT INTO recovery_men (client_id, full_name, username, password, phone, address, city, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
$add_sql_replace = 'INSERT INTO recovery_men (client_id, full_name, username, password, phone, address, city, status, can_create_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
$c = str_replace($add_sql_find, $add_sql_replace, $c);

$add_exec_find = '->execute([$client_id, $full_name, $username, $password, $phone, $address, $city, $status])';
$add_exec_replace = '->execute([$client_id, $full_name, $username, $password, $phone, $address, $city, $status, $can_create_user])';
$c = str_replace($add_exec_find, $add_exec_replace, $c);


// 3. Edit Member Logic
$edit_logic_find = '$status = $_POST[\'status\'] ?? \'active\';';
$edit_logic_replace = <<<'PHP'
$status = $_POST['status'] ?? 'active';
        $can_create_user = $_POST['can_create_user'] ?? 'yes';
PHP;
$c = str_replace($edit_logic_find, $edit_logic_replace, $c);

$edit_sql_find = 'UPDATE recovery_men SET full_name=?, password=?, phone=?, address=?, city=?, status=? WHERE id=? AND client_id=?';
$edit_sql_replace = 'UPDATE recovery_men SET full_name=?, password=?, phone=?, address=?, city=?, status=?, can_create_user=? WHERE id=? AND client_id=?';
$c = str_replace($edit_sql_find, $edit_sql_replace, $c);

$edit_exec_find = '->execute([$full_name, $password, $phone, $address, $city, $status, $id, $client_id])';
$edit_exec_replace = '->execute([$full_name, $password, $phone, $address, $city, $status, $can_create_user, $id, $client_id])';
$c = str_replace($edit_exec_find, $edit_exec_replace, $c);

// 4. Update btn-edit dataset
$c = str_replace('data-status="<?= $m[\'status\'] ?? \'active\' ?>">', 'data-status="<?= $m[\'status\'] ?? \'active\' ?>" data-can_create="<?= $m[\'can_create_user\'] ?? \'yes\' ?>">', $c);

// 5. Update Add Modal HTML
$add_modal_html = <<<'HTML'
<div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Status</label>
                    <select name="status" class="form-select bg-light" required>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Create User Permission</label>
                    <select name="can_create_user" class="form-select bg-light" required>
                        <option value="yes">Enabled</option>
                        <option value="no">Disabled</option>
                    </select>
                </div>
HTML;
$c = preg_replace('/<div class="col-md-6 mb-3">\s*<label class="form-label fw-bold small text-muted">Status<\/label>.*?<\/select>\s*<\/div>/s', $add_modal_html, $c, 1);

// 6. Update Edit Modal HTML
$edit_modal_html = <<<'HTML'
<div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Status</label>
                    <select name="status" id="edit_status" class="form-select bg-light" required>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Create User Permission</label>
                    <select name="can_create_user" id="edit_can_create_user" class="form-select bg-light" required>
                        <option value="yes">Enabled</option>
                        <option value="no">Disabled</option>
                    </select>
                </div>
HTML;
// Because preg_replace replaces all, we ensure we only match the one in editMemberModal or use a different string.
$c = str_replace('<select name="status" id="edit_status" class="form-select bg-light" required>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>', '<select name="status" id="edit_status" class="form-select bg-light" required>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Create User Permission</label>
                    <select name="can_create_user" id="edit_can_create_user" class="form-select bg-light" required>
                        <option value="yes">Enabled</option>
                        <option value="no">Disabled</option>
                    </select>
                </div>', $c);

// 7. Update JavaScript
$c = str_replace('$("#edit_status").val($(this).attr("data-status"));', '$("#edit_status").val($(this).attr("data-status"));
        $("#edit_can_create_user").val($(this).attr("data-can_create"));', $c);

file_put_contents($f, $c);
echo "Updated Operator UI.\n";
?>
