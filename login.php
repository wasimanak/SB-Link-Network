<?php
session_start();

$host = $_SERVER['HTTP_HOST'] ?? '';
if (stripos($host, 'customer.') === 0 || stripos($host, 'customer') !== false) {
    if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
        header("Location: customer/dashboard");
    } else {
        header("Location: customer/login");
    }
    exit;
}

require_once 'config/db.php';
require_once 'config/security.php';
if (file_exists('config/branding.php')) {
    require_once 'config/branding.php';
}

if (!defined('BRAND_NAME')) define('BRAND_NAME', 'NetBee Billing');
if (!defined('BRAND_LOGO_ABBR')) define('BRAND_LOGO_ABBR', 'NB');

$error = '';
$success = '';

// Rate Limiting Protection (10 failed attempts max, 15 minutes lockout)
list($is_blocked, $remaining_sec) = check_rate_limit($pdo, 'staff', 10, 15);

if ($is_blocked) {
    $min = ceil($remaining_sec / 60);
    $error = "Too many failed login attempts. For security, access is temporarily locked for {$min} minute(s).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = trim($_POST['password'] ?? '');

    if (empty($identifier) || empty($password)) {
        $error = 'Please enter your Username / Email and Password.';
    } else {
        $authenticated = false;

        try {
            // -------------------------------------------------------------
            // 1. Check CLIENTS (Admin / ISP Operator)
            // -------------------------------------------------------------
            if (!$authenticated) {
                $stmt = $pdo->prepare("SELECT * FROM clients WHERE email = ? OR company_name = ? OR (phone = ? AND phone != '') LIMIT 1");
                $stmt->execute([$identifier, $identifier, $identifier]);
                $client = $stmt->fetch();

                if ($client && (password_verify($password, $client['password']) || $client['password'] === $password)) {
                    if ($client['status'] === 'expired' || $client['status'] === 'suspended') {
                        $error = "Your account is {$client['status']}. Please contact Super Admin.";
                        $authenticated = true;
                    } else {
                        if ($client['password'] === $password) {
                            $new_hash = password_hash($password, PASSWORD_BCRYPT);
                            $pdo->prepare("UPDATE clients SET password = ? WHERE id = ?")->execute([$new_hash, $client['id']]);
                        }

                        clear_failed_attempts($pdo, 'staff');
                        try { $pdo->exec("DELETE FROM activity_logs WHERE (activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') AND created_at < NOW() - INTERVAL 24 HOUR"); } catch (Exception $e) {}
                        session_regenerate_id(true);

                        $_SESSION['operator_logged_in'] = true;
                        $_SESSION['operator_id'] = $client['id'];
                        $_SESSION['operator_email'] = $client['email'];
                        $_SESSION['operator_name'] = $client['company_name'];
                        $_SESSION['operator_company'] = $client['company_name'];
                        $_SESSION['client_id'] = $client['id'];

                        header("Location: admin/dashboard");
                        exit;
                    }
                }
            }

            // -------------------------------------------------------------
            // 2. Check MANAGERS (Manager)
            // -------------------------------------------------------------
            if (!$authenticated && empty($error)) {
                if (file_exists('config/manager_helper.php')) {
                    require_once 'config/manager_helper.php';
                    if (function_exists('ensure_manager_schema')) {
                        ensure_manager_schema($pdo);
                    }
                }

                $stmt = $pdo->prepare("
                    SELECT m.*, c.status as op_status, c.company_name as op_company, c.email as op_email 
                    FROM managers m 
                    JOIN clients c ON m.client_id = c.id 
                    WHERE (m.username = ? OR (m.email = ? AND m.email != '')) AND m.status = 'active' AND c.status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$identifier, $identifier]);
                $mgr = $stmt->fetch();

                if ($mgr && ($mgr['password'] === $password || password_verify($password, $mgr['password']))) {
                    if ($mgr['password'] === $password) {
                        $new_hash = password_hash($password, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE managers SET password = ? WHERE id = ?")->execute([$new_hash, $mgr['id']]);
                    }

                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);

                    $_SESSION['operator_logged_in'] = true;
                    $_SESSION['operator_id'] = $mgr['client_id'];
                    $_SESSION['operator_email'] = $mgr['op_email'];
                    $_SESSION['operator_name'] = $mgr['full_name'];
                    $_SESSION['operator_company'] = $mgr['op_company'];
                    $_SESSION['client_id'] = $mgr['client_id'];

                    $_SESSION['is_manager'] = true;
                    $_SESSION['manager_id'] = $mgr['id'];
                    $_SESSION['manager_username'] = $mgr['username'];
                    $_SESSION['manager_name'] = $mgr['full_name'];
                    $_SESSION['can_manage_packages'] = ($mgr['can_manage_packages'] === 'yes');
                    $_SESSION['can_collect_recovery'] = ($mgr['can_collect_recovery'] === 'yes');

                    if (function_exists('log_staff_activity')) {
                        log_staff_activity($pdo, 'Logged in to Manager Portal', 'System', 'Login');
                    }

                    header("Location: admin/dashboard");
                    exit;
                }
            }

            // -------------------------------------------------------------
            // 3. Check SUPER ADMINS
            // -------------------------------------------------------------
            if (!$authenticated && empty($error)) {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE username = ? OR email = ? LIMIT 1");
                    $stmt->execute([$identifier, $identifier]);
                    $sAdmin = $stmt->fetch();

                    if ($sAdmin && (password_verify($password, $sAdmin['password']) || $sAdmin['password'] === $password)) {
                        if ($sAdmin['password'] === $password) {
                            $new_hash = password_hash($password, PASSWORD_BCRYPT);
                            $pdo->prepare("UPDATE super_admins SET password = ? WHERE id = ?")->execute([$new_hash, $sAdmin['id']]);
                        }

                        clear_failed_attempts($pdo, 'staff');
                        session_regenerate_id(true);

                        $_SESSION['superadmin_logged_in'] = true;
                        $_SESSION['superadmin_id'] = $sAdmin['id'];
                        $_SESSION['superadmin_name'] = $sAdmin['name'];

                        header("Location: superadmin/dashboard");
                        exit;
                    }
                } catch (Exception $e) {}
            }

            // -------------------------------------------------------------
            // 4. Check DEALERS
            // -------------------------------------------------------------
            if (!$authenticated && empty($error)) {
                $stmt = $pdo->prepare("
                    SELECT d.*, c.status as op_status 
                    FROM dealers d 
                    JOIN clients c ON d.client_id = c.id 
                    WHERE (d.username = ? OR (d.email = ? AND d.email != '')) AND d.status = 'active' AND c.status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$identifier, $identifier]);
                $dealer = $stmt->fetch();

                if ($dealer && ($dealer['password'] === $password || password_verify($password, $dealer['password']))) {
                    if ($dealer['password'] === $password) {
                        $new_hash = password_hash($password, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE dealers SET password = ? WHERE id = ?")->execute([$new_hash, $dealer['id']]);
                    }

                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);

                    $_SESSION['dealer_id'] = $dealer['id'];
                    $_SESSION['client_id'] = $dealer['client_id'];
                    $_SESSION['dealer_name'] = $dealer['full_name'] ?? ($dealer['name'] ?? $dealer['username']);

                    header("Location: dealer/dashboard");
                    exit;
                }
            }

            // -------------------------------------------------------------
            // 5. Check LINEMEN
            // -------------------------------------------------------------
            if (!$authenticated && empty($error)) {
                $stmt = $pdo->prepare("
                    SELECT l.*, c.status as op_status 
                    FROM linemen l 
                    JOIN clients c ON l.client_id = c.id 
                    WHERE (l.username = ? OR (l.phone = ? AND l.phone != '')) AND l.status = 'active' AND c.status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$identifier, $identifier]);
                $lineman = $stmt->fetch();

                if ($lineman && ($lineman['password'] === $password || password_verify($password, $lineman['password']))) {
                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);

                    $_SESSION['lineman_id'] = $lineman['id'];
                    $_SESSION['client_id'] = $lineman['client_id'];
                    $_SESSION['lineman_name'] = $lineman['full_name'];

                    header("Location: lineman/dashboard");
                    exit;
                }
            }

            // -------------------------------------------------------------
            // 6. Check RECOVERY MEN (Operators / Staff)
            // -------------------------------------------------------------
            if (!$authenticated && empty($error)) {
                $stmt = $pdo->prepare("
                    SELECT r.*, c.status as op_status 
                    FROM recovery_men r 
                    JOIN clients c ON r.client_id = c.id 
                    WHERE (r.username = ? OR (r.phone = ? AND r.phone != '')) AND r.status = 'active' AND c.status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$identifier, $identifier]);
                $rm = $stmt->fetch();

                if ($rm && ($rm['password'] === $password || password_verify($password, $rm['password']))) {
                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);

                    $_SESSION['rm_id'] = $rm['id'];
                    $_SESSION['client_id'] = $rm['client_id'];
                    $_SESSION['rm_name'] = $rm['full_name'];

                    header("Location: recoveryman/dashboard");
                    exit;
                }
            }

            // -------------------------------------------------------------
            // 7. Check SUBSCRIBERS (Customers)
            // -------------------------------------------------------------
            if (!$authenticated && empty($error)) {
                $stmt = $pdo->prepare("SELECT s.*, c.status as op_status FROM subscribers s JOIN clients c ON s.client_id = c.id WHERE (s.username = ? OR (s.email = ? AND s.email != '')) LIMIT 1");
                $stmt->execute([$identifier, $identifier]);
                $sub = $stmt->fetch();

                if ($sub && ($sub['password'] === $password || password_verify($password, $sub['password']))) {
                    if ($sub['status'] === 'disabled') {
                        $error = "Your subscriber account is disabled. Please contact your internet provider.";
                    } else {
                        clear_failed_attempts($pdo, 'staff');
                        session_regenerate_id(true);

                        $_SESSION['customer_logged_in'] = true;
                        $_SESSION['subscriber_id'] = $sub['id'];
                        $_SESSION['subscriber_username'] = $sub['username'];
                        $_SESSION['client_id'] = $sub['client_id'];
                        $_SESSION['subscriber_name'] = $sub['full_name'] ?? $sub['username'];

                        header("Location: customer/dashboard");
                        exit;
                    }
                }
            }

            // If none matched
            if (empty($error)) {
                record_failed_attempt($pdo, 'staff', $identifier);
                $error = 'Invalid Username/Email or Password. Please try again.';
            }

        } catch (PDOException $e) {
            $error = 'Database Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>Sign In &mdash; <?= BRAND_NAME ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <!-- Google Fonts: Plus Jakarta Sans / Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap & FontAwesome 6 Pro/Free -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --brand-primary: #0ea5e9;
            --brand-indigo: #6366f1;
            --brand-purple: #8b5cf6;
            --bg-canvas: #060913;
            --card-glass: rgba(13, 20, 36, 0.78);
            --border-glass: rgba(255, 255, 255, 0.1);
            --border-hover: rgba(56, 189, 248, 0.4);
            --text-heading: #ffffff;
            --text-muted: #94a3b8;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-canvas);
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(14, 165, 233, 0.18) 0%, transparent 60%),
                radial-gradient(circle at 85% 90%, rgba(99, 102, 241, 0.14) 0%, transparent 50%),
                radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 32px 32px;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
            color: #f1f5f9;
            overflow-x: hidden;
            position: relative;
        }

        /* Ambient Fluid Glow Shapes */
        .ambient-glow {
            position: fixed;
            border-radius: 50%;
            filter: blur(140px);
            pointer-events: none;
            z-index: 0;
            opacity: 0.35;
        }
        .glow-1 {
            top: -120px;
            left: 50%;
            transform: translateX(-50%);
            width: min(650px, 90vw);
            height: 380px;
            background: radial-gradient(circle, #0284c7 0%, #38bdf8 40%, transparent 70%);
            animation: pulseA 10s ease-in-out infinite alternate;
        }
        .glow-2 {
            bottom: -150px;
            right: -80px;
            width: min(500px, 80vw);
            height: 500px;
            background: radial-gradient(circle, #6366f1 0%, #8b5cf6 40%, transparent 70%);
            animation: pulseB 12s ease-in-out infinite alternate;
        }

        @keyframes pulseA {
            0% { transform: translateX(-50%) scale(0.95); opacity: 0.3; }
            100% { transform: translateX(-50%) scale(1.08); opacity: 0.45; }
        }
        @keyframes pulseB {
            0% { transform: scale(0.9); opacity: 0.25; }
            100% { transform: scale(1.1); opacity: 0.4; }
        }

        /* Main Container Card */
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            padding: 16px;
            margin: auto;
        }

        .auth-card {
            background: var(--card-glass);
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            border: 1px solid var(--border-glass);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 28px;
            padding: 2.8rem 2.4rem;
            box-shadow: 
                0 0 0 1px rgba(255, 255, 255, 0.04),
                0 25px 60px -15px rgba(0, 0, 0, 0.75),
                0 0 90px -20px rgba(14, 165, 233, 0.18);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        /* Brand Emblem */
        .brand-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-emblem {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #0284c7 0%, #6366f1 50%, #4338ca 100%);
            border-radius: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 1.25rem;
            box-shadow: 
                0 12px 30px -6px rgba(14, 165, 233, 0.55),
                inset 0 1px 1px rgba(255, 255, 255, 0.4),
                inset 0 -2px 4px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.25);
            letter-spacing: 0.5px;
            position: relative;
        }

        .brand-emblem::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 26px;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.4), transparent);
            z-index: -1;
            filter: blur(6px);
        }

        .badge-unified {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 0.73rem;
            font-weight: 600;
            background: rgba(14, 165, 233, 0.1);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.25);
            margin-bottom: 0.75rem;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            background-color: #38bdf8;
            border-radius: 50%;
            box-shadow: 0 0 8px #38bdf8;
            animation: pulseDot 2s infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        .brand-title {
            color: var(--text-heading);
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 0.35rem;
        }

        .brand-subtitle {
            color: var(--text-muted);
            font-size: 0.88rem;
            font-weight: 400;
            margin-bottom: 0;
        }

        /* Modern Custom Form Groups */
        .input-group-custom {
            margin-bottom: 1.25rem;
            position: relative;
        }

        .input-label-custom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.84rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
            letter-spacing: 0.2px;
        }

        .input-wrapper-custom {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 18px;
            color: #64748b;
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.25s ease;
            z-index: 5;
        }

        .form-control-custom {
            width: 100%;
            height: 54px;
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 0 16px 0 48px;
            font-size: 0.96rem;
            font-family: inherit;
            color: #ffffff;
            font-weight: 500;
            outline: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .form-control-custom::placeholder {
            color: #64748b;
            font-weight: 400;
            font-size: 0.92rem;
        }

        .form-control-custom:hover {
            border-color: rgba(255, 255, 255, 0.22);
            background: rgba(15, 23, 42, 0.8);
        }

        .form-control-custom:focus {
            background: rgba(15, 23, 42, 0.95);
            border-color: #38bdf8;
            box-shadow: 
                0 0 0 4px rgba(56, 189, 248, 0.18),
                inset 0 2px 4px rgba(0, 0, 0, 0.25);
            color: #ffffff;
        }

        .input-wrapper-custom:focus-within .input-icon-left {
            color: #38bdf8;
        }

        /* Autofill Overrides to preserve deep dark aesthetics */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus {
            -webkit-text-fill-color: #ffffff !important;
            -webkit-box-shadow: 0 0 0px 1000px #0b1329 inset !important;
            transition: background-color 5000s ease-in-out 0s;
            caret-color: #ffffff;
        }

        /* Password Toggle Button */
        .password-toggle-btn {
            position: absolute;
            right: 14px;
            background: none;
            border: none;
            color: #64748b;
            padding: 8px 10px;
            cursor: pointer;
            font-size: 1rem;
            border-radius: 8px;
            transition: color 0.2s ease, transform 0.15s ease;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle-btn:hover {
            color: #38bdf8;
        }

        .password-toggle-btn:active {
            transform: scale(0.92);
        }

        /* Primary Sign-In Action Button */
        .btn-signin-premier {
            width: 100%;
            height: 54px;
            background: linear-gradient(135deg, #0284c7 0%, #4f46e5 50%, #4338ca 100%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 16px;
            color: #ffffff;
            font-size: 1.02rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 10px 25px -4px rgba(2, 132, 199, 0.45),
                0 4px 12px rgba(79, 70, 229, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 0.5rem;
        }

        .btn-signin-premier::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.18), transparent);
            transition: 0.6s ease;
        }

        .btn-signin-premier:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 14px 32px -4px rgba(2, 132, 199, 0.6),
                0 6px 16px rgba(79, 70, 229, 0.45);
            color: #ffffff;
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 50%, #4f46e5 100%);
        }

        .btn-signin-premier:hover::before {
            left: 100%;
        }

        .btn-signin-premier:active {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);
        }

        .btn-signin-premier:disabled {
            opacity: 0.75;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Error Notification Banner */
        .alert-premier-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-left: 4px solid #ef4444;
            color: #fca5a5;
            border-radius: 14px;
            padding: 12px 16px;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
            animation: shakeAlert 0.4s ease;
        }

        @keyframes shakeAlert {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-4px); }
            40%, 80% { transform: translateX(4px); }
        }

        /* Customer Portal Alternative Link */
        .customer-portal-pill {
            display: block;
            text-align: center;
            margin-top: 1.25rem;
            padding: 10px 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            color: #94a3b8;
            font-size: 0.84rem;
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .customer-portal-pill:hover {
            background: rgba(14, 165, 233, 0.08);
            border-color: rgba(56, 189, 248, 0.25);
            color: #38bdf8;
            transform: translateY(-1px);
        }

        /* Footer & Trust Shield */
        .auth-footer {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
            text-align: center;
        }

        .security-row {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.74rem;
            color: #64748b;
            margin-bottom: 0.35rem;
        }

        .copyright-text {
            color: #64748b;
            font-size: 0.74rem;
            margin: 0;
        }

        /* ================= Mobile Screen Optimizations ================= */
        @media (max-width: 576px) {
            .login-container {
                padding: 12px;
                max-width: 100%;
            }

            .auth-card {
                padding: 2.2rem 1.4rem;
                border-radius: 24px;
            }

            .brand-emblem {
                width: 64px;
                height: 64px;
                font-size: 26px;
                border-radius: 18px;
                margin-bottom: 1rem;
            }

            .brand-title {
                font-size: 1.45rem;
            }

            .brand-subtitle {
                font-size: 0.82rem;
            }

            .form-control-custom {
                height: 52px;
                font-size: 16px; /* Prevents auto-zoom in iOS Safari */
            }

            .btn-signin-premier {
                height: 52px;
                font-size: 0.98rem;
            }
        }
    </style>
</head>
<body>

    <!-- Ambient Glow Lighting -->
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>

    <!-- Login Container -->
    <div class="login-container">
        <div class="auth-card">
            
            <!-- Brand & Heading Area -->
            <div class="brand-header">
                <div class="brand-emblem">
                    <?= BRAND_LOGO_ABBR ?>
                </div>
                <div>
                    <span class="badge-unified">
                        <span class="status-dot"></span>
                        Secure Access Portal
                    </span>
                </div>
                <h1 class="brand-title"><?= BRAND_NAME ?></h1>
                <p class="brand-subtitle">Enter your credentials to access your control portal</p>
            </div>
            
            <!-- Error Banner -->
            <?php if (!empty($error)): ?>
                <div class="alert-premier-error" role="alert">
                    <i class="fa-solid fa-triangle-exclamation fs-5 flex-shrink-0 text-danger"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- Universal Smart Login Form -->
            <form method="POST" id="loginForm" autocomplete="on">
                
                <!-- Identifier Input (Username / Email) -->
                <div class="input-group-custom">
                    <label for="identifier" class="input-label-custom">
                        <span>Username or Email</span>
                    </label>
                    <div class="input-wrapper-custom">
                        <i class="fa-regular fa-user input-icon-left"></i>
                        <input 
                            type="text" 
                            name="identifier" 
                            id="identifier" 
                            class="form-control-custom" 
                            value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" 
                            required 
                            autocomplete="username" 
                            placeholder="Enter username, email or company..." 
                            autofocus
                        >
                    </div>
                </div>
                
                <!-- Password Input -->
                <div class="input-group-custom">
                    <label for="password" class="input-label-custom">
                        <span>Password</span>
                    </label>
                    <div class="input-wrapper-custom">
                        <i class="fa-solid fa-lock input-icon-left"></i>
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
                            class="form-control-custom" 
                            style="padding-right: 48px;" 
                            placeholder="Enter your account password..." 
                            required 
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle-btn" id="togglePasswordBtn" onclick="togglePasswordVisibility()" aria-label="Toggle password visibility">
                            <i class="fa-regular fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Submit Action Button -->
                <button type="submit" class="btn-signin-premier" id="btnSubmit">
                    <span id="btnText">Sign In</span>
                    <i class="fa-solid fa-arrow-right-to-bracket ms-1" id="btnIcon"></i>
                </button>
                
                <!-- Customer Portal Shortcut -->
                <a href="/customer/login" class="customer-portal-pill">
                    <i class="fa-solid fa-users me-1 text-info"></i>
                    <span>Are you a Subscriber? <b>Customer Portal &rarr;</b></span>
                </a>

            </form>
            
            <!-- Security Footer -->
            <div class="auth-footer">
                <div class="security-row">
                    <i class="fa-solid fa-shield-halved text-success"></i>
                    <span>256-Bit SSL End-to-End Encryption</span>
                </div>
                <p class="copyright-text">
                    &copy; <?= date('Y') ?> <?= BRAND_NAME ?>. All rights reserved.
                </p>
                <p class="copyright-text mt-1" style="font-size: 0.8rem;">
                    Powered by <a href="mailto:businessoperation7616@gmail.com" class="text-decoration-none fw-semibold" style="color: #60a5fa;" title="Contact Alpha4Technologies">Alpha4Technologies</a>
                </p>
            </div>

        </div>

        <!-- Bottom Page Branding -->
        <div class="text-center mt-3" style="position: relative; z-index: 2;">
            <p class="small mb-0" style="color: rgba(255,255,255,0.7); font-size: 0.82rem;">
                Powered by <a href="mailto:businessoperation7616@gmail.com" class="text-decoration-none fw-semibold text-white" style="text-decoration: underline !important;" title="Contact Alpha4Technologies">Alpha4Technologies</a>
            </p>
        </div>
    </div>

    <!-- Client-Side Scripting -->
    <script>
        function togglePasswordVisibility() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (!pwd || !icon) return;

            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Add interactive loading state on submit
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function() {
                const btn = document.getElementById('btnSubmit');
                const btnText = document.getElementById('btnText');
                const btnIcon = document.getElementById('btnIcon');
                if (btn && btnText) {
                    btn.disabled = true;
                    btnText.innerText = 'Authenticating...';
                    if (btnIcon) {
                        btnIcon.className = 'fa-solid fa-circle-notch fa-spin ms-2';
                    }
                }
            });
        }
    </script>
    <script src="/security-shield.js?v=2.1"></script>
</body>
</html>
