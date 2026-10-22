<?php
// Centralized MikroTik & RADIUS Auto-Sync & Multi-Tenant Network Helper
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros_api.class.php';

/**
 * 1. Initialize Network Database Columns & Defaults for an Operator
 */
if (!function_exists('init_operator_network_defaults')) {
function init_operator_network_defaults($pdo, $client_id) {
    if (empty($client_id)) return;

    try {
        // Ensure ip_pools table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ip_pools` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `client_id` INT NOT NULL,
          `nas_id` INT DEFAULT NULL,
          `pool_name` VARCHAR(64) NOT NULL,
          `ip_range` VARCHAR(100) NOT NULL,
          `gateway` VARCHAR(50) DEFAULT NULL,
          `subnet_cidr` VARCHAR(50) DEFAULT NULL,
          `wan_interface` VARCHAR(100) NOT NULL DEFAULT 'PTCL',
          `wan_provider_name` VARCHAR(100) DEFAULT 'PTCL',
          `routing_mark` VARCHAR(64) NOT NULL DEFAULT 'to-PTCL',
          `dns_servers` VARCHAR(100) DEFAULT '8.8.8.8, 1.1.1.1',
          `total_ips` INT DEFAULT 509,
          `used_ips` INT DEFAULT 0,
          `available_ips` INT DEFAULT 509,
          `mikrotik_synced` TINYINT(1) DEFAULT 0,
          `last_sync_error` TEXT DEFAULT NULL,
          `status` ENUM('active', 'inactive') DEFAULT 'active',
          `description` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX (`client_id`),
          INDEX (`nas_id`),
          INDEX (`pool_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Ensure router_wans table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS `router_wans` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `client_id` INT NOT NULL,
          `nas_id` INT DEFAULT NULL,
          `wan_name` VARCHAR(100) NOT NULL,
          `interface_name` VARCHAR(100) DEFAULT NULL,
          `routing_mark` VARCHAR(100) DEFAULT NULL,
          `status` ENUM('active', 'inactive') DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY `uk_client_wan` (`client_id`, `wan_name`),
          INDEX (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Seed a default WAN ONLY if operator has zero WANs configured
        $has_wans = $pdo->prepare("SELECT COUNT(*) FROM router_wans WHERE client_id = ?");
        $has_wans->execute([$client_id]);
        if ($has_wans->fetchColumn() == 0) {
            $pdo->prepare("INSERT IGNORE INTO router_wans (client_id, wan_name, routing_mark) VALUES (?, 'PTCL', 'to-PTCL')")
                ->execute([$client_id]);
        }

        // Auto-clean any hardware ether ports or dynamic sessions from router_wans
        $pdo->exec("DELETE FROM router_wans WHERE 
            wan_name LIKE 'ether%' 
            OR wan_name LIKE 'Ether%' 
            OR wan_name LIKE 'sfp%' 
            OR wan_name LIKE '<%' 
            OR wan_name LIKE '%>%' 
            OR wan_name LIKE '%pppoe%' 
            OR wan_name LIKE '%bandwidth%'
            OR wan_name LIKE '%ipt%'
            OR wan_name LIKE '%vlan%'
            OR wan_name LIKE '%VLAN%'");
    } catch (Exception $e) {}
}
}

/**
 * 2. Strict Validator: Only True WAN Providers Allowed
 */
if (!function_exists('is_valid_wan_provider_name')) {
function is_valid_wan_provider_name($name) {
    $name = trim($name);
    if (empty($name)) return false;
    
    // Block dynamic interfaces or angles
    if (strpos($name, '<') !== false || strpos($name, '>') !== false) return false;
    if (stripos($name, 'pppoe') !== false) return false;
    
    // Block physical ports: ether1..24, sfp, wlan, bridge
    if (preg_match('/^(ether|sfp|wlan|combo|bridge|loopback|switch)\d*/i', $name)) return false;

    // Block speed/pool names
    if (preg_match('/^\d+\s*(mbps|mb|m|k|kbps)$/i', $name)) return false;
    if (preg_match('/^(disable|expired|pppoe\s*pool)/i', $name)) return false;

    // Block comments/queues
    if (preg_match('/\b(bandwidth|traffic|queue|speed|rx|tx|ipt|link|test|port|vlan)\b/i', $name)) return false;

    // Block system lists
    $ignore = ['blacklist', 'whitelist', 'drop', 'allowed', 'bogons', 'block', 'isolated', 'invalid', 'local', 'lan', 'bridge', 'loopback', 'default', 'main', 'dns', 'ntp'];
    if (in_array(strtolower($name), $ignore)) return false;

    // Block raw IPs
    if (preg_match('/^\d+\.\d+\.\d+\.\d+/', $name)) return false;

    // Length check
    if (strlen($name) < 2 || strlen($name) > 30) return false;

    // Well-known ISPs
    if (preg_match('/^(ptcl|telenor|zong|stormfiber|nayatel|cybernet|wateen|transworld|jazz|ufone)$/i', $name)) {
        return true;
    }

    // Clean custom WANs: WAN1, WAN2, ISP1, ISP2
    if (preg_match('/^(wan|isp)[-_]?\d+$/i', $name)) {
        return true;
    }

    // Custom named providers containing no spaces
    if (preg_match('/^[A-Za-z0-9_-]{2,20}$/', $name)) {
        return true;
    }

    return false;
}
}

if (!function_exists('is_valid_wan_name')) {
    function is_valid_wan_name($name) {
        return is_valid_wan_provider_name($name);
    }
}

/**
 * 3. Auto-Calculate Subnet CIDR, Total IPs, and Gateway from an IP Range
 */
if (!function_exists('parse_ip_range_info')) {
function parse_ip_range_info($ip_range) {
    $ip_range = trim($ip_range);
    $result = [
        'subnet_cidr' => '',
        'total_ips' => 509,
        'gateway' => ''
    ];

    if (empty($ip_range)) return $result;

    if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s*-\s*(\d+\.\d+\.\d+\.\d+)$/', $ip_range, $matches)) {
        $start_ip = $matches[1];
        $end_ip = $matches[2];
        $start_long = ip2long($start_ip);
        $end_long = ip2long($end_ip);

        if ($start_long !== false && $end_long !== false && $end_long >= $start_long) {
            $count = ($end_long - $start_long) + 1;
            $result['total_ips'] = $count;

            $start_parts = explode('.', $start_ip);
            $end_parts = explode('.', $end_ip);

            // Suggested gateway
            if ($start_parts[3] == 2) {
                $result['gateway'] = "{$start_parts[0]}.{$start_parts[1]}.{$start_parts[2]}.1";
            }

            // Standard Class C (/24)
            if ($start_parts[0] == $end_parts[0] && $start_parts[1] == $end_parts[1] && $start_parts[2] == $end_parts[2]) {
                $result['subnet_cidr'] = "{$start_parts[0]}.{$start_parts[1]}.{$start_parts[2]}.0/24";
            }
            // Standard /23
            elseif ($start_parts[0] == $end_parts[0] && $start_parts[1] == $end_parts[1] && abs((int)$end_parts[2] - (int)$start_parts[2]) == 1) {
                $base_c = min((int)$start_parts[2], (int)$end_parts[2]);
                $base_c = $base_c - ($base_c % 2);
                $result['subnet_cidr'] = "{$start_parts[0]}.{$start_parts[1]}.{$base_c}.0/23";
            }
            // Standard /22
            elseif ($start_parts[0] == $end_parts[0] && $start_parts[1] == $end_parts[1] && abs((int)$end_parts[2] - (int)$start_parts[2]) <= 3) {
                $base_c = min((int)$start_parts[2], (int)$end_parts[2]);
                $base_c = $base_c - ($base_c % 4);
                $result['subnet_cidr'] = "{$start_parts[0]}.{$start_parts[1]}.{$base_c}.0/22";
            } else {
                $result['subnet_cidr'] = "{$start_parts[0]}.{$start_parts[1]}.{$start_parts[2]}.0/24";
            }
        }
    } elseif (preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d+)$/', $ip_range)) {
        $result['subnet_cidr'] = $ip_range;
    }

    return $result;
}
}

/**
 * 4. Live Sync Pools & WANs from MikroTik Router to Database (Auto-Import)
 */
if (!function_exists('sync_operator_mikrotik_pools')) {
function sync_operator_mikrotik_pools($pdo, $client_id) {
    init_operator_network_defaults($pdo, $client_id);

    $stmt = $pdo->prepare("SELECT id, nasname, api_port, api_user, api_password FROM nas WHERE client_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();
    if (!$nas) {
        return ['status' => false, 'message' => 'No router configured for this operator.'];
    }

    $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;
    $api_user = $nas['api_user'] ?: 'admin';
    $api_pass = $nas['api_password'] ?: '';

    $API = new RouterosAPI();
    $API->timeout = 3;
    $API->attempts = 1;
    $API->delay = 1;
    error_reporting(0);

    if (!$API->connect($nas['nasname'], $api_user, $api_pass, $api_port)) {
        return ['status' => false, 'message' => "Could not connect to router at {$nas['nasname']}:{$api_port}."];
    }

    try {
        // 1. Read from Router
        $raw_pools = $API->comm('/ip/pool/print');
        $raw_alists = $API->comm('/ip/firewall/address-list/print');
        $raw_mangle = $API->comm('/ip/firewall/mangle/print');
        $API->disconnect();

        $comment_to_list = [];
        $addr_to_list = [];
        $detected_wans = [];

        // Address lists parsing
        if (is_array($raw_alists)) {
            foreach ($raw_alists as $al) {
                // Ignore temporary dynamic client entries
                if (isset($al['dynamic']) && ($al['dynamic'] === 'true' || $al['dynamic'] === 'yes')) continue;

                $list_name = trim($al['list'] ?? '');
                $comment = trim($al['comment'] ?? '');
                $addr = trim($al['address'] ?? '');

                if (!empty($list_name) && is_valid_wan_provider_name($list_name)) {
                    $detected_wans[$list_name] = true;
                }
                if (!empty($comment) && !empty($list_name)) {
                    $comment_to_list[strtolower($comment)] = $list_name;
                }
                if (!empty($addr) && !empty($list_name)) {
                    $addr_to_list[$addr] = $list_name;
                }
            }
        }

        // Mangle rules parsing
        if (is_array($raw_mangle)) {
            foreach ($raw_mangle as $m) {
                $sal = trim($m['src-address-list'] ?? '');
                if (!empty($sal) && is_valid_wan_provider_name($sal)) {
                    $detected_wans[$sal] = true;
                }
                $rmark = trim($m['new-routing-mark'] ?? ($m['routing-mark'] ?? ''));
                if (!empty($rmark) && strcasecmp($rmark, 'main') !== 0) {
                    $clean_m = preg_replace('/^to[-_]/i', '', $rmark);
                    if (!empty($clean_m) && is_valid_wan_provider_name($clean_m)) {
                        $detected_wans[$clean_m] = true;
                    }
                }
            }
        }

        // Persist detected WANs in router_wans
        foreach (array_keys($detected_wans) as $w) {
            $pdo->prepare("INSERT INTO router_wans (client_id, nas_id, wan_name, routing_mark) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE nas_id = COALESCE(VALUES(nas_id), nas_id), status = 'active', updated_at = NOW()")
                ->execute([$client_id, $nas['id'], $w, 'to-' . $w]);
        }

        // Auto-clean any ghost WANs for this operator that do NOT exist on the router and are NOT used by any of their packages or pools
        if (!empty($detected_wans)) {
            $det_keys = array_keys($detected_wans);
            $in_det_ph = implode(',', array_fill(0, count($det_keys), '?'));
            $prune_sql = "DELETE FROM router_wans 
                          WHERE client_id = ? 
                            AND wan_name NOT IN ($in_det_ph)
                            AND wan_name NOT IN (SELECT DISTINCT wan_provider FROM packages WHERE client_id = ? AND wan_provider IS NOT NULL)
                            AND wan_name NOT IN (SELECT DISTINCT wan_provider_name FROM ip_pools WHERE client_id = ? AND wan_provider_name IS NOT NULL)";
            $pdo->prepare($prune_sql)->execute(array_merge([$client_id], $det_keys, [$client_id, $client_id]));
        }

        $synced_pools_count = 0;
        $new_pools_count = 0;

        // Auto-Import & Update Pools in ip_pools table
        if (is_array($raw_pools)) {
            foreach ($raw_pools as $p) {
                $p_name = trim($p['name'] ?? '');
                $p_ranges = trim($p['ranges'] ?? '');
                $p_total = (int)($p['total'] ?? 0);
                $p_used = (int)($p['used'] ?? 0);

                if (empty($p_name) || empty($p_ranges)) continue;

                $parsed = parse_ip_range_info($p_ranges);
                if ($p_total <= 0) $p_total = $parsed['total_ips'];
                $p_avail = max(0, $p_total - $p_used);
                $subnet_cidr = $parsed['subnet_cidr'];

                // Smart Match WAN Provider
                $assigned_wan = '';
                $lower_n = strtolower($p_name);
                if (isset($comment_to_list[$lower_n])) {
                    $assigned_wan = $comment_to_list[$lower_n];
                } elseif (!empty($subnet_cidr) && isset($addr_to_list[$subnet_cidr])) {
                    $assigned_wan = $addr_to_list[$subnet_cidr];
                }

                // If not matched by comment/address, check if pool name mentions any detected WAN (e.g. Zong, PTCL, Telenor)
                if (empty($assigned_wan)) {
                    foreach (array_keys($detected_wans) as $dw) {
                        if (stripos($p_name, $dw) !== false) {
                            $assigned_wan = $dw;
                            break;
                        }
                    }
                }

                // Fallback to primary detected WAN
                if (empty($assigned_wan) || !in_array($assigned_wan, array_keys($detected_wans))) {
                    if (isset($detected_wans['PTCL'])) {
                        $assigned_wan = 'PTCL';
                    } elseif (!empty($detected_wans)) {
                        $assigned_wan = array_keys($detected_wans)[0];
                    } else {
                        $assigned_wan = 'PTCL';
                    }
                }

                // Check DB
                $chk = $pdo->prepare("SELECT id FROM ip_pools WHERE pool_name = ? AND client_id = ?");
                $chk->execute([$p_name, $client_id]);
                $ex_id = $chk->fetchColumn();

                if ($ex_id) {
                    $upd = $pdo->prepare("UPDATE ip_pools SET nas_id=?, ip_range=?, subnet_cidr=?, wan_provider_name=?, wan_interface=?, routing_mark=?, total_ips=?, used_ips=?, available_ips=?, mikrotik_synced=1 WHERE id=?");
                    $upd->execute([$nas['id'], $p_ranges, $subnet_cidr, $assigned_wan, $assigned_wan, 'to-' . $assigned_wan, $p_total, $p_used, $p_avail, $ex_id]);
                    $synced_pools_count++;
                } else {
                    $ins = $pdo->prepare("INSERT INTO ip_pools (client_id, nas_id, pool_name, ip_range, subnet_cidr, wan_provider_name, wan_interface, routing_mark, total_ips, used_ips, available_ips, mikrotik_synced) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $ins->execute([$client_id, $nas['id'], $p_name, $p_ranges, $subnet_cidr, $assigned_wan, $assigned_wan, 'to-' . $assigned_wan, $p_total, $p_used, $p_avail]);
                    $new_pool_id = $pdo->lastInsertId();
                    $synced_pools_count++;
                    $new_pools_count++;

                    // Auto-link any existing unassigned package with matching name or speed
                    preg_match('/(\d+)\s*(mbps|mb|m)/i', $p_name, $m_num);
                    $speed_val = $m_num[1] ?? '';
                    $pdo->prepare("UPDATE packages SET pool_id = ?, wan_provider = ?, wan_interface = ?, routing_mark = ? 
                                   WHERE client_id = ? AND (pool_id IS NULL OR pool_id = 0) AND (name = ? OR name LIKE ?)")
                        ->execute([$new_pool_id, $assigned_wan, $assigned_wan, 'to-' . $assigned_wan, $client_id, $p_name, $speed_val ? "{$speed_val}%" : $p_name]);
                }
            }
        }

        $wan_mode_str = count($detected_wans) > 1 
            ? count($detected_wans) . " WAN Ports detected (Multi-WAN Load Balancing Active: " . implode(' & ', array_keys($detected_wans)) . ")"
            : (count($detected_wans) == 1 
                ? "1 WAN Port detected (" . array_keys($detected_wans)[0] . ") (Single WAN - Standard Routing, No Load Balancing)" 
                : "0 WAN Ports detected");

        return [
            'status' => true,
            'message' => "Router Synced: {$synced_pools_count} Pools checked ({$new_pools_count} newly imported), {$wan_mode_str}.",
            'pools_count' => $synced_pools_count,
            'new_pools' => $new_pools_count,
            'wans' => array_keys($detected_wans),
            'is_multi_wan' => (count($detected_wans) > 1)
        ];
    } catch (Exception $e) {
        return ['status' => false, 'message' => $e->getMessage()];
    }
}
}

/**
 * 5. Create a New Pool in Database AND Live on MikroTik Router
 */
if (!function_exists('create_mikrotik_pool_and_route')) {
function create_mikrotik_pool_and_route($pdo, $client_id, $pool_name, $ip_range, $wan_provider, $subnet_cidr = '', $gateway = '') {
    init_operator_network_defaults($pdo, $client_id);

    $pool_name = trim($pool_name);
    $ip_range = trim($ip_range);
    $wan_provider = trim($wan_provider) ?: 'PTCL';
    $subnet_cidr = trim($subnet_cidr);
    $gateway = trim($gateway);

    if (empty($pool_name) || empty($ip_range)) {
        return ['status' => false, 'message' => 'Pool Name and IP Range are required.'];
    }

    // Auto-parse CIDR if not provided
    $parsed = parse_ip_range_info($ip_range);
    if (empty($subnet_cidr)) $subnet_cidr = $parsed['subnet_cidr'];
    if (empty($gateway)) $gateway = $parsed['gateway'];
    $total_ips = $parsed['total_ips'] ?: 509;

    // Check duplicate in DB
    $chk = $pdo->prepare("SELECT id FROM ip_pools WHERE pool_name = ? AND client_id = ?");
    $chk->execute([$pool_name, $client_id]);
    if ($chk->fetchColumn()) {
        return ['status' => false, 'message' => "A pool with the name '{$pool_name}' already exists in your account!"];
    }

    // Register WAN in router_wans table
    $pdo->prepare("INSERT INTO router_wans (client_id, wan_name, routing_mark) VALUES (?, ?, ?) 
                   ON DUPLICATE KEY UPDATE status = 'active'")
        ->execute([$client_id, $wan_provider, 'to-' . $wan_provider]);

    // Connect to MikroTik if router configured
    $nas_stmt = $pdo->prepare("SELECT id, nasname, api_port, api_user, api_password FROM nas WHERE client_id = ? ORDER BY id ASC LIMIT 1");
    $nas_stmt->execute([$client_id]);
    $nas = $nas_stmt->fetch();

    $mikrotik_synced = 0;
    $router_feedback = "";

    if ($nas && !empty($nas['nasname']) && !empty($nas['api_user'])) {
        $API = new RouterosAPI();
        $API->timeout = 4;
        $API->attempts = 1;
        $API->delay = 1;
        error_reporting(0);
        $port = $nas['api_port'] ?: 8728;

        if ($API->connect($nas['nasname'], $nas['api_user'], $nas['api_password'] ?: '', $port)) {
            // A. Check & Add Pool on MikroTik (/ip/pool)
            $existing_pools = $API->comm('/ip/pool/print');
            $found_pool_id = null;
            if (is_array($existing_pools)) {
                foreach ($existing_pools as $ep) {
                    if (strcasecmp(trim($ep['name'] ?? ''), $pool_name) === 0) {
                        $found_pool_id = $ep['.id'];
                        break;
                    }
                }
            }

            if ($found_pool_id) {
                $API->comm('/ip/pool/set', [
                    '.id' => $found_pool_id,
                    'ranges' => $ip_range
                ]);
            } else {
                $API->comm('/ip/pool/add', [
                    'name' => $pool_name,
                    'ranges' => $ip_range
                ]);
            }

            // B. Check & Add Address List on MikroTik (/ip/firewall/address-list)
            if (!empty($subnet_cidr)) {
                $existing_alists = $API->comm('/ip/firewall/address-list/print');
                $found_al_id = null;
                if (is_array($existing_alists)) {
                    foreach ($existing_alists as $al) {
                        if (strcasecmp(trim($al['comment'] ?? ''), $pool_name) === 0) {
                            $found_al_id = $al['.id'];
                            break;
                        }
                    }
                }

                if ($found_al_id) {
                    $API->comm('/ip/firewall/address-list/set', [
                        '.id' => $found_al_id,
                        'list' => $wan_provider,
                        'address' => $subnet_cidr,
                        'comment' => $pool_name
                    ]);
                } else {
                    $API->comm('/ip/firewall/address-list/add', [
                        'list' => $wan_provider,
                        'address' => $subnet_cidr,
                        'comment' => $pool_name
                    ]);
                }
            }

            $API->disconnect();
            $mikrotik_synced = 1;
            $router_feedback = " & created live on MikroTik Router (/ip/pool and Address List: {$wan_provider})!";
        } else {
            $router_feedback = " (Router unreachable - saved in database and can be pushed later).";
        }
    } else {
        $router_feedback = " (Saved in database - connect router to push automatically).";
    }

    // Insert into ip_pools
    $ins = $pdo->prepare("INSERT INTO ip_pools (client_id, nas_id, pool_name, ip_range, gateway, subnet_cidr, wan_provider_name, wan_interface, routing_mark, total_ips, available_ips, mikrotik_synced)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $client_id,
        $nas ? $nas['id'] : null,
        $pool_name,
        $ip_range,
        $gateway,
        $subnet_cidr,
        $wan_provider,
        $wan_provider,
        'to-' . $wan_provider,
        $total_ips,
        $total_ips,
        $mikrotik_synced
    ]);
    $new_id = $pdo->lastInsertId();

    return [
        'status' => true,
        'id' => $new_id,
        'message' => "Pool '<strong>{$pool_name}</strong>' ({$ip_range}) created successfully{$router_feedback}"
    ];
}
}
