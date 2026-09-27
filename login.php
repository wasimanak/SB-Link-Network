<?php
session_start();
require_once 'config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    $identifier = trim($_POST['identifier'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($role) || empty($identifier) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        if ($role === 'operator') {
            // Operator uses email
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $identifier]);
            $client = $stmt->fetch();

            if ($client && password_verify($password, $client['password'])) {
                if ($client['status'] === 'expired' || $client['status'] === 'suspended') {
                    $error = "Your account is {$client['status']}. Please contact Super Admin.";
                } else {
                    $_SESSION['operator_id'] = $client['id'];
                    $_SESSION['operator_email'] = $client['email'];
                    $_SESSION['operator_name'] = $client['company_name'];
                    
                    // Also set client_id for generic compatibility if needed
                    $_SESSION['client_id'] = $client['id'];

                    header("Location: operator/dashboard.php");
                    exit;
                }
            } else {
                $error = 'Invalid Operator Email or Password.';
            }

        } elseif ($role === 'dealer') {
            $stmt = $pdo->prepare("SELECT * FROM dealers WHERE username = ? AND status = 'active'");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
            if ($user && $user['password'] === $password) {
                $_SESSION['dealer_id'] = $user['id'];
                $_SESSION['client_id'] = $user['client_id'];
                header("Location: dealer/dashboard.php");
                exit;
            } else {
                $error = 'Invalid Dealer Username or inactive account.';
            }

        } elseif ($role === 'lineman') {
            $stmt = $pdo->prepare("SELECT * FROM linemen WHERE username = ? AND status = 'active'");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
            if ($user && $user['password'] === $password) {
                $_SESSION['lineman_id'] = $user['id'];
                $_SESSION['client_id'] = $user['client_id'];
                $_SESSION['lineman_name'] = $user['full_name'];
                header("Location: lineman/dashboard.php");
                exit;
            } else {
                $error = 'Invalid Line Man Username or inactive account.';
            }

        } elseif ($role === 'recoveryman') {
            $stmt = $pdo->prepare("SELECT * FROM recovery_men WHERE username = ? AND status = 'active'");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
            if ($user && $user['password'] === $password) {
                $_SESSION['rm_id'] = $user['id'];
                $_SESSION['client_id'] = $user['client_id'];
                $_SESSION['rm_name'] = $user['full_name'];
                header("Location: recoveryman/dashboard.php");
                exit;
            } else {
                $error = 'Invalid Recovery Man Username or inactive account.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Login - SB Link Network</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
            transition: all 0.3s ease;
        }
        .login-header {
            background: #ffffff;
            padding: 30px;
            text-align: center;
            border-bottom: 2px solid #f1f5f9;
        }
        .brand-icon {
            font-size: 3rem;
            color: #3b82f6;
            margin-bottom: 10px;
        }
        .role-btn {
            border: 2px solid #e2e8f0;
            background: white;
            color: #64748b;
            font-weight: 600;
            border-radius: 12px;
            padding: 12px;
            transition: all 0.2s;
            cursor: pointer;
            text-align: center;
        }
        .role-btn:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        .role-btn.active {
            border-color: #3b82f6;
            background: #eff6ff;
            color: #1d4ed8;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.1);
        }
        .input-group-text { background: transparent; border-right: none; color: #94a3b8; }
        .form-control { border-left: none; padding-left: 0; }
        .form-control:focus { box-shadow: none; border-color: #dee2e6; }
        .input-group:focus-within {
            box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.25);
            border-radius: 0.375rem;
        }
        .input-group:focus-within .input-group-text, 
        .input-group:focus-within .form-control {
            border-color: #86b7fe;
        }
        
        #credentials_block {
            display: none;
            animation: fadeIn 0.4s ease forwards;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="container d-flex justify-content-center">
    <div class="login-card">
        <div class="login-header">
            <i class="fa-solid fa-network-wired brand-icon"></i>
            <h4 class="fw-bold text-dark mb-0">SB Link Network</h4>
            <p class="text-muted small mb-0 mt-1">Select your role to access your portal</p>
        </div>
        <div class="card-body p-4 p-md-5 pt-4">
            
            <?php if($error): ?>
                <div class="alert alert-danger border-0 shadow-sm d-flex align-items-center">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="unifiedLoginForm">
                <input type="hidden" name="role" id="selected_role" value="">
                
                <!-- Role Selection -->
                <div class="row g-3 mb-4" id="role_selection">
                    <div class="col-6">
                        <div class="role-btn" data-role="operator" onclick="selectRole('operator', 'Email', 'fa-envelope')">
                            <i class="fa-solid fa-user-tie fs-4 mb-2 d-block text-primary"></i>
                            Operator
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="role-btn" data-role="dealer" onclick="selectRole('dealer', 'Username', 'fa-user')">
                            <i class="fa-solid fa-handshake fs-4 mb-2 d-block text-success"></i>
                            Dealer
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="role-btn" data-role="recoveryman" onclick="selectRole('recoveryman', 'Username', 'fa-user')">
                            <i class="fa-solid fa-motorcycle fs-4 mb-2 d-block text-danger"></i>
                            Recovery
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="role-btn" data-role="lineman" onclick="selectRole('lineman', 'Username', 'fa-user')">
                            <i class="fa-solid fa-hard-hat fs-4 mb-2 d-block text-warning"></i>
                            Line Man
                        </div>
                    </div>
                </div>

                <!-- Credentials Block -->
                <div id="credentials_block">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small" id="lbl_identifier">Identifier</label>
                        <div class="input-group">
                            <span class="input-group-text"><i id="icon_identifier" class="fa-solid fa-user"></i></span>
                            <input type="text" name="identifier" id="input_identifier" class="form-control form-control-lg" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" name="password" class="form-control form-control-lg" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm" id="btn_submit">
                        Secure Login <i class="fa-solid fa-arrow-right ms-2"></i>
                    </button>
                    
                    <div class="text-center mt-3">
                        <a href="javascript:void(0)" onclick="resetRole()" class="text-muted small text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i> Change Role</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function selectRole(role, label, icon) {
        // Set hidden input
        document.getElementById('selected_role').value = role;
        
        // Highlight active button
        document.querySelectorAll('.role-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector(`.role-btn[data-role='${role}']`).classList.add('active');

        // Update Labels and Placeholders
        document.getElementById('lbl_identifier').innerText = label;
        document.getElementById('input_identifier').placeholder = `Enter your ${label.toLowerCase()}...`;
        document.getElementById('icon_identifier').className = `fa-solid ${icon}`;

        // Change button color based on role
        let btnSubmit = document.getElementById('btn_submit');
        btnSubmit.className = 'btn btn-lg w-100 fw-bold shadow-sm ';
        if(role === 'operator') btnSubmit.classList.add('btn-primary');
        if(role === 'dealer') btnSubmit.classList.add('btn-success');
        if(role === 'recoveryman') btnSubmit.classList.add('btn-danger');
        if(role === 'lineman') btnSubmit.classList.add('btn-warning');

        // Hide Role selection slightly or just show credentials
        document.getElementById('role_selection').style.display = 'none';
        document.getElementById('credentials_block').style.display = 'block';
        
        // Focus input
        setTimeout(() => { document.getElementById('input_identifier').focus(); }, 100);
    }
    
    function resetRole() {
        document.getElementById('selected_role').value = '';
        document.getElementById('role_selection').style.display = 'flex';
        document.getElementById('credentials_block').style.display = 'none';
        document.querySelectorAll('.role-btn').forEach(btn => btn.classList.remove('active'));
    }
</script>
</body>
</html>