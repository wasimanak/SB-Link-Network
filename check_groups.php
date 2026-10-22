<?php
require 'config/db.php';

echo "<h2>Group Reply Inspection & Test</h2>";

try {
    // 1. Show radgroupreply for both groups
    echo "<h3>radgroupreply for '4Mbps (Liaqatabad)' (Working Group):</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radgroupreply WHERE groupname = '4Mbps (Liaqatabad)'");
    $stmt->execute();
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "<h3>radgroupreply for '5MB (Liaqatabad)' (Failing Group):</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radgroupreply WHERE groupname = '5MB (Liaqatabad)'");
    $stmt->execute();
    $failing_group_rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($failing_group_rules);

    // 2. Temporarily switch shafaaqat16 to the working group '4Mbps (Liaqatabad)' to test
    echo "<h3>Test: Temporarily assigning 'shafaaqat16' to the working group '4Mbps (Liaqatabad)'...</h3>";
    $pdo->prepare("UPDATE radusergroup SET groupname = '4Mbps (Liaqatabad)' WHERE username = 'shafaaqat16'")->execute();
    echo "<p style='color:green; font-weight:bold;'>Temporarily switched to '4Mbps (Liaqatabad)'. Please ask shafaaqat16 to dial now!</p>";
    echo "<p>Agar ab connect ho gaya, tou iska matlab 100% '5MB (Liaqatabad)' package ki settings me koi kharabi thi!</p>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
