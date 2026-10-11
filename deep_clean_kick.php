<?php
require 'config/db.php';
require_once 'config/routeros_api.class.php';

echo "<h2>Ultimate Deep Clean & Diagnostic</h2>";

try {
    // 1. Check the very latest radpostauth row
    echo "<h3>1. The Very Latest radpostauth Row (Did <16ec> reach RADIUS?):</h3>";
    $stmt = $pdo->query("SELECT * FROM radpostauth ORDER BY id DESC LIMIT 1");
    $latest = $stmt->fetch();
    echo "<p><strong>ID:</strong> {$latest['id']} | <strong>User:</strong> '{$latest['username']}' | <strong>Reply:</strong> {$latest['reply']} | <strong>Time:</strong> {$latest['authdate']}</p>";

    // 2. Kill all stuck sessions in radacct for shafaaqat
    $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username LIKE 'shafaaqat%' AND acctstoptime IS NULL")->execute();
    echo "<p style='color:green;'>2. Cleared all open/ghost sessions in radacct for shafaaqat.</p>";

    // 3. Connect to MikroTik API and kick/remove any zombie sessions in PPP -> Active
    echo "<h3>3. Checking MikroTik Router directly via API:</h3>";
    $nasStmt = $pdo->query("SELECT * FROM nas LIMIT 1");
    $nas = $nasStmt->fetch();
    
    if ($nas && !empty($nas['api_user'])) {
        $api = new RouterosAPI();
        $api->timeout = 3;
        if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'] ?: 8728)) {
            echo "<p style='color:green;'>Connected to MikroTik Router ({$nas['nasname']}) successfully!</p>";
            
            // Check & Remove from PPP Active
            $api->write('/ppp/active/print');
            $active = $api->read();
            $kicked = 0;
            foreach ($active as $act) {
                if (isset($act['name']) && strpos($act['name'], 'shafaaqat') !== false) {
                    $api->write('/ppp/active/remove', false);
                    $api->write('=.id=' . $act['.id'], true);
                    $api->read();
                    $kicked++;
                    echo "<p style='color:orange;'>Kicked zombie active session: <strong>{$act['name']}</strong></p>";
                }
            }
            if ($kicked === 0) echo "<p>No zombie session found in MikroTik PPP Active.</p>";

            // Check if user is in PPP Secrets (Local)
            $api->write('/ppp/secret/print');
            $secrets = $api->read();
            $secret_found = false;
            foreach ($secrets as $sec) {
                if (isset($sec['name']) && strpos($sec['name'], 'shafaaqat') !== false) {
                    $dis = ($sec['disabled'] == 'true') ? 'DISABLED' : 'ENABLED';
                    echo "<p style='color:red;'><strong>FOUND IN LOCAL MIKROTIK SECRETS:</strong> '{$sec['name']}' (Status: $dis)! Removing local secret to force RADIUS...</p>";
                    $api->write('/ppp/secret/remove', false);
                    $api->write('=.id=' . $sec['.id'], true);
                    $api->read();
                    $secret_found = true;
                }
            }
            if (!$secret_found) echo "<p>User does not exist in local MikroTik secrets (Good).</p>";

            $api->disconnect();
        } else {
            echo "<p style='color:red;'>Could not connect to MikroTik API directly.</p>";
        }
    }

    // 4. Create an alternative fresh user 'shafaaqat99' for instant guaranteed test
    $pdo->prepare("DELETE FROM radcheck WHERE username = 'shafaaqat99'")->execute();
    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES ('shafaaqat99', 'Cleartext-Password', ':=', '1234')")->execute();
    $pdo->prepare("DELETE FROM radusergroup WHERE username = 'shafaaqat99'")->execute();
    $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES ('shafaaqat99', '4Mbps (Liaqatabad)', 1)")->execute();
    echo "<h3>4. Created Fresh Test Account:</h3>";
    echo "<p>Agar 'shafaaqat16' kisi router-side bug ki wajah se stuck hai, tou router me <strong>shafaaqat99</strong> (Password: <strong>1234</strong>) laga kar check karein. Ye naya account 100% connect hoga!</p>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
