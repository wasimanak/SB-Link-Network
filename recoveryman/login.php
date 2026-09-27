<?php
session_start();
if (isset($_SESSION['rm_id'])) { header("Location: dashboard.php"); exit; }
require_once '../config/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $stmt = $pdo->prepare("SELECT * FROM recovery_men WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && $user['password'] === $password) {
        $_SESSION['rm_id'] = $user['id'];
        $_SESSION['client_id'] = $user['client_id'];
        $_SESSION['rm_name'] = $user['full_name'];
        header("Location: dashboard.php"); exit;
    } else { $error = 'Invalid credentials or inactive account.'; }
}
?>
<!DOCTYPE html>
<html>
<head><title>Recovery Man Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
<div class="container" style="max-width: 400px;">
    <div class="card shadow border-0 rounded-4">
        <div class="card-header bg-danger text-white text-center py-3 border-0 rounded-top-4">
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-motorcycle me-2"></i> Recovery Man Portal</h5>
        </div>
        <div class="card-body p-4">
            <?php if($error): ?><div class="alert alert-danger small"><?= $error ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-3"><label class="fw-bold small text-secondary">Username</label><input type="text" name="username" class="form-control bg-light" required></div>
                <div class="mb-4"><label class="fw-bold small text-secondary">Password</label><input type="password" name="password" class="form-control bg-light" required></div>
                <button type="submit" class="btn btn-danger w-100 fw-bold py-2">Login</button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>