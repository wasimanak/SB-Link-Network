<?php
$root = 'C:/xampp/htdocs/SB Link Network/superadmin/';
$files = ['operator_add.php', 'operator_edit.php'];

foreach ($files as $f) {
    $file = $root . $f;
    if (!file_exists($file)) continue;
    
    $content = file_get_contents($file);
    
    // Replace the combined array_merge loop with mapped ones
    $old_loop = <<<'PHP'
                        foreach (array_merge($ppp_users, $hs_users) as $u) {
                            $uname = $u['name'] ?? '';
                            $upass = $u['password'] ?? '';
                            $uprof = $u['profile'] ?? 'default';
                            if (!$uname || $uname === 'default') continue;
                            
                            $uChk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
                            $uChk->execute([$uname]);
                            if (!$uChk->fetch()) {
                                $pkgStmt = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND client_id = ?");
                                $pkgStmt->execute([$uprof, $id]);
                                $pkg = $pkgStmt->fetch();
                                $pkg_id = $pkg ? $pkg['id'] : null;
                                
                                $pdo->prepare("INSERT INTO subscribers (client_id, username, password, full_name, package_id, status) VALUES (?, ?, ?, ?, ?, 'active')")
                                    ->execute([$id, $uname, $upass, $uname, $pkg_id]);
PHP;
    // For operator_add.php, $id is $client_id. Let's make it work for both by using regex or checking which variable is used.
    // Actually, let's use regex.

    $pattern = '/foreach\s*\(\s*array_merge\(\$ppp_users,\s*\$hs_users\)\s*as\s*\$u\s*\)\s*\{.*?\$pdo->prepare\("INSERT INTO subscribers \((.*?)\) VALUES \(\?, \?, \?, \?, \?, \'active\'\)"\)\s*->execute\(\[(\$.*?), \$uname, \$upass, \$uname, \$pkg_id\]\);/s';
    
    $replacement = <<<'PHP'
                        // Tag them with service types
                        $all_users = [];
                        foreach ($ppp_users as $u) { $u['service_type'] = 'pppoe'; $all_users[] = $u; }
                        foreach ($hs_users as $u) { $u['service_type'] = 'hotspot'; $all_users[] = $u; }

                        foreach ($all_users as $u) {
                            $uname = $u['name'] ?? '';
                            $upass = $u['password'] ?? '';
                            $uprof = $u['profile'] ?? 'default';
                            $stype = $u['service_type'];
                            if (!$uname || $uname === 'default') continue;
                            
                            $uChk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
                            $uChk->execute([$uname]);
                            if (!$uChk->fetch()) {
                                $pkgStmt = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND client_id = ?");
                                $pkgStmt->execute([$uprof, $2]);
                                $pkg = $pkgStmt->fetch();
                                $pkg_id = $pkg ? $pkg['id'] : null;
                                
                                $pdo->prepare("INSERT INTO subscribers ($1, service_type) VALUES (?, ?, ?, ?, ?, 'active', ?)")
                                    ->execute([$2, $uname, $upass, $uname, $pkg_id, $stype]);
PHP;

    $content = preg_replace($pattern, $replacement, $content);
    file_put_contents($file, $content);
}

echo "Sync logic updated to properly assign 'pppoe' or 'hotspot' during router linking/sync.\n";
?>
