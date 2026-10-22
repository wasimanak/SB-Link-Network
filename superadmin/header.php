<?php
require_once 'auth_check.php';
require_once '../config/db.php';
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
        body { background-color: #f4f6f9; color: #333; font-family: 'Inter', sans-serif; overflow-x: hidden; }
        
        /* Sidebar - Light Theme */
        .sidebar { background-color: #ffffff; min-height: 100vh; padding-top: 1rem; border-right: 1px solid #e5e7eb; box-shadow: 2px 0 10px rgba(0,0,0,0.02); }
        .sidebar a { color: #4b5563; text-decoration: none; padding: 0.75rem 1.5rem; display: block; border-radius: 0.375rem; margin: 0.25rem 1rem; transition: 0.2s; font-size: 0.95rem; font-weight: 500; }
        .sidebar a:hover { background-color: #f8fafc; color: #1e293b; }
        .sidebar a.active { background-color: #f1f5f9; color: #0f172a; border-left: 4px solid #3b82f6; padding-left: calc(1.5rem - 4px); }
        .sidebar i.menu-icon { width: 30px; color: #64748b; }
        .sidebar a.active i.menu-icon { color: #3b82f6; }
        
        /* Layout overrides */
        .topbar { background-color: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 1rem 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .card { background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 1.5rem; }
        .card-header { border-bottom: 1px solid #e2e8f0; background-color: #ffffff; font-weight: 600; padding: 1.25rem 1.5rem; border-top-left-radius: 0.75rem; border-top-right-radius: 0.75rem; }
        
        /* Tables */
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
    <div class="sidebar flex-shrink-0" style="width: 260px;">
        
        <!-- Brand / Logo Area -->
        <div class="d-flex align-items-center px-4 pt-3 pb-4 mb-2">
            <div class="bg-primary text-white rounded d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 40px; height: 40px;">
                <i class="fa-solid fa-crown fs-5 text-white"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;"><?= BRAND_SHORT_NAME ?></h5>
                <div class="text-muted" style="font-size: 0.70rem; font-weight: 500; text-transform: uppercase; letter-spacing: 1px;">Super Admin</div>
            </div>
        </div>

        <?php 
        $page = basename($_SERVER['PHP_SELF']); 
        $isSecurityAuth = !empty($_SESSION['security_authorized']) && (time() - ($_SESSION['security_auth_time'] ?? 0) < 1800);
        ?>
        <a href="dashboard" class="<?= in_array($page, ['dashboard.php', 'dashboard']) ? 'active' : '' ?>"><i class="fa-solid fa-gauge-high menu-icon"></i> Dashboard</a>
        
        <div class="px-4 mt-4 mb-2 text-muted fw-bold" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px;">Tenant Management</div>
        <a href="operators" class="<?= strpos($page, 'operator') !== false ? 'active' : '' ?>"><i class="fa-solid fa-users-cog menu-icon"></i> Operators (ISPs)</a>
        <a href="routers" class="<?= strpos($page, 'router') !== false ? 'active' : '' ?>"><i class="fa-solid fa-server menu-icon"></i> Global NAS/Routers</a>
        <a href="live_routers" class="<?= in_array($page, ['live_routers.php', 'live_routers']) ? 'active' : '' ?>"><i class="fa-solid fa-network-wired menu-icon"></i> Live Routers & Users</a>
        <a href="packages_master" class="<?= in_array($page, ['packages_master.php', 'packages_master']) ? 'active' : '' ?>"><i class="fa-solid fa-box-open menu-icon"></i> Master Packages</a>
        <a href="items" class="<?= in_array($page, ['items.php', 'items']) ? 'active' : '' ?>"><i class="fa-solid fa-boxes-stacked menu-icon"></i> Items</a>
        
        <div class="px-4 mt-4 mb-2 text-muted fw-bold" style="font-size: 0.65rem; text-transform: uppercase; letter-spacing: 1px;">System & Finance</div>
        <a href="billing" class="<?= in_array($page, ['billing.php', 'billing']) ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar menu-icon"></i> Subscriptions</a>
        <a href="support_admin" class="<?= strpos($page, 'support') !== false ? 'active' : '' ?>"><i class="fa-solid fa-headset menu-icon"></i> Support Tickets</a>
        <a href="logs" class="<?= in_array($page, ['logs.php', 'logs']) ? 'active' : '' ?>"><i class="fa-solid fa-file-shield menu-icon"></i> System Logs</a>
        <?php if ($isSecurityAuth): ?>
            <a href="security" class="<?= in_array($page, ['security.php', 'security']) ? 'active' : '' ?>"><i class="fa-solid fa-shield-halved menu-icon"></i> Security</a>
        <?php else: ?>
            <a href="javascript:void(0)" onclick="openSecurityPrompt()" class="<?= in_array($page, ['security.php', 'security']) ? 'active' : '' ?> d-flex align-items-center justify-content-between">
                <span><i class="fa-solid fa-shield-halved menu-icon"></i> Security</span>
                <i class="fa-solid fa-lock text-muted" style="font-size: 0.72rem;"></i>
            </a>
        <?php endif; ?>
        <a href="settings" class="<?= in_array($page, ['settings.php', 'settings']) ? 'active' : '' ?>"><i class="fa-solid fa-sliders menu-icon"></i> Global Settings</a>
        
        <hr class="text-muted opacity-25 mx-3 mt-4 mb-3">
        <a href="logout" class="text-danger mb-4"><i class="fa-solid fa-arrow-right-from-bracket menu-icon text-danger"></i> Logout</a>
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
