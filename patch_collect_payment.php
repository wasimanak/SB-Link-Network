<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Add packages fetch logic near the top
$pkgFetch = '
$pkgsStmt = $pdo->prepare("SELECT id, name, price FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgsStmt->execute([$client_id]);
$all_packages = $pkgsStmt->fetchAll(PDO::FETCH_ASSOC);
';
if (strpos($c, '$all_packages') === false) {
    $c = preg_replace('/(\/\/ Check RM permissions)/i', "$pkgFetch\n$1", $c);
}

// 2. Update receive_payment PHP block
$oldReceive = '$sub_username = $_POST[\'subscriber_username\'];
    $amount = (float)$_POST[\'amount\'];
    $note = trim($_POST[\'note\']);

    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            
            // Fetch current expiry and package price
            $stmt = $pdo->prepare("SELECT s.expiry_date, p.price FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
            $stmt->execute([$sub_id]);
            $subData = $stmt->fetch();';

$newReceive = '$sub_username = $_POST[\'subscriber_username\'];
    $amount = (float)$_POST[\'amount\'];
    $new_package_id = isset($_POST[\'new_package_id\']) && $_POST[\'new_package_id\'] !== \'\' ? (int)$_POST[\'new_package_id\'] : 0;
    $note = trim($_POST[\'note\']);

    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            
            if ($new_package_id > 0) {
                $pdo->prepare("UPDATE subscribers SET package_id = ? WHERE id = ?")->execute([$new_package_id, $sub_id]);
            }
            
            // Fetch current expiry and package price
            $stmt = $pdo->prepare("SELECT s.expiry_date, p.price FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
            $stmt->execute([$sub_id]);
            $subData = $stmt->fetch();';

if (strpos($c, '$new_package_id') === false) {
    $c = str_replace($oldReceive, $newReceive, $c);
}

// 3. Update the HTML Form
$oldForm = '<input type="hidden" name="action" value="receive_payment">
                    <input type="hidden" name="subscriber_id" id="m_subid">
                    <input type="hidden" name="subscriber_username" id="m_subuser">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary">Enter Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold text-success text-center" placeholder="e.g. 1500" required>
                    </div>';

$newForm = '<input type="hidden" name="action" value="receive_payment">
                    <input type="hidden" name="subscriber_id" id="m_subid">
                    <input type="hidden" name="subscriber_username" id="m_subuser">
                    
                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-secondary small">Change Package (Optional)</label>
                        <select name="new_package_id" id="m_newpkg" class="form-select fw-bold text-primary" onchange="updatePkgAmount()">
                            <option value="">-- Keep Current Package --</option>
                            <?php foreach($all_packages as $pkg): ?>
                                <option value="<?= $pkg[\'id\'] ?>" data-price="<?= $pkg[\'price\'] ?>"><?= htmlspecialchars($pkg[\'name\']) ?> (Rs <?= number_format($pkg[\'price\']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-secondary small">Enter Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="m_amount" class="form-control form-control-lg fw-bold text-success text-center" placeholder="e.g. 1500" required>
                        <div class="form-text text-muted text-center" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info"></i> Expiry will be extended automatically based on amount vs package price.</div>
                    </div>';

if (strpos($c, 'Change Package (Optional)') === false) {
    $c = str_replace($oldForm, $newForm, $c);
}

// 4. Add JS function
$jsFunction = '
<script>
function updatePkgAmount() {
    let sel = document.getElementById(\'m_newpkg\');
    let opt = sel.options[sel.selectedIndex];
    if(opt.value !== "") {
        let price = opt.getAttribute(\'data-price\');
        document.getElementById(\'m_amount\').value = price;
    }
}
</script>
';
if (strpos($c, 'updatePkgAmount()') === false) {
    $c = str_replace('</body>', $jsFunction . "\n</body>", $c);
}

file_put_contents($f, $c);
echo "Added Package Selection to Collect Payment Dialog.\n";
?>
