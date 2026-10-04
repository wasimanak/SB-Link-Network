<?php
session_start();
require_once '../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE username = :username OR email = :email LIMIT 1");
            $stmt->execute(['username' => $username, 'email' => $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true); // Prevent session fixation
                $_SESSION['superadmin_logged_in'] = true;
                $_SESSION['superadmin_id'] = $admin['id'];
                $_SESSION['superadmin_name'] = $admin['name'];
                
                header("Location: dashboard.php");
                exit;
            } else {
                $error = 'Invalid credentials.';
            }
        } catch (PDOException $e) {
            $error = 'System error occurred. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - SB Link Network</title>
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
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --accent: #8b5cf6;
            --bg-dark: #0b1120;
            --card-bg: rgba(30, 41, 59, 0.65);
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
            filter: blur(100px);
            opacity: 0.3;
            z-index: 0;
            animation: pulse 12s infinite alternate;
        }
        .shape-1 { top: -10%; left: -10%; width: 50vw; height: 50vw; background: var(--primary); }
        .shape-2 { bottom: -20%; right: -10%; width: 60vw; height: 60vw; background: var(--accent); }
        
        @keyframes pulse {
            0% { transform: scale(1) translate(0, 0); }
            100% { transform: scale(1.1) translate(5%, 5%); }
        }

        /* Glassmorphism Login Card */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }

        .login-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 3.5rem 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transform: translateY(0);
            transition: all 0.3s ease;
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
            margin: 0 auto 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.5);
            letter-spacing: 1px;
        }

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
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
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
            transition: color 0.2s;
            padding: 10px;
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
            box-shadow: 0 10px 20px -5px rgba(59, 130, 246, 0.4);
            margin-top: 1rem;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -5px rgba(59, 130, 246, 0.6);
            color: white;
        }

        /* Alert */
        .alert-custom {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-radius: 14px;
            font-size: 0.95rem;
            padding: 1rem;
        }
    </style>
</head>
<body>

    <!-- Abstract Background -->
    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>

    <div class="login-wrapper">
        <div class="login-card">
            
            <div class="text-center mb-4 pb-2">
                <div class="brand-logo">SB</div>
                <h3 class="fw-bold mb-1" style="letter-spacing: -0.5px;">Welcome Back</h3>
                <p class="text-secondary small fw-semibold" style="letter-spacing: 1px; color: #64748b !important;">SUPER ADMIN PORTAL</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-custom d-flex align-items-center mb-4 shadow-sm" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-3 fs-5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-floating mb-3 shadow-sm">
                    <input type="text" class="form-control" id="username" name="username" placeholder="Username or Email" required autocomplete="off">
                    <label for="username"><i class="fa-regular fa-user me-2"></i>Username</label>
                </div>
                
                <div class="form-floating mb-4 password-wrapper shadow-sm">
                    <input type="password" class="form-control pe-5" id="password" name="password" placeholder="Password" required>
                    <label for="password"><i class="fa-solid fa-lock me-2"></i>Password</label>
                    <span class="password-toggle" onclick="togglePassword()">
                        <i class="fa-solid fa-eye" id="toggleIcon"></i>
                    </span>
                </div>

                <button type="submit" class="btn btn-login w-100">
                    Sign In <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                </button>
            </form>
            
            <div class="text-center mt-4 pt-2">
                <p class="text-secondary small mb-0" style="color: #64748b !important;">&copy; <?= date('Y') ?> SB Link Network.<br>All rights reserved.</p>
            </div>
        </div>
    </div>

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
    </script>
</body>
</html>