<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/two_factor_helper.php';

init_two_factor_schema($pdo);

// Ensure user has passed password verification first
if (empty($_SESSION['2fa_pending_admin_id'])) {
    header("Location: login");
    exit;
}

$admin_id = (int)$_SESSION['2fa_pending_admin_id'];
$stmt = $pdo->prepare("SELECT * FROM super_admins WHERE id = ? LIMIT 1");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

if (!$admin || empty($admin['two_factor_enabled']) || empty($admin['two_factor_secret'])) {
    // 2FA not required for this user, log them in directly
    $_SESSION['superadmin_logged_in'] = true;
    $_SESSION['superadmin_id'] = $admin['id'];
    $_SESSION['superadmin_name'] = $admin['name'];
    $_SESSION['superadmin_username'] = $admin['username'];
    unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_admin_name'], $_SESSION['2fa_pending_admin_user'], $_SESSION['2fa_is_trusted_device'], $_SESSION['2fa_device_token']);
    header("Location: dashboard");
    exit;
}

$error = '';
$is_trusted = !empty($_SESSION['2fa_is_trusted_device']);
$existing_token = $_SESSION['2fa_device_token'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = trim($_POST['auth_mode'] ?? 'totp');
    $trust_device = !empty($_POST['trust_device']);

    if ($mode === 'recovery') {
        $recovery_code = strtoupper(trim($_POST['recovery_code'] ?? ''));
        $recovery_code = preg_replace('/[^A-Z0-9-]/', '', $recovery_code);

        $saved_codes = [];
        if (!empty($admin['two_factor_recovery_codes'])) {
            $saved_codes = json_decode($admin['two_factor_recovery_codes'], true) ?: [];
        }

        $code_index = array_search($recovery_code, $saved_codes, true);
        if ($code_index !== false) {
            // Remove used recovery code
            unset($saved_codes[$code_index]);
            $updated_codes = array_values($saved_codes);
            $pdo->prepare("UPDATE super_admins SET two_factor_recovery_codes = ? WHERE id = ?")
                ->execute([json_encode($updated_codes), $admin_id]);

            // Handle trusted device
            if ($trust_device) {
                totp_register_or_renew_trusted_device($pdo, $admin_id, $existing_token);
            }

            clear_failed_attempts($pdo, 'superadmin_2fa');
            session_regenerate_id(true);
            $_SESSION['superadmin_logged_in'] = true;
            $_SESSION['superadmin_id'] = $admin['id'];
            $_SESSION['superadmin_name'] = $admin['name'];
            $_SESSION['superadmin_username'] = $admin['username'];
            unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_admin_name'], $_SESSION['2fa_pending_admin_user'], $_SESSION['2fa_is_trusted_device'], $_SESSION['2fa_device_token']);

            header("Location: dashboard");
            exit;
        } else {
            record_failed_attempt($pdo, 'superadmin_2fa', $admin['username']);
            $error = 'Invalid recovery code. Please check and try again.';
        }
    } else {
        $code = trim($_POST['totp_code'] ?? '');
        $code = preg_replace('/\s+/', '', $code);

        if (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
            $error = 'Please enter a valid 6-digit authenticator code.';
        } else {
            if (totp_verify_code($admin['two_factor_secret'], $code)) {
                // Verified successfully!
                if ($trust_device) {
                    totp_register_or_renew_trusted_device($pdo, $admin_id, $existing_token);
                }

                clear_failed_attempts($pdo, 'superadmin_2fa');
                session_regenerate_id(true);
                $_SESSION['superadmin_logged_in'] = true;
                $_SESSION['superadmin_id'] = $admin['id'];
                $_SESSION['superadmin_name'] = $admin['name'];
                $_SESSION['superadmin_username'] = $admin['username'];
                unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_admin_name'], $_SESSION['2fa_pending_admin_user'], $_SESSION['2fa_is_trusted_device'], $_SESSION['2fa_device_token']);

                header("Location: dashboard");
                exit;
            } else {
                record_failed_attempt($pdo, 'superadmin_2fa', $admin['username']);
                $error = 'Incorrect 6-digit code. Please verify the code on your Google Authenticator app.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - <?= BRAND_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        :root {
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --accent: #8b5cf6;
            --bg-dark: #0b1120;
            --card-bg: rgba(30, 41, 59, 0.7);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            overflow: hidden;
            color: #f8fafc;
        }

        .bg-shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.25;
            z-index: 0;
            animation: pulse 12s infinite alternate;
        }
        .shape-1 { top: -10%; left: -10%; width: 50vw; height: 50vw; background: var(--primary); }
        .shape-2 { bottom: -20%; right: -10%; width: 60vw; height: 60vw; background: var(--accent); }
        
        @keyframes pulse {
            0% { transform: scale(1) translate(0, 0); }
            100% { transform: scale(1.1) translate(5%, 5%); }
        }

        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 460px;
            padding: 20px;
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 3rem 2.2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .shield-icon {
            width: 68px;
            height: 68px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.4);
        }

        .code-input {
            background-color: rgba(15, 23, 42, 0.7) !important;
            border: 2px solid rgba(255, 255, 255, 0.12) !important;
            color: #ffffff !important;
            font-size: 1.85rem !important;
            font-weight: 700 !important;
            letter-spacing: 12px !important;
            text-align: center !important;
            border-radius: 14px !important;
            height: 68px !important;
            transition: all 0.25s ease !important;
        }

        .code-input:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.25) !important;
            background-color: rgba(15, 23, 42, 0.9) !important;
        }

        .btn-verify {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border: none;
            color: white;
            font-weight: 700;
            border-radius: 14px;
            height: 52px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
            color: white;
        }

        .custom-check-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px 16px;
            transition: background 0.2s ease;
        }
        .custom-check-card:hover {
            background: rgba(255, 255, 255, 0.07);
        }
    </style>
