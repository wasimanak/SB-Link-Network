<?php
/**
 * NetBee / SB Link - Centralized Security & Rate Limiting Guard
 * 
 * Functions:
 * 1. Brute-force & Rate Limiting for login portals (SuperAdmin, Staff, Customer).
 * 2. Protection against session fixation and cookie hijacking.
 * 3. Guard against unauthorized access to maintenance/repair scripts.
 */
// Enforce Secure & HttpOnly Session Cookies (Blocks XSS Cookie Theft)
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.use_only_cookies', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        @ini_set('session.cookie_secure', '1');
    }
}
if (!function_exists('get_client_ip')) {
    function get_client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP']; // Cloudflare
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('init_security_tables')) {
    function init_security_tables($pdo) {
        static $initialized = false;
        if ($initialized || !$pdo) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `security_login_attempts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `portal` VARCHAR(30) NOT NULL,
                `identifier` VARCHAR(100) NOT NULL,
                `attempt_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_ip_portal` (`ip_address`, `portal`, `attempt_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $initialized = true;
        } catch (Exception $e) {}
    }
}

if (!function_exists('check_rate_limit')) {
    /**
     * Check if client IP is currently rate-limited (too many failed attempts)
     * 
     * @return array [bool $is_blocked, int $remaining_seconds]
     */
    function check_rate_limit($pdo, $portal = 'general', $max_attempts = 5, $lockout_minutes = 15) {
        if (!$pdo) return [false, 0];
        init_security_tables($pdo);
        $ip = get_client_ip();

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as attempts, MAX(attempt_time) as last_attempt 
                                   FROM `security_login_attempts` 
                                   WHERE `ip_address` = ? AND `portal` = ? 
                                     AND `attempt_time` > (NOW() - INTERVAL ? MINUTE)");
            $stmt->execute([$ip, $portal, $lockout_minutes]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);

            $attempts = (int)($res['attempts'] ?? 0);
            if ($attempts >= $max_attempts && !empty($res['last_attempt'])) {
                $last_time = strtotime($res['last_attempt']);
                $unlock_time = $last_time + ($lockout_minutes * 60);
                $remaining = max(0, $unlock_time - time());
                if ($remaining > 0) {
                    return [true, $remaining];
                }
            }
        } catch (Exception $e) {}

        return [false, 0];
    }
}

if (!function_exists('record_failed_attempt')) {
    function record_failed_attempt($pdo, $portal, $identifier) {
        if (!$pdo) return;
        init_security_tables($pdo);
        $ip = get_client_ip();
        try {
            $stmt = $pdo->prepare("INSERT INTO `security_login_attempts` (`ip_address`, `portal`, `identifier`, `attempt_time`) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$ip, $portal, substr(trim($identifier), 0, 100)]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('clear_failed_attempts')) {
    function clear_failed_attempts($pdo, $portal) {
        if (!$pdo) return;
        $ip = get_client_ip();
        try {
            $stmt = $pdo->prepare("DELETE FROM `security_login_attempts` WHERE `ip_address` = ? AND `portal` = ?");
            $stmt->execute([$ip, $portal]);
        } catch (Exception $e) {}
    }
}

if (!function_exists('guard_maintenance_script')) {
    /**
     * Prevents random web visitors from hitting repair/migration scripts directly
     */
    function guard_maintenance_script($script_title = 'Maintenance Tool') {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $is_cli = (php_sapi_name() === 'cli');
        $is_superadmin = !empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true;

        if (!$is_cli && !$is_superadmin) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>403 Forbidden - Access Restricted</title>
                <style>
                    body { background: #0b1120; color: #f8fafc; font-family: system-ui, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .box { background: #1e293b; padding: 40px; border-radius: 16px; border: 1px solid #334155; text-align: center; max-width: 480px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
                    h2 { color: #ef4444; margin-top: 0; }
                    p { color: #94a3b8; line-height: 1.6; }
                    a { display: inline-block; margin-top: 15px; color: #38bdf8; text-decoration: none; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class="box">
                    <h2><span style="font-size: 32px;">🛡️</span> Access Restricted</h2>
                    <p><strong><?= htmlspecialchars($script_title) ?></strong> is a protected system tool.</p>
                    <p>For network safety, this script can only be run via SSH Terminal (CLI) or by an authenticated Super Administrator.</p>
                    <a href="/superadmin/login.php">&larr; Super Admin Login</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}
