<?php
session_start();
if (isset($_SESSION['dealer_id'])) { header("Location: dashboard.php"); exit; }
require_once '../config/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $stmt = $pdo->prepare("SELECT * FROM dealers WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $dealer = $stmt->fetch();
    if ($dealer && $dealer['password'] === $password) {
        $_SESSION['dealer_id'] = $dealer['id'];
        $_SESSION['client_id'] = $dealer['client_id'];
        header("Location: dashboard.php"); exit;
    } else { $error = 'Invalid credentials or inactive account.'; }
}
?>
<!DOCTYPE html>
<html>
<head><title>Dealer Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 400px;">
    <div class="card shadow">
        <div class="card-header bg-dark text-white text-center"><h4>Dealer Portal</h4></div>
        <div class="card-body">
            <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-3"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>