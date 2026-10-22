<?php
// 1. Patch operator/subscriber_add.php
$f1 = 'operator/subscriber_add.php';
$c1 = file_get_contents($f1);
$find1 = <<<'PHP'
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
                    ->execute([$username, $pkg['name']]);
                
                // Only insert custom rate limit into radreply if it's strictly required, 
                // but usually the group handles it. If not unlimited, we could insert, but let's rely on group.
PHP;

$replace1 = <<<'PHP'
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
                    ->execute([$username, $pkg['name']]);
                
                // Always inject fail-safe rate limit in radreply with op '='
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                        ->execute([$username, $pkg['rate_limit']]);
                }
PHP;

if (strpos($c1, $find1) !== false) {
    $c1 = str_replace($find1, $replace1, $c1);
    file_put_contents($f1, $c1);
    echo "1. operator/subscriber_add.php patched successfully.\n";
}

// 2. Patch operator/subscriber_edit.php
$f2 = 'operator/subscriber_edit.php';
$c2 = file_get_contents($f2);
$find2 = <<<'PHP'
        $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $u]);
        $pdo->prepare("UPDATE radreply SET value=? WHERE username=? AND attribute='Mikrotik-Rate-Limit'")->execute([$pkg['rate_limit'], $u]);
PHP;

$replace2 = <<<'PHP'
        $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $u]);
        
        // Sync radusergroup to new package
        $pdo->prepare("DELETE FROM radusergroup WHERE username=?")->execute([$u]);
        $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$u, $pkg['name']]);
        
        // Sync radreply to new package rate limit
        $pdo->prepare("DELETE FROM radreply WHERE username=? AND attribute='Mikrotik-Rate-Limit'")->execute([$u]);
        if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'Unlimited') {
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
        }
PHP;

if (strpos($c2, $find2) !== false) {
    $c2 = str_replace($find2, $replace2, $c2);
    file_put_contents($f2, $c2);
    echo "2. operator/subscriber_edit.php patched successfully.\n";
}

// 3. Patch recoveryman/dashboard.php
$f3 = 'recoveryman/dashboard.php';
$c3 = file_get_contents($f3);
$find3 = <<<'PHP'
        if ($new_package_id > 0) {
            $pdo->prepare("UPDATE subscribers SET package_id = ? WHERE id = ?")->execute([$new_package_id, $sub_id]);
        }
PHP;

$replace3 = <<<'PHP'
        if ($new_package_id > 0) {
            $pdo->prepare("UPDATE subscribers SET package_id = ? WHERE id = ?")->execute([$new_package_id, $sub_id]);
            
            // Sync FreeRADIUS to new package
            $npStmt = $pdo->prepare("SELECT name, rate_limit FROM packages WHERE id = ?");
            $npStmt->execute([$new_package_id]);
            $newPkgInfo = $npStmt->fetch();
            if ($newPkgInfo) {
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$sub_username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$sub_username, $newPkgInfo['name']]);
                
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$sub_username]);
                if (!empty($newPkgInfo['rate_limit']) && $newPkgInfo['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$sub_username, $newPkgInfo['rate_limit']]);
                }
            }
        }
PHP;

if (strpos($c3, $find3) !== false) {
    $c3 = str_replace($find3, $replace3, $c3);
    file_put_contents($f3, $c3);
    echo "3. recoveryman/dashboard.php patched successfully.\n";
}
?>
