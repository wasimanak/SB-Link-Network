<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

// 1. Inject ALTER TABLE and column defaults if needed
$dbFix = '
try {
    $pdo->exec("ALTER TABLE dealers ADD COLUMN status ENUM(\'active\', \'disabled\') DEFAULT \'active\'");
} catch (Exception $e) {}
';

if (strpos($c, 'ALTER TABLE dealers ADD COLUMN status') === false) {
    // Put it right after header inclusion
    $c = preg_replace('/(require_once \'header.php\';)/i', "$1\n$dbFix", $c);
}

// 2. Inject Toggle Status Logic
$toggleLogic = '
// Handle Toggle Status
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'action\']) && $_POST[\'action\'] === \'toggle_status\') {
    $newStatus = ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'disabled\' : \'active\';
    
    $pdo->prepare("UPDATE dealers SET status = ? WHERE id = ?")->execute([$newStatus, $dealer_id]);
    
    if ($newStatus === \'disabled\') {
        $pdo->prepare("UPDATE subscribers SET status = \'disabled\' WHERE dealer_id = ? AND status = \'active\'")->execute([$dealer_id]);
        
        $stmt = $pdo->prepare("SELECT username FROM subscribers WHERE dealer_id = ?");
        $stmt->execute([$dealer_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($users)) {
            $in = str_repeat(\'?,\', count($users) - 1) . \'?\';
            $pdo->prepare("DELETE FROM radcheck WHERE attribute = \'Auth-Type\' AND value = \'Reject\' AND username IN ($in)")->execute($users);
            
            $insertQ = "INSERT INTO radcheck (username, attribute, op, value) VALUES ";
            $insertData = [];
            foreach($users as $u) {
                $insertQ .= "(?, \'Auth-Type\', \':=\', \'Reject\'),";
                array_push($insertData, $u);
            }
            $insertQ = rtrim($insertQ, \',\');
            $pdo->prepare($insertQ)->execute($insertData);
        }
        $msg = "Dealer DISABLED. All associated users have been blocked from internet access.";
    } else {
        $pdo->prepare("UPDATE subscribers SET status = \'active\' WHERE dealer_id = ? AND (expiry_date IS NULL OR expiry_date > NOW())")->execute([$dealer_id]);
        
        $stmt = $pdo->prepare("SELECT username FROM subscribers WHERE dealer_id = ? AND (expiry_date IS NULL OR expiry_date > NOW())");
        $stmt->execute([$dealer_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($users)) {
            $in = str_repeat(\'?,\', count($users) - 1) . \'?\';
            $pdo->prepare("DELETE FROM radcheck WHERE attribute = \'Auth-Type\' AND value = \'Reject\' AND username IN ($in)")->execute($users);
        }
        $msg = "Dealer ENABLED. Valid users have been restored.";
    }
    echo "<script>alert(\'$msg\'); window.location.href=\'dealer_view.php?id=$dealer_id\';</script>";
}
';

if (strpos($c, 'toggle_status') === false) {
    $c = str_replace('// Handle Delete Profile', $toggleLogic . "\n// Handle Delete Profile", $c);
}

// 3. Inject UI Button next to Delete Profile
$btnHTML = '
<form method="POST" class="m-0 p-0" onsubmit="return confirm(\'Are you sure you want to <?= ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'DISABLE\' : \'ENABLE\' ?> this dealer? <?= ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'This will block ALL their users.\' : \'\' ?>\');">
    <input type="hidden" name="action" value="toggle_status">
    <button type="submit" class="btn w-100 h-100 <?= ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'text-warning\' : \'text-success\' ?>"><i class="fa-solid <?= ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'fa-user-lock\' : \'fa-user-check\' ?>"></i> <?= ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'Disable<br>Dealer\' : \'Enable<br>Dealer\' ?></button>
</form>
';

// Find the form for delete_profile and add our form before it
$pattern = '/(<form method="POST" class="m-0 p-0" onsubmit="return confirm\(\'Are you sure you want to completely delete this dealer profile\?\'\);">)/is';
if (preg_match($pattern, $c) && strpos($c, 'toggle_status') !== false && strpos($c, 'fa-user-lock') === false) {
    $c = preg_replace($pattern, $btnHTML . "\n                  $1", $c);
}

// 4. Update the Dealer Details block to show the status badge
$statusBadge = '
<div class="d-flex justify-content-between mb-2">
    <span class="text-secondary">System Status:</span>
    <span class="fw-bold <?= ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'text-success\' : \'text-danger\' ?>">
        <?= strtoupper($dealer[\'status\'] ?? \'active\') ?>
    </span>
</div>
';

$c = preg_replace('/(<div class="d-flex justify-content-between mb-2">\s*<span class="text-secondary">Date Added:<\/span>)/is', $statusBadge . "\n                            $1", $c);

file_put_contents($f, $c);
echo "operator/dealer_view.php patched successfully with Dealer Enable/Disable feature.\n";
?>
