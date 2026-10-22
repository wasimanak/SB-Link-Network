<?php
$f = 'superadmin/packages_master.php';
$c = file_get_contents($f);

// 1. Fix the query to use company_name instead of username
$c = str_replace(
    'SELECT id, username FROM clients ORDER BY username ASC',
    'SELECT id, company_name FROM clients ORDER BY company_name ASC',
    $c
);

// Also fix the join query for the table display:
$c = str_replace(
    'SELECT p.master_id, c.id, c.username FROM packages p JOIN clients c ON p.client_id = c.id WHERE p.master_id IS NOT NULL',
    'SELECT p.master_id, c.id, c.company_name as username FROM packages p JOIN clients c ON p.client_id = c.id WHERE p.master_id IS NOT NULL',
    $c
);

$c = str_replace(
    'SELECT p.*, c.username as owner_name FROM packages p LEFT JOIN clients c ON p.client_id = c.id WHERE p.is_master = 1 OR p.master_id IS NULL ORDER BY p.id DESC',
    'SELECT p.*, c.company_name as owner_name FROM packages p LEFT JOIN clients c ON p.client_id = c.id WHERE p.is_master = 1 OR p.master_id IS NULL ORDER BY p.id DESC',
    $c
);


// 2. Replace Multi-Select with Checkboxes in Add Modal
$add_modal_select = <<<'HTML'
<label class="form-label fw-bold text-primary">Assign To</label>
                <div class="small text-muted mb-1">Check the boxes to assign this package.</div>
                <select name="operator_ids[]" class="form-select border-primary bg-light" multiple size="5" required>
                    <option value="0" selected>--- Global (All Operators) ---</option>
                    <?php foreach($operators as $op): ?>
                        <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['username']) ?></option>
                    <?php endforeach; ?>
                </select>
HTML;

// Note: I actually wrote username previously: htmlspecialchars($op['username'])
// I need to be careful with the exact string. Let's just use regex to replace the entire DIV.

$add_modal_new = <<<'HTML'
<label class="form-label fw-bold text-primary">Assign To</label>
                <div class="small text-muted mb-2">Check the boxes to assign this package.</div>
                <div class="border border-primary border-opacity-25 rounded p-3 bg-white mb-2 shadow-sm" style="max-height: 200px; overflow-y: auto;">
                    <div class="form-check border-bottom pb-2 mb-2">
                        <input class="form-check-input shadow-sm" type="checkbox" name="operator_ids[]" value="0" id="op_global_add" checked>
                        <label class="form-check-label fw-bold text-success" for="op_global_add">
                            <i class="fa-solid fa-earth-americas me-1"></i> Global (Available to All Operators)
                        </label>
                    </div>
                    <?php foreach($operators as $op): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="operator_ids[]" value="<?= $op['id'] ?>" id="op_add_<?= $op['id'] ?>">
                            <label class="form-check-label" for="op_add_<?= $op['id'] ?>">
                                <?= htmlspecialchars($op['company_name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
HTML;
$c = preg_replace('/<label class="form-label fw-bold text-primary">Assign To \(Multiple\)<\/label>.*?<\/select>/s', $add_modal_new, $c, 1);


// 3. Replace Multi-Select with Checkboxes in Edit Modal
$edit_modal_new = <<<'HTML'
<label class="form-label fw-bold text-primary">Assign To</label>
                                                <div class="small text-muted mb-2">Check the boxes to assign this package.</div>
                                                <div class="border border-primary border-opacity-25 rounded p-3 bg-white mb-2 shadow-sm" style="max-height: 200px; overflow-y: auto;">
                                                    <div class="form-check border-bottom pb-2 mb-2">
                                                        <input class="form-check-input shadow-sm" type="checkbox" name="operator_ids[]" value="0" id="op_global_edit_<?= $p['id'] ?>" <?= $is_global ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-bold text-success" for="op_global_edit_<?= $p['id'] ?>">
                                                            <i class="fa-solid fa-earth-americas me-1"></i> Global (Available to All Operators)
                                                        </label>
                                                    </div>
                                                    <?php foreach($operators as $op): 
                                                        $selected = '';
                                                        if (!empty($assignments[$p['id']])) {
                                                            foreach($assignments[$p['id']] as $asg) {
                                                                if($asg['id'] == $op['id']) $selected = 'checked';
                                                            }
                                                        }
                                                        if ($p['client_id'] == $op['id']) {
                                                            $selected = 'checked';
                                                        }
                                                    ?>
                                                        <div class="form-check mb-1">
                                                            <input class="form-check-input" type="checkbox" name="operator_ids[]" value="<?= $op['id'] ?>" id="op_edit_<?= $p['id'] ?>_<?= $op['id'] ?>" <?= $selected ?>>
                                                            <label class="form-check-label" for="op_edit_<?= $p['id'] ?>_<?= $op['id'] ?>">
                                                                <?= htmlspecialchars($op['company_name']) ?>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
HTML;
$c = preg_replace('/<label class="form-label fw-bold text-primary">Assign To \(Multiple\)<\/label>.*?<\/select>/s', $edit_modal_new, $c, 1);

file_put_contents($f, $c);
echo "Replaced dropdowns with checkboxes.\n";
?>
