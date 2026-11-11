<?php
/**
 * Two-Factor Authentication (TOTP / Google Authenticator) Helper
 * Standard RFC 6238 implementation with Trusted Devices support.
 */

if (!function_exists('init_two_factor_schema')) {
    /**
     * Self-healing migration for 2FA columns and trusted devices table
     */
    function init_two_factor_schema(PDO $pdo): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            // Check & add columns to super_admins
            $cols = $pdo->query("SHOW COLUMNS FROM `super_admins`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('two_factor_secret', $cols)) {
                $pdo->exec("ALTER TABLE `super_admins` ADD COLUMN `two_factor_secret` VARCHAR(64) DEFAULT NULL AFTER `password`");
            }
            if (!in_array('two_factor_enabled', $cols)) {
                $pdo->exec("ALTER TABLE `super_admins` ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `two_factor_secret`");
            }
            if (!in_array('two_factor_recovery_codes', $cols)) {
                $pdo->exec("ALTER TABLE `super_admins` ADD COLUMN `two_factor_recovery_codes` TEXT DEFAULT NULL AFTER `two_factor_enabled`");
            }
            if (!in_array('two_factor_setup_at', $cols)) {
                $pdo->exec("ALTER TABLE `super_admins` ADD COLUMN `two_factor_setup_at` DATETIME DEFAULT NULL AFTER `two_factor_recovery_codes`");
            }

            // Create trusted devices table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `superadmin_trusted_devices` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `admin_id` INT(11) NOT NULL,
                    `device_token_hash` VARCHAR(64) NOT NULL,
                    `device_name` VARCHAR(255) DEFAULT NULL,
                    `ip_address` VARCHAR(45) DEFAULT NULL,
                    `user_agent` TEXT DEFAULT NULL,
                    `last_authenticated_at` DATETIME NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_admin_token` (`admin_id`, `device_token_hash`),
                    INDEX `idx_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            // Silent fallback or log
            error_log("2FA Schema initialization error: " . $e->getMessage());
        }
    }
}

if (!function_exists('totp_base32_decode')) {
    /**
     * Decode a Base32 string to binary (RFC 4648)
     */
    function totp_base32_decode(string $b32): string {
        $b32 = strtoupper(trim($b32));
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $binary = '';

        for ($i = 0, $len = strlen($b32); $i < $len; $i++) {
            $char = $b32[$i];
            if ($char === '=' || $char === ' ') continue;
            $val = strpos($chars, $char);
            if ($val === false) continue;

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $binary;
    }
}

if (!function_exists('totp_generate_secret')) {
    /**
     * Generate a cryptographically secure Base32 secret for Google Authenticator (16 chars = 80 bits)
     */
    function totp_generate_secret(int $length = 16): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $randomBytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[ord($randomBytes[$i]) % 32];
        }
        return $secret;
    }
}

if (!function_exists('totp_get_code')) {
    /**
     * Compute 6-digit TOTP code for a given secret and timestamp (RFC 6238)
     */
    function totp_get_code(string $secret, ?int $timestamp = null): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $timeSlice = (int)floor($timestamp / 30);
        $packedTime = pack('N*', 0, $timeSlice);
        $binarySecret = totp_base32_decode($secret);

        $hash = hash_hmac('sha1', $packedTime, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part)[1] & 0x7FFFFFFF;

        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totp_verify_code')) {
    /**
     * Verify a 6-digit TOTP code with time drift tolerance
     */
    function totp_verify_code(string $secret, string $code, int $discrepancy = 1): bool {
        $code = trim($code);
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        $currentTime = time();
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculated = totp_get_code($secret, $currentTime + ($i * 30));
            if (hash_equals($calculated, $code)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('totp_generate_recovery_codes')) {
    /**
     * Generate 8 single-use recovery backup codes
     */
    function totp_generate_recovery_codes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $codes[] = $code;
        }
        return $codes;
    }
}

if (!function_exists('totp_get_otpauth_url')) {
    /**
     * Generate standard otpauth URI for Google Authenticator QR Code
     */
    function totp_get_otpauth_url(string $secret, string $accountName, string $issuer = 'Lasani Network'): string {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountName);
        return "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }
}

if (!function_exists('totp_parse_user_agent')) {
    /**
     * Parse User-Agent string into human readable name
     */
    function totp_parse_user_agent(?string $ua): string {
        if (empty($ua)) return 'Unknown Device';

        $os = 'Unknown OS';
        if (stripos($ua, 'Windows NT 10.0') !== false) $os = 'Windows 10/11';
        elseif (stripos($ua, 'Windows NT 6.3') !== false) $os = 'Windows 8.1';
        elseif (stripos($ua, 'Windows NT 6.1') !== false) $os = 'Windows 7';
        elseif (stripos($ua, 'Windows') !== false) $os = 'Windows PC';
        elseif (stripos($ua, 'Android') !== false) $os = 'Android Device';
        elseif (stripos($ua, 'iPhone') !== false) $os = 'iPhone';
        elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';
        elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) $os = 'Apple Mac';
        elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

        $browser = 'Browser';
        if (stripos($ua, 'Edg') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'Chrome') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'Safari') !== false) $browser = 'Safari';
        elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) $browser = 'Opera';

        return "{$os} ({$browser})";
    }
}

