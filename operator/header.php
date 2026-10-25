<?php
session_start();
require_once '../config/db.php';
require_once '../config/manager_helper.php';

// Ensure Manager schema & triggers are updated
ensure_manager_schema($pdo);

// Retain login activities for 7 days, and all other audit/financial logs for 2 Years (730 days)
try { 
    $pdo->exec("DELETE FROM activity_logs WHERE (activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') AND created_at < NOW() - INTERVAL 7 DAY");
    $pdo->exec("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 730 DAY"); 
} catch (Exception $e) {}

// Auto-sync subscriber status with expiry_date across the whole system
try {
    // 1. Any user whose expiry is in future (> NOW) but status is 'expired' -> sync to 'active'
    $pdo->exec("UPDATE subscribers SET status = 'active' WHERE expiry_date > NOW() AND status = 'expired'");
    // 2. Any user whose expiry has passed (<= NOW) but status is 'active' -> sync to 'expired'
    $pdo->exec("UPDATE subscribers SET status = 'expired' WHERE expiry_date <= NOW() AND status = 'active'");
    // 3. Remove stray Auth-Type := Reject for active subscribers with future expiry
    $pdo->exec("DELETE rc FROM radcheck rc 
                JOIN subscribers s ON rc.username = s.username COLLATE utf8mb4_general_ci 
                WHERE s.expiry_date > NOW() AND s.status = 'active' AND rc.attribute = 'Auth-Type' AND rc.value = 'Reject'");
} catch (Exception $e) {}


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

// Manager Session & Realtime Permission Validation
if (!empty($_SESSION['is_manager']) && !empty($_SESSION['manager_id'])) {
    $m_stmt = $pdo->prepare("SELECT status, full_name, username, can_manage_packages, can_collect_recovery FROM managers WHERE id = ? AND client_id = ?");
    $m_stmt->execute([$_SESSION['manager_id'], $_SESSION['operator_id']]);
    $current_mgr = $m_stmt->fetch();

    if (!$current_mgr || $current_mgr['status'] !== 'active') {
        session_destroy();
        echo "<script>alert('Manager account is inactive or disabled.'); window.location='../login.php';</script>";
        exit;
    }

    // Keep dynamic permissions in sync with database in real-time
    $_SESSION['manager_name'] = $current_mgr['full_name'];
    $_SESSION['manager_username'] = $current_mgr['username'];
    $_SESSION['can_manage_packages'] = ($current_mgr['can_manage_packages'] === 'yes');
    $_SESSION['can_collect_recovery'] = ($current_mgr['can_collect_recovery'] === 'yes');

    // Stamp MySQL session for automatic activity log capture
    try {
        $pdo->query("SET @current_manager_id = " . (int)$_SESSION['manager_id']);
        $pdo->query("SET @current_manager_name = " . $pdo->quote($current_mgr['full_name']));
    } catch (Exception $e) {}
} else {
    try {
        $pdo->query("SET @current_manager_id = NULL");
        $pdo->query("SET @current_manager_name = NULL");
    } catch (Exception $e) {}
}

$p = basename($_SERVER['PHP_SELF']);
$filter = $_GET['filter'] ?? '';

// Menu Active States
$isUserMenu = in_array($p, ['subscribers.php', 'subscribers', 'live_sessions.php', 'live_sessions', 'subscriber_add.php', 'subscriber_edit.php', 'subscriber_view.php']);
$isTeamMenu = in_array($p, ['recovery_man.php', 'recovery_man', 'recoveryman_profile.php', 'recoveryman_profile', 'line_man.php', 'line_man', 'lineman_profile.php', 'lineman_profile', 'managers.php', 'managers', 'manager_view.php']);
$isDealerMenu = in_array($p, ['dealers.php', 'dealers', 'dealer_view.php', 'dealer_view']);
$isAccMenu = in_array($p, ['invoices.php', 'invoices', 'expenses.php', 'expenses', 'net_worth.php', 'net_worth', 'fund_requests.php', 'fund_requests']);
$isTicketMenu = in_array($p, ['support_tickets.php', 'support_tickets', 'view_ticket.php', 'view_ticket', 'requests.php', 'requests']);
$isRechargeMenu = in_array($p, ['packages.php', 'packages', 'payment_gateways.php', 'payment_gateways']);
$isLogsMenu = in_array($p, ['activity_logs.php', 'activity_logs', 'package_logs.php', 'package_logs', 'session_logs.php', 'session_logs', 'ip_pools.php', 'ip_pools']);

// Fetch Pending Requests Count for Badges
$pending_pkgs = $pdo->query("SELECT COUNT(*) FROM package_requests WHERE client_id = {$_SESSION['operator_id']} AND status = 'pending'")->fetchColumn() ?: 0;
$pending_funds = $pdo->query("SELECT COUNT(*) FROM fund_requests WHERE client_id = {$_SESSION['operator_id']} AND status = 'pending'")->fetchColumn() ?: 0;
$open_tickets = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE client_id = {$_SESSION['operator_id']} AND status = 'open'")->fetchColumn() ?: 0;
$total_pending_requests = $pending_pkgs + $pending_funds + $open_tickets;

// Display name and avatar for bottom profile strip (UrAsool Design)
$displayName = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] : (!empty($_SESSION['operator_name']) ? $_SESSION['operator_name'] : ($tenant['company_name'] ?? 'Admin'));
$avatarInitial = strtoupper(substr(trim($displayName), 0, 1) ?: 'A');
$userRoleLabel = !empty($_SESSION['is_manager']) ? 'MANAGER' : 'ADMIN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta charset="UTF-8">
    <title><?= BRAND_NAME ?> - Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            font-size: 1.25rem;
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

        /* Active / Expanded State (Like "Area" in screenshot) */
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

        /* Sub-Menu Tree Connector System (Iconic L-Branches from Screenshot) */
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

        /* Active sub-item capsule (Like "Country" in screenshot) */
        .submenu-tree-item a.active {
            background-color: #f1f5f9 !important;
            color: #1e3a8a !important;
            font-weight: 700 !important;
        }

        /* Bottom User Profile Strip (ADMINCHECK1 from Screenshot) */
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
            overflow: hidden;
        }

        .sidebar-footer-user .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        .main-content {
            padding: 30px;
            min-height: 100vh;
            margin-left: var(--sidebar-width);
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
            margin-left: var(--sidebar-width);
            transition: margin-left var(--sidebar-transition);
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
            .main-content, .top-nav {
                margin-left: 0 !important;
            }
        }

        /* Desktop Toggle Collapse */
        body.sidebar-collapsed .sidebar {
            transform: translateX(-100%);
        }
        body.sidebar-collapsed .main-content,
        body.sidebar-collapsed .top-nav {
            margin-left: 0 !important;
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
        
        <!-- Top Logo Card (UrAsool Style) -->
        <div class="sidebar-logo-card">
            <div class="sidebar-logo-icon">
                <i class="fa-solid fa-wifi"></i>
            </div>
            <div class="sidebar-logo-details">
                <div class="sidebar-logo-title" title="<?= htmlspecialchars($tenant['company_name']) ?>">
                    <?= htmlspecialchars($tenant['company_name']) ?>
                </div>
                <div class="sidebar-logo-sub"><?= BRAND_SHORT_NAME ?> ADMIN</div>
            </div>
        </div>

        <!-- Scrollable Menu Items -->
        <div class="sidebar-menu-scroll">
            
            <!-- 1. Dashboard -->
            <a href="dashboard" class="nav-item-link <?= in_array($p, ['dashboard.php', 'dashboard']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-desktop nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>

            <!-- 2. Company Profile -->
            <a href="profile" class="nav-item-link <?= in_array($p, ['profile.php', 'profile']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-building nav-icon"></i>
                <span class="nav-text">Company Profile</span>
            </a>

            <!-- 3. Users Profile -->
            <a href="#usersMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isUserMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isUserMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-users nav-icon"></i>
                <span class="nav-text">Users Profile</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isUserMenu ? 'show' : '' ?>" id="usersMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item"><a href="subscribers" class="<?= ($p==='subscribers.php' && $filter==='') ? 'active' : '' ?>">All Users</a></li>
                    <li class="submenu-tree-item"><a href="live_sessions" class="<?= ($p==='live_sessions.php' && $filter!=='expired_online') ? 'active' : '' ?>">Online Users</a></li>
                    <li class="submenu-tree-item"><a href="live_sessions?filter=expired_online" class="<?= $filter==='expired_online' ? 'active' : '' ?>">Expired Online</a></li>
                    <li class="submenu-tree-item"><a href="subscribers?filter=offline" class="<?= $filter==='offline' ? 'active' : '' ?>">Offline Users (Radius)</a></li>
                    <li class="submenu-tree-item"><a href="subscribers?filter=expired" class="<?= $filter==='expired' ? 'active' : '' ?>">Expired Users</a></li>
                    <li class="submenu-tree-item"><a href="subscribers?filter=expiring_1w" class="<?= $filter==='expiring_1w' ? 'active' : '' ?>">Expiring In Week</a></li>
                    <li class="submenu-tree-item"><a href="subscribers?filter=expiring_2w" class="<?= $filter==='expiring_2w' ? 'active' : '' ?>">Expiring In 2 Weeks</a></li>
                </ul>
            </div>

            <!-- 4. Dealers Profile -->
            <a href="dealers" class="nav-item-link <?= in_array($p, ['dealers.php', 'dealers', 'dealer_view.php', 'dealer_view']) ? 'active-parent' : '' ?>">
                <i class="fa-solid fa-user-tie nav-icon"></i>
                <span class="nav-text">Dealers Profile</span>
            </a>

            <!-- 5. Operators & Staff -->
            <a href="#recoveryMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isTeamMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTeamMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-user-shield nav-icon"></i>
                <span class="nav-text">Operators</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isTeamMenu ? 'show' : '' ?>" id="recoveryMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item"><a href="recovery_man" class="<?= in_array($p, ['recovery_man.php', 'recovery_man', 'recoveryman_profile.php', 'recoveryman_profile']) ? 'active' : '' ?>">Operators</a></li>
                    <li class="submenu-tree-item"><a href="line_man" class="<?= in_array($p, ['line_man.php', 'line_man', 'lineman_profile.php', 'lineman_profile']) ? 'active' : '' ?>">Line Man (Staff)</a></li>
                    <?php if (empty($_SESSION['is_manager'])): ?>
                    <li class="submenu-tree-item"><a href="managers" class="<?= in_array($p, ['managers.php', 'managers', 'manager_view.php', 'manager_view']) ? 'active' : '' ?>">Managers</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- 6. Transactions -->
            <a href="#transMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isAccMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isAccMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-money-bill-transfer nav-icon"></i>
                <span class="nav-text">Transactions</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isAccMenu ? 'show' : '' ?>" id="transMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item"><a href="invoices" class="<?= in_array($p, ['invoices.php', 'invoices']) ? 'active' : '' ?>">Invoices / Receipts</a></li>
                    <li class="submenu-tree-item"><a href="fund_requests" class="<?= in_array($p, ['fund_requests.php', 'fund_requests']) ? 'active' : '' ?>">Payments / Fund Requests</a></li>
                    <li class="submenu-tree-item"><a href="expenses" class="<?= in_array($p, ['expenses.php', 'expenses']) ? 'active' : '' ?>">Daily Expenses</a></li>
                    <li class="submenu-tree-item"><a href="net_worth" class="<?= in_array($p, ['net_worth.php', 'net_worth']) ? 'active' : '' ?>">Business Net Worth</a></li>
                </ul>
            </div>

            <!-- 7. Complain -->
            <a href="#complainMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isTicketMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isTicketMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-headset nav-icon"></i>
                <span class="nav-text">Complain</span>
                <?php if($total_pending_requests > 0): ?>
                    <span class="badge bg-danger rounded-pill me-2" style="font-size: 0.72rem;"><?= $total_pending_requests ?></span>
                <?php endif; ?>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isTicketMenu ? 'show' : '' ?>" id="complainMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item">
                        <a href="support_tickets" class="<?= in_array($p, ['support_tickets.php', 'support_tickets', 'view_ticket.php', 'view_ticket']) ? 'active' : '' ?>">
                            <span>Support Tickets</span>
                            <?php if($open_tickets > 0): ?><span class="badge bg-danger rounded-pill"><?= $open_tickets ?></span><?php endif; ?>
                        </a>
                    </li>
                    <li class="submenu-tree-item">
                        <a href="requests" class="<?= in_array($p, ['requests.php', 'requests']) ? 'active' : '' ?>">
                            <span>Package Requests</span>
                            <?php if($pending_pkgs > 0): ?><span class="badge bg-danger rounded-pill"><?= $pending_pkgs ?></span><?php endif; ?>
                        </a>
                    </li>
                    <li class="submenu-tree-item">
                        <a href="fund_requests" class="<?= in_array($p, ['fund_requests.php', 'fund_requests']) ? 'active' : '' ?>">
                            <span>Fund Requests</span>
                            <?php if($pending_funds > 0): ?><span class="badge bg-danger rounded-pill"><?= $pending_funds ?></span><?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- 8. Messages -->
            <a href="#" class="nav-item-link">
                <i class="fa-solid fa-comment-dots nav-icon"></i>
                <span class="nav-text">Messages</span>
            </a>

            <!-- 9. Recharge System -->
            <a href="#rechargeMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isRechargeMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isRechargeMenu ? 'true' : 'false' ?>">
                <i class="fa-solid fa-wifi nav-icon"></i>
                <span class="nav-text">Recharge System</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isRechargeMenu ? 'show' : '' ?>" id="rechargeMenu">
                <ul class="submenu-tree">
                    <li class="submenu-tree-item"><a href="packages" class="<?= in_array($p, ['packages.php', 'packages']) ? 'active' : '' ?>">Master Packages</a></li>
                    <?php if (empty($_SESSION['is_manager'])): ?>
                    <li class="submenu-tree-item"><a href="payment_gateways" class="<?= in_array($p, ['payment_gateways.php', 'payment_gateways']) ? 'active' : '' ?>">Payment Gateways</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- 10. Logs & Reports -->
            <a href="#logsMenu" data-bs-toggle="collapse" class="nav-item-link has-submenu <?= $isLogsMenu ? 'active-parent' : 'collapsed' ?>" aria-expanded="<?= $isLogsMenu ? 'true' : 'false' ?>">
                <i class="fa-regular fa-file-lines nav-icon"></i>
                <span class="nav-text">Logs &amp; Reports</span>
                <i class="fa-solid fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $isLogsMenu ? 'show' : '' ?>" id="logsMenu">
                <ul class="submenu-tree">
                    <?php if (empty($_SESSION['is_manager'])): ?>
                    <li class="submenu-tree-item"><a href="ip_pools" class="<?= in_array($p, ['ip_pools.php', 'ip_pools']) ? 'active' : '' ?>">IP Pools (Multi-WAN)</a></li>
                    <?php endif; ?>
                    <li class="submenu-tree-item"><a href="activity_logs" class="<?= in_array($p, ['activity_logs.php', 'activity_logs']) ? 'active' : '' ?>">Activity Logs</a></li>
                    <li class="submenu-tree-item"><a href="package_logs" class="<?= in_array($p, ['package_logs.php', 'package_logs']) ? 'active' : '' ?>">Package Logs</a></li>
                    <li class="submenu-tree-item"><a href="session_logs" class="<?= in_array($p, ['session_logs.php', 'session_logs']) ? 'active' : '' ?>">Session Logs</a></li>
                </ul>
            </div>

        </div>

        <!-- Bottom User Profile Strip (ADMINCHECK1 from Screenshot) -->
        <div class="sidebar-footer-user">
            <a href="profile" style="text-decoration: none; display: flex; align-items: center; gap: 10px; flex-grow: 1; min-width: 0; color: inherit;">
                <div class="user-avatar">
                    <?php if (!empty($tenant['photo']) && file_exists(__DIR__ . '/../uploads/profiles/' . $tenant['photo'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($tenant['photo']) ?>?v=<?= time() ?>" alt="Avatar">
                    <?php else: ?>
                        <?= $avatarInitial ?>
                    <?php endif; ?>
                </div>
                <div class="user-info">
                    <div class="user-name" title="<?= htmlspecialchars($displayName) ?>">
                        <?= htmlspecialchars(strtoupper($displayName)) ?>
                    </div>
                    <div class="user-role"><?= $userRoleLabel ?></div>
                </div>
            </a>
            <a href="logout" class="logout-btn" title="Logout">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>

    </div>

    <!-- Top Navbar -->
    <div class="top-nav">
        <div class="d-flex align-items-center gap-3">
            <button type="button" id="topNavSidebarToggle" class="btn btn-sm btn-light border rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width: 36px; height: 36px;" title="Toggle Sidebar">
                <i class="fa-solid fa-bars-staggered"></i>
            </button>
            <a href="dashboard" class="text-decoration-none d-flex align-items-center gap-2">
                <div class="bg-primary text-white d-flex align-items-center justify-content-center rounded shadow-sm" style="width: 38px; height: 38px; background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fa-solid fa-wifi"></i>
                </div>
                <span class="fs-4 fw-bold text-dark" style="letter-spacing: -0.5px;"><?= BRAND_SHORT_NAME ?> <span class="text-primary"><?= htmlspecialchars($tenant['company_name']) ?></span></span>
                <?php if (!empty($_SESSION['is_manager'])): ?>
                    <span class="badge border rounded-pill px-3 py-1 ms-2 shadow-sm" style="font-size: 0.78rem; background: #ede9fe; color: #7c3aed; border-color: #ddd6fe !important;">
                        <i class="fa-solid fa-user-shield me-1"></i>Manager: <?= htmlspecialchars($_SESSION['manager_name'] ?? 'Manager') ?>
                    </span>
                <?php endif; ?>
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
