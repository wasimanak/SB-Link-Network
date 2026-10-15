<?php
session_start();
require_once '../config/db.php';

// Prune old activity logs (30 days)
try { $pdo->exec("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 30 DAY"); } catch (Exception $e) {}


// Auth Check
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: ../login.php");
    exit;
}

// Check if tenant is still active
$stmt = $pdo->prepare("SELECT company_name, status, expiry_date FROM clients WHERE id = ?");
$stmt->execute([$_SESSION['operator_id']]);
$tenant = $stmt->fetch();

if (!$tenant || $tenant['status'] !== 'active' || (strtotime($tenant['expiry_date']) < time())) {
    session_destroy();
    echo "<script>alert('Account suspended or expired. Contact Super Admin.'); window.location='../login.php';</script>";
    exit;
}

$p = basename($_SERVER['PHP_SELF']);
$filter = $_GET['filter'] ?? '';

// Menu Active States
$isUserMenu = in_array($p, ['subscribers.php', 'live_sessions.php']);
$isAccMenu = in_array($p, ['packages.php', 'fund_requests.php', 'invoices.php']);
$isTicketMenu = in_array($p, ['requests.php', 'fund_requests.php', 'support_tickets.php', 'view_ticket.php']);

// Fetch Pending Requests Count for Badges
$pending_pkgs = $pdo->query("SELECT COUNT(*) FROM package_requests WHERE client_id = {$_SESSION['operator_id']} AND status = 'pending'")->fetchColumn() ?: 0;
$pending_funds = $pdo->query("SELECT COUNT(*) FROM fund_requests WHERE client_id = {$_SESSION['operator_id']} AND status = 'pending'")->fetchColumn() ?: 0;
$open_tickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE client_id = {$_SESSION['operator_id']} AND status = 'open'")->fetchColumn() ?: 0;
$total_pending_requests = $pending_pkgs + $pending_funds + $open_tickets;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta charset="UTF-8">
    <title><?= BRAND_NAME ?> - Operator Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; color: #333; overflow-x: hidden; }
        
                /* --- Premium Modern Sidebar & Navigation --- */
        :root {
            --sidebar-width: 260px;
            --sidebar-mini-width: 75px;
            --sidebar-transition: 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #ffffff;
            border-right: 1px solid #eef2f6;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1045;
            transition: width var(--sidebar-transition), box-shadow var(--sidebar-transition), transform var(--sidebar-transition);
            box-shadow: 2px 0 12px rgba(15, 23, 42, 0.03);
            scrollbar-width: thin;
            scrollbar-color: #e2e8f0 transparent;
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }

        .sidebar a.nav-link-main {
            color: #475569;
            text-decoration: none;
            padding: 11px 16px;
            margin: 3px 12px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            font-size: 0.92rem;
            font-weight: 500;
            transition: all 0.18s ease;
            white-space: nowrap;
        }

        .sidebar a.nav-link-main:hover {
            background-color: #f8fafc;
            color: #0f172a;
        }

        .sidebar a.nav-link-main i.menu-icon {
            width: 26px;
            font-size: 1.15rem;
            color: #64748b;
            text-align: left;
            flex-shrink: 0;
            margin-right: 12px;
            transition: color 0.18s ease, transform 0.18s ease;
        }

        .sidebar a.nav-link-main:hover i.menu-icon {
            color: #2563eb;
            transform: scale(1.08);
        }

        /* Active Main Item */
        .sidebar a.active-parent {
            background-color: #eff6ff !important;
            color: #2563eb !important;
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(37,99,235,0.06);
        }

        .sidebar a.active-parent i.menu-icon {
            color: #2563eb !important;
        }

        /* Submenu Dropdown Arrow */
        .has-submenu::after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.2s ease;
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .has-submenu[aria-expanded="true"]::after {
            transform: rotate(180deg);
            color: #2563eb;
        }

        /* Submenu Styling */
        .submenu {
            background: transparent;
            margin: 2px 12px 6px 36px;
            padding-left: 0;
            border-left: 2px solid #f1f5f9;
        }

        .submenu a {
            display: block;
            color: #64748b;
            text-decoration: none;
            padding: 8px 14px 8px 16px;
            font-size: 0.88rem;
            font-weight: 500;
            border-radius: 8px;
            margin: 2px 0;
            transition: all 0.18s ease;
            white-space: nowrap;
        }

        .submenu a:hover {
            color: #0f172a;
            background-color: #f8fafc;
            padding-left: 20px;
        }

        .submenu a.active {
            color: #2563eb;
            font-weight: 600;
            background-color: #eff6ff;
        }

        /* Page Layout Margins */
        .main-content {
            padding: 30px;
            min-height: 100vh;
            transition: margin-left var(--sidebar-transition);
        }
        .top-nav {
            background-color: #ffffff;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eef2f6;
            box-shadow: 0 1px 5px rgba(0,0,0,0.02);
            transition: margin-left var(--sidebar-transition);
        }

        /* Pinned State (Full 260px) */
        body.sidebar-pinned .sidebar {
            width: var(--sidebar-width);
        }
        body.sidebar-pinned .main-content,
        body.sidebar-pinned .top-nav {
            margin-left: var(--sidebar-width);
        }

        /* Unpinned / Mini Mode (75px default, expands smoothly on hover) */
        body:not(.sidebar-pinned) .sidebar {
            width: var(--sidebar-mini-width);
        }
        body:not(.sidebar-pinned) .main-content,
        body:not(.sidebar-pinned) .top-nav {
            margin-left: var(--sidebar-mini-width);
        }

        /* When Mini and NOT hovered: hide labels cleanly, center icons */
        body:not(.sidebar-pinned) .sidebar:not(:hover) {
            overflow: hidden;
        }
        body:not(.sidebar-pinned) .sidebar:not(:hover) .menu-text,
        body:not(.sidebar-pinned) .sidebar:not(:hover) .brand-text,
        body:not(.sidebar-pinned) .sidebar:not(:hover) .has-submenu::after,
        body:not(.sidebar-pinned) .sidebar:not(:hover) .badge,
        body:not(.sidebar-pinned) .sidebar:not(:hover) .pin-toggle-btn,
        body:not(.sidebar-pinned) .sidebar:not(:hover) .submenu,
        body:not(.sidebar-pinned) .sidebar:not(:hover) .menu-section-heading {
            display: none !important;
        }
        body:not(.sidebar-pinned) .sidebar:not(:hover) .nav-link-main {
            padding: 12px 0;
            margin: 4px 10px;
            justify-content: center;
        }
        body:not(.sidebar-pinned) .sidebar:not(:hover) .nav-link-main i.menu-icon {
            margin: 0;
            width: auto;
            font-size: 1.25rem;
            text-align: center;
        }

        /* Floating Smooth Hover Expansion in Unpinned Mode */
        body:not(.sidebar-pinned) .sidebar:hover {
            width: var(--sidebar-width) !important;
            box-shadow: 12px 0 35px rgba(15, 23, 42, 0.12), 0 0 1px rgba(0,0,0,0.08);
        }

        /* Mobile Responsive */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width) !important;
            }
            body.mobile-sidebar-open .sidebar {
                transform: translateX(0);
                box-shadow: 0 0 50px rgba(0,0,0,0.25);
            }
            .main-content, .top-nav {
                margin-left: 0 !important;
            }
        }
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

    <!-- Sidebar -->
    <div class="sidebar" id="appSidebar">
        
        <!-- Brand / Logo Area -->
        <div class="d-flex align-items-center px-3 pt-3 pb-3 border-bottom mb-2">
            <div class="bg-primary text-white rounded-3 d-flex align-items-center justify-content-center me-3 shadow-sm flex-shrink-0" style="width: 42px; height: 42px; background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;">
                <i class="fa-solid fa-bolt fs-5"></i>
            </div>
            <div class="brand-text flex-grow-1 overflow-hidden" style="min-width: 0;">
                <h6 class="fw-bold mb-0 text-dark text-truncate" style="letter-spacing: -0.3px; font-size: 0.95rem;"><?= htmlspecialchars($tenant['company_name']) ?></h6>
                <div class="text-muted" style="font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px;">Operator Panel</div>
            </div>
            <button type="button" id="sidebarPinToggle" class="btn btn-sm btn-light border-0 text-secondary rounded-circle p-0 pin-toggle-btn" title="Pin / Unpin Sidebar" style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: transform 0.2s;">
                <i class="fa-solid fa-thumbtack" style="font-size: 0.75rem; transform: rotate(-45deg);"></i>
            </button>
        </div>

        <div class="px-3">
            <div class="text-muted fw-bold mb-2 px-3 menu-section-heading" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px;">Main Menu</div>
            <a href="dashboard.php" class="nav-link-main rounded-3 mb-1 <?= $p==='dashboard.php' ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-house menu-icon"></i> <span class="menu-text">Home</span>
            </a>

            <a href="profile.php" class="nav-link-main rounded-3 mb-1 <?= $p==='profile.php' ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-user menu-icon"></i> <span class="menu-text">My Profile</span>
            </a>

        <!-- Team -->
        <a href="#teamMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isTeamMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTeamMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-sitemap menu-icon"></i> <span class="menu-text">Team</span>
        </a>
        <div class="collapse submenu <?= $isTeamMenu ? 'show' : '' ?>" id="teamMenu">
            <a href="dealers.php" class="<?= $p==='dealers.php' || $p==='dealer_view.php' ? 'active' : '' ?>">Dealer</a>
            <a href="recovery_man.php" class="<?= $p==='recovery_man.php' ? 'active' : '' ?>">Recovery Man</a>
            <a href="line_man.php" class="<?= $p==='line_man.php' ? 'active' : '' ?>">Line Man</a>
        </div>

        <!-- User -->
        <a href="#userMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isUserMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isUserMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-user menu-icon"></i> <span class="menu-text">User</span>
        </a>
        <div class="collapse submenu <?= $isUserMenu ? 'show' : '' ?>" id="userMenu">
            <a href="subscribers.php" class="<?= ($p==='subscribers.php' && $filter==='') ? 'active' : '' ?>">All Users</a>
            <a href="live_sessions.php" class="<?= ($p==='live_sessions.php' && $filter!=='expired_online') ? 'active' : '' ?>">Online Users</a>
            <a href="live_sessions.php?filter=expired_online" class="<?= $filter==='expired_online' ? 'active' : '' ?>">Expired Online</a>
            <a href="subscribers.php?filter=offline" class="<?= $filter==='offline' ? 'active' : '' ?>">Offline Users (Radius)</a>
            <a href="subscribers.php?filter=expired" class="<?= $filter==='expired' ? 'active' : '' ?>">Expired Users</a>
            <a href="subscribers.php?filter=expiring_1w" class="<?= $filter==='expiring_1w' ? 'active' : '' ?>">Expiring In Week</a>
            <a href="subscribers.php?filter=expiring_2w" class="<?= $filter==='expiring_2w' ? 'active' : '' ?>">Expiring In 2 Weeks</a>
        </div>

        <!-- Accounting -->
        <a href="#accountingMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isAccMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isAccMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-dollar-sign menu-icon"></i> <span class="menu-text">Accounting</span>
        </a>
        <div class="collapse submenu <?= $isAccMenu ? 'show' : '' ?>" id="accountingMenu">
            
            <a href="invoices.php" class="<?= $p==='invoices.php' ? 'active' : '' ?>">Invoices / Receipts</a>
            <a href="fund_requests.php" class="<?= $p==='fund_requests.php' ? 'active' : '' ?>">Payments / Fund Requests</a>
        </div>

        <!-- Logs & Reports -->
        <a href="#logsMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= in_array($p, ['activity_logs.php', 'package_logs.php', 'session_logs.php']) ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= in_array($p, ['activity_logs.php', 'package_logs.php', 'session_logs.php']) ? 'true' : 'false' ?>">
            <i class="fa-regular fa-file-lines menu-icon"></i> <span class="menu-text">Logs & Reports</span>
        </a>
        <div class="collapse submenu <?= in_array($p, ['activity_logs.php', 'package_logs.php', 'session_logs.php']) ? 'show' : '' ?>" id="logsMenu">
            <a href="activity_logs.php" class="<?= $p==='activity_logs.php' ? 'active' : '' ?>">Activity Logs</a>
            <a href="package_logs.php" class="<?= $p==='package_logs.php' ? 'active' : '' ?>">Package Logs</a>
            <a href="session_logs.php" class="<?= $p==='session_logs.php' ? 'active' : '' ?>">Session Logs</a>
        </div>
        
        <!-- MikroTik Menu -->
        <!-- Network & Multi-WAN Pools -->
        <a href="ip_pools.php" class="nav-link-main rounded-3 mb-1 <?= $p==='ip_pools.php' ? 'active-parent' : '' ?>">
            <i class="fa-solid fa-network-wired menu-icon"></i> <span class="menu-text">IP Pools (Multi-WAN)</span>
        </a>
        <!-- Payment Gateways -->
        
        <!-- Requests & Support -->
        <a href="#ticketMenu" data-bs-toggle="collapse" class="nav-link-main has-submenu <?= $isTicketMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTicketMenu ? 'true' : 'false' ?>">
            <i class="fa-solid fa-headset menu-icon"></i> <span class="menu-text">Support & Requests</span>
            <?php if($total_pending_requests > 0): ?>
                <span class="badge bg-danger ms-auto rounded-pill"><?= $total_pending_requests ?></span>
            <?php endif; ?>
        </a>
        <div class="collapse submenu <?= $isTicketMenu ? 'show' : '' ?>" id="ticketMenu">
            <a href="requests.php" class="<?= $p==='requests.php' ? 'active' : '' ?>">Package Requests
                <?php if($pending_pkgs > 0): ?><span class="badge bg-danger float-end rounded-pill"><?= $pending_pkgs ?></span><?php endif; ?>
            </a>
            <a href="fund_requests.php" class="<?= $p==='fund_requests.php' ? 'active' : '' ?>">Fund Requests
                <?php if($pending_funds > 0): ?><span class="badge bg-danger float-end rounded-pill"><?= $pending_funds ?></span><?php endif; ?>
            </a>
            <a href="support_tickets.php" class="<?= $p==='support_tickets.php' || $p==='view_ticket.php' ? 'active' : '' ?>">Support Tickets
                <?php if($open_tickets > 0): ?><span class="badge bg-danger float-end rounded-pill"><?= $open_tickets ?></span><?php endif; ?>
            </a>
        </div>
        <a href="payment_gateways.php" class="nav-link-main <?= $p==='payment_gateways.php' ? 'active-parent' : '' ?>">
            <i class="fa-brands fa-cc-stripe menu-icon"></i> <span class="menu-text">Payment Gateways</span>
        </a>

        <a href="#" class="nav-link-main">
            <i class="fa-solid fa-bell menu-icon"></i> <span class="menu-text">Notices</span>
        </a>

        <hr class="text-muted opacity-25 mx-3 my-3">

        <a href="logout.php" class="nav-link-main text-danger rounded-3 mb-4 <?= $p==='logout.php' ? 'active-parent' : '' ?>">
            <i class="fa-solid fa-arrow-right-from-bracket menu-icon text-danger"></i> <span class="menu-text">Logout</span>
        </a>
        </div>
    </div>

    <!-- Top Navbar -->
    <div class="top-nav">
        <div class="d-flex align-items-center gap-3">
            <button type="button" id="topNavSidebarToggle" class="btn btn-sm btn-light border rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width: 36px; height: 36px;" title="Toggle Sidebar">
                <i class="fa-solid fa-bars-staggered"></i>
            </button>
            <a href="dashboard.php" class="text-decoration-none d-flex align-items-center gap-2">
                <div class="bg-primary text-white d-flex align-items-center justify-content-center rounded shadow-sm" style="width: 38px; height: 38px; background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fa-solid fa-wifi"></i>
                </div>
                <span class="fs-4 fw-bold text-dark" style="letter-spacing: -0.5px;"><?= BRAND_SHORT_NAME ?> <span class="text-primary"><?= htmlspecialchars($tenant['company_name']) ?></span></span>
            </a>
        </div>
        <div class="position-relative d-none d-md-block" style="width: 350px;">
            <div class="input-group" style="box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-radius: 20px;">
                <span class="input-group-text bg-white border-end-0 text-muted px-3" style="border-radius: 20px 0 0 20px; border-color: #e5e7eb;">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="text" id="omniSearch" class="form-control border-start-0 ps-0 shadow-none" placeholder="Search users, packages, tools..." style="border-radius: 0 20px 20px 0; background: #fff; border-color: #e5e7eb; font-size: 0.95rem;">
                <!-- Loading Spinner -->
                <span id="omniLoader" class="position-absolute end-0 top-50 translate-middle-y me-3 d-none" style="z-index: 5;">
                    <i class="fa-solid fa-circle-notch fa-spin text-primary"></i>
                </span>
            </div>
            
            <!-- Dropdown Results -->
            <div id="omniResults" class="position-absolute w-100 bg-white rounded shadow-lg mt-2 d-none" style="z-index: 1050; max-height: 400px; overflow-y: auto; border: 1px solid #f1f5f9; overflow-x: hidden;">
                <div class="list-group list-group-flush" id="omniResultsList"></div>
            </div>
        </div>
    </div>

    <!-- Omni Search Script -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        let debounceTimer;
        const searchInput = document.getElementById('omniSearch');
        const searchResults = document.getElementById('omniResults');
        const resultsList = document.getElementById('omniResultsList');
        const loader = document.getElementById('omniLoader');
        
        if (!searchInput) return;

        searchInput.addEventListener('input', function() {
            let q = this.value.trim();
            
            if (q.length < 2) {
                searchResults.classList.add('d-none');
                return;
            }
            
            clearTimeout(debounceTimer);
            loader.classList.remove('d-none');
            
            debounceTimer = setTimeout(function() {
                fetch('api_search.php?q=' + encodeURIComponent(q))
                    .then(response => response.json())
                    .then(data => {
                        resultsList.innerHTML = '';
                        
                        if (data.length === 0) {
                            resultsList.innerHTML = `<div class="p-3 text-center text-muted small"><i class="fa-solid fa-box-open mb-2 fs-4"></i><br>No results found for "<b>${q}</b>"</div>`;
                        } else {
                            let currentType = '';
                            
                            data.forEach(item => {
                                if (item.type !== currentType) {
                                    currentType = item.type;
                                    resultsList.innerHTML += `<div class="bg-light px-3 py-1 text-muted text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">${currentType}</div>`;
                                }
                                
                                resultsList.innerHTML += `
                                    <a href="${item.url}" class="list-group-item list-group-item-action border-0 px-3 py-2 d-flex align-items-center gap-3" style="transition: 0.2s;">
                                        <div class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                            <i class="fa-solid ${item.icon}"></i>
                                        </div>
                                        <div class="fw-medium text-dark" style="font-size: 0.9rem;">${item.title}</div>
                                    </a>
                                `;
                            });
                        }
                        
                        searchResults.classList.remove('d-none');
                        loader.classList.add('d-none');
                    })
                    .catch(() => {
                        loader.classList.add('d-none');
                    });
            }, 300);
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.position-relative')) {
                searchResults.classList.add('d-none');
            }
        });
    });
    </script>

    <!-- Main Content wrapper -->
    <div class="main-content">

