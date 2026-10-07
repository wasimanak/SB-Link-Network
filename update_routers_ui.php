<?php
// Patching operator/dealers.php
$f1 = 'operator/dealers.php';
$c1 = file_get_contents($f1);

$oldSelect1 = '<select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                            <?php foreach($all_nas as $n): ?>
                                <option value="<?= $n[\'nasname\'] ?>"><?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers. The dealer will only be able to create users on these routers.</div>';

$newCheckboxes1 = '<div class="border rounded p-2 bg-light shadow-sm" style="max-height: 150px; overflow-y: auto;">
                            <?php foreach($all_nas as $n): ?>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="assigned_routers[]" value="<?= $n[\'nasname\'] ?>" id="nas_add_<?= md5($n[\'nasname\']) ?>">
                                    <label class="form-check-label fw-bold text-dark" style="cursor: pointer;" for="nas_add_<?= md5($n[\'nasname\']) ?>">
                                        <?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> <span class="text-primary">(<?= $n[\'nasname\'] ?>)</span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text text-muted"><i class="fa-solid fa-hand-pointer"></i> Tap/Click to select one or multiple routers. No keyboard needed.</div>';

if (strpos($c1, '<div class="border rounded p-2 bg-light shadow-sm"') === false) {
    // If the exact match fails due to indentation, we can use regex
    $c1 = preg_replace('/<select name="assigned_routers\[\]".*?<\/div>/s', $newCheckboxes1, $c1);
    file_put_contents($f1, $c1);
    echo "dealers.php updated with checkboxes.\n";
} else {
    echo "dealers.php already has checkboxes.\n";
}

// Patching operator/dealer_view.php
$f2 = 'operator/dealer_view.php';
$c2 = file_get_contents($f2);

$newCheckboxes2 = '<div class="border rounded p-2 bg-light shadow-sm" style="max-height: 150px; overflow-y: auto;">
                        <?php foreach($all_nas as $n): ?>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="assigned_routers[]" value="<?= $n[\'nasname\'] ?>" id="nas_edit_<?= md5($n[\'nasname\']) ?>" <?= in_array($n[\'nasname\'], $curr_routers) ? \'checked\' : \'\' ?>>
                                <label class="form-check-label fw-bold text-dark" style="cursor: pointer;" for="nas_edit_<?= md5($n[\'nasname\']) ?>">
                                    <?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> <span class="text-primary">(<?= $n[\'nasname\'] ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text text-muted"><i class="fa-solid fa-hand-pointer"></i> Tap/Click to select one or multiple routers. No keyboard needed.</div>';

if (strpos($c2, '<div class="border rounded p-2 bg-light shadow-sm"') === false) {
    $c2 = preg_replace('/<select name="assigned_routers\[\]".*?<\/div>/s', $newCheckboxes2, $c2);
    file_put_contents($f2, $c2);
    echo "dealer_view.php updated with checkboxes.\n";
} else {
    echo "dealer_view.php already has checkboxes.\n";
}
?>
