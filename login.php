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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= BRAND_NAME ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <style>
        :root {
            --primary: #0ea5e9;
            --accent: #6366f1;
            --bg-dark: #0b1120;
            --card-bg: rgba(15, 23, 42, 0.75);
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            overflow-x: hidden;
            color: #f8fafc;
        }

        /* Abstract Animated Glow Elements */
        .bg-shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(130px);
            opacity: 0.28;
            z-index: 0;
            animation: pulse 14s infinite alternate;
            pointer-events: none;
        }
        .shape-1 { top: -15%; left: -10%; width: 50vw; height: 50vw; background: var(--primary); }
        .shape-2 { bottom: -15%; right: -10%; width: 55vw; height: 55vw; background: var(--accent); }
        
        @keyframes pulse {
            0% { transform: scale(1) translate(0, 0); }
            100% { transform: scale(1.12) translate(4%, 4%); }
        }

        /* Modern Glassmorphism Login Card */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            padding: 24px;
            margin: 20px 0;
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(28px);
            -webkit-backdrop-filter: blur(28px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 28px;
            padding: 2.8rem 2.2rem;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.05);
            transition: all 0.35s ease;
        }

        /* Brand Logo */
        .brand-logo {
            width: 76px;
            height: 76px;
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: white;
            margin: 0 auto 1.25rem;
            box-shadow: 0 12px 30px -6px rgba(14, 165, 233, 0.5);
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Form Inputs */
        .form-floating > .form-control {
            background-color: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 16px;
            height: 62px;
            font-size: 0.98rem;
            transition: all 0.2s ease;
        }
        
        .form-floating > .form-control:focus {
            background-color: rgba(15, 23, 42, 0.9);
            border-color: #38bdf8;
            box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.18);
            color: #ffffff;
        }

        .form-floating > label {
            color: #94a3b8;
            padding-left: 1.25rem;
            font-size: 0.9rem;
        }

        .password-wrapper { position: relative; }
        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            cursor: pointer;
            z-index: 10;
            padding: 8px;
            transition: color 0.2s;
        }
        .password-toggle:hover { color: #38bdf8; }

        /* Sign In Button */
        .btn-login {
            background: linear-gradient(135deg, #0284c7 0%, #4f46e5 100%);
            border: none;
            border-radius: 16px;
            color: white;
            padding: 15px;
            font-weight: 700;
            font-size: 1.05rem;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.45);
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 32px -5px rgba(79, 70, 229, 0.6);
            color: white;
            background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
        }
        .btn-login:active {
            transform: translateY(0);
        }

        /* Alert */
        .alert-custom {
            background-color: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
            border-radius: 16px;
            font-size: 0.9rem;
            padding: 0.9rem 1.1rem;
        }

        .security-badge {
            font-size: 0.78rem;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
    </style>
</head>
<body>

    <!-- Ambient Glowing Shapes -->
    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>

    <div class="login-wrapper">
        <div class="login-card">
            
            <!-- Brand Area -->
            <div class="text-center mb-4">
                <div class="brand-logo"><?= BRAND_LOGO_ABBR ?></div>
                <h3 class="fw-bold mb-1" style="letter-spacing: -0.5px;"><?= BRAND_NAME ?></h3>
                <p class="text-secondary small fw-medium mb-0">Sign in to your account</p>
            </div>
            
            <!-- Error Banner -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-custom d-flex align-items-center mb-4 shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-3 fs-5 flex-shrink-0 text-danger"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <!-- Universal Smart Login Form -->
            <form method="POST" autocomplete="on">
                
                <div class="form-floating mb-3 shadow-sm">
                    <input type="text" name="identifier" id="identifier" class="form-control" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required autocomplete="username" placeholder="Username or Email" autofocus>
                    <label for="identifier"><i class="fa-regular fa-user me-2 text-info"></i>Username or Email</label>
                </div>
                
                <div class="form-floating mb-4 password-wrapper shadow-sm">
                    <input type="password" class="form-control pe-5" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                    <label for="password"><i class="fa-solid fa-lock me-2 text-info"></i>Password</label>
                    <span class="password-toggle" onclick="togglePassword()" title="Show/Hide Password">
                        <i class="fa-solid fa-eye" id="toggleIcon"></i>
                    </span>
                </div>
                
                <button type="submit" class="btn btn-login w-100 mb-3 shadow">
                    <span>Sign In</span> <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                </button>
                
                <div class="text-center pt-2">
                    <a href="/customer/login" class="text-decoration-none small text-info text-opacity-75 hover-opacity-100 fw-semibold">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1" style="font-size: 0.72rem;"></i> Customer Portal Sign In
                    </a>
                </div>

            </form>
            
            <!-- Security & Copyright Footer -->
            <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-10">
                <div class="security-badge mb-2">
                    <i class="fa-solid fa-shield-halved text-success"></i>
                    <span>Encrypted &amp; Secure Authentication</span>
                </div>
                <p class="text-secondary small mb-0" style="font-size: 0.76rem; color: #64748b !important;">
                    &copy; <?= date('Y') ?> <?= BRAND_NAME ?>. All rights reserved.
                </p>
            </div>

        </div>
    </div>

    <!-- Scripts -->
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
    <script src="/security-shield.js?v=2.1"></script>
</body>
</html>
