<?php
session_start();
require_once '../config/db.php';

$error = $_GET['error'] ?? '';
if ($error === 'account_blocked') {
    $error = "Your account has been suspended or expired. Contact Super Admin.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $client = $stmt->fetch();

        if ($client && password_verify($password, $client['password'])) {
            if ($client['status'] !== 'active' || (strtotime($client['expiry_date']) < time() && $client['expiry_date'] !== null)) {
                $error = "Account is suspended or expired.";
            } else {
                session_regenerate_id(true);
                $_SESSION['operator_logged_in'] = true;
                $_SESSION['operator_id'] = $client['id'];
                $_SESSION['operator_company'] = $client['company_name'];
                header("Location: dashboard.php");
                exit;
            }
        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Login - SB Link Network</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); width: 100%; max-width: 400px; }
        .form-control { background-color: #334155; border: 1px solid #475569; color: #f8fafc; }
        .form-control:focus { background-color: #334155; border-color: #10b981; box-shadow: 0 0 0 0.25rem rgba(16, 185, 129, 0.25); color: #f8fafc; }
        .input-group-text { background-color: #334155; border: 1px solid #475569; color: #4b5563; cursor: pointer; }
    </style>
</head>
<body>
    <div class="login-card border-top border-success border-4">
        <div class="text-center mb-4">
            <h3 class="text-white">Tenant Portal</h3>
            <p class="text-muted">SB Link Network</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label text-light">Email Address</label>
                <input type="email" class="form-control" name="email" required>
            </div>
            <div class="mb-4">
                <label class="form-label text-light">Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" required>
                    <span class="input-group-text" onclick="togglePassword()"><i class="fa-solid fa-eye" id="toggleIcon"></i></span>
                </div>
            </div>
            <button type="submit" class="btn btn-success w-100">Sign In <i class="fa-solid fa-right-to-bracket ms-2"></i></button>
        </form>
    </div>

    <script>
        function togglePassword() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text'; icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password'; icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
