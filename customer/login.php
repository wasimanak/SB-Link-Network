<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

// Fetch all active/suspended operators for the dropdown
$opStmt = $pdo->query("SELECT id, company_name, subarea FROM clients WHERE status != 'expired' ORDER BY company_name ASC");
$operators = $opStmt->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if ($client_id === 0) {
        $error = "Please select your provider/city.";
    } else {
        // Must match BOTH username and client_id
        $stmt = $pdo->prepare("SELECT id, client_id, password, status FROM subscribers WHERE username = ? AND client_id = ?");
        $stmt->execute([$username, $client_id]);
        $user = $stmt->fetch();

        if ($user && $user['password'] === $password) {
            if ($user['status'] === 'disabled') {
                $error = "Your account is disabled. Please contact support.";
            } else {
                // Check operator status
                $opCheckStmt = $pdo->prepare("SELECT status FROM clients WHERE id = ?");
                $opCheckStmt->execute([$user['client_id']]);
                $op_status = $opCheckStmt->fetchColumn();

                if ($op_status === 'suspended' || $op_status === 'expired') {
                    $error = "Service temporarily unavailable (Provider Suspended).";
                } else {
                    $_SESSION['customer_logged_in'] = true;
                    $_SESSION['customer_id'] = $user['id'];
                    $_SESSION['customer_client_id'] = $user['client_id'];
                    $_SESSION['customer_username'] = $username;
                    header("Location: dashboard.php");
                    exit;
                }
            }
        } else {
            $error = "Invalid username, password, or provider selected.";
        }
    }
}

if (isset($_GET['error'])) {
    if ($_GET['error'] == 'operator_suspended') $error = "Service temporarily unavailable.";
    if ($_GET['error'] == 'account_disabled') $error = "Your account is disabled.";
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login - SB Link Network</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background-color: #0b1120; 
            font-family: 'Inter', system-ui, sans-serif;
            display: flex; align-items: center; justify-content: center; min-height: 100vh; 
            background-image: radial-gradient(circle at 50% -20%, #1e293b, #0b1120);
        }
        .login-card { 
            background: linear-gradient(145deg, #1e293b, #0f172a); 
            border-radius: 16px; 
            border: 1px solid rgba(255, 255, 255, 0.05); 
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.7); 
            width: 100%; max-width: 420px; padding: 2.5rem 2rem; 
        }
        .text-accent { color: #38bdf8; }
        .btn-accent { 
            background: linear-gradient(135deg, #38bdf8, #0284c7); 
            color: #fff; font-weight: 600; border: none; border-radius: 10px; 
            padding: 12px 20px; transition: 0.3s; 
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.4); 
        }
        .btn-accent:hover { 
            background: linear-gradient(135deg, #0284c7, #0369a1); 
            color: #fff; transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.6); 
        }
        .form-control, .form-select {
            background-color: rgba(15, 23, 42, 0.6) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            color: #f8fafc !important;
            border-radius: 8px;
            padding: 10px 15px;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
            border-color: #38bdf8 !important;
        }
    </style>
</head>
<body>
    <div class="login-card text-center">
        <h2 class="text-accent fw-bold mb-3"><i class="fa-solid fa-wifi"></i> SB Link</h2>
        <h6 class="mb-4 text-secondary">Customer Portal Login</h6>
        
        <?php if($error): ?>
            <div class="alert alert-danger text-start small p-2 rounded"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-3 text-start">
                <label class="form-label text-secondary small fw-bold">Select Area / Provider <span class="text-danger">*</span></label>
                <select name="client_id" class="form-select" required>
                    <option value="">-- Choose your provider/city --</option>
                    <?php foreach($operators as $op): ?>
                        <option value="<?= $op['id'] ?>">
                            <?= htmlspecialchars($op['company_name']) ?> <?= !empty($op['subarea']) ? ' - ' . htmlspecialchars($op['subarea']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3 text-start">
                <label class="form-label text-secondary small fw-bold">Username</label>
                <input type="text" name="username" class="form-control" required placeholder="Enter PPPoE / Hotspot Username">
            </div>
            <div class="mb-4 text-start">
                <label class="form-label text-secondary small fw-bold">Password</label>
                <input type="password" name="password" class="form-control" required placeholder="Enter Password">
            </div>
            <button type="submit" class="btn btn-accent w-100"><i class="fa-solid fa-shield-halved me-2"></i> Secure Login</button>
        </form>
    </div>
</body>
</html>
