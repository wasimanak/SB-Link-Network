<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

$oldLogic = 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'action\']) && $_POST[\'action\'] === \'delete_profile\') {
    $pdo->prepare("DELETE FROM dealers WHERE id=?")->execute([$dealer_id]);
    echo "<script>alert(\'Dealer deleted!\'); window.location.href=\'dealers.php\';</script>";
}';

$newLogic = 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'action\']) && $_POST[\'action\'] === \'delete_profile\') {
    // 1. Fetch all users belonging to this dealer
    $stmt = $pdo->prepare("SELECT username FROM subscribers WHERE dealer_id = ?");
    $stmt->execute([$dealer_id]);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 2. Delete all users from RADIUS tables
    if (!empty($users)) {
        $in = str_repeat(\'?,\', count($users) - 1) . \'?\';
        $pdo->prepare("DELETE FROM radcheck WHERE username IN ($in)")->execute($users);
        $pdo->prepare("DELETE FROM radreply WHERE username IN ($in)")->execute($users);
        $pdo->prepare("DELETE FROM radusergroup WHERE username IN ($in)")->execute($users);
        
        // 3. Delete from subscribers table
        $pdo->prepare("DELETE FROM subscribers WHERE dealer_id = ?")->execute([$dealer_id]);
    }
    
    // 4. Finally delete the dealer
    $pdo->prepare("DELETE FROM dealers WHERE id=?")->execute([$dealer_id]);
    
    echo "<script>alert(\'Dealer and all associated users deleted successfully!\'); window.location.href=\'dealers.php\';</script>";
}';

// Some spacing issues might prevent simple str_replace, let's use preg_replace
$pattern = '/if \(\$_SERVER\[\'REQUEST_METHOD\'\] === \'POST\' && isset\(\$_POST\[\'action\'\]\) && \$_POST\[\'action\'\] === \'delete_profile\'\) \{.*?window\.location\.href=\'dealers\.php\';<\/script>";\s*\}/is';
if (preg_match($pattern, $c)) {
    $c = preg_replace($pattern, $newLogic, $c);
    file_put_contents($f, $c);
    echo "operator/dealer_view.php successfully patched with Cascade Delete.\n";
} else {
    echo "Could not find the exact delete_profile block to replace.\n";
}
?>
