<?php
echo "<h2>SB Link Network - Auto Fixer (Dropdown Version)</h2>";

// 1. Clean Database
require "config/db.php";
$stmt = $pdo->query("SELECT id, assigned_routers FROM dealers WHERE assigned_routers != ''");
$cleaned = 0;
while($row = $stmt->fetch()) {
    $routers = explode(',', $row['assigned_routers']);
    $new_routers = [];
    foreach($routers as $r) {
        $r = trim($r);
        if (filter_var($r, FILTER_VALIDATE_IP)) { continue; } // Remove IPs
        if (!empty($r)) { $new_routers[] = $r; }
    }
    $new_val = implode(',', $new_routers);
    if ($new_val !== $row['assigned_routers']) {
        $pdo->prepare("UPDATE dealers SET assigned_routers = ? WHERE id = ?")->execute([$new_val, $row['id']]);
        $cleaned++;
    }
}
echo "<p>✅ Database cleaned ($cleaned dealers fixed).</p>";

// 2. Fix dealer_view.php (Dropdown)
$f1 = 'operator/dealer_view.php';
if (file_exists($f1)) {
    $c1 = file_get_contents($f1);
    
    // First ensure strict IDs matching
    $c1 = preg_replace('/in_array\(\$n\[\'id\'\], \$curr_routers\) \|\| in_array\(\$n\[\'nasname\'\], \$curr_routers\)/i', 'in_array($n[\'id\'], $curr_routers)', $c1);
    
    // Convert checkboxes to select
    $pattern1 = '/<div class="border rounded p-2 bg-light shadow-sm".*?<\/div>.*?<div class="form-text text-muted">.*?<\/div>/s';
    $multiSelect1 = '<select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                        <?php foreach($all_nas as $n): ?>
                            <option value="<?= $n[\'id\'] ?>" <?= (in_array($n[\'id\'], $curr_routers)) ? \'selected\' : \'\' ?>>
                                <?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers. The dealer will only be able to create users on these routers.</div>';
    
    if (preg_match($pattern1, $c1)) {
        $c1 = preg_replace($pattern1, $multiSelect1, $c1);
    }
    file_put_contents($f1, $c1);
    echo "<p>✅ operator/dealer_view.php patched to Dropdown.</p>";
}

// 3. Fix dealers.php (Dropdown)
$f2 = 'operator/dealers.php';
if (file_exists($f2)) {
    $c2 = file_get_contents($f2);
    
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

    // Remove old checkbox div if it exists
    $pattern2 = '/<div class="border rounded p-2 bg-light shadow-sm".*?<\/div>.*?<div class="form-text text-muted">.*?<\/div>/s';
    if (preg_match($pattern2, $c2)) {
        $c2 = preg_replace($pattern2, '', $c2);
    }

    if (strpos($c2, 'name="assigned_routers[]"') === false) {
        $c2 = str_replace('</div>
        <div class="modal-footer', $routersField . "\n" . '        </div>
        <div class="modal-footer', $c2);
    }
    file_put_contents($f2, $c2);
    echo "<p>✅ operator/dealers.php patched to Dropdown.</p>";
}

// 4. Fix dealer/users.php
$f3 = 'dealer/users.php';
if (file_exists($f3)) {
    $c3 = file_get_contents($f3);
    $oldQ = 'SELECT id, nasname, shortname FROM nas WHERE id IN ($in) OR nasname IN ($in)';
    $newQ = 'SELECT id, nasname, shortname FROM nas WHERE id IN ($in)';
    $c3 = str_replace($oldQ, $newQ, $c3);
    
    $oldestQ = 'SELECT nasname, shortname FROM nas WHERE nasname IN ($in)';
    $c3 = str_replace($oldestQ, $newQ, $c3);
    
    file_put_contents($f3, $c3);
    echo "<p>✅ dealer/users.php patched.</p>";
}

echo "<h3>🎉 All Done! Please do a Hard Refresh (CTRL + F5) in your Operator Panel.</h3>";
?>
