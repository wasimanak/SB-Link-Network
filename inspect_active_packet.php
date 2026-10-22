<?php
require 'config/db.php';

// Accept user from GET or default to aqeelshah
$target_user = $_GET['user'] ?? 'aqeelshah';

echo "<h2>Live Packet Decode for Active User: '$target_user'</h2>";

try {
    // 1. Get Password and Group for this user
    $pStmt = $pdo->prepare("SELECT password FROM subscribers WHERE username = ?");
    $pStmt->execute([$target_user]);
    $pass = $pStmt->fetchColumn() ?: '1234';

    echo "<h3>1. Attributes in Database for '$target_user':</h3>";
    
    // Check radreply
    $rStmt = $pdo->prepare("SELECT * FROM radreply WHERE username = ?");
    $rStmt->execute([$target_user]);
    $replies = $rStmt->fetchAll();

    // Check radgroupreply
    $grStmt = $pdo->prepare("SELECT gr.* FROM radusergroup g JOIN radgroupreply gr ON g.groupname = gr.groupname WHERE g.username = ?");
    $grStmt->execute([$target_user]);
    $group_replies = $grStmt->fetchAll();

    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-family:monospace;'>";
    echo "<tr style='background:#0d6efd; color:#fff;'><th>Table</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    foreach ($replies as $r) {
        echo "<tr><td>radreply</td><td>{$r['attribute']}</td><td>{$r['op']}</td><td style='color:green; font-weight:bold;'>{$r['value']}</td></tr>";
    }
    foreach ($group_replies as $gr) {
        echo "<tr><td>radgroupreply ({$gr['groupname']})</td><td>{$gr['attribute']}</td><td>{$gr['op']}</td><td style='color:purple; font-weight:bold;'>{$gr['value']}</td></tr>";
    }
    echo "</table>";

    // 2. Run radtest -x to get the RAW UDP Access-Accept Packet
    echo "<h3>2. Raw UDP Network Packet (Access-Accept) Sent to MikroTik:</h3>";
    $nasSecret = $pdo->query("SELECT secret FROM nas LIMIT 1")->fetchColumn() ?: 'testing123';
    
    $cmd = "radtest -x " . escapeshellarg($target_user) . " " . escapeshellarg($pass) . " 127.0.0.1 0 testing123 2>&1";
    $output = shell_exec($cmd);
    if (strpos($output, 'No reply') !== false || empty($output)) {
        $cmd = "radtest -x " . escapeshellarg($target_user) . " " . escapeshellarg($pass) . " 127.0.0.1 0 " . escapeshellarg($nasSecret) . " 2>&1";
        $output = shell_exec($cmd);
    }

    echo "<pre style='background:#1e1e1e; color:#00ff00; padding:15px; border-radius:8px; overflow-x:auto;'>";
    echo htmlspecialchars($output);
    echo "</pre>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
