<?php
session_start();
if (!isset($_SESSION['dealer_id'])) {
    header("Location: login.php");
    exit;
}
require_once '../config/db.php';

$dealer_id = $_SESSION['dealer_id'];
$client_id = $_SESSION['client_id'];

// Get Dealer Data & Permissions
$stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = ? AND status = 'active'");
$stmt->execute([$dealer_id]);
$current_dealer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_dealer) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Global Gateway Config for Recharge
$gwStmt = $pdo->prepare("SELECT gateway_name, account_name FROM payment_gateways WHERE client_id = ? AND status = 'active' LIMIT 1");
$gwStmt->execute([$client_id]);
$active_gateway = $gwStmt->fetch();

$opStmt = $pdo->prepare("SELECT company_name FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator_info = $opStmt->fetch();

$gateway_display_name = $active_gateway ? $active_gateway['gateway_name'] : 'Meezan Bank';
$gateway_account_name = ($active_gateway && !empty($active_gateway['account_name'])) ? $active_gateway['account_name'] : ($operator_info['company_name'] ?: 'SB-Link Network');

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dealer Dashboard - SB Link</title>
    <!-- Same UI logic as Operator -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
        :root {
            --primary-bg: #f8fafc;
            --accent-color: #3b82f6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--primary-bg); color: #334155; }
        
        /* Light Sidebar Styles (Matches Operator) */
        #sidebar {
            width: 260px; height: 100vh; position: fixed; background-color: #ffffff; 
            top: 0; left: 0; border-right: 1px solid #e5e7eb; overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.02); z-index: 1000;
        }
        
        .sidebar-brand {
            padding: 1.5rem;
            color: #0f172a;
            font-size: 1.25rem;
            font-weight: 700;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-item { margin: 0; }
        
        .nav-link {
            color: #4b5563; text-decoration: none; padding: 14px 20px; 
            display: flex; align-items: center; font-size: 1rem; font-weight: 500;
            transition: all 0.2s ease;
            border-radius: 0;
            gap: 0;
        }
        
        .nav-link:hover {
            background-color: #f8fafc; color: #1e293b;
        }
        
        .nav-link i {
            width: 30px; text-align: left; font-size: 1.1rem; color: #64748b;
        }
        
        .nav-link.active {
            background-color: #f1f5f9; color: #0f172a; border-left: 4px solid #3b82f6; padding-left: 16px;
        }
        
        .nav-link.active i {
            color: #3b82f6;
        }
        
        .nav-link.text-danger:hover {
            background-color: #fef2f2; color: #dc2626 !important;
        }
        
        .nav-link.text-danger:hover i {
            color: #dc2626 !important;
        }

        /* Main Content */
        #main-content { margin-left: 260px; padding: 2rem; min-height: 100vh; transition: all 0.3s ease; }
        
        /* Top Header */
        .top-header {
            background: white;
            padding: 1rem 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dealer-badge {
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #334155;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border: none;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .stat-icon {
            width: 60px; height: 60px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
        }
        .bg-light-primary { background: #eff6ff; color: #3b82f6; }
        .bg-light-success { background: #f0fdf4; color: #22c55e; }
        .bg-light-warning { background: #fefce8; color: #eab308; }
        .bg-light-danger { background: #fef2f2; color: #ef4444; }
        
        .stat-details h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .stat-details p { margin: 0; color: #64748b; font-size: 0.875rem; font-weight: 500; }
    </style>
</head>
<body>

<nav id="sidebar">
    <div class="sidebar-brand">
        <i class="fa-solid fa-network-wired text-primary"></i> SB Link
    </div>
    
    <div class="px-4 py-3 mb-2 border-bottom border-secondary border-opacity-25">
        <div class="text-dark fw-bold"><?= htmlspecialchars($current_dealer['franchise'] ?: $current_dealer['full_name']) ?></div>
        <div class="small text-muted"><i class="fa-solid fa-wallet text-success me-1"></i> Rs. <?= number_format($current_dealer['balance'], 2) ?></div>
    </div>

    <ul class="nav flex-column mt-3">
        <li class="nav-item">
            <a class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                <i class="fa-solid fa-gauge"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current_page == 'users.php' ? 'active' : '' ?>" href="users.php">
                <i class="fa-solid fa-users"></i> My Users
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                <i class="fa-solid fa-money-bill-transfer"></i> Online Recharge
            </a>
        </li>
        <li class="nav-item mt-4">
            <a class="nav-link text-danger" href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </li>
    </ul>
</nav>

<div id="main-content">
    <div class="top-header">
        <div>
            <h5 class="mb-0 fw-bold">Dealer Portal</h5>
            <small class="text-muted">Manage your franchise and users</small>
        </div>
        <div class="dealer-badge">
            <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="fa-solid fa-user"></i>
            </div>
            <?= htmlspecialchars($current_dealer['username']) ?>
        </div>
    </div>