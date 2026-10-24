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
 * 2. Real-Time Auto-Block & Walled Garden Sync for Subscribers
 *
 * Enterprise ISP Architecture (Like PTCL, StormFiber, Nayatel):
 * 1. DISABLED users (status = 'disabled'):
 *    - In radcheck: 'Auth-Type := Reject' (Hard cutoff, no connection permitted).
 *    - Open sessions are immediately kicked from MikroTik.
 *
 * 2. EXPIRED users (status = 'expired' OR expiry_date <= NOW()):
 *    - In radcheck: 'Auth-Type' is REMOVED so PPPoE connection stays ONLINE (Green light / optical line monitored).
 *    - In radreply:
 *      * 'Mikrotik-Address-List = EXPIRED_USERS' (MikroTik firewall drops all traffic = 0 KB data leak).
 *      * 'Mikrotik-Rate-Limit = 10k/10k' (Strict throttle for billing portal loading only).
 *      * 'Mikrotik-Group = expired' (Assigns expired profile).
 *    - Kicked ONCE upon transition so router reconnects into EXPIRED_USERS.
 *    - While already in walled garden, cron DOES NOT kick them again, keeping line 24/7 online.
 *
 * 3. RENEWED/ACTIVE users (status = 'active' AND (expiry_date IS NULL OR expiry_date > NOW())):
 *    - Removes 'Auth-Type' reject from radcheck.
 *    - Removes 'EXPIRED_USERS' from radreply.
 *    - Restores normal package rate-limit and profile in radreply.
 *    - Kicked once so session reconnects with full speed and unrestricted internet.
 *
 * @param PDO $pdo
 * @param int|null $client_id
 * @param bool $kick_sessions
 * @return array
 */
