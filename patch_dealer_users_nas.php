<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

// 1. Backend logic for add_user
$oldAddBackend = 'if ($pkg) {
            try {
                $pdo->beginTransaction();';

$newAddBackend = '$router_nasname = trim($_POST[\'router_nasname\'] ?? \'\');
        if (empty($router_nasname)) {
            echo "<script>alert(\'Please select a router for this user.\'); window.location=\'users.php\';</script>";
            exit;
        }
        
        if ($pkg) {
            try {
                $pdo->beginTransaction();';

if (strpos($c, 'router_nasname') === false) {
    $c = str_replace($oldAddBackend, $newAddBackend, $c);
}

// 2. Radcheck Insert logic
$oldRadcheck = '$pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, \'Cleartext-Password\', \':=\', ?)")->execute([$username, $password]);';
$newRadcheck = '$pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, \'Cleartext-Password\', \':=\', ?)")->execute([$username, $password]);
                
                // Enforce the specific router (NAS) for this user
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, \'NAS-IP-Address\', \'==\', ?)")->execute([$username, $router_nasname]);';

if (strpos($c, '\'NAS-IP-Address\'') === false) {
    $c = str_replace($oldRadcheck, $newRadcheck, $c);
}

// 3. Fetch Dealer's assigned routers at the top of the file
$topFetch = '// Fetch Dealer Packages for Modals';
$newTopFetch = '// Parse Dealer\'s Assigned Routers
$assigned_nas_ips = array_filter(explode(\',\', $current_dealer[\'assigned_routers\'] ?? \'\'));
$dealer_routers = [];
if (!empty($assigned_nas_ips)) {
    $in = str_repeat(\'?,\', count($assigned_nas_ips) - 1) . \'?\';
    $rStmt = $pdo->prepare("SELECT nasname, shortname FROM nas WHERE nasname IN ($in)");
    $rStmt->execute($assigned_nas_ips);
    $dealer_routers = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Dealer Packages for Modals';

if (strpos($c, 'Parse Dealer\'s Assigned Routers') === false) {
    $c = str_replace($topFetch, $newTopFetch, $c);
}

// 4. Update the HTML Modal for Add User
$oldPackageSelect = '<select name="package_id" id="add_package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p[\'id\'] ?>"><?= htmlspecialchars($p[\'name\']) ?> (Rs.<?= $p[\'dealer_price\'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>';
$newPackageSelect = '<select name="package_id" id="add_package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p[\'id\'] ?>"><?= htmlspecialchars($p[\'name\']) ?> (Rs.<?= $p[\'dealer_price\'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Select Router / City <span class="text-danger">*</span></label>
                <select name="router_nasname" class="form-select" required>
                    <?php if(empty($dealer_routers)): ?>
                        <option value="">No Routers Assigned (Contact Operator)</option>
                    <?php else: ?>
                        <?php foreach($dealer_routers as $r): ?>
                            <option value="<?= $r[\'nasname\'] ?>"><?= htmlspecialchars($r[\'shortname\'] ?: \'Router\') ?> (<?= $r[\'nasname\'] ?>)</option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>';

if (strpos($c, 'name="router_nasname"') === false) {
    $c = str_replace($oldPackageSelect, $newPackageSelect, $c);
    file_put_contents($f, $c);
    echo "Added router selection to dealer/users.php\n";
} else {
    echo "Already added to dealer/users.php\n";
}
?>
