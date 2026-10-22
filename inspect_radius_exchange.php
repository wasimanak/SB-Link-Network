<?php
require 'config/db.php';

echo "<h2>Live RADIUS Packet & Exchange Inspector</h2>";

try {
    // 1. Get the latest 3 users from radpostauth
    $stmt = $pdo->query("SELECT * FROM radpostauth ORDER BY id DESC LIMIT 5");
    $recent_auths = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>1. What MikroTik Sent to FreeRADIUS (Latest 5 Connections from radpostauth):</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-family:monospace;'>";
    echo "<tr style='background:#333; color:#fff;'><th>ID</th><th>Username Sent by MikroTik</th><th>Password Sent by MikroTik</th><th>RADIUS Decision</th><th>Time</th></tr>";
    foreach ($recent_auths as $r) {
        $color = ($r['reply'] == 'Access-Accept') ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$r['id']}</td>";
        echo "<td><strong>'{$r['username']}'</strong></td>";
        echo "<td>'{$r['pass']}'</td>";
        echo "<td style='color:$color; font-weight:bold;'>{$r['reply']}</td>";
        echo "<td>{$r['authdate']}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // 2. What FreeRADIUS Sent Back in Reply (Access-Accept Packet Attributes)
    if (!empty($recent_auths)) {
        $sample_user = $recent_auths[0]['username'];
        echo "<h3>2. What FreeRADIUS Sends Back to MikroTik for '$sample_user' (Access-Accept Payload):</h3>";

        // Fetch User Group
        $gStmt = $pdo->prepare("SELECT groupname FROM radusergroup WHERE username = ?");
        $gStmt->execute([$sample_user]);
        $groups = $gStmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch Individual Reply Attributes (radreply)
        $rStmt = $pdo->prepare("SELECT attribute, op, value FROM radreply WHERE username = ?");
        $rStmt->execute([$sample_user]);
        $ind_replies = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Group Reply Attributes (radgroupreply)
        $grp_replies = [];
        if (!empty($groups)) {
            $in = str_repeat('?,', count($groups) - 1) . '?';
            $grStmt = $pdo->prepare("SELECT groupname, attribute, op, value FROM radgroupreply WHERE groupname IN ($in)");
            $grStmt->execute($groups);
            $grp_replies = $grStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-family:monospace;'>";
        echo "<tr style='background:#0d6efd; color:#fff;'><th>Source</th><th>RADIUS Attribute</th><th>OP</th><th>Value Sent to MikroTik</th></tr>";
        
        $has_attributes = false;
        foreach ($ind_replies as $ir) {
            $has_attributes = true;
            echo "<tr><td>Individual (radreply)</td><td><strong>{$ir['attribute']}</strong></td><td>{$ir['op']}</td><td style='color:green; font-weight:bold;'>{$ir['value']}</td></tr>";
        }
        foreach ($grp_replies as $gr) {
            $has_attributes = true;
            echo "<tr><td>Package Group: {$gr['groupname']} (radgroupreply)</td><td><strong>{$gr['attribute']}</strong></td><td>{$gr['op']}</td><td style='color:purple; font-weight:bold;'>{$gr['value']}</td></tr>";
        }

        if (!$has_attributes) {
            echo "<tr><td colspan='4' style='color:red;'>NO ATTRIBUTES FOUND IN DATABASE! FreeRADIUS sends an empty reply!</td></tr>";
        }
        echo "</table>";
    }

    // 3. Live Protocol Test using radtest -x (Raw UDP Packet Decode)
    echo "<h3>3. Live Raw UDP Packet Decode (Simulation for '$sample_user'):</h3>";
    $test_pass = !empty($recent_auths) ? $recent_auths[0]['pass'] : '1234';
    
    // Try testing with testing123, then fallback to nas secret
    $nasSecret = $pdo->query("SELECT secret FROM nas LIMIT 1")->fetchColumn() ?: 'testing123';
    
    $cmd = "radtest -x " . escapeshellarg($sample_user) . " " . escapeshellarg($test_pass) . " 127.0.0.1 0 testing123 2>&1";
    $output = shell_exec($cmd);
    
    if (strpos($output, 'No reply') !== false || empty($output)) {
        $cmd = "radtest -x " . escapeshellarg($sample_user) . " " . escapeshellarg($test_pass) . " 127.0.0.1 0 " . escapeshellarg($nasSecret) . " 2>&1";
        $output = shell_exec($cmd);
    }

    echo "<pre style='background:#1e1e1e; color:#00ff00; padding:15px; border-radius:8px; overflow-x:auto;'>";
    echo htmlspecialchars($output ?: "radtest command not available or no reply from localhost.");
    echo "</pre>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
