<?php
/**
 * Speed Rate Limit & Night/Bandwidth Scheduler Helper
 * Handles speed normalization (rx/tx -> upload/download),
 * FreeRADIUS radgroupreply/radreply synchronization,
 * and live MikroTik Simple Queue updates / session kicks.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/radius_kick_helper.php';

/**
 * Normalize any speed input into clean MikroTik rate-limit format (e.g. "5M", "512k", "2M/10M")
 * Rejects or strips invalid suffixes like "mbps", "MB", bare numbers like "10" -> "10M"
 *
 * @param string $speed
 * @return string
 */
function normalize_mikrotik_speed($speed) {
    $speed = trim($speed ?? '');
    if (empty($speed) || strcasecmp($speed, 'Unlimited') === 0 || strcasecmp($speed, 'No Limit') === 0) {
        return 'Unlimited';
    }

    // If slash exists (upload/download)
    if (strpos($speed, '/') !== false) {
        $parts = explode('/', $speed, 2);
        $up = normalize_single_speed($parts[0]);
        $down = normalize_single_speed($parts[1]);
        if ($up === 'Unlimited' && $down === 'Unlimited') return 'Unlimited';
        return $up . '/' . $down;
    }

    return normalize_single_speed($speed);
}

function normalize_single_speed($val) {
    $val = trim($val ?? '');
    if (empty($val) || strcasecmp($val, 'Unlimited') === 0 || strcasecmp($val, 'No Limit') === 0) {
        return 'Unlimited';
    }

    // Strip bps, b/s, bit
    $clean = preg_replace('/(bps|b\/s|bit)/i', '', $val);
    $clean = trim($clean);

    // If bare integer (e.g. "5", "10", "20") -> treat as Megabits "5M", "10M", "20M"
    if (is_numeric($clean)) {
        return ((int)$clean) . 'M';
    }

    // Format: 10M, 10MB, 10m -> 10M
    if (preg_match('/^(\d+)\s*(m|mb|meg|mega)$/i', $clean, $m)) {
        return $m[1] . 'M';
    }

    // Format: 512k, 512kb, 512kilo -> 512k
    if (preg_match('/^(\d+)\s*(k|kb|kilo)$/i', $clean, $m)) {
        return $m[1] . 'k';
    }

    // Format: 1G, 1gb -> 1G
    if (preg_match('/^(\d+)\s*(g|gb|giga)$/i', $clean, $m)) {
        return $m[1] . 'G';
    }

    return $clean;
}

/**
 * Check if a time string (e.g. "14:30") falls within [start_time, end_time]
 * Accurately handles overnight / cross-midnight windows (e.g. 23:00 to 08:00)
 *
 * @param string $cur_time "H:i"
 * @param string $start_time "H:i"
 * @param string $end_time "H:i"
 * @return bool
 */
function is_time_in_scheduler_window($cur_time, $start_time, $end_time) {
    $cur = strtotime("1970-01-01 " . trim($cur_time));
    $start = strtotime("1970-01-01 " . trim($start_time));
    $end = strtotime("1970-01-01 " . trim($end_time));

    if ($start === false || $end === false || $cur === false) {
        return false;
    }

    if ($start < $end) {
        // Standard same-day window (e.g. 09:00 to 17:00)
        return ($cur >= $start && $cur < $end);
    } else {
        // Overnight window crossing midnight (e.g. 23:00 to 08:00)
        return ($cur >= $start || $cur < $end);
    }
}

/**
 * Determine the active speed of a package at a given time
 *
 * @param array $pkg Package row from DB
 * @param string|null $check_time Optional "H:i", defaults to current server time
 * @return array
 */
function get_active_package_speed($pkg, $check_time = null) {
    if (!$check_time) {
        $check_time = date('H:i');
    }

    $normal_speed = normalize_mikrotik_speed($pkg['rate_limit'] ?? 'Unlimited');
    $scheduler_json = trim($pkg['speed_scheduler'] ?? '');

    if (empty($scheduler_json)) {
        return [
            'speed'          => $normal_speed,
            'is_scheduled'   => false,
            'scheduler_mode' => 'normal',
            'scheduler'      => null
        ];
    }

    $sched = json_decode($scheduler_json, true);
    if (!is_array($sched) || empty($sched['start_time']) || empty($sched['end_time']) || empty($sched['speed'])) {
        return [
            'speed'          => $normal_speed,
            'is_scheduled'   => false,
            'scheduler_mode' => 'normal',
            'scheduler'      => null
        ];
    }

    $sched_speed = normalize_mikrotik_speed($sched['speed']);
    $in_window = is_time_in_scheduler_window($check_time, $sched['start_time'], $sched['end_time']);

    if ($in_window) {
        return [
            'speed'          => $sched_speed,
            'is_scheduled'   => true,
            'scheduler_mode' => 'night',
            'scheduler'      => $sched
        ];
    } else {
        return [
            'speed'          => $normal_speed,
            'is_scheduled'   => false,
            'scheduler_mode' => 'normal',
            'scheduler'      => $sched
        ];
    }
}

