<?php
require_once 'auth_check.php';
require_once '../config/db.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - SB Link Network</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; color: #1e293b; font-family: 'Inter', sans-serif; }
        
        /* Sidebar */
        .sidebar { background-color: #0f172a; min-height: 100vh; padding-top: 1rem; }
        .sidebar a { color: #94a3b8; text-decoration: none; padding: 0.75rem 1.5rem; display: block; border-radius: 0.375rem; margin: 0.25rem 1rem; transition: 0.2s; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background-color: #1e293b; color: #f8fafc; }
        .sidebar i.menu-icon { width: 25px; }
        
        /* Layout overrides */
        .topbar { background-color: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 1rem 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
        .card-header { border-bottom: 1px solid #e2e8f0; background-color: #f8fafc; font-weight: 600; padding: 1rem 1.5rem; }
        
        /* Tables */
        .table { color: #334155; }
        .table-dark { --bs-table-bg: #1e293b; --bs-table-border-color: #334155; }
    </style>
</head>
<body>

    <!-- Global Loading Overlay -->
    <div id="global-loader" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.7); z-index: 99999; align-items: center; justify-content: center; flex-direction: column; backdrop-filter: blur(5px);">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5 class="text-white mt-3 fw-bold">Processing, please wait...</h5>
        <div class="text-white-50 small">Do not close or refresh this page</div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                if(!form.classList.contains('no-loader') && form.checkValidity()) {
                    document.getElementById('global-loader').style.display = 'flex';
                    setTimeout(() => { document.getElementById('global-loader').style.display = 'none'; }, 15000); 
                }
            });
        });
    });
    function showGlobalLoader() { document.getElementById('global-loader').style.display = 'flex'; }
    function hideGlobalLoader() { document.getElementById('global-loader').style.display = 'none'; }
    </script>
<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar flex-shrink-0 shadow-sm" style="width: 250px;">
        <div class="px-4 mb-4 text-center">
            <h5 class="text-white fw-bold"><i class="fa-solid fa-network-wired text-primary"></i> SB Link</h5>
            <small class="text-muted">Super Admin</small>
        </div>
        <?php $page = basename($_SERVER['PHP_SELF']); ?>
        <a href="dashboard.php" class="<?= $page === 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-gauge-high menu-icon"></i> Dashboard</a>
        
        <div class="px-4 mt-3 mb-2 text-muted small fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 1px;">Tenant Management</div>
        <a href="operators.php" class="<?= strpos($page, 'operator') !== false ? 'active' : '' ?>"><i class="fa-solid fa-users-cog menu-icon"></i> Operators (ISPs)</a>
        <a href="routers.php" class="<?= strpos($page, 'router') !== false ? 'active' : '' ?>"><i class="fa-solid fa-server menu-icon"></i> NAS / Routers</a>
        <a href="global_users.php" class="<?= $page === 'global_users.php' ? 'active' : '' ?>"><i class="fa-solid fa-globe menu-icon"></i> Global Subscribers</a>
        
        <div class="px-4 mt-3 mb-2 text-muted small fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 1px;">System & Billing</div>
        <a href="billing.php" class="<?= $page === 'billing.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar menu-icon"></i> Billing & Subscriptions</a>
        <a href="logs.php" class="<?= $page === 'logs.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-shield menu-icon"></i> System Logs</a>
        <a href="settings.php" class="<?= $page === 'settings.php' ? 'active' : '' ?>"><i class="fa-solid fa-sliders menu-icon"></i> Global Settings</a>
        
        <hr class="border-secondary mx-3 mt-4">
        <a href="logout.php" class="text-danger"><i class="fa-solid fa-right-from-bracket menu-icon"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="flex-grow-1" style="background-color: #f1f5f9; color: #1e293b; min-height: 100vh;">
        <!-- Topbar -->
        <div class="topbar d-flex justify-content-between align-items-center shadow-sm" style="background-color: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 1rem 2rem;">
            <h4 class="mb-0 text-capitalize"><?= str_replace('.php', '', $page) ?></h4>
            <div>
                <span class="me-3"><i class="fa-regular fa-user-circle"></i> <?= htmlspecialchars($_SESSION['superadmin_name'] ?? 'Admin') ?></span>
            </div>
        </div>
        
        <div class="p-4">
