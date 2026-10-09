<?php
require_once 'config/db.php';

$users = ['mustafa19ml', 'mushtaq19ml'];

foreach ($users as $u) {
    echo "<h2>User: $u</h2>";
    
    // Check radacct
    $stmt = $pdo->query("SELECT radacctid, acctsessionid, acctstarttime, acctstoptime FROM radacct WHERE username = '$u' ORDER BY acctstarttime DESC LIMIT 1");
    $acct = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<h3>Latest radacct:</h3>";
    print_r($acct);
    
    // Check radpostauth
    $stmt = $pdo->query("SELECT authdate, reply FROM radpostauth WHERE username = '$u' ORDER BY authdate DESC LIMIT 3");
    $auth = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Latest radpostauth:</h3>";
    print_r($auth);
    
    if ($acct && !empty($auth)) {
        $diff = strtotime($acct['acctstarttime']) - strtotime($auth[0]['authdate']);
        echo "<p>Time Difference: $diff seconds</p>";
    }
}
?>