function sync_expired_subscribers($pdo, $client_id = null, $kick_sessions = true) {
    $walled_count = 0;
    $hard_blocked_count = 0;
    $kicked_count = 0;
    $restored_count = 0;

    try {
        // Reusable prepared statements
        $delRadcheckAuthStmt = $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'");
        $insertRejectStmt    = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')");
        $checkRejectStmt     = $pdo->prepare("SELECT COUNT(*) FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'");
        
        $delWalledRadreply   = $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Rate-Limit', 'Mikrotik-Group')");
        $insertRadreply      = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, '=', ?)");
        $updateStatusStmt    = $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ? AND status = 'active'");

        // Map online users currently in radacct
        $onlineStmt = $pdo->query("SELECT DISTINCT username FROM radacct WHERE acctstoptime IS NULL");
        $online_users = $onlineStmt->fetchAll(PDO::FETCH_COLUMN);
        $online_map = array_flip($online_users);

        // -------------------------------------------------------------
        // Group A: HARD-DISABLED SUBSCRIBERS (status = 'disabled')
        // Strict cutoff - no connection allowed
        // -------------------------------------------------------------
        $sqlDisabled = "SELECT s.id, s.username, s.client_id FROM subscribers s WHERE s.status = 'disabled'";
        if ($client_id) {
            $sqlDisabled .= " AND s.client_id = " . (int)$client_id;
        }
        $disabledUsers = $pdo->query($sqlDisabled)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($disabledUsers as $d) {
            $user = trim($d['username']);
            if (empty($user)) continue;

            $checkRejectStmt->execute([$user]);
            if ($checkRejectStmt->fetchColumn() == 0) {
                $delRadcheckAuthStmt->execute([$user]);
                $insertRejectStmt->execute([$user]);
                $hard_blocked_count++;
            }

            // Remove walled garden reply entries if any
            $delWalledRadreply->execute([$user]);

            // If online, kick them out immediately
            if ($kick_sessions && isset($online_map[$user])) {
                kick_subscriber_session($pdo, $user, $d['client_id']);
                $kicked_count++;
            }
        }

        // -------------------------------------------------------------
        // Group B: EXPIRED SUBSCRIBERS (Walled Garden / Redirection)
        // (expiry_date <= NOW()) AND status != 'disabled'
        // Allowed to connect (shows Online) but throttled & added to EXPIRED_USERS list
        // -------------------------------------------------------------
        $sqlExpired = "
            SELECT s.id, s.username, s.client_id, s.status, s.expiry_date
            FROM subscribers s
            WHERE (
                (s.expiry_date IS NOT NULL AND s.expiry_date <= NOW())
                OR (s.expiry_date IS NULL AND s.status = 'expired')
            )
            AND s.status != 'disabled'
        ";
        if ($client_id) {
            $sqlExpired .= " AND s.client_id = " . (int)$client_id;
        }
        $expiredUsers = $pdo->query($sqlExpired)->fetchAll(PDO::FETCH_ASSOC);

        // Statement to check if user is already configured with EXPIRED_USERS in radreply
        $checkWalledStmt = $pdo->prepare("
            SELECT COUNT(*) FROM radreply 
            WHERE username = ? AND attribute = 'Mikrotik-Address-List' AND value = 'EXPIRED_USERS'
        ");

        foreach ($expiredUsers as $u) {
            $user = trim($u['username']);
            if (empty($user)) continue;

            // Ensure status is marked 'expired' in subscribers table
            if ($u['status'] === 'active') {
                $updateStatusStmt->execute([$u['id']]);
            }

            // Remove any Auth-Type := Reject from radcheck so PPPoE connection succeeds
            $delRadcheckAuthStmt->execute([$user]);

            // Check if user is ALREADY walled in radreply
            $checkWalledStmt->execute([$user]);
            $is_already_walled = ($checkWalledStmt->fetchColumn() > 0);

            if (!$is_already_walled) {
                // Newly expired: inject Walled Garden attributes into radreply
                $delWalledRadreply->execute([$user]);
                $insertRadreply->execute([$user, 'Mikrotik-Address-List', 'EXPIRED_USERS']);
                $insertRadreply->execute([$user, 'Mikrotik-Rate-Limit', '10k/10k']);
                $insertRadreply->execute([$user, 'Mikrotik-Group', 'expired']);
                $walled_count++;

                // Kick once so router re-dials and joins EXPIRED_USERS
                if ($kick_sessions && isset($online_map[$user])) {
                    kick_subscriber_session($pdo, $user, $u['client_id']);
                    $kicked_count++;
                }
            } else {
                // If already walled but rate limit is still old (e.g. 128k), update to 10k/10k
                $checkRate = $pdo->prepare("SELECT value FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'");
                $checkRate->execute([$user]);
                $currRate = $checkRate->fetchColumn();
                if ($currRate !== '10k/10k') {
                    $pdo->prepare("UPDATE radreply SET value = '10k/10k' WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$user]);
                    if ($kick_sessions && isset($online_map[$user])) {
                        kick_subscriber_session($pdo, $user, $u['client_id']);
                    }
                }
            }
            // If already walled and up to date, DO NOT kick! Connection remains 24/7 online for line testing.
        }

        // -------------------------------------------------------------
        // Group C: ACTIVE / RENEWED SUBSCRIBERS
        // (expiry_date IS NULL OR expiry_date > NOW()) AND status != 'disabled'
        // Restores package speed and unblocks firewall
        // -------------------------------------------------------------
        $sqlActiveNeedsRestoration = "
            SELECT DISTINCT s.id, s.username, s.client_id, s.package_id, s.status
            FROM subscribers s
            WHERE (s.expiry_date IS NULL OR s.expiry_date > NOW())
              AND s.status != 'disabled'
              AND (
                  s.status != 'active'
                  OR EXISTS (SELECT 1 FROM radcheck rc WHERE rc.username = s.username AND rc.attribute = 'Auth-Type')
                  OR EXISTS (SELECT 1 FROM radreply rr WHERE rr.username = s.username AND rr.attribute = 'Mikrotik-Address-List' AND rr.value = 'EXPIRED_USERS')
                  OR EXISTS (SELECT 1 FROM radreply rr WHERE rr.username = s.username AND rr.attribute = 'Mikrotik-Group' AND rr.value = 'expired')
              )
        ";
        if ($client_id) {
            $sqlActiveNeedsRestoration .= " AND s.client_id = " . (int)$client_id;
        }
        $restoredUsers = $pdo->query($sqlActiveNeedsRestoration)->fetchAll(PDO::FETCH_ASSOC);

        $pkgStmt = $pdo->prepare("
            SELECT p.name, p.rate_limit, pl.pool_name 
            FROM packages p 
            LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
            WHERE p.id = ?
        ");

        foreach ($restoredUsers as $ru) {
            $user = trim($ru['username']);
            if (empty($user)) continue;

            // Ensure status in subscribers table is active
            if ($ru['status'] !== 'active') {
                $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([$ru['id']]);
            }

            // Remove Reject from radcheck
            $delRadcheckAuthStmt->execute([$user]);

            // Remove walled garden entries from radreply
            $delWalledRadreply->execute([$user]);

            // Restore normal package rate-limit & profile
            if (!empty($ru['package_id'])) {
                $pkgStmt->execute([$ru['package_id']]);
                $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

                if ($pkg) {
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit' && $pkg['rate_limit'] !== 'Unlimited') {
                        $insertRadreply->execute([$user, 'Mikrotik-Rate-Limit', $pkg['rate_limit']]);
                    }
                    $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                    if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                        if (!empty($pkg['name']) && preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                            $target_profile = $m_prof[1] . 'Mbps';
                        }
                    }
                    if (!empty($target_profile)) {
                        $insertRadreply->execute([$user, 'Mikrotik-Group', $target_profile]);
                    }
                }
            }

            // Kick once so session re-establishes with full package speed
            if ($kick_sessions && isset($online_map[$user])) {
                kick_subscriber_session($pdo, $user, $ru['client_id']);
            }

            $restored_count++;
        }

    } catch (Exception $e) {
        error_log("Error in sync_expired_subscribers: " . $e->getMessage());
    }

    return [
        'blocked' => $walled_count + $hard_blocked_count,
        'walled' => $walled_count,
        'disabled' => $hard_blocked_count,
        'kicked' => $kicked_count,
        'restored' => $restored_count
    ];
}