if (!function_exists('totp_check_trusted_device')) {
    /**
     * Check if current browser is a trusted device for this superadmin
     * Returns:
     *   'is_trusted' => bool
     *   'requires_daily_2fa' => bool (true if 24 hours have passed since last authentication)
     *   'device' => array|null
     *   'raw_token' => string|null
     */
    function totp_check_trusted_device(PDO $pdo, int $adminId): array {
        $cookieName = 'sa_trusted_device';
        if (empty($_COOKIE[$cookieName])) {
            return ['is_trusted' => false, 'requires_daily_2fa' => true, 'device' => null, 'raw_token' => null];
        }

        $cookieVal = $_COOKIE[$cookieName];
        $parts = explode(':', $cookieVal, 2);
        if (count($parts) !== 2 || (int)$parts[0] !== $adminId) {
            return ['is_trusted' => false, 'requires_daily_2fa' => true, 'device' => null, 'raw_token' => null];
        }

        $rawToken = $parts[1];
        $tokenHash = hash('sha256', $rawToken);

        try {
            $currentSqlDate = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("
                SELECT * FROM `superadmin_trusted_devices` 
                WHERE `admin_id` = ? AND `device_token_hash` = ? AND `expires_at` > ? 
                LIMIT 1
            ");
            $stmt->execute([$adminId, $tokenHash, $currentSqlDate]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                return ['is_trusted' => false, 'requires_daily_2fa' => true, 'device' => null, 'raw_token' => null];
            }

            // Check if authenticated within the last 24 hours (86,400 seconds)
            $lastAuthTime = strtotime($device['last_authenticated_at']);
            $hoursPassed = (time() - $lastAuthTime) / 3600;

            if ($hoursPassed < 24) {
                // Device is trusted AND was authenticated today / within 24 hours
                return [
                    'is_trusted' => true,
                    'requires_daily_2fa' => false,
                    'device' => $device,
                    'raw_token' => $rawToken
                ];
            } else {
                // Device is trusted, but 24 hours passed: requires daily 2FA challenge
                return [
                    'is_trusted' => true,
                    'requires_daily_2fa' => true,
                    'device' => $device,
                    'raw_token' => $rawToken
                ];
            }
        } catch (Exception $e) {
            return ['is_trusted' => false, 'requires_daily_2fa' => true, 'device' => null, 'raw_token' => null];
        }
    }
}

if (!function_exists('totp_register_or_renew_trusted_device')) {
    /**
     * Mark device as trusted or renew its daily authentication
     */
    function totp_register_or_renew_trusted_device(PDO $pdo, int $adminId, ?string $existingToken = null): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $deviceName = totp_parse_user_agent($ua);
        $cookieName = 'sa_trusted_device';

        try {
            $now = date('Y-m-d H:i:s');
            $expires = date('Y-m-d H:i:s', time() + (86400 * 30));

            if (!empty($existingToken)) {
                $tokenHash = hash('sha256', $existingToken);
                $stmt = $pdo->prepare("
                    UPDATE `superadmin_trusted_devices`
                    SET `last_authenticated_at` = ?,
                        `expires_at` = ?,
                        `ip_address` = ?,
                        `device_name` = ?
                    WHERE `admin_id` = ? AND `device_token_hash` = ?
                ");
                $stmt->execute([$now, $expires, $ip, $deviceName, $adminId, $tokenHash]);
                if ($stmt->rowCount() > 0) {
                    // Update cookie expiry to 30 days
                    setcookie($cookieName, $adminId . ':' . $existingToken, [
                        'expires' => time() + (86400 * 30),
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                    return;
                }
            }

            // Generate new token
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);

            $stmt = $pdo->prepare("
                INSERT INTO `superadmin_trusted_devices` 
                (`admin_id`, `device_token_hash`, `device_name`, `ip_address`, `user_agent`, `last_authenticated_at`, `expires_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$adminId, $tokenHash, $deviceName, $ip, $ua, $now, $expires]);

            setcookie($cookieName, $adminId . ':' . $rawToken, [
                'expires' => time() + (86400 * 30),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } catch (Exception $e) {
            error_log("Error saving trusted device: " . $e->getMessage());
        }
    }
}
