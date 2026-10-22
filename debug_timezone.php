<?php
require_once 'config/db.php';

$users = ['mustafa19ml', 'mushtaq19ml'];

echo "<h3>Timezone Debug</h3>";
$q1 = $pdo->query("SELECT NOW() as mysql_now")->fetch();
echo "MySQL NOW(): " . $q1['mysql_now'] . "<br>";
echo "PHP NOW(): " . date('Y-m-d H:i:s') . "<br><br>";

foreach ($users as $u) {
    echo "<b>User: $u</b><br>";
    
    // radacct
    $stmt = $pdo->query("SELECT acctstarttime FROM radacct WHERE username = '$u' ORDER BY acctstarttime DESC LIMIT 1");
    $acct = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "radacct.acctstarttime: " . ($acct['acctstarttime'] ?? 'Not found') . "<br>";
    
    // radpostauth
    $stmt = $pdo->query("SELECT authdate FROM radpostauth WHERE username = '$u' AND reply='Access-Accept' ORDER BY authdate DESC LIMIT 1");
    $auth = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "radpostauth.authdate: " . ($auth['authdate'] ?? 'Not found') . "<br><hr>";
}
?>
