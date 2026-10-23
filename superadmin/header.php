<?php
require_once 'auth_check.php';
require_once '../config/db.php';

$page = basename($_SERVER['PHP_SELF']);
$isSecurityAuth = !empty($_SESSION['security_authorized']) && (time() - ($_SESSION['security_auth_time'] ?? 0) < 1800);

// SuperAdmin display credentials
$superadminName = $_SESSION['superadmin_name'] ?? ($_SESSION['superadmin_username'] ?? 'Super Admin');
$superadminUsername = $_SESSION['superadmin_username'] ?? 'admin';
$avatarInitial = strtoupper(substr(trim($superadminUsername ?: $superadminName), 0, 1) ?: 'S');

// Open tickets count for notification badge
$open_tickets_super = 0;
try {
    $open_tickets_super = (int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'open'")->fetchColumn();
} catch (Exception $e) {}

// Menu Active States
$isOpMenu = strpos($page, 'operator') !== false || strpos($page, 'admin') !== false || in_array($page, ['global_users.php', 'global_users', 'admins', 'admin_add', 'admin_edit']);
$isRouterMenu = strpos($page, 'router') !== false || in_array($page, ['live_routers.php', 'live_routers']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - <?= BRAND_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            background-color: #f8fafc; 
            color: #1e293b; 
            overflow-x: hidden; 
        }
        
        /* --- UrAsool-Style White Curved Sidebar --- */
        :root {
            --sidebar-width: 270px;
            --sidebar-transition: 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #ffffff;
            border-top-right-radius: 36px;
            border-bottom-right-radius: 36px;
            box-shadow: 6px 0 28px rgba(15, 23, 42, 0.04);
            border-right: 1px solid #edf2f7;
            overflow: hidden;
            z-index: 1045;
            display: flex;
            flex-direction: column;
            transition: transform var(--sidebar-transition), width var(--sidebar-transition);
        }

        /* Top Logo Card */
        .sidebar-logo-card {
            background: #ffffff;
            border: 1.5px solid #edf2f7;
            border-radius: 14px;
            padding: 12px 14px;
            margin: 18px 16px 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            flex-shrink: 0;
        }

        .sidebar-logo-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1d9bf0, #2563eb);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
        }

        .sidebar-logo-details {
            min-width: 0;
            flex-grow: 1;
        }

        .sidebar-logo-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #1e3a8a;
            letter-spacing: -0.4px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-logo-sub {
            font-size: 0.68rem;
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
            padding: 4px 14px 14px 14px;
            scrollbar-width: thin;
            scrollbar-color: #94a3b8 #f8fafc;
        }

        .sidebar-menu-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-menu-scroll::-webkit-scrollbar-track {
            background: #f8fafc;
            border-radius: 10px;
        }
        .sidebar-menu-scroll::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 10px;
        }
        .sidebar-menu-scroll::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        /* Parent Nav Link Items */
        .nav-item-link {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            margin: 3px 0;
            border-radius: 12px;
            color: #1e293b;
            font-weight: 600;
            font-size: 0.92rem;
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
            font-size: 1.15rem;
            color: #1e293b;
            margin-right: 12px;
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
            background-color: #f1f3f6 !important;
            color: #0f172a !important;
            font-weight: 700;
        }

        .nav-item-link[aria-expanded="true"] .nav-chevron {
            transform: rotate(180deg);
            color: #0f172a;
        }

        /* Sub-Menu Tree Connector System (Iconic L-Branches) */
        .submenu-tree {
            position: relative;
            padding-left: 20px;
            margin: 2px 0 8px 16px;
            list-style: none;
        }

        /* Continuous vertical guide line */
        .submenu-tree::before {
            content: '';
            position: absolute;
            top: -2px;
            bottom: 14px;
            left: 8px;
            width: 2px;
            background-color: #cbd5e1;
        }

        .submenu-tree-item {
            position: relative;
            margin: 3px 0;
        }

        /* The curved L-branch connector */
        .submenu-tree-item::before {
            content: '';
            position: absolute;
            left: -12px;
            top: -6px;
            width: 14px;
            height: 22px;
            border-left: 2px solid #cbd5e1;
            border-bottom: 2px solid #cbd5e1;
            border-bottom-left-radius: 9px;
            pointer-events: none;
        }

        .submenu-tree-item a {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 12px 7px 16px;
            font-size: 0.88rem;
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .submenu-tree-item a:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }

        /* Active sub-item capsule */
        .submenu-tree-item a.active {
            background-color: #f1f5f9 !important;
            color: #1e3a8a !important;
            font-weight: 700 !important;
        }

        /* Bottom User Profile Strip */
        .sidebar-footer-user {
            padding: 14px 18px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: auto;
            border-bottom-right-radius: 36px;
            flex-shrink: 0;
        }

        .sidebar-footer-user .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #2563eb;
            color: #ffffff;
            font-weight: 800;
            font-size: 1.05rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 3px 8px rgba(37, 99, 235, 0.28);
        }

        .sidebar-footer-user .user-info {
            flex-grow: 1;
            min-width: 0;
        }

        .sidebar-footer-user .user-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer-user .user-role {
            font-size: 0.68rem;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 0.5px;
        }

        .sidebar-footer-user .logout-btn {
            color: #1e293b;
            font-size: 1.15rem;
            text-decoration: none;
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: color 0.18s, background-color 0.18s;
            flex-shrink: 0;
        }

        .sidebar-footer-user .logout-btn:hover {
            color: #dc2626;
            background: #fee2e2;
        }

        /* Page Layout Margins */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            width: calc(100% - var(--sidebar-width));
            flex-grow: 1;
            background-color: #f1f5f9;
            color: #1e293b;
            transition: margin-left var(--sidebar-transition), width var(--sidebar-transition);
        }

        .topbar {
            background-color: #ffffff;
            border-bottom: 1px solid #eef2f6;
            padding: 14px 28px;
            box-shadow: 0 1px 5px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Mobile Responsive Drawer */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }
            body.mobile-sidebar-open .sidebar {
                transform: translateX(0);
                box-shadow: 0 0 50px rgba(0,0,0,0.25);
            }
            .main-wrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* Desktop Toggle Collapse */
        body.sidebar-collapsed .sidebar {
            transform: translateX(-100%);
        }
        body.sidebar-collapsed .main-wrapper {
            margin-left: 0 !important;
            width: 100% !important;
        }

        /* Card & Table Polish */
        .card { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 1.5rem; }
        .card-header { border-bottom: 1px solid #e2e8f0; background-color: #ffffff; font-weight: 600; padding: 1.25rem 1.5rem; border-top-left-radius: 0.75rem; border-top-right-radius: 0.75rem; }
        .table { color: #334155; }
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
    <div class="sidebar" id="appSidebar">
        
        <!-- Top Logo Card (UrAsool Style) -->
        <div class="sidebar-logo-card">
            <div class="sidebar-logo-icon">
                <i class="fa-solid fa-crown"></i>
            </div>
            <div class="sidebar-logo-details">
                <div class="sidebar-logo-title" title="<?= BRAND_NAME ?>">
                    <?= BRAND_SHORT_NAME ?>
                </div>
                <div class="sidebar-logo-sub">SUPER ADMIN</div>
            </div>
        </div>

        <!-- Scrollable Menu Items -->
        <div class="sidebar-menu-scroll">
            
            <!-- 1. Dashboard -->
            <a href="dashboard" class="nav-item-link <?= in_array($page, ['dashboard.php', 'dashboard']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-desktop nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>

            <!-- 2. Admins (ISPs) -->
            <a href="#operatorsMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isOpMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isOpMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-users-gear nav-icon"></i>
                <span class="nav-text">Admins (ISPs)</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isOpMenu ? 'show' : '' ?>" id="operatorsMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item"><a href="admins" class="<?= in_array($page, ['operators.php', 'operators', 'operator_edit.php', 'admins.php', 'admins', 'admin_edit.php', 'admin_edit']) ? 'active' : '' ?>">All Admins</a></li>
                    <li class="submenu-tree-item"><a href="admin_add" class="<?= in_array($page, ['operator_add.php', 'operator_add', 'admin_add.php', 'admin_add']) ? 'active' : '' ?>">Add Admin</a></li>
                    <li class="submenu-tree-item"><a href="global_users" class="<?= in_array($page, ['global_users.php', 'global_users']) ? 'active' : '' ?>">Global Users</a></li>
                </ul>
            </div>

            <!-- 3. Global Routers & NAS -->
            <a href="#routersMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isRouterMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isRouterMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-server nav-icon"></i>
                <span class="nav-text">Routers &amp; NAS</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isRouterMenu ? 'show' : '' ?>" id="routersMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item"><a href="routers" class="<?= in_array($page, ['routers.php', 'routers']) ? 'active' : '' ?>">NAS / Routers List</a></li>
                    <li class="submenu-tree-item"><a href="router_add" class="<?= in_array($page, ['router_add.php', 'router_add']) ? 'active' : '' ?>">Add NAS Router</a></li>
                    <li class="submenu-tree-item"><a href="live_routers" class="<?= in_array($page, ['live_routers.php', 'live_routers']) ? 'active' : '' ?>">Live Routers &amp; Users</a></li>
                </ul>
            </div>

            <!-- 4. Master Packages -->
            <a href="packages_master" class="nav-item-link <?= in_array($page, ['packages_master.php', 'packages_master']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-box-open nav-icon"></i>
                <span class="nav-text">Master Packages</span>
            </a>

            <!-- 5. Items -->
            <a href="items" class="nav-item-link <?= in_array($page, ['items.php', 'items']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                <span class="nav-text">Items</span>
            </a>

            <!-- 6. Subscriptions -->
            <a href="billing" class="nav-item-link <?= in_array($page, ['billing.php', 'billing']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-file-invoice-dollar nav-icon"></i>
                <span class="nav-text">Subscriptions</span>
            </a>

            <!-- 7. Support Tickets -->
            <a href="support_admin" class="nav-item-link <?= strpos($page, 'support') !== false ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-headset nav-icon"></i>
                <span class="nav-text">Support Tickets</span>
                <?php if ($open_tickets_super > 0): ?>
                    <span class="badge bg-danger rounded-pill ms-auto me-1" style="font-size: 0.72rem;"><?= $open_tickets_super ?></span>
                <?php endif; ?>
            </a>

            <!-- 8. System Logs -->
            <a href="logs" class="nav-item-link <?= in_array($page, ['logs.php', 'logs']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-file-shield nav-icon"></i>
                <span class="nav-text">System Logs</span>
            </a>

            <!-- 9. Security Gate -->
            <?php if ($isSecurityAuth): ?>
                <a href="security" class="nav-item-link <?= in_array($page, ['security.php', 'security']) ? 'active-parent' : '' ?>">
                    <i class="fa-solid fa-shield-halved nav-icon"></i>
                    <span class="nav-text">Security</span>
                </a>
            <?php else: ?>
                <a href="javascript:void(0)" onclick="openSecurityPrompt()" class="nav-item-link <?= in_array($page, ['security.php', 'security']) ? 'active-parent' : '' ?>">
                    <i class="fa-solid fa-shield-halved nav-icon"></i>
                    <span class="nav-text">Security</span>
                    <i class="fa-solid fa-lock text-muted" style="font-size: 0.72rem; margin-left: auto;"></i>
                </a>
            <?php endif; ?>

            <!-- 10. Global Settings -->
            <a href="settings" class="nav-item-link <?= in_array($page, ['settings.php', 'settings']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-sliders nav-icon"></i>
                <span class="nav-text">Global Settings</span>
            </a>

        </div>

        <!-- Bottom User Profile Strip (UrAsool Design) -->
        <div class="sidebar-footer-user">
            <div class="user-avatar">
                <?= $avatarInitial ?>
            </div>
            <div class="user-info">
                <div class="user-name" title="<?= htmlspecialchars($superadminUsername) ?>">
                    <?= htmlspecialchars(strtoupper($superadminUsername)) ?>
                </div>
                <div class="user-role">SUPER ADMIN</div>
            </div>
            <a href="logout" class="logout-btn" title="Logout">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>

    </div>

    <!-- Security Gate Modal (MariaDB Password Verification) -->
    <div class="modal fade" id="securityGateModal" tabindex="-1" aria-labelledby="securityGateModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white py-3 px-4">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h6 class="modal-title fw-bold mb-0" id="securityGateModalLabel">Security Verification Required</h6>
                            <span class="text-white-50 small" style="font-size: 0.72rem;">MariaDB Password Protected</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <form onsubmit="handleSecurityGateSubmit(event)" id="formSecurityGate">
                    <div class="modal-body p-4">
                        <div class="text-center mb-3">
                            <div class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center shadow-sm mb-2" style="width: 56px; height: 56px;">
                                <i class="fa-solid fa-lock fs-3"></i>
                            </div>
                            <h6 class="fw-bold text-dark mb-1">Enter MariaDB Password</h6>
                            <p class="text-muted small mb-0">
                                <strong>Security &amp; Passwords</strong> section access karne ke liye live MariaDB database password enter karein.
                            </p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-dark fw-bold small mb-1">
                                MariaDB Password <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="password" id="gate_mariadb_password" class="form-control" placeholder="Enter MariaDB password" required autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('gate_mariadb_password'); p.type = p.type === 'password' ? 'text' : 'password';">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">
                                Usky baghair access nahi ho sakta.
                            </div>
                        </div>

                        <div id="gate_error_msg" class="alert alert-danger py-2 px-3 small rounded-3 d-none mb-0"></div>
                    </div>

                    <div class="modal-footer bg-light border-top border-light-subtle py-2 px-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="btn_gate_submit" class="btn btn-danger rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                            <i class="fa-solid fa-key me-1"></i> Verify &amp; Access
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openSecurityPrompt() {
        const errDiv = document.getElementById('gate_error_msg');
        const pwdInput = document.getElementById('gate_mariadb_password');
        if (errDiv) { errDiv.classList.add('d-none'); errDiv.innerText = ''; }
        if (pwdInput) { pwdInput.value = ''; }
        const modalEl = document.getElementById('securityGateModal');
        if (modalEl) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            setTimeout(() => { if (pwdInput) pwdInput.focus(); }, 350);
        }
    }

    function handleSecurityGateSubmit(e) {
        e.preventDefault();
        const pwdInput = document.getElementById('gate_mariadb_password');
        const btn = document.getElementById('btn_gate_submit');
        const errDiv = document.getElementById('gate_error_msg');

        if (!pwdInput || !pwdInput.value) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Verifying...';
        errDiv.classList.add('d-none');

        const formData = new FormData();
        formData.append('ajax_action', 'verify_security_access');
        formData.append('mariadb_password', pwdInput.value);

        fetch('security.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-key me-1"></i> Verify &amp; Access';
            if (data.success) {
                window.location.href = data.redirect || 'security';
            } else {
                errDiv.innerText = data.message || 'Incorrect MariaDB password. Access denied.';
                errDiv.classList.remove('d-none');
                pwdInput.focus();
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-key me-1"></i> Verify &amp; Access';
            errDiv.innerText = 'Network request failed: ' + err.message;
            errDiv.classList.remove('d-none');
        });
    }
    </script>

    <!-- Main Content Wrapper -->
    <div class="main-wrapper flex-grow-1">
        <!-- Topbar -->
        <div class="topbar">
            <div class="d-flex align-items-center gap-3">
                <button type="button" id="topNavSidebarToggle" class="btn btn-sm btn-light border rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width: 36px; height: 36px;" title="Toggle Sidebar">
                    <i class="fa-solid fa-bars-staggered"></i>
                </button>
                <div class="d-flex align-items-center gap-2">
                    <h4 class="mb-0 text-capitalize fw-bold text-dark" style="letter-spacing: -0.3px; font-size: 1.15rem;"><?= str_replace(['.php', '_'], ['', ' '], $page) ?></h4>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="badge border rounded-pill px-3 py-2 bg-primary-subtle text-primary border-primary-subtle fw-semibold d-flex align-items-center gap-2">
                    <i class="fa-solid fa-crown text-warning"></i>
                    <span><?= htmlspecialchars($superadminName) ?></span>
                </span>
            </div>
        </div>
        
        <div class="p-4">

<!-- Modern Sidebar Logic -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    var body = document.body;
    var topToggle = document.getElementById("topNavSidebarToggle");

    if (topToggle) {
        topToggle.addEventListener("click", function(e) {
            e.preventDefault();
            if (window.innerWidth <= 991) {
                body.classList.toggle("mobile-sidebar-open");
            } else {
                body.classList.toggle("sidebar-collapsed");
            }
        });
    }

    // Close mobile drawer when clicking outside
    document.addEventListener("click", function(e) {
        if (window.innerWidth <= 991 && body.classList.contains("mobile-sidebar-open")) {
            var sidebar = document.querySelector(".sidebar");
            if (sidebar && !sidebar.contains(e.target) && topToggle && !topToggle.contains(e.target)) {
                body.classList.remove("mobile-sidebar-open");
            }
        }
    });
});
</script>
