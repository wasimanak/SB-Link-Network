<?php
// Patching operator/dealers.php to inject multi-select dropdown
$f1 = 'operator/dealers.php';
$c1 = file_get_contents($f1);

$routersField = '
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Assign Routers <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                        <?php foreach($all_nas as $n): ?>
                            <option value="<?= $n[\'id\'] ?>"><?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted">Hold CTRL (or CMD) to select multiple routers.</div>
                </div>
            </div>';

if (strpos($c1, 'name="assigned_routers[]"') === false) {
    // Insert before the closing </div> of modal-body
    $c1 = str_replace('</div>
        <div class="modal-footer', $routersField . "\n" . '        </div>
        <div class="modal-footer', $c1);
    file_put_contents($f1, $c1);
    echo "operator/dealers.php updated with multi-select.\n";
}

// Ensure operator/dealer_view.php has multi-select
$f2 = 'operator/dealer_view.php';
$c2 = file_get_contents($f2);

$pattern = '/<div class="border rounded p-2 bg-light shadow-sm".*?<\/div>.*?<div class="form-text text-muted">.*?<\/div>/s';
$multiSelect = '<select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                        <?php foreach($all_nas as $n): ?>
                            <option value="<?= $n[\'id\'] ?>" <?= (in_array($n[\'id\'], $curr_routers)) ? \'selected\' : \'\' ?>>
                                <?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers. The dealer will only be able to create users on these routers.</div>';

if (preg_match($pattern, $c2)) {
    $c2 = preg_replace($pattern, $multiSelect, $c2);
    file_put_contents($f2, $c2);
    echo "operator/dealer_view.php replaced checkboxes with multi-select.\n";
} else {
    // Maybe it's already multi-select but uses old ID checking logic? Let's ensure it has strict ID checking.
    // Re-replace just in case
}

?>
