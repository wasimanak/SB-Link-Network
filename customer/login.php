<?php
session_start();
require_once '../config/db.php';

if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Please enter your username and password.";
    } else {
        // Query subscribers by username (unique)
        $stmt = $pdo->prepare("SELECT id, client_id, password, status FROM subscribers WHERE username = ?");
        $stmt->execute([$username]);
        $users = $stmt->fetchAll();

        $user = null;
        if (count($users) === 1) {
            $user = $users[0];
        } elseif (count($users) > 1) {
            // If duplicate username exists across operators, match by password
            foreach ($users as $u) {
                if ($u['password'] === $password) {
                    $user = $u;
                    break;
                }
            }
            if (!$user) {
                $user = $users[0];
            }
        }

        if ($user) {
            if ($user['password'] === $password) {
                if ($user['status'] === 'disabled') {
                    $error = "Your account is disabled. Please contact your internet provider.";
                } else {
                    // Check operator status
                    $opCheckStmt = $pdo->prepare("SELECT status FROM clients WHERE id = ?");
                    $opCheckStmt->execute([$user['client_id']]);
                    $op_status = $opCheckStmt->fetchColumn();

                    if ($op_status === 'suspended' || $op_status === 'expired') {
                        $error = "Service temporarily unavailable (Provider account suspended).";
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
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
    }
}

if (isset($_GET['error'])) {
    if ($_GET['error'] == 'operator_suspended') $error = "Service temporarily unavailable.";
    if ($_GET['error'] == 'account_disabled') $error = "Your account is disabled.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login - <?= BRAND_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background-color: #f8fafc; 
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background-image: 
                radial-gradient(at 0% 0%, rgba(37, 99, 235, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(59, 130, 246, 0.05) 0px, transparent 50%);
        }

        .login-card { 
            background: #ffffff; 
            border-radius: 24px; 
            border: 1px solid #eef2f6; 
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.07); 
            width: 100%; 
            max-width: 440px; 
            padding: 2.75rem 2.25rem;
            position: relative;
        }

        .brand-icon-box {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.5rem;
            margin: 0 auto 16px auto;
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25);
        }

        .form-control, .form-select {
            background-color: #ffffff !important;
            border: 1.5px solid #e2e8f0 !important;
            color: #0f172a !important;
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12) !important;
            border-color: #2563eb !important;
        }

        .btn-login { 
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); 
            color: #ffffff; 
            font-weight: 700; 
            border: none; 
            border-radius: 12px; 
            padding: 13px 20px; 
            transition: all 0.25s ease;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3); 
        }

        .btn-login:hover { 
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); 
            color: #ffffff; 
            transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45); 
        }

        .toggle-pwd-btn {
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0 14px;
            cursor: pointer;
        }
        .toggle-pwd-btn:hover {
            color: #0f172a;
        }
    </style>
</head>
<body>

    <div class="login-card">
        
        <!-- Brand Header -->
        <div class="text-center mb-4">
            <div class="brand-icon-box">
                <i class="fa-solid fa-wifi"></i>
            </div>
            <h4 class="fw-bold text-dark mb-1" style="letter-spacing: -0.5px;"><?= BRAND_NAME ?></h4>
            <div class="text-muted small">Customer Self-Service Portal</div>
        </div>
        
        <!-- Error Alert -->
        <?php if($error): ?>
            <div class="alert alert-danger border-0 rounded-4 small p-3 mb-4 d-flex align-items-center gap-2 bg-danger-subtle text-danger-emphasis">
                <i class="fa-solid fa-circle-exclamation fs-5 flex-shrink-0"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>
        
        <!-- Login Form -->
        <form method="POST">
            
            <!-- Username -->
            <div class="mb-3">
                <label class="form-label text-dark small fw-bold mb-1">
                    <i class="fa-solid fa-user text-primary me-1"></i> Subscriber Username <span class="text-danger">*</span>
                </label>
                <input type="text" name="username" class="form-control" required placeholder="e.g. your_username" autocomplete="username">
            </div>

            <!-- Password -->
            <div class="mb-4">
                <label class="form-label text-dark small fw-bold mb-1">
                    <i class="fa-solid fa-lock text-primary me-1"></i> Password <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <input type="password" name="password" id="user_password" class="form-control" required placeholder="Enter your password" autocomplete="current-password">
                    <button class="btn btn-outline-secondary border-start-0 toggle-pwd-btn" type="button" onclick="const p = document.getElementById('user_password'); p.type = p.type === 'password' ? 'text' : 'password';">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-login w-100 mb-3">
                <i class="fa-solid fa-right-to-bracket me-2"></i> Sign In to Dashboard
            </button>

            <div class="text-center text-muted small mt-4" style="font-size: 0.76rem;">
                Need help with your internet connection? Contact your local ISP operator.
            </div>
        </form>

    </div>

</body>
</html>
