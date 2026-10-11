<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Inject PHP Logic for Create User
$search_marker = "// Search Logic";
$pos_search = strpos($c, $search_marker);

$create_user_logic = <<<'PHP'
// Handle Create New User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $mobile = trim($_POST['mobile']);
    $address = trim($_POST['address']);
    $package_id = (int)$_POST['package_id'];
    $amount_collected = (float)$_POST['amount_collected'];

    try {
        $pdo->beginTransaction();
        
        // Check if username exists
        $chk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            throw new Exception("Username '$username' already exists!");
        }

        // Get Package Details
        $pkgStmt = $pdo->prepare("SELECT name, price, rate_limit FROM packages WHERE id = ?");
        $pkgStmt->execute([$package_id]);
        $pkg = $pkgStmt->fetch();
        if (!$pkg) throw new Exception("Invalid package selected.");
        
        $package_price = (float)$pkg['price'];
        
        // 1 Month Expiry
        $now = time();
        $new_expiry_time = strtotime('+1 month', $now);
        $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
        $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);
        
        // Calculate Initial Balance (Collected - Package Price)
        $initial_balance = $amount_collected - $package_price;

        // Insert Subscriber
        $insSub = $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, full_name, mobile, address, service_type, status, balance, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'pppoe', 'active', ?, ?)");
        $insSub->execute([$client_id, $package_id, $username, $password, $full_name, $mobile, $address, $initial_balance, $new_expiry_db]);
        
        // Insert RADIUS basics
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $new_expiry_rad]);
        
        if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
        }
        
        // Ledger Entries
        // A. Package Charge
        $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'debit', ?, ?, ?)")
            ->execute([$client_id, $username, $package_price, "Account Created. Initial Package Charge ({$pkg['name']})", (0 - $package_price)]);
            
        // B. Amount Collected
        if ($amount_collected > 0) {
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $username, $amount_collected, "Initial Payment Collected by RM: " . $_SESSION['rm_username'], $initial_balance]);
                
            // Update RM Cash
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount_collected, $rm_id]);
        }

        // Activity Log
        $activity_msg = "Created New User with {$pkg['name']}. Charged: Rs. $package_price. Collected: Rs. $amount_collected. Expiry: " . date('d M Y', $new_expiry_time);
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $username, $activity_msg]);

        $pdo->commit();
        echo "<script>alert('User Created Successfully!\\nExpiry set to 1 Month.\\nBalance: Rs. $initial_balance'); window.location='dashboard.php';</script>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

// Search Logic
PHP;

$c = substr_replace($c, $create_user_logic, $pos_search, strlen("// Search Logic"));

// 2. Inject UI Button
// Search for `<div class="d-flex align-items-center gap-3">` which usually holds the header actions, or search for `SearchBar` container.
$header_pattern = '/<div class="col-md-8 mx-auto text-center mb-4">.*?<form method="GET"/s';
$pos_header = preg_match($header_pattern, $c, $matches, PREG_OFFSET_CAPTURE);

if ($pos_header) {
    $match_str = $matches[0][0];
    $match_pos = $matches[0][1];
    
    $new_header = <<<'HTML'
<div class="col-md-8 mx-auto text-center mb-4">
            <h5 class="fw-bold text-dark mb-3">Search Subscriber</h5>
            <div class="d-flex justify-content-center gap-2 mb-3">
                <button class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
            </div>
            <form method="GET"
HTML;
    $c = substr_replace($c, $new_header, $match_pos, strlen($match_str));
}

// 3. Inject Add User Modal HTML at the end of the file (before </body>)
$modal_html = <<<'HTML'
<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-primary text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i> Create New User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="create_user">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Full Name</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="John Doe">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Mobile No.</label>
                    <input type="text" name="mobile" class="form-control" placeholder="03001234567">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Username (PPPoE ID)</label>
                    <input type="text" name="username" class="form-control" required placeholder="john123">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Password</label>
                    <input type="text" name="password" class="form-control" required placeholder="pass123">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Street 1, House 2">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Select Package (1 Month Validity)</label>
                    <select name="package_id" class="form-select fw-bold text-primary" required>
                        <option value="">-- Choose Package --</option>
                        <?php foreach($all_packages as $pkg): ?>
                            <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Price: Rs <?= number_format($pkg['price']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Amount Collected Now (Optional)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">Rs.</span>
                        <input type="number" step="0.01" name="amount_collected" class="form-control" value="0" placeholder="0.00">
                    </div>
                    <small class="text-muted">If you don't collect the full package price, the remaining will be saved as a Minus (-) Balance for this user.</small>
                </div>
            </div>
        </div>
        <div class="modal-footer px-4 py-3 bg-white border-top">
            <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary fw-bold px-5 shadow-sm"><i class="fa-solid fa-check me-1"></i> Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

</body>
HTML;
$c = str_replace('</body>', $modal_html, $c);

file_put_contents($f, $c);
echo "Successfully injected Create User logic and UI.\n";
?>
