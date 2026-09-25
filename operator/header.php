<?php
session_start();
require_once '../config/db.php';

// Auth Check
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: login.php");
    exit;
}

// Check if tenant is still active
$stmt = $pdo->prepare("SELECT status, expiry_date FROM clients WHERE id = ?");
$stmt->execute([$_SESSION['operator_id']]);
$tenant = $stmt->fetch();

if (!$tenant || $tenant['status'] !== 'active' || (strtotime($tenant['expiry_date']) < time())) {
    session_destroy();
    echo "<script>alert('Account suspended or expired. Contact Super Admin.'); window.location='login.php';</script>";
    exit;
}

$p = basename($_SERVER['PHP_SELF']);
$filter = $_GET['filter'] ?? '';

// Menu Active States
$isUserMenu = in_array($p, ['subscribers.php', 'live_sessions.php']);
$isAccMenu = in_array($p, ['packages.php']);
$isTicketMenu = in_array($p, ['requests.php']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Operator Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; color: #333; overflow-x: hidden; }
        
        /* Sidebar Styles based on requested design */
        .sidebar {
            width: 260px; height: 100vh; position: fixed; background-color: #ffffff; 
            padding-top: 15px; border-right: 1px solid #e5e7eb; overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.02);
        }
        .sidebar a.nav-link-main {
            color: #4b5563; text-decoration: none; padding: 14px 20px; 
            display: flex; align-items: center; font-size: 1rem; font-weight: 500;
        }
        .sidebar a.nav-link-main:hover { background-color: #f8fafc; color: #1e293b; }
        .sidebar a.nav-link-main i.menu-icon { width: 30px; text-align: left; font-size: 1.1rem; color: #64748b; }
        
        /* Active Parent Item */
        .sidebar a.active-parent {
            background-color: #f1f5f9; color: #0f172a; border-left: 4px solid #3b82f6; padding-left: 16px;
        }
        .sidebar a.active-parent i.menu-icon { color: #3b82f6; }

        /* Dropdown Icon */
        .has-submenu::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; margin-left: auto; transition: 0.2s; color: #94a3b8; }
        .has-submenu[aria-expanded="true"]::after { transform: rotate(180deg); }

        /* Submenu Styling */
        .submenu { background-color: #fafbfc; position: relative; margin-top: 0; padding-top: 5px; padding-bottom: 5px; }
        /* Vertical Line */
        .submenu::before {
            content: ""; position: absolute; left: 30px; top: 0; bottom: 0; width: 1px; background-color: #e2e8f0;
        }
        .submenu a {
            display: block; color: #64748b; text-decoration: none; padding: 10px 20px 10px 50px; 
            position: relative; font-size: 0.95rem; font-weight: 400; transition: 0.2s;
        }
        .submenu a::before {
            content: ""; position: absolute; left: 27px; top: 50%; transform: translateY(-50%);
            width: 7px; height: 7px; background-color: #cbd5e1; border-radius: 50%; z-index: 1; transition: 0.2s;
        }
        .submenu a:hover { color: #1e293b; }
        .submenu a:hover::before { background-color: #94a3b8; }
        
        /* Active Sub Item */
        .submenu a.active { color: #3b82f6; font-weight: 500; }
        .submenu a.active::before { background-color: #3b82f6; }

        .main-content { margin-left: 260px; padding: 30px; min-height: 100vh; }
        @media (max-width: 768px) { .sidebar { display: none; } .main-content { margin-left: 0; } }

        /* Top Nav */
        .top-nav { background-color: #ffffff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; margin-left: 260px; box-shadow: 0 1px 5px rgba(0,0,0,0.02); }
        @media (max-width: 768px) { .top-nav { margin-left: 0; } }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar">
        
        <a href="dashboard.php" class="nav-link-main <?= $p==='dashboard.php' ? 'active-parent' : '' ?>">
            <i class="fa-solid fa-house menu-icon"></i> Home
        </a>

        <a href="profile.php" class="nav-link-main <?= $p==='profile.php' ? 'active-parent' : '' ?>">
            <i class="fa-solid fa-user menu-icon"></i> My Profile
        </a>

        <!-- Team -->
        <a href="#teamMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu collapsed" aria-expanded="false">
            <i class="fa-solid fa-sitemap menu-icon"></i> Team
        </a>
        <div class="collapse submenu" id="teamMenu">
            <a href="#">All Teams</a>
            <a href="#">Add Member</a>
        </div>

        <!-- User -->
        <a href="#userMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isUserMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isUserMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-user menu-icon"></i> User
        </a>
        <div class="collapse submenu <?= $isUserMenu ? 'show' : '' ?>" id="userMenu">
            <a href="subscribers.php" class="<?= ($p==='subscribers.php' && $filter==='') ? 'active' : '' ?>">All Users</a>
            <a href="live_sessions.php" class="<?= $p==='live_sessions.php' ? 'active' : '' ?>">Online Users</a>
            <a href="subscribers.php?filter=offline" class="<?= $filter==='offline' ? 'active' : '' ?>">Offline Users (Radius)</a>
            <a href="subscribers.php?filter=expired" class="<?= $filter==='expired' ? 'active' : '' ?>">Expired Users</a>
            <a href="subscribers.php?filter=expiring_1w" class="<?= $filter==='expiring_1w' ? 'active' : '' ?>">Expiring In Week</a>
            <a href="subscribers.php?filter=expiring_2w" class="<?= $filter==='expiring_2w' ? 'active' : '' ?>">Expiring In 2 Weeks</a>
        </div>

        <!-- Accounting -->
        <a href="#accountingMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isAccMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isAccMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-dollar-sign menu-icon"></i> Accounting
        </a>
        <div class="collapse submenu <?= $isAccMenu ? 'show' : '' ?>" id="accountingMenu">
            <a href="packages.php" class="<?= $p==='packages.php' ? 'active' : '' ?>">All Packages</a>
            <a href="#">Invoices</a>
            <a href="#">Payments</a>
        </div>

        <!-- Logs & Reports -->
        <a href="#logsMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= in_array($p, ['activity_logs.php']) ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= in_array($p, ['activity_logs.php']) ? 'true' : 'false' ?>">
            <i class="fa-regular fa-file-lines menu-icon"></i> Logs & Reports
        </a>
        <div class="collapse submenu <?= in_array($p, ['activity_logs.php']) ? 'show' : '' ?>" id="logsMenu">
            <a href="activity_logs.php" class="<?= $p==='activity_logs.php' ? 'active' : '' ?>">Activity Logs</a>
            <a href="#">Session Logs</a>
        </div>
        
        <!-- MikroTik Menu -->
        <a href="#mikrotikMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= in_array($p, ['mikrotik_connect.php', 'mikrotik_sync.php']) ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= in_array($p, ['mikrotik_connect.php', 'mikrotik_sync.php']) ? 'true' : 'false' ?>">
            <i class="fa-solid fa-router menu-icon"></i> MikroTik
        </a>
        <div class="collapse submenu <?= in_array($p, ['mikrotik_connect.php', 'mikrotik_sync.php']) ? 'show' : '' ?>" id="mikrotikMenu">
            <a href="mikrotik_connect.php" class="<?= $p==='mikrotik_connect.php' ? 'active' : '' ?>">Connection Settings</a>
            <a href="mikrotik_sync.php" class="<?= $p==='mikrotik_sync.php' ? 'active' : '' ?>">Live Sync</a>
        </div>

        <!-- Tickets -->
        <a href="#ticketsMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isTicketMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTicketMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-receipt menu-icon"></i> Tickets
        </a>
        <div class="collapse submenu <?= $isTicketMenu ? 'show' : '' ?>" id="ticketsMenu">
            <a href="requests.php" class="<?= $p==='requests.php' ? 'active' : '' ?>">Renewal Requests</a>
            <a href="#">Support Tickets</a>
        </div>

        <a href="#" class="nav-link-main">
            <i class="fa-solid fa-bell menu-icon"></i> Notices
        </a>

        <a href="logout.php" class="nav-link-main mt-3">
            <i class="fa-solid fa-circle-xmark menu-icon"></i> Logout
        </a>

    </div>

    <!-- Top Navbar -->
    <div class="top-nav">
        <div>
            <span class="fs-5 fw-bold text-dark">Dashboard</span>
        </div>
        <div>
            <div class="input-group">
                <input type="text" class="form-control bg-light border-secondary text-dark" placeholder="Search...">
                <button class="btn btn-outline-secondary"><i class="fa-solid fa-search"></i></button>
            </div>
        </div>
    </div>

    <!-- Main Content wrapper -->
    <div class="main-content">
