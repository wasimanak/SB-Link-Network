<?php
require 'config/db.php';
$target = 'shafaaqat16';

echo "<h2>Deep Diagnostic & Instant Test for '$target'</h2>";

try {
    // 1. Show all radcheck rows
    echo "<h3>1. radcheck Table</h3><table border='1' cellpadding='5'>";
    $stmt = $pdo->prepare("SELECT * FROM radcheck WHERE username = ?");
    $stmt->execute([$target]);
    $checks = $stmt->fetchAll();
    foreach ($checks as $c) {
        echo "<tr><td>{$c['id']}</td><td>{$c['username']}</td><td>{$c['attribute']}</td><td>{$c['op']}</td><td>{$c['value']}</td></tr>";
    }
    echo "</table>";

    // 2. Check radusergroup & radgroupcheck
    echo "<h3>2. Group Tables</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radusergroup WHERE username = ?");
    $stmt->execute([$target]);
    $groups = $stmt->fetchAll();
    if ($groups) {
        echo "<h4>User Groups:</h4>";
        print_r($groups);
        foreach ($groups as $g) {
            $gStmt = $pdo->prepare("SELECT * FROM radgroupcheck WHERE groupname = ?");
            $gStmt->execute([$g['groupname']]);
            echo "<h4>radgroupcheck for '{$g['groupname']}':</h4>";
            print_r($gStmt->fetchAll());
        }
    } else {
        echo "<p>No groups assigned in radusergroup.</p>";
    }

    // 3. CLEAN radcheck for shafaaqat16 (Keep ONLY Cleartext-Password := 1234)
    echo "<h3>3. Aggressive Reset of radcheck for '$target'</h3>";
    $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$target]);
    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', '1234')")->execute([$target]);
    echo "<p style='color:green;'>All extra attributes deleted. Only 'Cleartext-Password := 1234' is now saved in radcheck.</p>";

    // 4. Run radtest directly from the VPS to get exact response
    echo "<h3>4. Live radtest from VPS CLI</h3>";
    // Check NAS secret from nas table
    $nasStmt = $pdo->query("SELECT secret FROM nas LIMIT 1");
    $nasSecret = $nasStmt->fetchColumn() ?: 'testing123';
    
    $cmd = "radtest $target 1234 127.0.0.1 0 $nasSecret 2>&1";
    $output = shell_exec($cmd);
    echo "<pre style='background:#1e1e1e; color:#00ff00; padding:10px;'>Command: $cmd\n\n" . htmlspecialchars($output) . "</pre>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