</head>
<body>

    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>

    <div class="login-wrapper">
        <div class="login-card">
            
            <div class="shield-icon">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            
            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1 text-white">Two-Factor Authentication</h4>
                <p class="text-secondary small mb-0">
                    Hello, <strong class="text-white"><?= htmlspecialchars($_SESSION['2fa_pending_admin_name'] ?? 'Super Admin') ?></strong>!
                </p>
                <div class="text-white-50 small mt-1" style="font-size: 0.8rem;" id="instructionsText">
                    Open your <strong>Google Authenticator</strong> app and enter the 6-digit code.
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2.5 px-3 border-0 rounded-3 small d-flex align-items-center gap-2 mb-4" style="background: rgba(239, 68, 68, 0.15); color: #fca5a5;">
                    <i class="fa-solid fa-circle-exclamation fs-5 flex-shrink-0"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- TOTP Code Form -->
            <form method="POST" id="totpForm" autocomplete="off">
                <input type="hidden" name="auth_mode" id="authModeInput" value="totp">

                <!-- 6-Digit Code Input -->
                <div class="mb-3" id="totpInputSection">
                    <label class="form-label text-secondary small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">
                        Authentication Code
                    </label>
                    <input type="text" 
                           inputmode="numeric" 
                           pattern="[0-9]*"
                           maxlength="6" 
                           name="totp_code" 
                           id="totp_code" 
                           class="form-control code-input" 
                           placeholder="······" 
                           autofocus 
                           required>
                </div>

                <!-- Recovery Code Input (Hidden by default) -->
                <div class="mb-3 d-none" id="recoveryInputSection">
                    <label class="form-label text-secondary small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">
                        8-Character Recovery Code
                    </label>
                    <input type="text" 
                           name="recovery_code" 
                           id="recovery_code" 
                           class="form-control text-center text-uppercase fw-bold text-white fs-5" 
                           placeholder="e.g. A1B2-C3D4" 
                           style="background: rgba(15, 23, 42, 0.7); border: 2px solid rgba(255, 255, 255, 0.12); height: 58px; border-radius: 14px; letter-spacing: 2px;">
                    <div class="form-text text-secondary small mt-1" style="font-size: 0.72rem;">
                        Enter one of the emergency backup recovery codes saved when setting up 2FA.
                    </div>
                </div>

                <!-- Trusted Device Checkbox (Require code only once a day) -->
                <div class="mb-4">
                    <div class="custom-check-card d-flex align-items-start gap-2.5">
                        <input class="form-check-input mt-1" type="checkbox" name="trust_device" id="trustDeviceCheck" value="1" <?= $is_trusted ? 'checked' : 'checked' ?>>
                        <label class="form-check-label text-white small" for="trustDeviceCheck" style="cursor: pointer;">
                            <div class="fw-semibold">Trust this device (Ask once a day)</div>
                            <div class="text-secondary" style="font-size: 0.72rem;">
                                Din me sirf 1 bar authenticator code require hoga.
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-verify w-100 d-flex align-items-center justify-content-center gap-2 mb-3">
                    <i class="fa-solid fa-lock-open"></i>
                    <span>Verify &amp; Access Dashboard</span>
                </button>
            </form>

            <!-- Mode Switcher -->
            <div class="text-center pt-2 border-top border-secondary border-opacity-25 mt-3">
                <button type="button" id="toggleRecoveryBtn" class="btn btn-link text-decoration-none text-secondary hover-text-white small p-0" onclick="toggleAuthMode()">
                    <i class="fa-solid fa-key me-1"></i> Use emergency recovery code instead
                </button>
                <div class="mt-3">
                    <a href="login?logout=1" class="text-secondary small text-decoration-none">
                        <i class="fa-solid fa-arrow-left me-1"></i> Cancel &amp; return to Login
                    </a>
                </div>
            </div>

        </div>
    </div>

    <script>
    let isRecoveryMode = false;

    function toggleAuthMode() {
        isRecoveryMode = !isRecoveryMode;
        const modeInput = document.getElementById('authModeInput');
        const totpSection = document.getElementById('totpInputSection');
        const recoverySection = document.getElementById('recoveryInputSection');
        const toggleBtn = document.getElementById('toggleRecoveryBtn');
        const instructions = document.getElementById('instructionsText');
        const totpInput = document.getElementById('totp_code');
        const recInput = document.getElementById('recovery_code');

        if (isRecoveryMode) {
            modeInput.value = 'recovery';
            totpSection.classList.add('d-none');
            recoverySection.classList.remove('d-none');
            totpInput.removeAttribute('required');
            recInput.setAttribute('required', 'required');
            toggleBtn.innerHTML = '<i class="fa-solid fa-mobile-screen me-1"></i> Use Google Authenticator code';
            instructions.innerHTML = 'Enter your <strong>single-use backup recovery code</strong>.';
            recInput.focus();
        } else {
            modeInput.value = 'totp';
            totpSection.classList.remove('d-none');
            recoverySection.classList.add('d-none');
            recInput.removeAttribute('required');
            totpInput.setAttribute('required', 'required');
            toggleBtn.innerHTML = '<i class="fa-solid fa-key me-1"></i> Use emergency recovery code instead';
            instructions.innerHTML = 'Open your <strong>Google Authenticator</strong> app and enter the 6-digit code.';
            totpInput.focus();
        }
    }

    // Auto-submit when 6 digits are typed
    document.getElementById('totp_code').addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value.length === 6 && !isRecoveryMode) {
            document.getElementById('totpForm').submit();
        }
    });
    </script>
</body>
</html>
