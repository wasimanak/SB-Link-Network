<?php
require 'config/db.php';

echo "<h2>Comparison: Working User vs 'shafaaqat16'</h2>";

try {
    // 1. Find a currently working user
    $stmt = $pdo->query("SELECT username FROM radacct WHERE acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
    $working_user = $stmt->fetchColumn();

    if (!$working_user) {
        // Fallback: find any user with recent successful login in radpostauth
        $stmt = $pdo->query("SELECT username FROM radpostauth WHERE reply = 'Access-Accept' ORDER BY id DESC LIMIT 1");
        $working_user = $stmt->fetchColumn();
    }

    echo "<h3>Comparing Working User (<span style='color:green;'>$working_user</span>) vs Failing User (<span style='color:red;'>shafaaqat16</span>)</h3>";

    function dump_user_data($pdo, $u) {
        echo "<div style='border:1px solid #ccc; padding:10px; margin:10px 0; background:#f9f9f9;'>";
        echo "<h4>Data for: <strong>$u</strong></h4>";
        
        // Subscribers
        $s = $pdo->prepare("SELECT * FROM subscribers WHERE username = ?");
        $s->execute([$u]);
        echo "<strong>subscribers:</strong><br><pre>" . print_r($s->fetch(), true) . "</pre>";

        // radcheck
        $rc = $pdo->prepare("SELECT * FROM radcheck WHERE username = ?");
        $rc->execute([$u]);
        echo "<strong>radcheck:</strong><br><pre>" . print_r($rc->fetchAll(), true) . "</pre>";

        // radreply
        $rr = $pdo->prepare("SELECT * FROM radreply WHERE username = ?");
        $rr->execute([$u]);
        echo "<strong>radreply:</strong><br><pre>" . print_r($rr->fetchAll(), true) . "</pre>";

        // radusergroup
        $rug = $pdo->prepare("SELECT * FROM radusergroup WHERE username = ?");
        $rug->execute([$u]);
        echo "<strong>radusergroup:</strong><br><pre>" . print_r($rug->fetchAll(), true) . "</pre>";

        echo "</div>";
    }

    if ($working_user) {
        dump_user_data($pdo, $working_user);
    }
    dump_user_data($pdo, 'shafaaqat16');

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
