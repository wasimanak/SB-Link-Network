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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background-color: #0f172a; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); width: 100%; max-width: 400px; }
        .form-control { background-color: #334155; border: 1px solid #475569; color: #f8fafc; }
        .form-control:focus { background-color: #334155; border-color: #3b82f6; box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25); color: #f8fafc; }
        .input-group-text { background-color: #334155; border: 1px solid #475569; color: #94a3b8; cursor: pointer; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="text-center mb-4">
            <h3 class="text-white">SB Link Network</h3>
            <p class="text-muted">Super Admin Portal</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label for="username" class="form-label text-light">Username or Email</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label text-light">Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" required>
                    <span class="input-group-text" onclick="togglePassword()">
                        <i class="fa-solid fa-eye" id="toggleIcon"></i>
                    </span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Sign In <i class="fa-solid fa-arrow-right ms-2"></i></button>
        </form>
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