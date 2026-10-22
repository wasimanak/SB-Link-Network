<?php
$f = 'superadmin/packages_master.php';
$c = file_get_contents($f);

// 1. Add fetch operators logic
$c = str_replace("require_once 'header.php';\n", "require_once 'header.php';\n\n// Fetch Operators\n\$opStmt = \$pdo->query(\"SELECT id, username FROM clients ORDER BY username ASC\");\n\$operators = \$opStmt->fetchAll(PDO::FETCH_ASSOC);\n", $c);

// 2. Fix Add Backend
$add_backend_find = <<<'PHP'
$data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];

                $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb) VALUES (0, :name, :rate_limit, :validity, :price, :data_limit)");
                $stmt->execute([
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                    'validity' => $validity_days,
                    'price' => $price,
                    'data_limit' => $data_limit_gb
                ]);
PHP;
$add_backend_replace = <<<'PHP'
$data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];
                $client_id = isset($_POST['operator_id']) ? (int)$_POST['operator_id'] : 0;

                $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb) VALUES (:client_id, :name, :rate_limit, :validity, :price, :data_limit)");
                $stmt->execute([
                    'client_id' => $client_id,
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                    'validity' => $validity_days,
                    'price' => $price,
                    'data_limit' => $data_limit_gb
                ]);
PHP;
$c = str_replace($add_backend_find, $add_backend_replace, $c);

// 3. Fix Edit Backend
$edit_backend_find = <<<'PHP'
$data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];

                $stmt = $pdo->prepare("UPDATE packages SET name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit WHERE id = :id AND client_id = 0");
                $stmt->execute([
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                    'validity' => $validity_days,
                    'price' => $price,
                    'data_limit' => $data_limit_gb,
                    'id' => $id
                ]);
PHP;
$edit_backend_replace = <<<'PHP'
$data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];
                $client_id = isset($_POST['operator_id']) ? (int)$_POST['operator_id'] : 0;

                $stmt = $pdo->prepare("UPDATE packages SET client_id = :client_id, name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit WHERE id = :id");
                $stmt->execute([
                    'client_id' => $client_id,
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                    'validity' => $validity_days,
                    'price' => $price,
                    'data_limit' => $data_limit_gb,
                    'id' => $id
                ]);
PHP;
$c = str_replace($edit_backend_find, $edit_backend_replace, $c);

// 4. Fix Delete Backend
$del_backend_find = '$stmt = $pdo->prepare("DELETE FROM packages WHERE id = :id AND client_id = 0");';
$del_backend_replace = '$stmt = $pdo->prepare("DELETE FROM packages WHERE id = :id");';
$c = str_replace($del_backend_find, $del_backend_replace, $c);

// 5. Update Package Fetch Query
$fetch_query_find = '$stmt = $pdo->query("SELECT * FROM packages WHERE client_id = 0 ORDER BY id DESC");';
$fetch_query_replace = '$stmt = $pdo->query("SELECT p.*, c.username as operator_username FROM packages p LEFT JOIN clients c ON p.client_id = c.id ORDER BY p.id DESC");';
$c = str_replace($fetch_query_find, $fetch_query_replace, $c);


// 6. Update Header & Column
$c = str_replace('Master Packages', 'All Packages', $c);
$c = str_replace('Add Master Package', 'Add Package', $c);
$c = str_replace('Master package', 'Package', $c); // success messages

$th_find = '<th>Package Name</th>';
$th_replace = '<th>Package Name</th><th>Owner (Operator)</th>';
$c = str_replace($th_find, $th_replace, $c);

$td_find = '<td class="fw-bold text-primary"><?= htmlspecialchars($p[\'name\']) ?></td>';
$td_replace = '<td class="fw-bold text-primary"><?= htmlspecialchars($p[\'name\']) ?></td>
<td>
    <?php if($p[\'client_id\'] == 0): ?>
        <span class="badge bg-success">Global (All)</span>
    <?php else: ?>
        <span class="badge bg-primary"><i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($p[\'operator_username\']) ?></span>
    <?php endif; ?>
</td>';
$c = str_replace($td_find, $td_replace, $c);

// 7. Update Add Modal
$add_modal_select = <<<'HTML'
<div class="mb-3">
                                <label class="form-label fw-bold text-primary">Assign To (Operator)</label>
                                <select name="operator_id" class="form-select border-primary bg-light" required>
                                    <option value="0">🌍 Global (Available to All Operators)</option>
                                    <?php foreach($operators as $op): ?>
                                        <option value="<?= $op['id'] ?>">👤 <?= htmlspecialchars($op['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
HTML;
// I need to insert this before "Package Name" in addPackageModal.
// First let's find the Add Modal HTML
$c = preg_replace('/<div class="mb-3">\s*<label class="form-label fw-bold">Package Name<\/label>\s*<input type="text" name="name" class="form-control" required>\s*<\/div>/i', $add_modal_select . "\n                            " . '<div class="mb-3"><label class="form-label fw-bold">Package Name</label><input type="text" name="name" class="form-control" required></div>', $c, 1);

// 8. Update Edit Modal
$edit_modal_select = <<<'HTML'
<div class="mb-3">
                                                <label class="form-label fw-bold text-primary">Assign To (Operator)</label>
                                                <select name="operator_id" class="form-select border-primary bg-light" required>
                                                    <option value="0" <?= $p['client_id'] == 0 ? 'selected' : '' ?>>🌍 Global (Available to All Operators)</option>
                                                    <?php foreach($operators as $op): ?>
                                                        <option value="<?= $op['id'] ?>" <?= $p['client_id'] == $op['id'] ? 'selected' : '' ?>>👤 <?= htmlspecialchars($op['username']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
HTML;
$c = preg_replace('/<div class="mb-3">\s*<label class="form-label fw-bold">Package Name<\/label>\s*<input type="text" name="name" class="form-control" value="<\?= htmlspecialchars\(\$p\[\'name\'\]\) \?>" required>\s*<\/div>/', $edit_modal_select . "\n                                            " . '<div class="mb-3"><label class="form-label fw-bold">Package Name</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($p[\'name\']) ?>" required></div>', $c);


file_put_contents($f, $c);
echo "Packages Manager Updated.\n";
?>
