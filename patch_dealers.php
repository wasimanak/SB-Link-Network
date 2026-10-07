<?php
$f = 'operator/dealers.php';
$c = file_get_contents($f);

// 1. Update the INSERT logic to save assigned_routers
$oldInsert = '$stmt = $pdo->prepare("INSERT INTO dealers (client_id, full_name, username, password, national_id, email, phone, address, city) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$client_id, $full_name, $username, $password, $national_id, $email, $phone, $address, $city]);';
$newInsert = '$assigned_routers = isset($_POST[\'assigned_routers\']) ? implode(\',\', $_POST[\'assigned_routers\']) : \'\';
        
        $stmt = $pdo->prepare("INSERT INTO dealers (client_id, full_name, username, password, national_id, email, phone, address, city, assigned_routers) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$client_id, $full_name, $username, $password, $national_id, $email, $phone, $address, $city, $assigned_routers]);';

if (strpos($c, 'implode(\',\', $_POST[\'assigned_routers\'])') === false) {
    $c = str_replace($oldInsert, $newInsert, $c);
}

// 2. Fetch Operator's NAS list to show in Add Dealer modal
$nasQuery = '
// Fetch Dealers
$stmt = $pdo->prepare("
    SELECT d.*, 
';
$nasFetch = '
// Fetch Operator NAS
$nasStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$all_nas = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealers
$stmt = $pdo->prepare("
    SELECT d.*, 
';

if (strpos($c, '$all_nas = $nasStmt->fetchAll') === false) {
    $c = str_replace($nasQuery, $nasFetch, $c);
}

// 3. Add the HTML for the multi-select dropdown in the Add Dealer modal
$oldModalHTML = '<div class="col-md-6 mb-3">
                        <label class="form-label text-muted small fw-bold">City</label>
                        <input type="text" name="city" class="form-control">
                    </div>
                </div>';
$newModalHTML = '<div class="col-md-6 mb-3">
                        <label class="form-label text-muted small fw-bold">City</label>
                        <input type="text" name="city" class="form-control">
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label text-muted small fw-bold">Assign MikroTik Routers <span class="text-danger">*</span></label>
                        <select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                            <?php foreach($all_nas as $n): ?>
                                <option value="<?= $n[\'nasname\'] ?>"><?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers. The dealer will only be able to create users on these routers.</div>
                    </div>
                </div>';

if (strpos($c, 'name="assigned_routers[]"') === false) {
    $c = str_replace($oldModalHTML, $newModalHTML, $c);
    file_put_contents($f, $c);
    echo "Added assigned_routers logic to operator/dealers.php\n";
} else {
    echo "Already added to dealers.php\n";
}
?>
