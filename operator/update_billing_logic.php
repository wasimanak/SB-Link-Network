<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/subscribers.php';
$content = file_get_contents($file);

// Add dealer fetching to the top
$dealerFetchCode = <<<'PHP'
// Fetch Dealers for Dropdown
$dealerStmt = $pdo->prepare("SELECT id, username, full_name, balance FROM dealers WHERE client_id = ? AND status = 'active'");
$dealerStmt->execute([$client_id]);
$dealers = $dealerStmt->fetchAll();
PHP;

if (strpos($content, '$dealers = $dealerStmt') === false) {
    $content = preg_replace('/(\/\/ Fetch Packages for Dropdown)/', $dealerFetchCode . "\n\n$1", $content);
}

// Modify the 'renew_user' logic to calculate and deduct dealer balance
$oldRenewLogicStart = <<<'PHP'
            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
PHP;
$newRenewLogicStart = <<<'PHP'
            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    
                    // Deduct balance if user belongs to a dealer
                    $dStmt = $pdo->prepare("SELECT dealer_id FROM subscribers WHERE id = ?");
                    $dStmt->execute([$id]);
                    $sub_dealer_id = $dStmt->fetchColumn();
                    
                    if ($sub_dealer_id) {
                        // Find price
                        $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $dpStmt->execute([$sub_dealer_id, $package_id]);
                        $dp_price = $dpStmt->fetchColumn();
                        if ($dp_price === false) {
                            $dp_price = $pkg['price'] ?? 0;
                        }
                        
                        // Calculate days
                        $seconds = strtotime($expiry_date) - time();
                        if ($seconds > 0) {
                            $days = ceil($seconds / 86400); // total days
                            $price_per_day = $dp_price / 30; // standard 30 day month
                            $deduction = round($days * $price_per_day, 2);
                            
                            if ($deduction > 0) {
                                // Deduct from dealer
                                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $sub_dealer_id]);
                                
                                // Record in dealer_ledger if exists, or just dealer_notes
                                $note = "Renewed user $u for $days days. Deducted Rs. $deduction";
                                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                            }
                        }
                    }

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
PHP;
$content = str_replace($oldRenewLogicStart, $newRenewLogicStart, $content);

// Modify the 'add_user' logic to deduct dealer balance and accept dealer_id
$oldAddUserTop = <<<'PHP'
    // ADD USER (From Modal)
    if ($action === 'add_user') {
        $full_name = trim($_POST['full_name']);
PHP;
$newAddUserTop = <<<'PHP'
    // ADD USER (From Modal)
    if ($action === 'add_user') {
        $dealer_id = isset($_POST['dealer_id']) ? (int)$_POST['dealer_id'] : 0;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $full_name = trim($_POST['full_name']);
PHP;
$content = str_replace($oldAddUserTop, $newAddUserTop, $content);

$oldAddUserInsert = <<<'PHP'
            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$client_id, $package_id, $username, $password, $service_type, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude]);
PHP;
$newAddUserInsert = <<<'PHP'
            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    
                    if ($dealer_id > 0) {
                        // Find price
                        $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $dpStmt->execute([$dealer_id, $package_id]);
                        $dp_price = $dpStmt->fetchColumn();
                        if ($dp_price === false) {
                            $dp_price = $pkg['price'] ?? 0;
                        }
                        
                        $deduction = 0;
                        if ($expiry_date) {
                            $seconds = strtotime($expiry_date) - time();
                            if ($seconds > 0) {
                                $days = ceil($seconds / 86400);
                                $price_per_day = $dp_price / 30;
                                $deduction = round($days * $price_per_day, 2);
                            }
                        } else {
                            $deduction = $dp_price; // Standard 1 month deduction
                        }
                        
                        if ($deduction > 0) {
                            $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                            $note = "Created user $username. Deducted Rs. $deduction";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                        }
                    }

                    $pdo->prepare("INSERT INTO subscribers (client_id, dealer_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$client_id, $dealer_id > 0 ? $dealer_id : null, $package_id, $username, $password, $service_type, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude, $expiry_date]);
PHP;
$content = str_replace($oldAddUserInsert, $newAddUserInsert, $content);

// Update Add User HTML to include Dealer dropdown and Expiry date
$oldAddUserHtml = <<<'HTML'
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                                    <select name="package_id" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php foreach($packages as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
HTML;
$newAddUserHtml = <<<'HTML'
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                                    <select name="package_id" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php foreach($packages as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Assign Dealer (Optional)</label>
                                    <select name="dealer_id" class="form-select">
                                        <option value="">None</option>
                                        <?php foreach($dealers as $d): ?>
                                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['username']) ?> (Bal: Rs.<?= $d['balance'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Custom Expiry (Optional)</label>
                                    <input type="datetime-local" name="expiry_date" class="form-control">
                                    <small class="text-muted">Leave blank for standard 30 days or no expiry.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
HTML;

if (strpos($content, 'name="dealer_id"') === false) {
    // Note: Since we flattened the form in previous step, the HTML structure changed!
    // The previous structure was flattened to removing accordion items.
    // Let's replace by looking for the Select Package field.
    $search = <<<'HTML'
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                                    <select name="package_id" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php foreach($packages as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
HTML;
    $replace = <<<'HTML'
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                                    <select name="package_id" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php foreach($packages as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Assign Dealer (Optional)</label>
                                    <select name="dealer_id" class="form-select">
                                        <option value="">None</option>
                                        <?php foreach($dealers as $d): ?>
                                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (Bal: <?= $d['balance'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Custom Expiry (Optional)</label>
                                    <input type="datetime-local" name="expiry_date" class="form-control">
                                    <small class="text-muted" style="font-size: 0.75rem;">If dealer selected, balance deducts by days.</small>
                                </div>
                            </div>
HTML;
    $content = str_replace($search, $replace, $content);
}

file_put_contents($file, $content);
echo "Updated backend logic for dynamic billing in subscribers.php\n";

// Now apply the same logic changes to dashboard.php if needed, since it also handles Add User.
$file2 = 'C:/xampp/htdocs/SB Link Network/operator/dashboard.php';
$content2 = file_get_contents($file2);
if (strpos($content2, '$dealers = $dealerStmt') === false) {
    $content2 = preg_replace('/(\/\/ Fetch Packages for Dropdown)/', $dealerFetchCode . "\n\n$1", $content2);
    $content2 = str_replace($oldAddUserTop, $newAddUserTop, $content2);
    $content2 = str_replace($oldAddUserInsert, $newAddUserInsert, $content2);
    $content2 = str_replace($search, $replace, $content2);
    file_put_contents($file2, $content2);
    echo "Updated backend logic for dynamic billing in dashboard.php\n";
}

?>
