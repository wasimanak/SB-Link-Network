<?php
// Path: /config/cron_expiry.php
require_once __DIR__ . '/security.php';

// Allow execution only via CLI, SuperAdmin, or protected cron token
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $is_sa = !empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true;
    $cron_key = $_GET['key'] ?? '';
    if (!$is_sa && $cron_key !== 'sb_cron_secret_786') {
        http_response_code(403);
        die('403 Forbidden: Cron jobs can only be run via CLI or with authorized key.');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros_api.class.php';

try {
    $sql = "
        SELECT DISTINCT s.id, s.username, s.client_id, s.service_type, n.nasname, n.api_port, n.api_user, n.api_password, s.status
        FROM subscribers s
        JOIN nas n ON s.client_id = n.client_id
        WHERE s.expiry_date <= NOW() 
        AND (
            s.status = 'active' 
            OR s.username IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)
        )
    ";
    $stmt = $pdo->query($sql);
    $expired_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($expired_users)) {
        exit; // Nothing to do
    }

    foreach ($expired_users as $u) {
        $target_user = trim($u['username']);
        $service_type = strtolower(trim($u['service_type']));

        if (!empty($u['api_user']) && !empty($u['api_password'])) {
            $API = new RouterosAPI();
            $API->timeout = 5; 
            
            if ($API->connect($u['nasname'], $u['api_user'], $u['api_password'], $u['api_port'] ?: 8728)) {
                
                if ($service_type === 'pppoe') {
                    // Fetch all PPPoE users and match in PHP
                    $active = $API->comm('/ppp/active/print');
                    foreach ($active as $session) {
                        if (trim($session['name']) == $target_user) {
                            $API->comm('/ppp/active/remove', ['.id' => $session['.id']]);
                        }
                    }
                } else {
                    // Fetch all Hotspot users and match in PHP
                    $active = $API->comm('/ip/hotspot/active/print');
                    foreach ($active as $session) {
                        if (trim($session['user']) == $target_user) {
                            $API->comm('/ip/hotspot/active/remove', ['.id' => $session['.id']]);
                        }
                    }
                }
                $API->disconnect();
            }
        }

        // Always ensure the database marks them as expired
        if ($u['status'] !== 'expired') {
            $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ?")->execute([$u['id']]);
        }
        
        // HARD BLOCK IN FREERADIUS (Bypass FreeRADIUS Timezone bugs)
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$target_user]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$target_user]);
    }

} catch (Exception $e) {
    // Silent fail for cron
}
?>
