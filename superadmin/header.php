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
        body { background-color: #0f172a; color: #f8fafc; }
        .sidebar { background-color: #1e293b; min-height: 100vh; padding-top: 1rem; border-right: 1px solid #334155;}
        .sidebar a { color: #cbd5e1; text-decoration: none; padding: 0.75rem 1.5rem; display: block; border-radius: 0.375rem; margin: 0.25rem 1rem; }
        .sidebar a:hover, .sidebar a.active { background-color: #3b82f6; color: white; }
        .topbar { background-color: #1e293b; border-bottom: 1px solid #334155; padding: 1rem 2rem; }
        .card { background-color: #1e293b; border: 1px solid #334155; margin-bottom: 1rem; }
        .card-header { border-bottom: 1px solid #334155; background-color: rgba(0,0,0,0.1); }
        .table { color: #f8fafc; }
        .table-dark { --bs-table-bg: #1e293b; --bs-table-border-color: #334155; }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <div class="sidebar flex-shrink-0 shadow-sm" style="width: 250px;">
        <div class="px-4 mb-4 text-center">
            <h5 class="text-white fw-bold"><i class="fa-solid fa-network-wired text-primary"></i> SB Link</h5>
            <small class="text-muted">Super Admin</small>
        </div>
        <?php $page = basename($_SERVER['PHP_SELF']); ?>
        <a href="dashboard.php" class="<?= $page === 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-line me-2"></i> Dashboard</a>
        <a href="operators.php" class="<?= strpos($page, 'operator') !== false ? 'active' : '' ?>"><i class="fa-solid fa-users-cog me-2"></i> Operators</a>
        <a href="routers.php" class="<?= strpos($page, 'router') !== false ? 'active' : '' ?>"><i class="fa-solid fa-server me-2"></i> Routers</a>
        <a href="logs.php" class="<?= $page === 'logs.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-shield me-2"></i> System Logs</a>
        <hr class="border-secondary mx-3">
        <a href="logout.php" class="text-danger"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a>
    </div>

    <!-- Main Content -->
    <div class="flex-grow-1">
        <!-- Topbar -->
        <div class="topbar d-flex justify-content-between align-items-center shadow-sm">
            <h4 class="mb-0 text-capitalize"><?= str_replace('.php', '', $page) ?></h4>
            <div>
                <span class="me-3"><i class="fa-regular fa-user-circle"></i> <?= htmlspecialchars($_SESSION['superadmin_name'] ?? 'Admin') ?></span>
            </div>
        </div>
        
        <div class="p-4">
