<?php
session_start();
if (!isset($_SESSION['dealer_id'])) {
    header("Location: ../login.php");
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
    header("Location: ../login.php");
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
$gateway_account_name = ($active_gateway && !empty($active_gateway['account_name'])) ? $active_gateway['account_name'] : ($operator_info['company_name'] ?: BRAND_NAME);

$current_page = basename($_SERVER['PHP_SELF']);
$filter = $_GET['filter'] ?? '';
$isUserMenu = in_array($current_page, ['users.php', 'users', 'live_sessions.php', 'live_sessions', 'subscribers.php', 'subscribers', 'subscriber_view.php']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dealer Dashboard - <?= BRAND_NAME ?></title>
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
        
        /* Modern Sidebar Styles (Matches Operator / Admin) */
        #sidebar {
            width: 260px; height: 100vh; position: fixed; background-color: #ffffff; 
            top: 0; left: 0; border-right: 1px solid #e5e7eb; overflow: hidden;
            display: flex; flex-direction: column;
            box-shadow: 2px 0 10px rgba(0,0,0,0.02); z-index: 1000;
        }

        .sidebar-brand {
            padding: 1.25rem 1.25rem;
            color: #0f172a;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }

        .sidebar-logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #1d9bf0, #2563eb);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }

        .sidebar-logo-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: -0.3px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 170px;
        }

        .sidebar-logo-sub {
            font-size: 0.65rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        /* Scrollable Menu Area */
        .sidebar-menu-scroll {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 10px 14px 20px 14px;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 #f8fafc;
        }

        .sidebar-menu-scroll::-webkit-scrollbar { width: 5px; }
        .sidebar-menu-scroll::-webkit-scrollbar-track { background: #f8fafc; }
        .sidebar-menu-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

        /* Parent Nav Link Items */
        .nav-item-link {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            margin: 3px 0;
            border-radius: 10px;
            color: #334155;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.18s ease;
            cursor: pointer;
            user-select: none;
        }

        .nav-item-link:hover {
            background-color: #f8fafc;
            color: #0f172a;
        }

        .nav-item-link .nav-icon {
            width: 24px;
            font-size: 1.1rem;
            color: #475569;
            margin-right: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .nav-item-link .nav-text {
            flex-grow: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-item-link .nav-chevron {
            font-size: 0.72rem;
            color: #94a3b8;
            margin-left: auto;
            transition: transform 0.25s ease;
        }

        /* Active / Expanded State */
        .nav-item-link.active-parent,
        .nav-item-link[aria-expanded="true"] {
            background-color: #f1f5f9 !important;
            color: #0f172a !important;
            font-weight: 700;
        }

        .nav-item-link[aria-expanded="true"] .nav-chevron {
            transform: rotate(180deg);
            color: #0f172a;
        }

        /* Sub-Menu Tree Connector System (L-Branches) */
        .submenu-tree {
            position: relative;
            padding-left: 18px;
            margin: 2px 0 8px 14px;
            list-style: none;
        }

        .submenu-tree::before {
            content: '';
            position: absolute;
            top: -2px;
            bottom: 12px;
            left: 8px;
            width: 2px;
            background-color: #cbd5e1;
        }

        .submenu-tree-item {
            position: relative;
            margin: 3px 0;
        }

        .submenu-tree-item::before {
            content: '';
            position: absolute;
            left: -10px;
            top: -6px;
            width: 12px;
            height: 20px;
            border-left: 2px solid #cbd5e1;
            border-bottom: 2px solid #cbd5e1;
            border-bottom-left-radius: 8px;
            pointer-events: none;
        }

        .submenu-tree-item a {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 12px 6px 14px;
            font-size: 0.84rem;
            font-weight: 500;
            color: #475569;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .submenu-tree-item a:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }

        .submenu-tree-item a.active {
            background-color: #eff6ff !important;
            color: #1d4ed8 !important;
            font-weight: 700 !important;
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
        <div class="d-flex align-items-center gap-2">
            <div class="sidebar-logo-icon">
                <i class="fa-solid fa-wifi"></i>
            </div>
            <div>
                <div class="sidebar-logo-title" title="<?= htmlspecialchars($current_dealer['franchise'] ?: $current_dealer['full_name']) ?>">
                    <?= htmlspecialchars($current_dealer['franchise'] ?: $current_dealer['full_name']) ?>
                </div>
                <div class="sidebar-logo-sub"><?= BRAND_SHORT_NAME ?> DEALER</div>
            </div>
        </div>
    </div>
    
    <div class="px-3 py-2 mb-2 border-bottom bg-light bg-opacity-50">
        <div class="d-flex justify-content-between align-items-center">
            <span class="small text-muted fw-bold">Wallet:</span>
            <span class="small fw-bold text-success"><i class="fa-solid fa-wallet me-1"></i>Rs. <?= number_format($current_dealer['balance'], 2) ?></span>
        </div>
    </div>

    <div class="sidebar-menu-scroll">
        <!-- 1. Dashboard -->
        <a href="dashboard" class="nav-item-link <?= in_array($current_page, ['dashboard.php', 'dashboard']) ? 'active-parent' : '' ?>">
            <i class="fa-solid fa-gauge nav-icon"></i>
            <span class="nav-text">Dashboard</span>
        </a>

        <!-- 2. Users Profile Dropdown (matching Admin/Operator) -->
        <a href="#usersMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isUserMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isUserMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-users nav-icon"></i>
            <span class="nav-text">Users Profile</span>
            <i class="fa-solid fa-chevron-down nav-chevron"></i>
        </a>
        <div class="collapse <?= $isUserMenu ? 'show' : '' ?>" id="usersMenu">
            <ul class="submenu-tree">
                <li class="submenu-tree-item">
                    <a href="users" class="<?= (in_array($current_page, ['users.php', 'users', 'subscribers.php', 'subscribers']) && $filter==='') ? 'active' : '' ?>">All Users</a>
                </li>
                <li class="submenu-tree-item">
                    <a href="live_sessions" class="<?= (in_array($current_page, ['live_sessions.php', 'live_sessions']) && $filter!=='expired_online') || (in_array($current_page, ['users.php', 'users']) && $filter==='online') ? 'active' : '' ?>">Online Users</a>
                </li>
                <li class="submenu-tree-item">
                    <a href="live_sessions?filter=expired_online" class="<?= (in_array($current_page, ['live_sessions.php', 'live_sessions']) && $filter==='expired_online') || (in_array($current_page, ['users.php', 'users']) && $filter==='expired_online') ? 'active' : '' ?>">Expired Online</a>
                </li>
                <li class="submenu-tree-item">
                    <a href="users?filter=offline" class="<?= (in_array($current_page, ['users.php', 'users']) && $filter==='offline') ? 'active' : '' ?>">Offline Users (Radius)</a>
                </li>
                <li class="submenu-tree-item">
                    <a href="users?filter=expired" class="<?= (in_array($current_page, ['users.php', 'users']) && $filter==='expired') ? 'active' : '' ?>">Expired Users</a>
                </li>
                <li class="submenu-tree-item">
                    <a href="users?filter=expiring_1w" class="<?= (in_array($current_page, ['users.php', 'users']) && $filter==='expiring_1w') ? 'active' : '' ?>">Expiring In Week</a>
                </li>
                <li class="submenu-tree-item">
                    <a href="users?filter=expiring_2w" class="<?= (in_array($current_page, ['users.php', 'users']) && $filter==='expiring_2w') ? 'active' : '' ?>">Expiring In 2 Weeks</a>
                </li>
            </ul>
        </div>

        <!-- 3. My Profile -->
        <a href="profile" class="nav-item-link <?= in_array($current_page, ['profile.php', 'profile']) ? 'active-parent' : '' ?>">
            <i class="fa-solid fa-id-badge nav-icon"></i>
            <span class="nav-text">My Profile</span>
        </a>

        <!-- 4. Online Recharge -->
        <a href="#" class="nav-item-link" data-bs-toggle="modal" data-bs-target="#addFundsModal">
            <i class="fa-solid fa-money-bill-transfer nav-icon"></i>
            <span class="nav-text">Online Recharge</span>
        </a>

        <!-- 4. Logout -->
        <a href="logout" class="nav-item-link text-danger mt-3">
            <i class="fa-solid fa-right-from-bracket nav-icon text-danger"></i>
            <span class="nav-text text-danger">Logout</span>
        </a>
    </div>
</nav>

<div id="main-content">
    <div class="top-header">
        <div>
            <h5 class="mb-0 fw-bold">Dealer Portal</h5>
            <small class="text-muted">Manage your franchise and users</small>
        </div>
        <div class="dealer-badge" onclick="window.location='profile';" style="cursor: pointer;" title="View My Profile">
            <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="fa-solid fa-user"></i>
            </div>
            <?= htmlspecialchars($current_dealer['username']) ?>
        </div>
    </div>
