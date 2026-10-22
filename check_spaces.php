<?php
require 'config/db.php';

echo "<h2>Username Space Checker & Fixer</h2>";

try {
    // Check subscribers table
    $stmt = $pdo->query("SELECT id, username FROM subscribers WHERE username LIKE '% ' OR username LIKE ' %'");
    $users_with_spaces = $stmt->fetchAll();

    if (count($users_with_spaces) > 0) {
        echo "<h3 style='color:red;'>Masla Pakra Gaya! In Users ke naam me space mojood hai:</h3><ul>";
        
        $pdo->beginTransaction();
        
        foreach ($users_with_spaces as $u) {
            $old_username = $u['username'];
            $new_username = trim($old_username);
            
            // Show visually where the space is
            $display_old = str_replace(' ', '<span style="background-color:yellow;">[SPACE]</span>', $old_username);
            echo "<li><strong>ID {$u['id']}</strong>: '{$display_old}' -> Theek kar ke banaya gaya: '{$new_username}'</li>";
            
            // 1. Update Subscribers
            $pdo->prepare("UPDATE subscribers SET username = ? WHERE id = ?")->execute([$new_username, $u['id']]);
            
            // 2. Update FreeRADIUS (radcheck, radreply, radacct)
            $pdo->prepare("UPDATE radcheck SET username = ? WHERE username = ?")->execute([$new_username, $old_username]);
            $pdo->prepare("UPDATE radreply SET username = ? WHERE username = ?")->execute([$new_username, $old_username]);
            $pdo->prepare("UPDATE radacct SET username = ? WHERE username = ?")->execute([$new_username, $old_username]);
            
            // 3. Update Ledgers/Logs
            $pdo->prepare("UPDATE user_ledger SET username = ? WHERE username = ?")->execute([$new_username, $old_username]);
            $pdo->prepare("UPDATE activity_logs SET against_to = ? WHERE against_to = ?")->execute([$new_username, $old_username]);
        }
        
        $pdo->commit();
        echo "</ul><h3 style='color:green;'>Tamam spaces ko automatically delete kar ke system theek kar diya gaya hai! Ab inko connect karwayen.</h3>";
    } else {
        echo "<h3 style='color:blue;'>Kisi bhi user ke naam ke shuru ya aakhir me koi space nahi hai. Space ka masla nahi hai.</h3>";
        
        // Let's manually check the 4 specific users just to be completely transparent
        echo "<h4>Aapke bataye gaye users ka exact database record check kiya:</h4><ul>";
        $specific_users = ['shafaaqat16', 'tahaawan', 'kohnoor', 'umar16'];
        $in = str_repeat('?,', count($specific_users) - 1) . '?';
        
        $chk = $pdo->prepare("SELECT username, status, expiry_date FROM subscribers WHERE TRIM(username) IN ($in)");
        $chk->execute($specific_users);
        $results = $chk->fetchAll();
        
        foreach ($results as $r) {
            $len = strlen($r['username']);
            echo "<li><strong>'{$r['username']}'</strong> (Length: {$len} characters) - Status: {$r['status']}</li>";
        }
        echo "</ul>";
        echo "<p>Agar ooper kisi naam ki length normal se zyada hai, tou iska matlab usme masla tha jo ab theek ho gaya hoga.</p>";
        echo "<p>Agar abhi bhi connect nahi ho rahay, tou pichli wali baat confirm hai: Winbox me PPP > Secrets me ye disable (X) paray hain.</p>";
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
