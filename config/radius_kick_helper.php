<?php
// config/radius_kick_helper.php
// Centralized Engine for FreeRADIUS Expiry Sync & MikroTik Dual-Engine Kick
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros_api.class.php';

/**
 * 1. Kick a subscriber session from MikroTik (Dual Engine: API + PoD)
 *
 * @param PDO $pdo
 * @param string $username
 * @param int|null $client_id
 * @return array ['success' => bool, 'methods' => array, 'message' => string]
 */
function kick_subscriber_session($pdo, $username, $client_id = null) {
    $username = trim($username);
    if (empty($username)) {
        return ['success' => false, 'methods' => [], 'message' => 'Empty username'];
    }

    $kicked = false;
    $methods_used = [];

    // Step A: Find the user's active session and router info
    // First, check radacct for which router (nasipaddress) this user is connected to right now
    $nasList = [];
    try {
        $sessStmt = $pdo->prepare("
            SELECT r.nasipaddress, n.*
            FROM radacct r
            JOIN nas n ON r.nasipaddress = n.nasname
            WHERE r.username = ? AND r.acctstoptime IS NULL
            ORDER BY r.radacctid DESC
        ");
        $sessStmt->execute([$username]);
        $nasList = $sessStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Fallback: If no active session in radacct, find all candidate routers
    if (empty($nasList)) {
        try {
            if ($client_id) {
                $nStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
                $nStmt->execute([$client_id]);
                $nasList = $nStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if (empty($nasList)) {
                $subStmt = $pdo->prepare("SELECT client_id FROM subscribers WHERE username = ?");
                $subStmt->execute([$username]);
                $cid = $subStmt->fetchColumn();
                if ($cid) {
                    $nStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
                    $nStmt->execute([$cid]);
                    $nasList = $nStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            if (empty($nasList)) {
                $nasList = $pdo->query("SELECT * FROM nas LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {}
    }

    // Step B: Loop through candidate routers and kick via MikroTik API and PoD
    foreach ($nasList as $nas) {
        $nas_ip   = trim($nas['nasname'] ?? '');
        $api_user = trim($nas['api_user'] ?? '');
        $api_pass = trim($nas['api_password'] ?? '');
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;
        $coa_port = !empty($nas['coa_port']) ? (int)$nas['coa_port'] : 3799;
        $secret   = trim($nas['secret'] ?? '');

        // 1. Try MikroTik RouterOS API (/ppp/active and /ip/hotspot/active)
        if (!empty($nas_ip) && !empty($api_user)) {
            try {
                $API = new RouterosAPI();
                $API->timeout = 2; // Fast 2s timeout
                $API->attempts = 1;

                if ($API->connect($nas_ip, $api_user, $api_pass, $api_port)) {
                    // Check /ppp/active
                    $active_ppp = $API->comm('/ppp/active/print');
                    if (is_array($active_ppp)) {
                        foreach ($active_ppp as $sess) {
                            if (isset($sess['name']) && strcasecmp(trim($sess['name']), $username) === 0) {
                                $API->comm('/ppp/active/remove', ['.id' => $sess['.id']]);
                                $kicked = true;
                                $methods_used[] = "MikroTik API (/ppp/active: {$nas_ip})";
                            }
                        }
                    }

                    // Check /ip/hotspot/active
                    $active_hs = $API->comm('/ip/hotspot/active/print');
                    if (is_array($active_hs)) {
                        foreach ($active_hs as $sess) {
                            if (isset($sess['user']) && strcasecmp(trim($sess['user']), $username) === 0) {
                                $API->comm('/ip/hotspot/active/remove', ['.id' => $sess['.id']]);
                                $kicked = true;
                                $methods_used[] = "MikroTik API (/ip/hotspot/active: {$nas_ip})";
                            }
                        }
                    }

                    $API->disconnect();
                }
            } catch (Exception $e) {}
        }

        // 2. Try RADIUS PoD (Packet of Disconnect via UDP 3799 / radclient)
        if (!empty($nas_ip) && !empty($secret) && function_exists('exec')) {
            try {
                $radclient_bin = 'radclient';
                $dest = escapeshellarg("{$nas_ip}:{$coa_port}");
                $sec = escapeshellarg($secret);
                $user_attr = escapeshellarg("User-Name=" . $username);
                $cmd = "echo $user_attr | $radclient_bin -r 1 -t 1 $dest disconnect $sec 2>&1";
                @exec($cmd, $rad_out, $rad_code);
                if ($rad_code === 0) {
                    $kicked = true;
                    $methods_used[] = "RADIUS PoD ({$nas_ip}:{$coa_port})";
                }
            } catch (Exception $e) {}
        }
    }

    // Step C: Always clear open session in radacct
    try {
        $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL")->execute([$username]);
    } catch (Exception $e) {}

    return [
        'success' => $kicked,
        'methods' => $methods_used,
        'message' => $kicked ? ("Session kicked via " . implode(', ', $methods_used)) : "Session cleared in database (router API/PoD signaled)"
    ];
}

/**
 * 2. Real-Time Auto-Block & Sync Function for ALL Expired Subscribers
 *
 * Enforces:
 * 1. Anyone with expiry_date <= NOW() or status IN ('expired', 'disabled') GETS 'Auth-Type := Reject' in radcheck!
 * 2. Active sessions for expired users are kicked from MikroTik router.
 * 3. Active users (expiry_date > NOW() and status = 'active') HAVE 'Auth-Type = Reject' REMOVED so they can connect!
 *
 * @param PDO $pdo
 * @param int|null $client_id
 * @param bool $kick_sessions
 * @return array
 */
function sync_expired_subscribers($pdo, $client_id = null, $kick_sessions = true) {
    $blocked_count = 0;
    $kicked_count = 0;
    $restored_count = 0;

    try {
        // Query 1: Find all users that SHOULD BE BLOCKED
        // Condition: (expiry_date <= NOW() OR status IN ('expired', 'disabled'))
        $sqlBlocked = "
            SELECT s.id, s.username, s.client_id, s.status, s.expiry_date
            FROM subscribers s
            WHERE (
                (s.expiry_date IS NOT NULL AND s.expiry_date <= NOW())
                OR s.status IN ('expired', 'disabled')
            )
        ";
        if ($client_id) {
            $sqlBlocked .= " AND s.client_id = " . (int)$client_id;
        }

        $stmtBlocked = $pdo->query($sqlBlocked);
        $to_block = $stmtBlocked->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($to_block)) {
            $updateStatusStmt = $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ? AND status = 'active'");
            
            // Check existing Reject in radcheck to avoid duplicate inserts
            $checkRejectStmt = $pdo->prepare("SELECT COUNT(*) FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'");
            $insertRejectStmt = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')");

            // Check who is currently online among the expired
            $onlineExpiredStmt = $pdo->query("SELECT DISTINCT username FROM radacct WHERE acctstoptime IS NULL");
            $online_users = $onlineExpiredStmt->fetchAll(PDO::FETCH_COLUMN);
            $online_map = array_flip($online_users);

            foreach ($to_block as $u) {
                $user = trim($u['username']);
                if (empty($user)) continue;

                // Mark expired in subscribers if it was active
                if ($u['status'] === 'active') {
                    $updateStatusStmt->execute([$u['id']]);
                }

                // CRITICAL: Ensure Auth-Type := Reject is in radcheck
                // This guarantees FreeRADIUS rejects any new auth attempt!
                $checkRejectStmt->execute([$user]);
                if ($checkRejectStmt->fetchColumn() == 0) {
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$user]);
                    $insertRejectStmt->execute([$user]);
                    $blocked_count++;
                }

                // If user is currently online and kick is enabled, kick them immediately
                if ($kick_sessions && isset($online_map[$user])) {
                    kick_subscriber_session($pdo, $user, $u['client_id']);
                    $kicked_count++;
                }
            }
        }

        // Query 2: Ensure ACTIVE users do NOT have 'Auth-Type = Reject' in radcheck
        // Condition: expiry_date > NOW() AND status = 'active'
        $sqlActive = "
            SELECT DISTINCT s.username
            FROM subscribers s
            JOIN radcheck rc ON s.username = rc.username
            WHERE s.status = 'active'
              AND (s.expiry_date IS NULL OR s.expiry_date > NOW())
              AND rc.attribute = 'Auth-Type'
              AND rc.value = 'Reject'
        ";
        if ($client_id) {
            $sqlActive .= " AND s.client_id = " . (int)$client_id;
        }

        $stmtActive = $pdo->query($sqlActive);
        $to_unblock = $stmtActive->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($to_unblock)) {
            $delRejectStmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'");
            foreach ($to_unblock as $active_u) {
                $delRejectStmt->execute([$active_u]);
                $restored_count++;
            }
        }

    } catch (Exception $e) {
        error_log("Error in sync_expired_subscribers: " . $e->getMessage());
    }

    return [
        'blocked' => $blocked_count,
        'kicked' => $kicked_count,
        'restored' => $restored_count
    ];
}
