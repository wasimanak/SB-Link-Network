<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

// 1. Patch edit_profile backend logic
$oldBackend1 = 'if (!empty($_POST[\'password\'])) {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, password=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=? WHERE id=?");
        $stmt->execute([
            $_POST[\'full_name\'], $_POST[\'username\'], $_POST[\'password\'], $_POST[\'national_id\'], $_POST[\'email\'], 
            $_POST[\'phone\'], $_POST[\'franchise\'], $_POST[\'address\'], $_POST[\'city\'], $dealer_id
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=? WHERE id=?");
        $stmt->execute([
            $_POST[\'full_name\'], $_POST[\'username\'], $_POST[\'national_id\'], $_POST[\'email\'], 
            $_POST[\'phone\'], $_POST[\'franchise\'], $_POST[\'address\'], $_POST[\'city\'], $dealer_id
        ]);
    }';
    
$newBackend1 = '$assigned_routers = isset($_POST[\'assigned_routers\']) ? implode(\',\', $_POST[\'assigned_routers\']) : \'\';
    
    if (!empty($_POST[\'password\'])) {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, password=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=?, assigned_routers=? WHERE id=?");
        $stmt->execute([
            $_POST[\'full_name\'], $_POST[\'username\'], $_POST[\'password\'], $_POST[\'national_id\'], $_POST[\'email\'], 
            $_POST[\'phone\'], $_POST[\'franchise\'], $_POST[\'address\'], $_POST[\'city\'], $assigned_routers, $dealer_id
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=?, assigned_routers=? WHERE id=?");
        $stmt->execute([
            $_POST[\'full_name\'], $_POST[\'username\'], $_POST[\'national_id\'], $_POST[\'email\'], 
            $_POST[\'phone\'], $_POST[\'franchise\'], $_POST[\'address\'], $_POST[\'city\'], $assigned_routers, $dealer_id
        ]);
    }';

if (strpos($c, 'assigned_routers=? WHERE id=?') === false) {
    $c = str_replace($oldBackend1, $newBackend1, $c);
}

// 2. Fetch NAS at top
$fetchNAS = '
$nasStmt = $pdo->prepare("SELECT nasname, shortname FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$all_nas = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer';

if (strpos($c, '$all_nas = $nasStmt->fetchAll') === false) {
    $c = str_replace('// Fetch Dealer', $fetchNAS, $c);
}

// 3. Patch HTML Modal
$oldHtml = '<div class="col-md-12 mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($dealer[\'address\']??\'\') ?>"></div>';
$newHtml = '<div class="col-md-12 mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($dealer[\'address\']??\'\') ?>"></div>
                <div class="col-md-12 mb-3">
                    <label class="form-label text-muted small fw-bold">Assign MikroTik Routers <span class="text-danger">*</span></label>
                    <?php $curr_routers = explode(\',\', $dealer[\'assigned_routers\'] ?? \'\'); ?>
                    <select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                        <?php foreach($all_nas as $n): ?>
                            <option value="<?= $n[\'nasname\'] ?>" <?= in_array($n[\'nasname\'], $curr_routers) ? \'selected\' : \'\' ?>><?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers.</div>
                </div>';

if (strpos($c, 'name="assigned_routers[]"') === false) {
    $c = str_replace($oldHtml, $newHtml, $c);
    file_put_contents($f, $c);
    echo "Patched dealer_view.php with router assignments\n";
} else {
    echo "Already patched\n";
}
?>