/**
 * Sync FreeRADIUS (radgroupreply and radreply for subscribers) with the active speed
 *
 * @param PDO $pdo
 * @param int $package_id
 * @param string $pkg_name
 * @param string $rate_limit Normalized speed (e.g. "2M/10M")
 * @param string|null $pool_name
 * @param int $client_id
 * @return int Number of subscribers updated
 */
function sync_freeradius_rate_and_profile($pdo, $package_id, $pkg_name, $rate_limit, $pool_name, $client_id) {
    $rate_limit = normalize_mikrotik_speed($rate_limit);

    // Determine target profile / pool name for Mikrotik-Group
    $target_profile = !empty($pool_name) ? $pool_name : '';
    if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
        if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg_name, $m_prof)) {
            $target_profile = $m_prof[1] . 'Mbps';
        } else {
            $target_profile = $pkg_name;
        }
    }

    // A. Sync radgroupreply for package group
    try {
        $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute IN ('Mikrotik-Group', 'Framed-Pool', 'Mikrotik-Address-List')")->execute([$pkg_name]);
        $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$pkg_name]);
        if (!empty($rate_limit) && $rate_limit !== 'Unlimited' && $rate_limit !== 'No Limit') {
            $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")
                ->execute([$pkg_name, $rate_limit]);
        }
    } catch (Exception $e) {}

    // B. Sync existing subscribers in radreply AND radusergroup
    $updated_count = 0;
    try {
        $sub_stmt = $pdo->prepare("SELECT username FROM subscribers WHERE package_id = ? AND client_id = ?");
        $sub_stmt->execute([$package_id, $client_id]);
        $subscribers = $sub_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($subscribers as $u) {
            // 1. Sync radusergroup to package name
            $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$u]);
            $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$u, $pkg_name]);

            // 2. Sync Mikrotik-Rate-Limit in radreply (:= enforces exact speed cap)
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
            if (!empty($rate_limit) && $rate_limit !== 'Unlimited' && $rate_limit !== 'No Limit') {
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")
                    ->execute([$u, $rate_limit]);
            }

            // 3. Remove unwanted dynamic address list / framed pool
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);

            // 4. Set Mikrotik-Group
            if (!empty($target_profile)) {
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
            }

            $updated_count++;
        }
    } catch (Exception $e) {}

    return $updated_count;
}

/**
 * Live update active MikroTik simple queues or refresh sessions for a package
 *
 * @param PDO $pdo
 * @param int $package_id
 * @param int $client_id
 * @param string $new_speed
 * @return array
 */
