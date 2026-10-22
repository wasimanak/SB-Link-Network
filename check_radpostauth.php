<?php
require 'config/db.php';

echo "<h2>FreeRADIUS radpostauth (Live Authentication Attempts)</h2>";

try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'radpostauth'");
    if ($stmt->rowCount() == 0) {
        echo "<h3 style='color:red;'>radpostauth table does not exist in database.</h3>";
        exit;
    }

    echo "<h3>Recent Authentication Attempts for 'shafaaqat16' and others:</h3>";
    $stmt = $pdo->query("SELECT * FROM radpostauth WHERE username IN ('shafaaqat16', 'tahaawan', 'kohnoor', 'umar16') ORDER BY id DESC LIMIT 20");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse; font-family: monospace;'>";
        echo "<tr style='background: #333; color: #fff;'><th>ID</th><th>Username</th><th>Password Sent By User Router</th><th>Reply from RADIUS</th><th>Date & Time</th></tr>";
        foreach ($rows as $r) {
            $reply_color = ($r['reply'] == 'Access-Accept') ? 'green' : 'red';
            echo "<tr>";
            echo "<td>{$r['id']}</td>";
            echo "<td><strong>{$r['username']}</strong></td>";
            echo "<td style='background: #fffbe6;'><strong>'" . htmlspecialchars($r['pass']) . "'</strong> (Length: " . strlen($r['pass']) . ")</td>";
            echo "<td style='color: {$reply_color}; font-weight: bold;'>{$r['reply']}</td>";
            echo "<td>{$r['authdate']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p>Agar 'Password Sent By User Router' me <strong>1234</strong> ke ilawa kuch aur hai ya length 4 se zyada hai, tou samjh len customer ke router me password ghalat dala hua hai!</p>";
    } else {
        echo "<p>No recent attempts found for these specific users in radpostauth.</p>";
        echo "<h4>Showing the absolute latest 10 attempts on the whole server:</h4>";
        $stmt2 = $pdo->query("SELECT * FROM radpostauth ORDER BY id DESC LIMIT 10");
        $all_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse; font-family: monospace;'>";
        echo "<tr style='background: #333; color: #fff;'><th>ID</th><th>Username</th><th>Password Sent</th><th>Reply</th><th>Date</th></tr>";
        foreach ($all_rows as $r) {
            echo "<tr><td>{$r['id']}</td><td>{$r['username']}</td><td>" . htmlspecialchars($r['pass']) . "</td><td>{$r['reply']}</td><td>{$r['authdate']}</td></tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