<!-- Modern Sidebar Logic -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    var isPinned = localStorage.getItem("operator_sidebar_pinned") === "true";
    var body = document.body;
    var pinBtn = document.getElementById("sidebarPinToggle");
    var topToggle = document.getElementById("topNavSidebarToggle");

    function updatePinState(pinned) {
        if (pinned) {
            body.classList.add("sidebar-pinned");
            if (pinBtn) {
                pinBtn.classList.add("text-primary");
                pinBtn.classList.remove("text-secondary");
                var pinIcon = pinBtn.querySelector("i");
                if (pinIcon) pinIcon.style.transform = "rotate(0deg)";
                pinBtn.title = "Unpin Sidebar (Enable Auto-hide on hover)";
            }
        } else {
            body.classList.remove("sidebar-pinned");
            if (pinBtn) {
                pinBtn.classList.remove("text-primary");
                pinBtn.classList.add("text-secondary");
                var pinIcon = pinBtn.querySelector("i");
                if (pinIcon) pinIcon.style.transform = "rotate(-45deg)";
                pinBtn.title = "Pin Sidebar (Keep permanently open)";
            }
        }
        localStorage.setItem("operator_sidebar_pinned", pinned);
    }

    // Apply saved pin state
    updatePinState(isPinned);

    if (pinBtn) {
        pinBtn.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            isPinned = !body.classList.contains("sidebar-pinned");
            updatePinState(isPinned);
        });
    }

    if (topToggle) {
        topToggle.addEventListener("click", function(e) {
            e.preventDefault();
            if (window.innerWidth <= 991) {
                body.classList.toggle("mobile-sidebar-open");
            } else {
                isPinned = !body.classList.contains("sidebar-pinned");
                updatePinState(isPinned);
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
