<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/routeros_api.class.php';

echo "<h2>MikroTik API Connection Test (Advanced)</h2>";

if (!isset($_GET['user'])) {
    echo "Please provide a username in the URL.";
    exit;
}

$username = trim($_GET['user']);

$stmt = $pdo->prepare("
    SELECT s.username, s.service_type, n.nasname, n.api_port, n.api_user, n.api_password 
    FROM subscribers s 
    JOIN nas n ON s.client_id = n.client_id 
    WHERE s.username = ?
");
$stmt->execute([$username]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    echo "User '$username' not found.";
    exit;
}

echo "Target User: <b>'{$u['username']}'</b> (Service Type in DB: {$u['service_type']})<br>";

$API = new RouterosAPI();
$API->debug = false; 

if ($API->connect($u['nasname'], $u['api_user'], $u['api_password'], $u['api_port'] ?: 8728)) {
    echo "<br><b style='color:green'>SUCCESS! Connected to MikroTik.</b><br><br>";
    
    echo "<h3>PPPoE Active Sessions List:</h3>";
    $pppoe_active = $API->comm('/ppp/active/print');
    
    $found_pppoe = false;
    foreach ($pppoe_active as $session) {
        echo "'" . $session['name'] . "'<br>";
        if (trim($session['name']) == $username) {
            $found_pppoe = true;
            $API->comm('/ppp/active/remove', ['.id' => $session['.id']]);
            echo "<br><b style='color:green'>SUCCESS: Kicked PPPoE session for '{$session['name']}'!</b><br>";
        }
    }
    
    if (!$found_pppoe) {
        echo "<b style='color:red'>User '$username' was NOT found in the PPPoE active list above.</b><br>";
    }
    
    echo "<h3>Hotspot Active Sessions List:</h3>";
    $hotspot_active = $API->comm('/ip/hotspot/active/print');
    
    $found_hs = false;
    foreach ($hotspot_active as $session) {
        echo "'" . $session['user'] . "'<br>";
        if (trim($session['user']) == $username) {
            $found_hs = true;
            $API->comm('/ip/hotspot/active/remove', ['.id' => $session['.id']]);
            echo "<br><b style='color:green'>SUCCESS: Kicked Hotspot session for '{$session['user']}'!</b><br>";
        }
    }
    
    if (!$found_hs) {
         echo "<b style='color:red'>User '$username' was NOT found in the Hotspot active list above.</b><br>";
    }

    $API->disconnect();
} else {
    echo "FAILED to connect to MikroTik.";
}
?>
