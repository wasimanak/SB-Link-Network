<?php
require 'config/db.php';

echo "<h2>Pattern Analysis: Why Are Users Failing?</h2>";

try {
    // 1. Get recent Rejected usernames
    $stmt1 = $pdo->query("SELECT DISTINCT username FROM radpostauth WHERE reply = 'Access-Reject' ORDER BY id DESC LIMIT 20");
    $rejected_users = $stmt1->fetchAll(PDO::FETCH_COLUMN);

    // 2. Get recent Accepted usernames
    $stmt2 = $pdo->query("SELECT DISTINCT username FROM radpostauth WHERE reply = 'Access-Accept' ORDER BY id DESC LIMIT 20");
    $accepted_users = $stmt2->fetchAll(PDO::FETCH_COLUMN);

    echo "<h3>1. Recent Rejected Users (" . count($rejected_users) . ")</h3>";
    echo "<p>" . implode(', ', $rejected_users) . "</p>";

    echo "<h3>2. Recent Accepted Users (" . count($accepted_users) . ")</h3>";
    echo "<p>" . implode(', ', $accepted_users) . "</p>";

    // 3. Inspect Subscriber Table Columns for Both Groups
    function analyze_group($pdo, $usernames, $title, $color) {
        if (empty($usernames)) return;
        $in = str_repeat('?,', count($usernames) - 1) . '?';
        $stmt = $pdo->prepare("SELECT client_id, package_id, dealer_id, status, service_type, COUNT(*) as cnt 
                               FROM subscribers WHERE username IN ($in) 
                               GROUP BY client_id, package_id, dealer_id, status, service_type");
        $stmt->execute($usernames);
        $rows = $stmt->fetchAll();

        echo "<h4 style='color:$color;'>$title Analysis (Grouped by Properties):</h4>";
        echo "<table border='1' cellpadding='6' style='border-collapse:collapse;'>";
        echo "<tr><th>Client ID</th><th>Package ID</th><th>Dealer ID</th><th>Status</th><th>Service Type</th><th>User Count</th></tr>";
        foreach ($rows as $r) {
            echo "<tr><td>{$r['client_id']}</td><td>{$r['package_id']}</td><td>{$r['dealer_id']}</td><td>'{$r['status']}'</td><td>{$r['service_type']}</td><td><strong>{$r['cnt']}</strong></td></tr>";
        }
        echo "</table>";
    }

    analyze_group($pdo, $rejected_users, "Rejected Users", "red");
    analyze_group($pdo, $accepted_users, "Accepted Users", "green");

    // 4. Check radcheck differences
    echo "<h3>3. radcheck Table Attributes Summary</h3>";
    $in_all = str_repeat('?,', count($rejected_users) - 1) . '?';
    $rc_stmt = $pdo->prepare("SELECT attribute, op, COUNT(*) as cnt FROM radcheck WHERE username IN ($in_all) GROUP BY attribute, op");
    $rc_stmt->execute($rejected_users);
    echo "<h4>Attributes present on Rejected Users:</h4>";
    print_r($rc_stmt->fetchAll());

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
