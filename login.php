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

$error = '';
$success = '';

// Rate Limiting Protection (7 failed attempts max, 15 minutes lockout)
list($is_blocked, $remaining_sec) = check_rate_limit($pdo, 'staff', 7, 15);

if ($is_blocked) {
    $min = ceil($remaining_sec / 60);
    $error = "Too many failed login attempts. For security, access is temporarily locked for {$min} minute(s).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    $identifier = trim($_POST['identifier'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($role) || empty($identifier) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        try {
            if ($role === 'operator' || $role === 'admin') {
                // Admin (formerly Operator) uses email or company name
                $stmt = $pdo->prepare("SELECT * FROM clients WHERE email = ? OR company_name = ? LIMIT 1");
                $stmt->execute([$identifier, $identifier]);
                $client = $stmt->fetch();

                if ($client && (password_verify($password, $client['password']) || $client['password'] === $password)) {
                    if ($client['status'] === 'expired' || $client['status'] === 'suspended') {
                        $error = "Your account is {$client['status']}. Please contact Super Admin.";
                    } else {
                        // Auto-upgrade plain-text password to secure Bcrypt hash
                        if ($client['password'] === $password) {
                            $new_hash = password_hash($password, PASSWORD_BCRYPT);
                            $pdo->prepare("UPDATE clients SET password = ? WHERE id = ?")->execute([$new_hash, $client['id']]);
                        }

                        clear_failed_attempts($pdo, 'staff');
                        // Prune login activity logs older than 24 hours
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
                } else {
                    record_failed_attempt($pdo, 'staff', $identifier);
                    $error = 'Invalid Admin Email/Username or Password.';
                }

            } elseif ($role === 'dealer') {
                // Dealer login by unique username
                $stmt = $pdo->prepare("SELECT d.*, c.status as op_status FROM dealers d JOIN clients c ON d.client_id = c.id WHERE d.username = ? AND d.status = 'active' AND c.status = 'active'");
                $stmt->execute([$identifier]);
                $user = $stmt->fetch();

                if ($user && ($user['password'] === $password || password_verify($password, $user['password']))) {
                    // Auto-upgrade plain-text password to secure Bcrypt hash
                    if ($user['password'] === $password) {
                        $new_hash = password_hash($password, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE dealers SET password = ? WHERE id = ?")->execute([$new_hash, $user['id']]);
                    }

                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);
                    $_SESSION['dealer_id'] = $user['id'];
                    $_SESSION['client_id'] = $user['client_id'];
                    $_SESSION['dealer_name'] = $user['name'] ?? $user['username'];
                    header("Location: dealer/dashboard");
                    exit;
                } else {
                    record_failed_attempt($pdo, 'staff', $identifier);
                    $error = 'Invalid Dealer credentials, or Admin is inactive/suspended.';
                }

            } elseif ($role === 'lineman') {
                // Lineman login by unique username
                $stmt = $pdo->prepare("SELECT l.*, c.status as op_status FROM linemen l JOIN clients c ON l.client_id = c.id WHERE l.username = ? AND l.status = 'active' AND c.status = 'active'");
                $stmt->execute([$identifier]);
                $user = $stmt->fetch();

                if ($user && ($user['password'] === $password || password_verify($password, $user['password']))) {
                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);
                    $_SESSION['lineman_id'] = $user['id'];
                    $_SESSION['client_id'] = $user['client_id'];
                    $_SESSION['lineman_name'] = $user['full_name'];
                    header("Location: lineman/dashboard");
                    exit;
                } else {
                    record_failed_attempt($pdo, 'staff', $identifier);
                    $error = 'Invalid Line Man credentials, or Admin is inactive/suspended.';
                }

            } elseif ($role === 'recoveryman' || $role === 'operator_staff') {
                // Operator (formerly Recoveryman) login by unique username
                $stmt = $pdo->prepare("SELECT r.*, c.status as op_status FROM recovery_men r JOIN clients c ON r.client_id = c.id WHERE r.username = ? AND r.status = 'active' AND c.status = 'active'");
                $stmt->execute([$identifier]);
                $user = $stmt->fetch();

                if ($user && ($user['password'] === $password || password_verify($password, $user['password']))) {
                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);
                    $_SESSION['rm_id'] = $user['id'];
                    $_SESSION['client_id'] = $user['client_id'];
                    $_SESSION['rm_name'] = $user['full_name'];
                    header("Location: recoveryman/dashboard");
                    exit;
                } else {
                    record_failed_attempt($pdo, 'staff', $identifier);
                    $error = 'Invalid Operator credentials, or Admin is inactive/suspended.';
                }

            } elseif ($role === 'manager') {
                require_once 'config/manager_helper.php';
                ensure_manager_schema($pdo);

                // Manager login by unique username
                $stmt = $pdo->prepare("
                    SELECT m.*, c.status as op_status, c.company_name as op_company, c.email as op_email 
                    FROM managers m 
                    JOIN clients c ON m.client_id = c.id 
                    WHERE m.username = ? AND m.status = 'active' AND c.status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$identifier]);
                $user = $stmt->fetch();

                if ($user && ($user['password'] === $password || password_verify($password, $user['password']))) {
                    // Auto-upgrade plain-text password to secure Bcrypt hash
                    if ($user['password'] === $password) {
                        $new_hash = password_hash($password, PASSWORD_BCRYPT);
                        $pdo->prepare("UPDATE managers SET password = ? WHERE id = ?")->execute([$new_hash, $user['id']]);
                    }

                    clear_failed_attempts($pdo, 'staff');
                    session_regenerate_id(true);

                    // Manager uses Operator interface with tenant context
                    $_SESSION['operator_logged_in'] = true;
                    $_SESSION['operator_id'] = $user['client_id'];
                    $_SESSION['operator_email'] = $user['op_email'];
                    $_SESSION['operator_name'] = $user['full_name'];
                    $_SESSION['operator_company'] = $user['op_company'];
                    $_SESSION['client_id'] = $user['client_id'];

                    // Specific Manager identity and permission flags
                    $_SESSION['is_manager'] = true;
                    $_SESSION['manager_id'] = $user['id'];
                    $_SESSION['manager_username'] = $user['username'];
                    $_SESSION['manager_name'] = $user['full_name'];
                    $_SESSION['can_manage_packages'] = ($user['can_manage_packages'] === 'yes');
                    $_SESSION['can_collect_recovery'] = ($user['can_collect_recovery'] === 'yes');

                    log_staff_activity($pdo, 'Logged in to Manager Portal', 'System', 'Login');

                    header("Location: admin/dashboard");
                    exit;
                } else {
                    record_failed_attempt($pdo, 'staff', $identifier);
                    $error = 'Invalid Manager credentials, or Admin account is inactive/suspended.';
                }
            } else {
                $error = 'Invalid role selected.';
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
    <title>Login - <?= BRAND_NAME ?></title>
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
            --card-bg: rgba(15, 23, 42, 0.7);
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

        /* Abstract Background Elements */
        .bg-shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.3;
            z-index: 0;
            animation: pulse 12s infinite alternate;
        }
        .shape-1 { top: -15%; left: -10%; width: 50vw; height: 50vw; background: var(--primary); }
        .shape-2 { bottom: -15%; right: -10%; width: 60vw; height: 60vw; background: var(--accent); }
        
        @keyframes pulse {
            0% { transform: scale(1) translate(0, 0); }
            100% { transform: scale(1.1) translate(5%, 5%); }
        }

        /* Glassmorphism Login Card */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 460px;
            padding: 20px;
            margin: 20px 0;
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transition: all 0.4s ease;
        }

        /* Logo Area */
        .brand-logo {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: white;
            margin: 0 auto 1.2rem;
            box-shadow: 0 10px 25px -5px rgba(14, 165, 233, 0.5);
            letter-spacing: 1px;
        }

        /* Role Selection Cards */
        .role-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .role-grid > .role-card:last-child:nth-child(odd) {
            grid-column: span 2;
            max-width: 215px;
            margin: 0 auto;
            width: 100%;
        }

        .role-card {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 1.5rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .role-card:hover {
            background: rgba(30, 41, 59, 0.8);
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.1);
        }

        .role-card i {
            font-size: 2rem;
            margin-bottom: 12px;
            display: block;
            transition: transform 0.3s ease;
        }
        
        .role-card:hover i { transform: scale(1.1); }

        .role-card .role-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: #cbd5e1;
        }

        /* Active states for specific roles */
        .role-card[data-role="operator"] i { color: #3b82f6; }
        .role-card[data-role="manager"] i { color: #8b5cf6; }
        .role-card[data-role="dealer"] i { color: #10b981; }
        .role-card[data-role="recoveryman"] i { color: #ef4444; }
        .role-card[data-role="lineman"] i { color: #f59e0b; }

        /* Form Controls */
        .form-floating > .form-control {
            background-color: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            border-radius: 14px;
            height: 62px;
            font-size: 1rem;
        }
        
        .form-floating > .form-control:focus {
            background-color: rgba(15, 23, 42, 0.8);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.15);
        }

        .form-floating > label {
            color: #94a3b8;
            padding-left: 1.2rem;
        }
        


        /* Custom Input Group for Password */
        .password-wrapper { position: relative; }
        .password-toggle {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            cursor: pointer;
            z-index: 10;
            padding: 10px;
            transition: color 0.2s;
        }
        .password-toggle:hover { color: white; }

        /* Button */
        .btn-login {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border: none;
            border-radius: 14px;
            color: white;
            padding: 15px;
            font-weight: 600;
            font-size: 1.15rem;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px -5px rgba(14, 165, 233, 0.4);
            margin-top: 10px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -5px rgba(14, 165, 233, 0.6);
            color: white;
        }

        /* Color variations for btn-login based on Role */
        .btn-operator { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 10px 20px -5px rgba(59, 130, 246, 0.4); }
        .btn-manager { background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 10px 20px -5px rgba(139, 92, 246, 0.4); }
        .btn-dealer { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.4); }
        .btn-recoveryman { background: linear-gradient(135deg, #ef4444, #dc2626); box-shadow: 0 10px 20px -5px rgba(239, 68, 68, 0.4); }
        .btn-lineman { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 10px 20px -5px rgba(245, 158, 11, 0.4); }
        
        .btn-operator:hover { box-shadow: 0 15px 25px -5px rgba(59, 130, 246, 0.6); }
        .btn-manager:hover { box-shadow: 0 15px 25px -5px rgba(139, 92, 246, 0.6); }
        .btn-dealer:hover { box-shadow: 0 15px 25px -5px rgba(16, 185, 129, 0.6); }
        .btn-recoveryman:hover { box-shadow: 0 15px 25px -5px rgba(239, 68, 68, 0.6); }
        .btn-lineman:hover { box-shadow: 0 15px 25px -5px rgba(245, 158, 11, 0.6); }

        /* Alert */
        .alert-custom {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-radius: 14px;
            font-size: 0.95rem;
            padding: 1rem;
        }
        
        .credentials-block {
            display: none;
            animation: fadeIn 0.4s ease forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .back-link {
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s;
            display: inline-block;
            margin-top: 1rem;
        }
        .back-link:hover { color: white; }
    </style>
</head>
<body>

    <!-- Abstract Background -->
    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>

    <div class="login-wrapper">
        <div class="login-card">
            
            <div class="text-center mb-4">
                <div class="brand-logo"><?= BRAND_LOGO_ABBR ?></div>
                <h3 class="fw-bold mb-1" style="letter-spacing: -0.5px;"><?= BRAND_NAME ?></h3>
                <p class="text-secondary small fw-semibold" id="dynamicSubtitle" style="letter-spacing: 1px; color: #64748b !important;">SELECT YOUR ROLE</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-custom d-flex align-items-center mb-4 shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-3 fs-5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" id="unifiedLoginForm">
                <input type="hidden" name="role" id="selected_role" value="<?= htmlspecialchars($_POST['role'] ?? '') ?>">
                
                <!-- Role Selection -->
                <div class="role-grid" id="role_selection">
                    <div class="role-card" data-role="operator" onclick="selectRole('operator', 'Email')">
                        <i class="fa-solid fa-user-tie"></i>
                        <div class="role-name">Admin</div>
                    </div>
                    <div class="role-card" data-role="manager" onclick="selectRole('manager', 'Username')">
                        <i class="fa-solid fa-user-shield"></i>
                        <div class="role-name">Manager</div>
                    </div>
                    <div class="role-card" data-role="dealer" onclick="selectRole('dealer', 'Username')">
                        <i class="fa-solid fa-handshake"></i>
                        <div class="role-name">Dealer</div>
                    </div>
                    <div class="role-card" data-role="recoveryman" onclick="selectRole('recoveryman', 'Username')">
                        <i class="fa-solid fa-motorcycle"></i>
                        <div class="role-name">Operator</div>
                    </div>
                    <div class="role-card" data-role="lineman" onclick="selectRole('lineman', 'Username')">
                        <i class="fa-solid fa-hard-hat"></i>
                        <div class="role-name">Line Man</div>
                    </div>
                </div>

                <div class="text-center mt-3 pt-3 border-top border-secondary border-opacity-25" id="customer_login_link">
                    <a href="/customer/login" class="text-decoration-none small text-info fw-semibold">
                        <i class="fa-solid fa-users me-1"></i> Are you a Customer? Sign In to Customer Portal &rarr;
                    </a>
                </div>

                <!-- Credentials Block -->
                <div class="credentials-block" id="credentials_block">
                    
                    <div class="form-floating mb-3 shadow-sm">
                        <input type="text" name="identifier" id="input_identifier" class="form-control" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required autocomplete="off" placeholder="Identifier">
                        <label for="input_identifier" id="lbl_identifier"><i class="fa-regular fa-user me-2" id="icon_identifier"></i>Identifier</label>
                    </div>
                    
                    <div class="form-floating mb-4 password-wrapper shadow-sm">
                        <input type="password" class="form-control pe-5" id="password" name="password" placeholder="Password" required>
                        <label for="password"><i class="fa-solid fa-lock me-2"></i>Password</label>
                        <span class="password-toggle" onclick="togglePassword()">
                            <i class="fa-solid fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                    
                    <button type="submit" class="btn btn-login w-100" id="btn_submit">
                        Secure Login <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                    </button>
                    
                    <div class="text-center">
                        <a href="javascript:void(0)" onclick="resetRole()" class="back-link"><i class="fa-solid fa-arrow-left me-1"></i> Choose a different role</a>
                    </div>
                </div>
            </form>
            
            <div class="text-center mt-4 pt-2">
                <p class="text-secondary small mb-0" style="color: #64748b !important;">&copy; <?= date('Y') ?> <?= BRAND_NAME ?>.<br>All rights reserved.</p>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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

        function selectRole(role, label) {
            document.getElementById('selected_role').value = role;
            
            let iconClass = 'fa-user';
            if(role === 'operator') iconClass = 'fa-envelope';

            document.getElementById('lbl_identifier').innerHTML = `<i class="fa-regular ${iconClass} me-2"></i>${label}`;
            
            let btnSubmit = document.getElementById('btn_submit');
            btnSubmit.className = 'btn btn-login w-100'; // reset
            
            // Dynamic Subtitle & Button styling
            let subtitle = "LOGIN";
            
            if(role === 'operator') {
                btnSubmit.classList.add('btn-operator');
                subtitle = "ADMIN LOGIN";
            } else if(role === 'manager') {
                btnSubmit.classList.add('btn-manager');
                subtitle = "MANAGER LOGIN";
            } else if(role === 'dealer') {
                btnSubmit.classList.add('btn-dealer');
                subtitle = "DEALER LOGIN";
            } else if(role === 'recoveryman') {
                btnSubmit.classList.add('btn-recoveryman');
                subtitle = "OPERATOR LOGIN";
            } else if(role === 'lineman') {
                btnSubmit.classList.add('btn-lineman');
                subtitle = "LINE MAN LOGIN";
            }
            
            document.getElementById('dynamicSubtitle').innerText = subtitle;

            document.getElementById('role_selection').style.display = 'none';
            let custLink = document.getElementById('customer_login_link');
            if (custLink) custLink.style.display = 'none';
            document.getElementById('credentials_block').style.display = 'block';
            
            setTimeout(() => { document.getElementById('input_identifier').focus(); }, 100);
        }
        
        function resetRole() {
            document.getElementById('selected_role').value = '';
            document.getElementById('role_selection').style.display = 'grid';
            let custLink = document.getElementById('customer_login_link');
            if (custLink) custLink.style.display = 'block';
            document.getElementById('credentials_block').style.display = 'none';
            document.getElementById('dynamicSubtitle').innerText = "SELECT YOUR ROLE";
        }

        // Auto-select role if form was submitted but had error
        let preRole = document.getElementById('selected_role').value;
        if (preRole) {
            let lbl = preRole === 'operator' ? 'Email' : 'Username';
            selectRole(preRole, lbl);
        }
    </script>
    <style>
        body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
        input, textarea, select, [contenteditable="true"] { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }
    </style>
    <script src="/security-shield.js?v=2.1"></script>
</body>
</html>