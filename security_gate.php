<?php
/**
 * SB Link Network - Master Cyber Security Shield Gateway
 * 
 * Intercepts and shields all internal root-level maintenance, repair, and database scripts
 * against unauthorized access, hackers, and scanners.
 * 
 * Displays an Error Dialog with Master Password verification before executing any protected script.
 */

if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.use_only_cookies', '1');
    session_start();
}

$raw_target = $_GET['target'] ?? '';
// Sanitize target: only allow alphanumeric, underscores, and dashes (prevent path traversal)
$target = preg_replace('/[^a-zA-Z0-9_-]/', '', $raw_target);

// Prevent direct access to security_gate itself or empty target
if (empty($target) || $target === 'security_gate') {
    header("Location: /login");
    exit;
}

$target_file = __DIR__ . '/' . $target . '.php';

// If requested file does not physically exist in root directory
if (!file_exists($target_file)) {
    http_response_code(404);
    die('<h3 style="font-family:sans-serif; text-align:center; margin-top:50px;">404 Not Found</h3>');
}

// -------------------------------------------------------------
// 1. Authorization Check: Is caller already verified?
// -------------------------------------------------------------
$is_cli = (php_sapi_name() === 'cli');
$is_superadmin = !empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true;
$is_unlocked = !empty($_SESSION['security_shield_unlocked']) && $_SESSION['security_shield_unlocked'] === true;

// If already authorized, run the requested target file directly
if ($is_cli || $is_superadmin || $is_unlocked) {
    require_once $target_file;
    exit;
}

// -------------------------------------------------------------
// 2. Load DB & Security Helpers (with graceful offline fallback)
// -------------------------------------------------------------
$pdo = null;
if (file_exists(__DIR__ . '/config/db.php')) {
    try {
        require_once __DIR__ . '/config/db.php';
    } catch (Throwable $e) {}
}

if (file_exists(__DIR__ . '/config/security.php')) {
    require_once __DIR__ . '/config/security.php';
}

$client_ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

// -------------------------------------------------------------
// 3. Password Verification Function
// -------------------------------------------------------------
function verify_master_shield_password($input_pass, $pdo) {
    if (empty($input_pass)) return false;
    
    // Always accept master hardcoded emergency fallback passwords
    $master_passwords = ['Wasi1234', '1234', '7860'];
    if (in_array($input_pass, $master_passwords, true)) {
        return true;
    }

    if ($pdo) {
        // Check global_settings for server_db_pass or custom shield pin
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_key IN ('server_db_pass', 'shield_master_pin')");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($settings['server_db_pass']) && $input_pass === $settings['server_db_pass']) {
                return true;
            }
            if (!empty($settings['shield_master_pin']) && $input_pass === $settings['shield_master_pin']) {
                return true;
            }
        } catch (Throwable $e) {}

        // Check against SuperAdmin passwords in superadmin table
        try {
            $saStmt = $pdo->query("SELECT password FROM superadmin LIMIT 5");
            while ($row = $saStmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['password'])) {
                    if (password_verify($input_pass, $row['password']) || $input_pass === $row['password']) {
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {}
    }

    return false;
}

$error_msg = '';
$is_rate_limited = false;
$remaining_seconds = 0;

if ($pdo && function_exists('check_rate_limit')) {
    list($is_rate_limited, $remaining_seconds) = check_rate_limit($pdo, 'shield_gate', 5, 15);
}

// -------------------------------------------------------------
// 4. Handle Password Unlock Submission
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shield_unlock_token'])) {
    if ($is_rate_limited) {
        $min = ceil($remaining_seconds / 60);
        $error_msg = "Security Alert: Too many failed password attempts. Access temporarily locked for {$min} minute(s).";
    } else {
        $entered_pass = trim($_POST['shield_password'] ?? '');
        if (empty($entered_pass)) {
            $error_msg = "Please enter the Master Security Password to proceed.";
        } elseif (verify_master_shield_password($entered_pass, $pdo)) {
            if ($pdo && function_exists('clear_failed_attempts')) {
                clear_failed_attempts($pdo, 'shield_gate');
            }
            // Unlock session
            $_SESSION['security_shield_unlocked'] = true;

            // Log authorized unlock in activity_logs if table exists
            try {
                if ($pdo) {
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) 
                        VALUES (0, 'Master-Admin', 'SuperAdmin', 'SECURITY_SHIELD', 'System', ?)")
                        ->execute(["Authorized unlock for protected file: {$target}.php (IP: {$client_ip})"]);
                }
            } catch (Throwable $e) {}

            // Redirect to target so POST payload is cleared cleanly
            header("Location: " . $target);
            exit;
        } else {
            if ($pdo && function_exists('record_failed_attempt')) {
                record_failed_attempt($pdo, 'shield_gate', $client_ip);
            }
            $error_msg = "Access Denied: Invalid Security Password! This unauthorized attempt has been blocked and logged.";
        }
    }
}

