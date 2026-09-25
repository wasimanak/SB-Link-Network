<?php
// cron_expiry.php
// Run this file every minute via Windows Task Scheduler or Linux Cron
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros_api.class.php';

echo "Running Expiry Check...\n";

// Find users who have expired but are still marked as active
$stmt = $pdo->query("SELECT s.id, s.username, s.client_id, s.service_type, n.nasname, n.api_port, n.api_user, n.api_password 
    FROM subscribers s 
    JOIN nas n ON s.client_id = n.client_id 
    WHERE s.expiry_date < NOW() AND s.status = 'active'");
$expired_users = $stmt->fetchAll();

if (empty($expired_users)) {
    echo "No new expired users found.\n";
    exit;
}

foreach ($expired_users as $u) {
    echo "Expiring User: {$u['username']}...\n";
    
    // 1. Update Database Status
    $upd = $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ?");
    $upd->execute([$u['id']]);
    
    // 2. Disconnect from MikroTik
    if (!empty($u['nasname'])) {
        $API = new RouterosAPI();
        $API->timeout = 3;
        
        if ($API->connect($u['nasname'], $u['api_user'], $u['api_password'], $u['api_port'])) {
            if ($u['service_type'] === 'pppoe') {
                // Find and remove active PPPoE session
                $active = $API->comm('/ppp/active/print', ['?name' => $u['username']]);
                if (!empty($active)) {
                    foreach ($active as $session) {
                        $API->comm('/ppp/active/remove', ['.id' => $session['.id']]);
                        echo " - Disconnected PPPoE session for {$u['username']}\n";
                    }
                }
            } else {
                // Find and remove active Hotspot session
                $active = $API->comm('/ip/hotspot/active/print', ['?user' => $u['username']]);
                if (!empty($active)) {
                    foreach ($active as $session) {
                        $API->comm('/ip/hotspot/active/remove', ['.id' => $session['.id']]);
                        echo " - Disconnected Hotspot session for {$u['username']}\n";
                    }
                }
            }
            $API->disconnect();
        } else {
            echo " - Could not connect to MikroTik ({$u['nasname']}) to kick user.\n";
        }
    }
}
echo "Done.\n";