function live_update_mikrotik_speed_for_package($pdo, $package_id, $client_id, $new_speed) {
    if (empty($new_speed) || $new_speed === 'Unlimited') return ['updated' => 0, 'kicked' => 0];

    // Find active subscribers of this package from radacct
    $active_users = [];
    try {
        $stmt = $pdo->prepare("
            SELECT r.username, r.nasipaddress, r.framedipaddress 
            FROM radacct r 
            JOIN subscribers s ON r.username = s.username 
            WHERE s.package_id = ? AND s.client_id = ? AND r.acctstoptime IS NULL
        ");
        $stmt->execute([$package_id, $client_id]);
        $active_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if (empty($active_users)) {
        return ['updated' => 0, 'kicked' => 0];
    }

    // Fetch routers for this operator
    $nas_stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
    $nas_stmt->execute([$client_id]);
    $nasList = $nas_stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated_count = 0;
    $kicked_count = 0;

    foreach ($nasList as $nas) {
        $nas_ip   = trim($nas['nasname'] ?? '');
        $api_user = trim($nas['api_user'] ?? '');
        $api_pass = trim($nas['api_password'] ?? '');
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;

        if (empty($nas_ip) || empty($api_user)) continue;

        try {
            $API = new RouterosAPI();
            $API->timeout = 3;
            $API->attempts = 1;

            if ($API->connect($nas_ip, $api_user, $api_pass, $api_port)) {
                // Get all simple queues
                $queues = $API->comm('/queue/simple/print');
                $queue_map = [];
                if (is_array($queues)) {
                    foreach ($queues as $q) {
                        $q_name = trim($q['name'] ?? '');
                        if (!empty($q_name)) {
                            // Can match "<pppoe-username>" or "<username>"
                            $clean_q_name = preg_replace('/^<pppoe-|>$/', '', $q_name);
                            $queue_map[strtolower($clean_q_name)] = $q['.id'];
                            $queue_map[strtolower($q_name)] = $q['.id'];
                        }
                    }
                }

                foreach ($active_users as $usr) {
                    $u = strtolower(trim($usr['username']));
                    if (isset($queue_map[$u])) {
                        // Live in-place update of Simple Queue Max Limit without disconnecting the user!
                        $API->comm('/queue/simple/set', [
                            '.id'       => $queue_map[$u],
                            'max-limit' => $new_speed
                        ]);
                        $updated_count++;
                    } else {
                        // If queue not found, kick PPPoE session so user reconnects with new speed in 1s
                        try {
                            kick_subscriber_session($pdo, $usr['username'], $client_id);
                            $kicked_count++;
                        } catch (Exception $e) {}
                    }
                }

                $API->disconnect();
            }
        } catch (Exception $e) {}
    }

    return ['updated' => $updated_count, 'kicked' => $kicked_count];
}

/**
 * Master Speed Scheduler Engine
 * Scans packages with speed_scheduler, detects Day/Night transitions,
 * updates FreeRADIUS, and pushes live speeds to MikroTik routers.
 *
 * @param PDO $pdo
 * @param int|null $client_id
 * @param bool $force_sync
 * @return array
 */
function sync_all_speed_schedulers($pdo, $client_id = null, $force_sync = false) {
    $now_time = date('H:i');
    $where = ["p.speed_scheduler IS NOT NULL AND p.speed_scheduler != ''"];
    $params = [];

    if ($client_id !== null && $client_id > 0) {
        $where[] = "p.client_id = ?";
        $params[] = $client_id;
    }

    $sql = "SELECT p.*, pl.pool_name 
            FROM packages p 
            LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
            WHERE " . implode(' AND ', $where) . " 
            ORDER BY p.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];

    foreach ($packages as $pkg) {
        $pkg_id = (int)$pkg['id'];
        $pkg_name = $pkg['name'];
        $cid = (int)$pkg['client_id'];
        $pool_name = $pkg['pool_name'] ?? '';

        $active_info = get_active_package_speed($pkg, $now_time);
        $target_speed = $active_info['speed'];
        $is_night = $active_info['is_scheduled'];
        $mode = $active_info['scheduler_mode'];

        // Check current speed in radgroupreply
        $grpStmt = $pdo->prepare("SELECT value FROM radgroupreply WHERE groupname = ? AND attribute = 'Mikrotik-Rate-Limit' LIMIT 1");
        $grpStmt->execute([$pkg_name]);
        $current_grp_speed = $grpStmt->fetchColumn() ?: '';

        $needs_update = $force_sync || (strcasecmp($current_grp_speed, $target_speed) !== 0);

        if ($needs_update) {
            // 1. Update FreeRADIUS radgroupreply and radreply
            $sub_count = sync_freeradius_rate_and_profile($pdo, $pkg_id, $pkg_name, $target_speed, $pool_name, $cid);

            // 2. Live update MikroTik routers (Simple Queues & Sessions)
            $live_res = live_update_mikrotik_speed_for_package($pdo, $pkg_id, $cid, $target_speed);

            // 3. Log to activity_logs
            try {
                $act_desc = "Speed Scheduler applied [{$mode} mode]: Package '{$pkg_name}' speed set to {$target_speed} ({$sub_count} users synced, {$live_res['updated']} live queues updated)";
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'System Scheduler', ?, 'Package', ?)")
                    ->execute([$cid, $pkg_name, $act_desc]);
            } catch (Exception $e) {}

            $results[] = [
                'package_id'    => $pkg_id,
                'package_name'  => $pkg_name,
                'mode'          => $mode,
                'speed'         => $target_speed,
                'sub_count'     => $sub_count,
                'live_updated'  => $live_res['updated'],
                'live_kicked'   => $live_res['kicked'],
                'changed'       => true
            ];
        } else {
            $results[] = [
                'package_id'    => $pkg_id,
                'package_name'  => $pkg_name,
                'mode'          => $mode,
                'speed'         => $target_speed,
                'changed'       => false
            ];
        }
    }

    return $results;
}