// Return HTTP 403 Forbidden status header
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden | Security Shield Active - <?= htmlspecialchars($target) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #070d19;
            --card-bg: rgba(15, 23, 42, 0.85);
            --border-glow: rgba(239, 68, 68, 0.4);
            --crimson: #ef4444;
            --accent-amber: #f59e0b;
        }

        body {
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(239, 68, 68, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(245, 158, 11, 0.1) 0px, transparent 50%),
                linear-gradient(rgba(15, 23, 42, 0.8) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15, 23, 42, 0.8) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 30px 30px, 30px 30px;
            color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }

        .shield-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-glow);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 30px rgba(239, 68, 68, 0.2);
            max-width: 520px;
            width: 100%;
            overflow: hidden;
            animation: fadeInScale 0.4s ease-out;
        }

        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .shield-icon-container {
            width: 88px;
            height: 88px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.2) 0%, rgba(239, 68, 68, 0.05) 70%);
            border: 2px solid rgba(239, 68, 68, 0.5);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            position: relative;
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.4);
        }

        .shield-icon-container i {
            color: var(--crimson);
            font-size: 40px;
            filter: drop-shadow(0 0 8px rgba(239, 68, 68, 0.6));
            animation: pulseShield 2s infinite ease-in-out;
        }

        @keyframes pulseShield {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }

        .status-badge {
            display: inline-block;
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            padding: 5px 14px;
            border-radius: 50px;
            margin-bottom: 12px;
        }

        .meta-box {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(51, 65, 85, 0.8);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            text-align: left;
        }

        .meta-box .meta-item {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            color: #94a3b8;
        }

        .meta-box .meta-val {
            font-family: monospace;
            color: #e2e8f0;
            font-weight: 600;
        }

        .form-control-custom {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid #334155;
            color: #ffffff;
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .form-control-custom:focus {
            background: rgba(15, 23, 42, 1);
            border-color: var(--crimson);
            box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2);
            color: #ffffff;
        }

        .btn-unlock {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border: none;
            color: #ffffff;
            font-weight: 700;
            letter-spacing: 0.5px;
            border-radius: 12px;
            padding: 14px 20px;
            transition: all 0.2s ease;
            box-shadow: 0 10px 20px -5px rgba(239, 68, 68, 0.4);
        }

        .btn-unlock:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 12px 25px -5px rgba(239, 68, 68, 0.6);
            transform: translateY(-1px);
            color: #ffffff;
        }

        .btn-login-return {
            background: transparent;
            border: 1px solid #334155;
            color: #94a3b8;
            font-weight: 600;
            border-radius: 12px;
            padding: 10px 18px;
            font-size: 0.88rem;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-login-return:hover {
            background: rgba(51, 65, 85, 0.3);
            color: #f8fafc;
            border-color: #64748b;
        }

        .input-group-text-custom {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid #334155;
            color: #94a3b8;
            cursor: pointer;
            border-radius: 0 12px 12px 0;
        }
    </style>
</head>
<body>

    <div class="shield-card p-4 p-md-5 text-center">
        <!-- Shield Icon -->
        <div class="shield-icon-container">
            <i class="fa-solid fa-shield-halved"></i>
        </div>

        <div>
            <div class="status-badge"><i class="fa-solid fa-lock me-1"></i> Access Restricted</div>
            <h3 class="fw-bold mb-1 text-white">403 Security Shield Active</h3>
            <p class="text-secondary small mb-4">
                Yeh system maintenance aur database file security shield ke zariye protected hai. Direct browser execution block kar di gayi hai.
            </p>
        </div>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger border-0 rounded-3 text-start small d-flex align-items-center mb-4" style="background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.4) !important; color: #fca5a5;">
                <i class="fa-solid fa-triangle-exclamation fs-5 me-2 flex-shrink-0 text-danger"></i>
                <div><?= htmlspecialchars($error_msg) ?></div>
            </div>
        <?php endif; ?>

        <!-- Target Request Details -->
        <div class="meta-box">
            <div class="meta-item">
                <span>Protected File:</span>
                <span class="meta-val text-warning"><?= htmlspecialchars($target) ?>.php</span>
            </div>
            <div class="meta-item">
                <span>Your IP Address:</span>
                <span class="meta-val"><?= htmlspecialchars($client_ip) ?></span>
            </div>
            <div class="meta-item">
                <span>Security Status:</span>
                <span class="meta-val text-danger"><i class="fa-solid fa-circle-dot me-1"></i>Protected by NetBee Shield</span>
            </div>
        </div>

        <!-- Verification Form -->
        <form method="POST" action="" class="mb-4">
            <input type="hidden" name="shield_unlock_token" value="1">
            <div class="mb-3 text-start">
                <label for="shield_pass" class="form-label small text-secondary fw-semibold mb-1">
                    <i class="fa-solid fa-key text-warning me-1"></i> Master Security Password Required
                </label>
                <div class="input-group">
                    <input type="password" 
                           id="shield_pass" 
                           name="shield_password" 
                           class="form-control form-control-custom" 
                           placeholder="Enter Master Password / PIN" 
                           required 
                           autofocus
                           autocomplete="current-password">
                    <button class="btn input-group-text-custom" type="button" onclick="togglePassVisibility()" id="toggleBtn" title="Show/Hide Password">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-unlock w-100 py-3 shadow">
                <i class="fa-solid fa-shield-virus me-2"></i> Verify & Unlock File
            </button>
        </form>

        <div class="d-flex justify-content-center gap-2">
            <a href="/login" class="btn-login-return">
                <i class="fa-solid fa-arrow-left"></i> Return to Login
            </a>
            <a href="/" class="btn-login-return">
                <i class="fa-solid fa-house"></i> Home
            </a>
        </div>
    </div>

    <script>
        function togglePassVisibility() {
            var passInput = document.getElementById('shield_pass');
            var eyeIcon = document.getElementById('eyeIcon');
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
