<?php
require 'config/db.php';
$target = 'shafaaqat16';

echo "<h2>Diagnostic Report for '$target'</h2>";

try {
    // 1. Check Subscribers
    echo "<h3>1. Subscribers Table</h3><table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    $stmt = $pdo->prepare("SELECT id, username, status, expiry_date, password, package_id FROM subscribers WHERE username = ?");
    $stmt->execute([$target]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($sub) {
        foreach ($sub as $k => $v) { echo "<tr><th>$k</th><td>$v</td></tr>"; }
    } else {
        echo "<tr><td>NOT FOUND IN SUBSCRIBERS TABLE!</td></tr>";
    }
    echo "</table>";

    // 2. Check Radcheck
    echo "<h3>2. radcheck Table (FreeRADIUS Authentication)</h3><table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    $stmt = $pdo->prepare("SELECT attribute, op, value FROM radcheck WHERE username = ?");
    $stmt->execute([$target]);
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($checks) {
        foreach ($checks as $c) { echo "<tr><td>{$c['attribute']}</td><td>{$c['op']}</td><td>{$c['value']}</td></tr>"; }
    } else {
        echo "<tr><td colspan='3'>NOT FOUND IN RADCHECK! (This is why it fails)</td></tr>";
    }
    echo "</table>";

    // 3. Check Radreply
    echo "<h3>3. radreply Table (FreeRADIUS Limits)</h3><table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    $stmt = $pdo->prepare("SELECT attribute, op, value FROM radreply WHERE username = ?");
    $stmt->execute([$target]);
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($replies) {
        foreach ($replies as $r) { echo "<tr><td>{$r['attribute']}</td><td>{$r['op']}</td><td>{$r['value']}</td></tr>"; }
    } else {
        echo "<tr><td colspan='3'>No reply attributes found (Usually fine).</td></tr>";
    }
    echo "</table>";

    // 4. Check Radacct (Ghost Sessions)
    echo "<h3>4. radacct Table (Ghost Sessions / Simultaneous Use)</h3><table border='1' cellpadding='5' style='border-collapse: collapse;'><tr><th>Acct Session ID</th><th>Start Time</th><th>Stop Time</th><th>Terminate Cause</th></tr>";
    $stmt = $pdo->prepare("SELECT acctsessionid, acctstarttime, acctstoptime, acctterminatecause FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT 5");
    $stmt->execute([$target]);
    $accts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ghost_found = false;
    if ($accts) {
        foreach ($accts as $a) {
            $stop = $a['acctstoptime'] ? $a['acctstoptime'] : "<strong style='color:red;'>NULL (STILL ONLINE)</strong>";
            if (!$a['acctstoptime']) $ghost_found = true;
            echo "<tr><td>{$a['acctsessionid']}</td><td>{$a['acctstarttime']}</td><td>$stop</td><td>{$a['acctterminatecause']}</td></tr>";
        }
    } else {
        echo "<tr><td colspan='4'>No sessions found.</td></tr>";
    }
    echo "</table>";

    // 5. Automatic fixes based on findings
    echo "<h3>5. Automatic Fixes Applied</h3><ul>";
    if ($ghost_found) {
        $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL")->execute([$target]);
        echo "<li><strong style='color:green;'>Fixed:</strong> Closed active ghost sessions in radacct. This fixes 'Simultaneous Use' or 'Already Online' errors.</li>";
    }
    
    // Check if Cleartext-Password is missing or mismatched
    $has_pw = false;
    foreach ($checks as $c) {
        if ($c['attribute'] === 'Cleartext-Password') {
            $has_pw = true;
            if ($sub && $c['value'] !== $sub['password']) {
                $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$sub['password'], $target]);
                echo "<li><strong style='color:green;'>Fixed:</strong> Password mismatch between subscribers and radcheck corrected.</li>";
            }
        }
        if ($c['attribute'] === 'Auth-Type' && $c['value'] === 'Reject') {
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$target]);
            echo "<li><strong style='color:green;'>Fixed:</strong> Stray Auth-Type Reject rule deleted.</li>";
        }
    }
    if (!$has_pw && $sub) {
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$target, $sub['password']]);
        echo "<li><strong style='color:green;'>Fixed:</strong> Inserted missing Cleartext-Password into radcheck.</li>";
    }
    echo "</ul><p>Please try connecting the user now!</p>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
