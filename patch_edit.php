<?php
$f = 'operator/subscriber_edit.php';
$c = file_get_contents($f);

// Fix the DB update logic
$find = <<<'PHP'
    if ($pkg && $u) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE subscribers SET password=?, package_id=?, expiry_date=? WHERE id=?")->execute([$password, $package_id, $expiry, $id]);
        $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $u]);
        $pdo->prepare("UPDATE radreply SET value=? WHERE username=? AND attribute='Mikrotik-Rate-Limit'")->execute([$pkg['rate_limit'], $u]);
        $pdo->commit();
        header("Location: subscribers.php");
        exit;
    }
PHP;

$replace = <<<'PHP'
    if ($pkg && $u) {
        $pdo->beginTransaction();
        
        // Auto-determine status based on expiry
        $status = 'active';
        if ($expiry && strtotime($expiry) < time()) {
            $status = 'expired';
        }
        
        $pdo->prepare("UPDATE subscribers SET password=?, package_id=?, expiry_date=?, status=? WHERE id=?")->execute([$password, $package_id, $expiry, $status, $id]);
        $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $u]);
        $pdo->prepare("UPDATE radreply SET value=? WHERE username=? AND attribute='Mikrotik-Rate-Limit'")->execute([$pkg['rate_limit'], $u]);
        
        // Fix RADIUS Expiration and Auth-Type rules
        $pdo->prepare("DELETE FROM radcheck WHERE username=? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
        if ($expiry) {
            $formatted_expiry = date('d M Y H:i:s', strtotime($expiry));
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
        }
        
        if ($status === 'expired') {
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
        }

        $pdo->commit();
        header("Location: subscribers.php");
        exit;
    }
PHP;

$c = str_replace($find, $replace, $c);
file_put_contents($f, $c);
echo "operator/subscriber_edit.php patched locally.\n";
?>
