<?php
// =========================================================================
// NetBee / SB Link Network - SMS Reminders & Mobile Gateway Engine
// Enables using an Android smartphone as an unlimited self-hosted SMS Gateway
// Handles automated expiry alerts, custom bulk SMS, queue management & logs
// =========================================================================

if (!defined('SMS_MAX_CHAR_SINGLE')) {
    define('SMS_MAX_CHAR_SINGLE', 160);
}

/**
 * Ensure necessary SMS tables and schema exist in the database (Self-Healing)
 */
if (!function_exists('ensure_sms_tables_exist')) {
    function ensure_sms_tables_exist($pdo) {
        static $tables_checked = false;
        if ($tables_checked) return;
        $tables_checked = true;

        // 1. Gateway Settings & Templates Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sms_gateway_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `client_id` INT NOT NULL UNIQUE,
                `gateway_mode` VARCHAR(30) NOT NULL DEFAULT 'polling',
                `gateway_token` VARCHAR(64) NOT NULL UNIQUE,
                `gateway_http_url` VARCHAR(255) NULL,
                `gateway_api_key` VARCHAR(255) NULL,
                `sim_slot` TINYINT NOT NULL DEFAULT 1,
                `auto_expiry_reminders` TINYINT NOT NULL DEFAULT 1,
                `remind_days_before` VARCHAR(50) NOT NULL DEFAULT '3,1',
                `remind_on_expiry` TINYINT NOT NULL DEFAULT 1,
                `remind_days_after` VARCHAR(50) NOT NULL DEFAULT '1,3',
                `template_before_expiry` TEXT NULL,
                `template_on_expiry` TEXT NULL,
                `template_after_expiry` TEXT NULL,
                `last_phone_ping_at` DATETIME NULL,
                `phone_device_name` VARCHAR(100) NULL,
                `phone_battery_level` VARCHAR(20) NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`client_id`),
                INDEX (`gateway_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 2. SMS Queue & Sent Logs Table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sms_queue_logs` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `client_id` INT NOT NULL,
                `subscriber_id` INT NULL,
                `username` VARCHAR(100) NULL,
                `recipient_mobile` VARCHAR(50) NOT NULL,
                `message_type` VARCHAR(40) NOT NULL DEFAULT 'custom_single',
                `message_text` TEXT NOT NULL,
                `status` ENUM('pending', 'sent', 'failed', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
                `error_message` TEXT NULL,
                `gateway_ref` VARCHAR(100) NULL,
                `scheduled_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `sent_at` DATETIME NULL,
                `created_by` VARCHAR(100) DEFAULT 'System',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (`client_id`),
                INDEX (`subscriber_id`),
                INDEX (`recipient_mobile`),
                INDEX (`status`),
                INDEX (`scheduled_at`),
                INDEX (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
}

/**
 * Clean & Format Pakistani Mobile Numbers for SIM SMS dispatch
 * Converts +923001234567, 923001234567, 0300-1234567 into standard 03001234567 format
 */
if (!function_exists('format_mobile_for_sms')) {
    function format_mobile_for_sms($raw) {
        $clean = preg_replace('/[^0-9]/', '', (string)$raw);
        if (empty($clean)) return '';

        // If starts with 923... (12 digits)
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '92') {
            return '0' . substr($clean, 2);
        }
        // If starts with 00923... (14 digits)
        if (strlen($clean) === 14 && substr($clean, 0, 4) === '0092') {
            return '0' . substr($clean, 4);
        }
        // Standard 11-digit Pakistani mobile 03001234567
        if (strlen($clean) === 11 && substr($clean, 0, 2) === '03') {
            return $clean;
        }
        // 10 digits without leading 0 (e.g. 3001234567)
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '3') {
            return '0' . $clean;
        }

        return $clean;
    }
}

/**
 * Fetch or Initialize Client's SMS Gateway Settings
 */
if (!function_exists('get_sms_gateway_settings')) {
    function get_sms_gateway_settings($pdo, $client_id) {
        ensure_sms_tables_exist($pdo);
        $client_id = (int)$client_id;

        $stmt = $pdo->prepare("SELECT * FROM sms_gateway_settings WHERE client_id = ? LIMIT 1");
        $stmt->execute([$client_id]);
        $settings = $stmt->fetch();

        if (!$settings) {
            // Generate a secure 32-character random token
            $newToken = bin2hex(random_bytes(16));

            $defaultBefore = "Muhtaram {name} ({username}), apka internet package {package} ({price} PKR) {expiry_date} ko expire ho jayega. Baraye meherbani qabal az waqt renew karwayen ta k internet jari rahe. Shukriya - {company}";
            $defaultOn     = "Muhtaram {name} ({username}), apka internet package {package} aaj expire ho chuka hai. Fauri renewal k liye rabta karein: {phone}. Shukriya - {company}";
            $defaultAfter  = "Salam {name}, apka internet package expire hue {days_expired} din ho chuke hain. Dubara bahali k liye rabta karein: {phone}. Shukriya - {company}";

            $ins = $pdo->prepare("
                INSERT INTO sms_gateway_settings (
                    client_id, gateway_mode, gateway_token, sim_slot,
                    auto_expiry_reminders, remind_days_before, remind_on_expiry, remind_days_after,
                    template_before_expiry, template_on_expiry, template_after_expiry
                ) VALUES (
                    ?, 'polling', ?, 1,
                    1, '3,1', 1, '1,3',
                    ?, ?, ?
                )
            ");
            $ins->execute([$client_id, $newToken, $defaultBefore, $defaultOn, $defaultAfter]);

            $stmt->execute([$client_id]);
            $settings = $stmt->fetch();
        }

        // Set default template fallbacks if empty
        if (empty($settings['template_before_expiry'])) {
            $settings['template_before_expiry'] = "Muhtaram {name} ({username}), apka internet package {package} ({price} PKR) {expiry_date} ko expire ho jayega. Baraye meherbani qabal az waqt renew karwayen ta k internet jari rahe. Shukriya - {company}";
        }
        if (empty($settings['template_on_expiry'])) {
            $settings['template_on_expiry'] = "Muhtaram {name} ({username}), apka internet package {package} aaj expire ho chuka hai. Fauri renewal k liye rabta karein: {phone}. Shukriya - {company}";
        }
        if (empty($settings['template_after_expiry'])) {
            $settings['template_after_expiry'] = "Salam {name}, apka internet package expire hue {days_expired} din ho chuke hain. Dubara bahali k liye rabta karein: {phone}. Shukriya - {company}";
        }

        return $settings;
    }
}

/**
 * Save / Update Client SMS Gateway Settings
 */
if (!function_exists('save_sms_gateway_settings')) {
    function save_sms_gateway_settings($pdo, $client_id, $data) {
        ensure_sms_tables_exist($pdo);
        $client_id = (int)$client_id;

        $gateway_mode          = in_array($data['gateway_mode'] ?? '', ['polling', 'direct_http', 'custom_api']) ? $data['gateway_mode'] : 'polling';
        $gateway_http_url      = trim($data['gateway_http_url'] ?? '');
        $gateway_api_key       = trim($data['gateway_api_key'] ?? '');
        $sim_slot              = max(1, min(2, (int)($data['sim_slot'] ?? 1)));
        $auto_expiry_reminders = !empty($data['auto_expiry_reminders']) ? 1 : 0;
        $remind_days_before    = trim($data['remind_days_before'] ?? '3,1');
        $remind_on_expiry      = !empty($data['remind_on_expiry']) ? 1 : 0;
        $remind_days_after     = trim($data['remind_days_after'] ?? '1,3');
        $template_before       = trim($data['template_before_expiry'] ?? '');
        $template_on           = trim($data['template_on_expiry'] ?? '');
        $template_after        = trim($data['template_after_expiry'] ?? '');

        // Regenerate token if requested
        $tokenSql = "";
        $params = [
            $gateway_mode, $gateway_http_url, $gateway_api_key, $sim_slot,
            $auto_expiry_reminders, $remind_days_before, $remind_on_expiry, $remind_days_after,
            $template_before, $template_on, $template_after
        ];

        if (!empty($data['regenerate_token'])) {
            $newToken = bin2hex(random_bytes(16));
            $tokenSql = ", gateway_token = ?";
            $params[] = $newToken;
        }

        $params[] = $client_id;

        $stmt = $pdo->prepare("
            UPDATE sms_gateway_settings SET
                gateway_mode = ?,
                gateway_http_url = ?,
                gateway_api_key = ?,
                sim_slot = ?,
                auto_expiry_reminders = ?,
                remind_days_before = ?,
                remind_on_expiry = ?,
                remind_days_after = ?,
                template_before_expiry = ?,
                template_on_expiry = ?,
                template_after_expiry = ?
                $tokenSql
            WHERE client_id = ?
        ");
        return $stmt->execute($params);
    }
}

/**
 * Replace template smart tags with real subscriber details
 */
if (!function_exists('resolve_sms_template_tags')) {
    function resolve_sms_template_tags($template, $sub, $client) {
        $name        = !empty($sub['full_name']) ? $sub['full_name'] : (!empty($sub['name']) ? $sub['name'] : $sub['username']);
        $username    = $sub['username'] ?? '';
        $mobile      = $sub['mobile'] ?? ($sub['phone'] ?? '');
        $package     = $sub['package_name'] ?? 'Internet';
        $price       = number_format((float)($sub['package_price'] ?? ($sub['price'] ?? 0)), 0);
        $balance     = number_format((float)($sub['balance'] ?? 0), 2);
        $company     = !empty($client['company_name']) ? $client['company_name'] : (!empty($client['name']) ? $client['name'] : BRAND_NAME);
        $phone       = !empty($client['phone']) ? $client['phone'] : (!empty($client['mobile']) ? $client['mobile'] : BRAND_SUPPORT_PHONE);
        
        $expDateRaw  = $sub['expiry_date'] ?? '';
        $expDateFmt  = 'N/A';
        $daysLeft    = 0;
        $daysExpired = 0;

        if (!empty($expDateRaw) && $expDateRaw > '2000-01-01') {
            $expTs = strtotime($expDateRaw);
            $expDateFmt = date('d M Y, h:i A', $expTs);
            $diffSeconds = $expTs - time();
            if ($diffSeconds >= 0) {
                $daysLeft = (int)ceil($diffSeconds / 86400);
            } else {
                $daysExpired = (int)ceil(abs($diffSeconds) / 86400);
            }
        }

        $replacements = [
            '{name}'         => $name,
            '{username}'     => $username,
            '{mobile}'       => $mobile,
            '{package}'      => $package,
            '{price}'        => $price,
            '{balance}'      => $balance,
            '{expiry_date}'  => $expDateFmt,
            '{days_left}'    => (string)$daysLeft,
            '{days_expired}' => (string)$daysExpired,
            '{company}'      => $company,
            '{phone}'        => $phone,
            '{support}'      => $phone
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}

/**
 * Queue a single SMS message
 */
if (!function_exists('queue_sms_message')) {
    function queue_sms_message($pdo, $client_id, $mobile, $message, $type = 'custom_single', $sub_id = null, $username = null, $created_by = 'System', $scheduled_at = null) {
        ensure_sms_tables_exist($pdo);
        $cleanMobile = format_mobile_for_sms($mobile);
        $message = trim((string)$message);

        if (empty($cleanMobile) || strlen($cleanMobile) < 10) {
            return false;
        }
        if (empty($message)) {
            return false;
        }

        $sched = $scheduled_at ? $scheduled_at : date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO sms_queue_logs (
                client_id, subscriber_id, username, recipient_mobile,
                message_type, message_text, status, scheduled_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ");
        $stmt->execute([
            (int)$client_id,
            $sub_id ? (int)$sub_id : null,
            $username ? trim($username) : null,
            $cleanMobile,
            $type,
            $message,
            $sched,
            $created_by
        ]);

        return (int)$pdo->lastInsertId();
    }
}

/**
 * Scan Subscribers & Auto-Queue Expiry Reminders
 * Ensures no duplicate SMS is queued for the same user on the same date for the same rule.
 */
if (!function_exists('scan_and_queue_expiry_reminders')) {
    function scan_and_queue_expiry_reminders($pdo, $client_id) {
        $settings = get_sms_gateway_settings($pdo, $client_id);
        if (empty($settings['auto_expiry_reminders'])) {
            return ['status' => 'disabled', 'queued' => 0];
        }

        // Fetch client branding
        $cStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $cStmt->execute([$client_id]);
        $client = $cStmt->fetch() ?: [];

        // Parse rules
        $beforeDays = array_filter(array_map('intval', explode(',', $settings['remind_days_before'] ?? '3,1')));
        $afterDays  = array_filter(array_map('intval', explode(',', $settings['remind_days_after'] ?? '1,3')));
        $onExpiry   = !empty($settings['remind_on_expiry']);

        $todayDate = date('Y-m-d');
        $now = time();

        // Fetch all subscribers with valid mobile numbers and packages
        $stmt = $pdo->prepare("
            SELECT s.id, s.username, s.full_name, s.mobile, s.phone, s.status, s.expiry_date, s.balance,
                   p.name as package_name, p.price as package_price
            FROM subscribers s
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE s.client_id = ?
              AND (
                  (s.mobile IS NOT NULL AND s.mobile != '') 
                  OR (s.phone IS NOT NULL AND s.phone != '')
              )
              AND s.expiry_date IS NOT NULL
              AND s.expiry_date > '2000-01-01'
        ");
        $stmt->execute([$client_id]);
        $subscribers = $stmt->fetchAll();

        // Check already queued / sent messages today to avoid duplicates
        $chkStmt = $pdo->prepare("
            SELECT subscriber_id, message_type 
            FROM sms_queue_logs 
            WHERE client_id = ? 
              AND DATE(created_at) = ? 
              AND status IN ('pending', 'sent', 'delivered')
        ");
        $chkStmt->execute([$client_id, $todayDate]);
        $alreadySentToday = [];
        while ($row = $chkStmt->fetch()) {
            if ($row['subscriber_id']) {
                $alreadySentToday[$row['subscriber_id'] . '_' . $row['message_type']] = true;
            }
        }

        $queuedCount = 0;

        foreach ($subscribers as $sub) {
            $subId = (int)$sub['id'];
            $expTs = strtotime($sub['expiry_date']);
            $diffSeconds = $expTs - $now;
            $diffDays = (int)round($diffSeconds / 86400);

            $targetMobile = !empty($sub['mobile']) ? $sub['mobile'] : $sub['phone'];
            $formattedMobile = format_mobile_for_sms($targetMobile);
            if (empty($formattedMobile)) continue;

            // Rule 1: Before Expiry (diffDays > 0)
            if ($diffDays > 0 && in_array($diffDays, $beforeDays)) {
                $typeKey = "expiry_before_{$diffDays}d";
                if (!isset($alreadySentToday[$subId . '_' . $typeKey])) {
                    $msgText = resolve_sms_template_tags($settings['template_before_expiry'], $sub, $client);
                    queue_sms_message($pdo, $client_id, $formattedMobile, $msgText, $typeKey, $subId, $sub['username'], 'Auto Cron');
                    $alreadySentToday[$subId . '_' . $typeKey] = true;
                    $queuedCount++;
                }
            }

            // Rule 2: On Expiry Day (diffDays == 0 or expires today)
            elseif ($onExpiry && $diffDays === 0 && date('Y-m-d', $expTs) === $todayDate) {
                $typeKey = "expiry_on_day";
                if (!isset($alreadySentToday[$subId . '_' . $typeKey])) {
                    $msgText = resolve_sms_template_tags($settings['template_on_expiry'], $sub, $client);
                    queue_sms_message($pdo, $client_id, $formattedMobile, $msgText, $typeKey, $subId, $sub['username'], 'Auto Cron');
                    $alreadySentToday[$subId . '_' . $typeKey] = true;
                    $queuedCount++;
                }
            }

            // Rule 3: After Expiry (diffDays < 0 -> expired recovery)
            elseif ($diffDays < 0) {
                $pastDays = abs($diffDays);
                if (in_array($pastDays, $afterDays)) {
                    $typeKey = "expiry_after_{$pastDays}d";
                    if (!isset($alreadySentToday[$subId . '_' . $typeKey])) {
                        $msgText = resolve_sms_template_tags($settings['template_after_expiry'], $sub, $client);
                        queue_sms_message($pdo, $client_id, $formattedMobile, $msgText, $typeKey, $subId, $sub['username'], 'Auto Cron');
                        $alreadySentToday[$subId . '_' . $typeKey] = true;
                        $queuedCount++;
                    }
                }
            }
        }

        return ['status' => 'success', 'queued' => $queuedCount];
    }
}

/**
 * Dispatch pending messages if configured for Direct HTTP / Custom API Push
 */
if (!function_exists('dispatch_sms_direct_http')) {
    function dispatch_sms_direct_http($settings, $recipient, $message) {
        $url = trim($settings['gateway_http_url'] ?? '');
        if (empty($url)) {
            return ['success' => false, 'error' => 'Gateway HTTP URL is not configured'];
        }

        $apiKey  = trim($settings['gateway_api_key'] ?? '');
        $simSlot = (int)($settings['sim_slot'] ?? 1);

        // Standard Capcom6 / Android SMS Gateway REST API format:
        // POST to http://<ip>:8080/v1/sms/send with JSON payload
        $payload = [
            'phone'    => $recipient,
            'message'  => $message,
            'simNumber'=> $simSlot
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: ' . (!empty($apiKey) ? 'Basic ' . base64_encode('sms:' . $apiKey) : '')
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => "cURL Error: {$err}"];
        }

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'response' => $response];
        }

        return ['success' => false, 'error' => "HTTP {$code}: {$response}"];
    }
}

/**
 * Get Comprehensive SMS Stats for Admin Dashboard & Reminders UI
 */
if (!function_exists('get_sms_dashboard_stats')) {
    function get_sms_dashboard_stats($pdo, $client_id) {
        ensure_sms_tables_exist($pdo);
        $client_id = (int)$client_id;

        $stats = [
            'sent_today'     => 0,
            'sent_month'     => 0,
            'pending_queue'  => 0,
            'failed_count'   => 0,
            'gateway_online' => false,
            'phone_seen_ago' => 'Never',
            'device_name'    => 'Not Connected',
            'battery_level'  => 'N/A'
        ];

        // Queue & Logs Stats
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_queue,
                SUM(CASE WHEN status IN ('sent', 'delivered') AND DATE(sent_at) = CURRENT_DATE() THEN 1 ELSE 0 END) as sent_today,
                SUM(CASE WHEN status IN ('sent', 'delivered') AND sent_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN 1 ELSE 0 END) as sent_month,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM sms_queue_logs 
            WHERE client_id = ?
        ");
        $stmt->execute([$client_id]);
        $qRow = $stmt->fetch();
        if ($qRow) {
            $stats['pending_queue'] = (int)($qRow['pending_queue'] ?? 0);
            $stats['sent_today']    = (int)($qRow['sent_today'] ?? 0);
            $stats['sent_month']    = (int)($qRow['sent_month'] ?? 0);
            $stats['failed_count']  = (int)($qRow['failed_count'] ?? 0);
        }

        // Phone connection status
        $settings = get_sms_gateway_settings($pdo, $client_id);
        if (!empty($settings['last_phone_ping_at'])) {
            $lastPingTs = strtotime($settings['last_phone_ping_at']);
            $diffSeconds = time() - $lastPingTs;
            
            // If phone pinged in the last 90 seconds, mark as ONLINE
            if ($diffSeconds <= 900) {
                $stats['gateway_online'] = true;
            }

            if ($diffSeconds < 60) {
                $stats['phone_seen_ago'] = "{$diffSeconds}s ago";
            } elseif ($diffSeconds < 3600) {
                $stats['phone_seen_ago'] = round($diffSeconds / 60) . "m ago";
            } elseif ($diffSeconds < 86400) {
                $stats['phone_seen_ago'] = round($diffSeconds / 3600) . "h ago";
            } else {
                $stats['phone_seen_ago'] = date('d M, h:i A', $lastPingTs);
            }
        }

        if (!empty($settings['phone_device_name'])) {
            $stats['device_name'] = $settings['phone_device_name'];
        }
        if (!empty($settings['phone_battery_level'])) {
            $stats['battery_level'] = $settings['phone_battery_level'];
        }

        return $stats;
    }
}
