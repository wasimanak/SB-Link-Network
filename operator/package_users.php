<?php
// operator/package_users.php
// Package Wise Subscribers Management & Live Package Switcher
// STRICTLY ADMIN ONLY - Managers are blocked
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Strictly block Managers
if (!empty($_SESSION['is_manager'])) {
    header("Location: dashboard.php");
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/radius_kick_helper.php';

$client_id = (int)($_SESSION['operator_id'] ?? 1);

// ==========================================
// 2. AJAX ENDPOINT: LIVE PACKAGE SWITCH
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_package') {
    header('Content-Type: application/json; charset=UTF-8');

    $sub_id = (int)($_POST['subscriber_id'] ?? 0);
    $new_pkg_id = (int)($_POST['new_package_id'] ?? 0);

    if ($sub_id <= 0 || $new_pkg_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid subscriber or package parameters.']);
        exit;
    }

    // Verify subscriber belongs to this operator
    $subStmt = $pdo->prepare("SELECT id, username, full_name, status, expiry_date, package_id FROM subscribers WHERE id = ? AND client_id = ?");
    $subStmt->execute([$sub_id, $client_id]);
    $subscriber = $subStmt->fetch(PDO::FETCH_ASSOC);

    if (!$subscriber) {
        echo json_encode(['status' => 'error', 'message' => 'Subscriber not found or unauthorized.']);
        exit;
    }

    $old_pkg_id = (int)($subscriber['package_id'] ?? 0);

    // Verify target package belongs to this operator
    $pkgStmt = $pdo->prepare("
        SELECT p.id, p.name, p.rate_limit, p.price, p.validity_days, p.pool_id, pl.pool_name 
        FROM packages p 
        LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
        WHERE p.id = ? AND p.client_id = ?
    ");
    $pkgStmt->execute([$new_pkg_id, $client_id]);
    $new_pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$new_pkg) {
        echo json_encode(['status' => 'error', 'message' => 'Target package not found.']);
        exit;
    }

    // Fetch old package name for audit logging
    $oldPkgStmt = $pdo->prepare("SELECT name FROM packages WHERE id = ?");
    $oldPkgStmt->execute([$old_pkg_id]);
    $old_pkg_name = $oldPkgStmt->fetchColumn() ?: 'Unassigned';

    $is_expired = ($subscriber['status'] === 'expired') || (!empty($subscriber['expiry_date']) && strtotime($subscriber['expiry_date']) <= time());
    $u = trim($subscriber['username']);

    try {
        $pdo->beginTransaction();

        // Step A: Update package_id in subscribers table
        $pdo->prepare("UPDATE subscribers SET package_id = ? WHERE id = ?")->execute([$new_pkg_id, $sub_id]);

        // Step B: Synchronize FreeRADIUS radusergroup
        $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$u]);
        // Only assign to package group if user is active (expired users stay out of package group)
        if (!$is_expired && $subscriber['status'] !== 'disabled') {
            $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$u, $new_pkg['name']]);
        }

        // Step C: Synchronize FreeRADIUS radreply Rate Limit
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);

        if ($is_expired) {
            // Keep strictly locked at 10k/10k
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', '10k/10k')")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', '=', 'EXPIRED_USERS')")->execute([$u]);
        } else {
            // Apply new package speed
            if (!empty($new_pkg['rate_limit']) && $new_pkg['rate_limit'] !== 'Unlimited') {
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $new_pkg['rate_limit']]);
            }
            // Remove walled garden address list
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
        }

        // Step D: Profile assignment (Mikrotik-Group)
        $target_profile = !empty($new_pkg['pool_name']) ? $new_pkg['pool_name'] : '';
        if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
            if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $new_pkg['name'], $m_prof)) {
                $target_profile = $m_prof[1] . 'Mbps';
            }
        }
        if (!empty($target_profile) && !$is_expired) {
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
        }

        // Step E: Activity Log
        $log_activity = "Changed Package from '{$old_pkg_name}' to '{$new_pkg['name']}'";
        try {
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, 'Admin', 'Admin', ?, 'User', ?)")->execute([$client_id, $u, $log_activity]);
        } catch (Exception $e) {}

        $pdo->commit();

        // Step F: Kick active session from MikroTik so new speed takes effect immediately!
        kick_subscriber_session($pdo, $u, $client_id);

        echo json_encode([
            'status' => 'success',
            'message' => "Package for '{$u}' changed to '{$new_pkg['name']}' successfully! FreeRADIUS & MikroTik synchronized.",
            'subscriber_id' => $sub_id,
            'username' => $u,
            'old_package_id' => $old_pkg_id,
            'new_package_id' => $new_pkg_id,
            'new_package_name' => $new_pkg['name'],
            'new_rate_limit' => $new_pkg['rate_limit'] ?: 'Unlimited',
            'new_price' => number_format($new_pkg['price'], 0)
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ==========================================
// 3. PAGE VIEW: FETCH PACKAGES & SUBSCRIBERS
// ==========================================
require_once 'header.php';

// Fetch all packages for this client
$pkgQuery = $pdo->prepare("
    SELECT p.*, COUNT(s.id) as subscriber_count
    FROM packages p
    LEFT JOIN subscribers s ON p.id = s.package_id AND s.client_id = ?
    WHERE p.client_id = ?
    GROUP BY p.id
    ORDER BY p.price ASC, p.name ASC
");
$pkgQuery->execute([$client_id, $client_id]);
$packages = $pkgQuery->fetchAll(PDO::FETCH_ASSOC);

// Map of all packages for the dropdowns
$package_options = [];
foreach ($packages as $pkg) {
    $package_options[$pkg['id']] = $pkg;
}

// Fetch all subscribers with online status from radacct
$subQuery = $pdo->prepare("
    SELECT s.id, s.username, s.full_name, s.phone, s.status, s.expiry_date, s.package_id, s.balance,
           p.name as package_name, p.rate_limit,
           IF(r.username IS NOT NULL, 1, 0) as is_online
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    LEFT JOIN (
        SELECT DISTINCT username 
        FROM radacct 
        WHERE acctstoptime IS NULL
    ) r ON s.username = r.username
    WHERE s.client_id = ?
    ORDER BY s.status ASC, s.username ASC
");
$subQuery->execute([$client_id]);
$subscribers = $subQuery->fetchAll(PDO::FETCH_ASSOC);

// Group subscribers by package_id
$package_subscribers = [];
$total_subscribers_count = count($subscribers);
$total_online_count = 0;
$total_expired_count = 0;
$total_active_count = 0;

foreach ($subscribers as $sub) {
    $pid = (int)($sub['package_id'] ?? 0);
    $package_subscribers[$pid][] = $sub;

    if ($sub['is_online']) {
        $total_online_count++;
    }
    if ($sub['status'] === 'expired' || (!empty($sub['expiry_date']) && strtotime($sub['expiry_date']) <= time())) {
        $total_expired_count++;
    } else {
        $total_active_count++;
    }
}

// Accent colors array for elegant visual separation of packages
$accent_colors = [
    ['border' => '#2563eb', 'bg' => '#eff6ff', 'text' => '#1d4ed8'],
    ['border' => '#0d9488', 'bg' => '#f0fdfa', 'text' => '#0f766e'],
    ['border' => '#7c3aed', 'bg' => '#f5f3ff', 'text' => '#6d28d9'],
    ['border' => '#ea580c', 'bg' => '#fff7ed', 'text' => '#c2410c'],
    ['border' => '#0284c7', 'bg' => '#f0f9ff', 'text' => '#0369a1'],
    ['border' => '#db2777', 'bg' => '#fdf2f8', 'text' => '#be185d'],
    ['border' => '#16a34a', 'bg' => '#f0fdf4', 'text' => '#15803d'],
];
?>

<!-- SweetAlert2 for modern confirmation and reactive toast alerts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* ======================================================== */
    /* PREMIUM ENTERPRISE SAAS DESIGN SYSTEM                    */
    /* ======================================================== */
    
    /* Top Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border-radius: 16px;
        padding: 16px 20px;
        border: 1px solid #eef2f6;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.02);
        display: flex;
        align-items: center;
        gap: 16px;
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
    }
    .stat-pill-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.55rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
        letter-spacing: -0.5px;
    }
    .stat-pill-lbl {
        font-size: 0.74rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        margin-top: 4px;
    }

    /* Filter Chips */
    .filter-chip {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-weight: 700;
        font-size: 0.8rem;
        padding: 6px 14px;
        border-radius: 50px;
        cursor: pointer;
        transition: all 0.18s ease;
        display: inline-flex;
        align-items: center;
        user-select: none;
    }
    .filter-chip:hover {
        background: #f1f5f9;
        color: #0f172a;
        border-color: #cbd5e1;
    }
    .filter-chip.active {
        background: #2563eb !important;
        color: #ffffff !important;
        border-color: #2563eb !important;
        box-shadow: 0 3px 10px rgba(37, 99, 235, 0.28);
    }

    /* Package Section Card */
    .package-section {
        background: #ffffff;
        border-radius: 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.025);
        overflow: hidden;
        transition: box-shadow 0.25s ease, border-color 0.25s ease;
    }
    .package-section:hover {
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.045);
    }
    .package-header-banner {
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 18px 24px;
        cursor: pointer;
        user-select: none;
        transition: background-color 0.18s ease;
    }
    .package-header-banner:hover {
        background-color: #fbfcfe;
    }

    .package-icon-box {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .package-title {
        font-size: 1.12rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.3px;
        line-height: 1.2;
    }

    /* Subscriber Card */
    .sub-card {
        background: #ffffff;
        border: 1.5px solid #edf2f7;
        border-radius: 16px;
        padding: 16px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s ease, border-color 0.2s ease;
        position: relative;
    }
    .sub-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 26px rgba(0, 0, 0, 0.06);
        border-color: #cbd5e1;
    }

    .sub-avatar {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        color: #ffffff;
        font-weight: 800;
        font-size: 1.15rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
        position: relative;
    }

    /* Pulsing Online Radar Dot */
    .online-indicator-dot {
        position: absolute;
        bottom: -2px;
        right: -2px;
        width: 12px;
        height: 12px;
        background-color: #10b981;
        border: 2px solid #ffffff;
        border-radius: 50%;
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
        animation: pulse-dot 1.8s infinite;
    }
    @keyframes pulse-dot {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    /* Clean Badges */
    .badge-soft-success { background: #ecfdf5 !important; color: #059669 !important; border: 1px solid #a7f3d0 !important; font-weight: 700; }
    .badge-soft-danger { background: #fef2f2 !important; color: #dc2626 !important; border: 1px solid #fecaca !important; font-weight: 700; }
    .badge-soft-primary { background: #eff6ff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; font-weight: 700; }
    .badge-soft-secondary { background: #f1f5f9 !important; color: #475569 !important; border: 1px solid #e2e8f0 !important; font-weight: 700; }

    /* Switch Plan Container & Select Dropdown */
    .switch-plan-box {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
        margin-top: 12px;
    }
    .pkg-select-dropdown {
        font-size: 0.85rem;
        font-weight: 600;
        background-color: #ffffff;
        border: 1.5px solid #cbd5e1;
        border-radius: 50px;
        padding: 6px 14px;
        color: #0f172a;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .pkg-select-dropdown:hover {
        border-color: #94a3b8;
    }
    .pkg-select-dropdown:focus {
        border-color: #2563eb;
        background-color: #ffffff;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }

    /* Move Card Animation */
    .card-moving {
        opacity: 0.35;
        transform: scale(0.95);
        pointer-events: none;
        filter: grayscale(50%);
    }

    .rotate-chevron {
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .rotate-chevron.collapsed-icon {
        transform: rotate(-90deg);
    }
</style>

<div class="container-fluid px-0">

    <!-- Top Hero Section Banner -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 52px; height: 52px; background: linear-gradient(135deg, #2563eb, #1d4ed8); font-size: 1.4rem; box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3) !important;">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <div>
                <h4 class="fw-bold text-dark mb-1" style="letter-spacing: -0.4px;">Package Wise Users Management</h4>
                <div class="text-secondary small">Organized overview of all subscribers grouped by package with instant 1-click plan switching &amp; MikroTik live sync.</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm" onclick="expandAllPackages()">
                <i class="fa-solid fa-up-right-and-down-left-from-center me-1"></i> Expand All
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm" onclick="collapseAllPackages()">
                <i class="fa-solid fa-down-left-and-up-right-to-center me-1"></i> Collapse All
            </button>
            <button type="button" class="btn btn-light btn-sm border rounded-circle text-secondary shadow-sm" style="width: 34px; height: 34px;" onclick="location.reload()" title="Refresh Page">
                <i class="fa-solid fa-rotate-right"></i>
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(37, 99, 235, 0.12); color: #2563eb;">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div>
                    <div class="stat-pill-val font-monospace"><?= count($packages) ?></div>
                    <div class="stat-pill-lbl">Master Packages</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(6, 182, 212, 0.12); color: #0891b2;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="stat-pill-val font-monospace" id="stat_total_subscribers"><?= number_format($total_subscribers_count) ?></div>
                    <div class="stat-pill-lbl">Total Subscribers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                    <i class="fa-solid fa-wifi"></i>
                </div>
                <div>
                    <div class="stat-pill-val text-success font-monospace"><?= number_format($total_online_count) ?></div>
                    <div class="stat-pill-lbl">Live Online</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(239, 68, 68, 0.12); color: #dc2626;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="stat-pill-val text-danger font-monospace"><?= number_format($total_expired_count) ?></div>
                    <div class="stat-pill-lbl">Expired Users</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Search & Interactive Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white" style="border: 1px solid #eef2f6 !important;">
        <div class="row align-items-center g-3">
            <div class="col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted px-3 rounded-start-pill" style="border-color: #cbd5e1;">
                        <i class="fa-solid fa-magnifying-glass text-primary"></i>
                    </span>
                    <input type="text" id="liveUserSearch" class="form-control border-start-0 rounded-end-pill shadow-none" placeholder="Search by username, full name or phone..." onkeyup="filterSubscribersLive()" style="border-color: #cbd5e1; font-size: 0.92rem;">
                </div>
            </div>
            <div class="col-lg-7 d-flex align-items-center justify-content-lg-end gap-2 flex-wrap">
                <span class="text-muted small fw-bold me-1"><i class="fa-solid fa-filter me-1"></i>Filter:</span>
                <span class="filter-chip active" id="filter_all" onclick="setSubFilter('all', this)">
                    All Users (<span id="chip_all_count"><?= $total_subscribers_count ?></span>)
                </span>
                <span class="filter-chip" id="filter_online" onclick="setSubFilter('online', this)">
                    <i class="fa-solid fa-circle text-success me-1" style="font-size: 0.45rem;"></i> Online (<span id="chip_online_count"><?= $total_online_count ?></span>)
                </span>
                <span class="filter-chip" id="filter_expired" onclick="setSubFilter('expired', this)">
                    <i class="fa-solid fa-clock-rotate-left text-danger me-1"></i> Expired (<span id="chip_expired_count"><?= $total_expired_count ?></span>)
                </span>
                <span class="filter-chip" id="filter_active" onclick="setSubFilter('active', this)">
                    <i class="fa-solid fa-circle-check text-primary me-1"></i> Active
                </span>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- PACKAGES & SUBSCRIBERS ACCORDION LIST       -->
    <!-- ========================================== -->
    <div class="d-flex flex-column gap-4" id="packagesListContainer">

        <?php if (empty($packages)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
            <div class="text-muted mb-3"><i class="fa-solid fa-box-open fa-3x"></i></div>
            <h5 class="fw-bold text-dark">No Packages Created Yet</h5>
            <p class="text-secondary small mb-3">You need to create Master Packages first to view subscribers by package.</p>
            <div>
                <a href="packages" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-plus me-1"></i> Create Package
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php 
        $color_idx = 0;
        foreach ($packages as $pkg): 
            $pid = (int)$pkg['id'];
            $pkg_subs = $package_subscribers[$pid] ?? [];
            $subs_count = count($pkg_subs);
            $accent = $accent_colors[$color_idx % count($accent_colors)];
            $color_idx++;

            // Count online in this package
            $pkg_online = 0;
            foreach ($pkg_subs as $ps) {
                if ($ps['is_online']) $pkg_online++;
            }
        ?>
        <div class="package-section" id="pkg_section_<?= $pid ?>" style="border-left: 5px solid <?= $accent['border'] ?> !important;">
            
            <!-- Package Header Banner -->
            <div class="package-header-banner d-flex flex-wrap align-items-center justify-content-between gap-3" onclick="togglePackageCollapse(<?= $pid ?>)">
                <div class="d-flex align-items-center gap-3">
                    <div class="package-icon-box" style="background: <?= $accent['bg'] ?>; color: <?= $accent['text'] ?>;">
                        <i class="fa-solid fa-cube"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="package-title mb-0"><?= htmlspecialchars($pkg['name']) ?></h5>
                            <span class="badge rounded-pill px-2 py-1 font-monospace" style="background: <?= $accent['bg'] ?>; color: <?= $accent['text'] ?>; border: 1px solid <?= $accent['border'] ?>33; font-size: 0.78rem;">
                                <i class="fa-solid fa-gauge-high me-1"></i><?= htmlspecialchars($pkg['rate_limit'] ?: 'Unlimited') ?>
                            </span>
                            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1 font-monospace" style="font-size: 0.78rem;">
                                Rs <?= number_format($pkg['price'], 0) ?>
                            </span>
                            <?php if (!empty($pkg['validity_days'])): ?>
                            <span class="badge rounded-pill bg-light text-muted border px-2 py-1" style="font-size: 0.74rem;">
                                <i class="fa-regular fa-calendar-check me-1"></i><?= (int)$pkg['validity_days'] ?> Days
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($pkg['wan_provider'])): ?>
                            <span class="badge rounded-pill bg-light text-dark border px-2 py-1" style="font-size: 0.74rem;">
                                <i class="fa-solid fa-network-wired me-1 text-primary"></i><?= htmlspecialchars($pkg['wan_provider']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <div class="badge rounded-pill px-3 py-2 fw-bold font-monospace shadow-sm" id="badge_count_<?= $pid ?>" style="background: #0f172a; color: #ffffff; font-size: 0.82rem;">
                        <span class="count-num"><?= $subs_count ?></span> Users 
                        <?php if ($pkg_online > 0): ?>
                            <span class="badge bg-success text-white rounded-pill ms-1 py-0 px-2 font-monospace" style="font-size: 0.7rem;">
                                <i class="fa-solid fa-circle text-white me-1" style="font-size: 0.4rem;"></i><?= $pkg_online ?> online
                            </span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-chevron-down rotate-chevron" id="chevron_pkg_<?= $pid ?>"></i>
                    </button>
                </div>
            </div>

            <!-- Package Users Grid (Collapsible Body) -->
            <div class="card-body p-3 p-md-4 collapse show" id="pkg_body_<?= $pid ?>">

                <!-- Empty State Message -->
                <div class="text-center py-5 text-muted empty-package-msg <?= $subs_count > 0 ? 'd-none' : '' ?>" id="empty_msg_<?= $pid ?>">
                    <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                        <i class="fa-solid fa-user-slash fa-2x text-muted opacity-50"></i>
                    </div>
                    <h6 class="fw-bold text-secondary mb-1">No Subscribers Assigned</h6>
                    <div class="small text-muted">Switch any subscriber from other packages or assign new subscribers here.</div>
                </div>

                <!-- Subscribers Responsive Grid -->
                <div class="row g-3 subscribers-grid" id="pkg_grid_<?= $pid ?>">
                    <?php foreach ($pkg_subs as $s): 
                        $avatarChar = strtoupper(substr(trim($s['username']), 0, 1) ?: 'U');
                        $isExp = ($s['status'] === 'expired') || (!empty($s['expiry_date']) && strtotime($s['expiry_date']) <= time());
                        $statusCategory = $s['is_online'] ? 'online' : ($isExp ? 'expired' : ($s['status'] === 'disabled' ? 'disabled' : 'active'));
                    ?>
                    <div class="col-12 col-md-6 col-xl-4 subscriber-card-col" 
                         id="user_col_<?= $s['id'] ?>" 
                         data-username="<?= htmlspecialchars(strtolower($s['username'])) ?>" 
                         data-fullname="<?= htmlspecialchars(strtolower($s['full_name'] ?? '')) ?>"
                         data-phone="<?= htmlspecialchars($s['phone'] ?? '') ?>"
                         data-status="<?= $statusCategory ?>">
                        
                        <div class="sub-card h-100 d-flex flex-column justify-content-between" id="user_card_<?= $s['id'] ?>">
                            
                            <div>
                                <!-- Top Row: Avatar + Details + Status Badge -->
                                <div class="d-flex align-items-start gap-3 mb-2">
                                    <div class="sub-avatar">
                                        <?= $avatarChar ?>
                                        <?php if ($s['is_online']): ?>
                                            <span class="online-indicator-dot" title="User is currently Online"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" target="_blank" class="fw-bold text-dark text-truncate text-decoration-none" title="Open subscriber profile in new tab" style="font-size: 0.96rem;">
                                                <?= htmlspecialchars($s['username']) ?>
                                                <i class="fa-solid fa-arrow-up-right-from-square text-muted ms-1" style="font-size: 0.65rem;"></i>
                                            </a>
                                            <?php if ($s['is_online']): ?>
                                                <span class="badge badge-soft-success rounded-pill px-2 py-1" style="font-size: 0.7rem;"><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.45rem;"></i>Online</span>
                                            <?php elseif ($s['status'] === 'disabled'): ?>
                                                <span class="badge badge-soft-danger rounded-pill px-2 py-1" style="font-size: 0.7rem;">Disabled</span>
                                            <?php elseif ($isExp): ?>
                                                <span class="badge badge-soft-danger rounded-pill px-2 py-1" style="font-size: 0.7rem;">Expired</span>
                                            <?php else: ?>
                                                <span class="badge badge-soft-primary rounded-pill px-2 py-1" style="font-size: 0.7rem;">Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($s['full_name'])): ?>
                                            <div class="small text-muted text-truncate" style="font-size: 0.8rem; font-weight: 500;">
                                                <i class="fa-solid fa-user me-1 text-secondary opacity-75"></i><?= htmlspecialchars($s['full_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Expiry Date Info Strip -->
                                <div class="d-flex align-items-center justify-content-between p-2 rounded-3 bg-light border mb-2" style="font-size: 0.76rem;">
                                    <span class="text-muted"><i class="fa-regular fa-clock me-1 text-secondary"></i>Expiry:</span>
                                    <span class="fw-bold font-monospace <?= $isExp ? 'text-danger' : 'text-dark' ?>">
                                        <?= !empty($s['expiry_date']) ? date('d M Y, h:i A', strtotime($s['expiry_date'])) : 'No Expiry Set' ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Bottom Row: Package Switch Dropdown Button -->
                            <div class="switch-plan-box">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="text-muted small fw-bold" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-arrows-rotate text-primary me-1"></i>SWITCH PACKAGE:
                                    </span>
                                    <span class="spinner-border spinner-border-sm text-primary d-none" id="loader_<?= $s['id'] ?>" style="width: 14px; height: 14px;"></span>
                                </div>
                                <select class="form-select form-select-sm pkg-select-dropdown" 
                                        id="select_pkg_<?= $s['id'] ?>" 
                                        data-current-pkg="<?= $pid ?>" 
                                        onchange="onPackageSelectChange(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['username'])) ?>', this)">
                                    <?php foreach ($packages as $opt): ?>
                                        <option value="<?= $opt['id'] ?>" <?= $opt['id'] == $pid ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt['name']) ?> (<?= htmlspecialchars($opt['rate_limit'] ?: 'Unltd') ?> - Rs <?= number_format($opt['price'], 0) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
        <?php endforeach; ?>

        <!-- ========================================== -->
        <!-- UNASSIGNED SUBSCRIBERS (NO PACKAGE SET)    -->
        <!-- ========================================== -->
        <?php 
            $unassigned_subs = $package_subscribers[0] ?? [];
            if (!empty($unassigned_subs)): 
        ?>
        <div class="package-section border-warning" id="pkg_section_0" style="border-left: 5px solid #f59e0b !important;">
            <div class="package-header-banner bg-warning-subtle d-flex flex-wrap align-items-center justify-content-between gap-3" onclick="togglePackageCollapse(0)">
                <div class="d-flex align-items-center gap-3">
                    <div class="package-icon-box bg-white text-warning shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h5 class="package-title mb-0">Unassigned / Custom Package Subscribers</h5>
                        <div class="text-muted small">These subscribers currently have no master package assigned. Select any plan below to assign them.</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge rounded-pill bg-warning text-dark px-3 py-2 fw-bold font-monospace shadow-sm" id="badge_count_0" style="font-size: 0.85rem;">
                        <span class="count-num"><?= count($unassigned_subs) ?></span> Users
                    </span>
                    <button type="button" class="btn btn-sm btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-chevron-down rotate-chevron" id="chevron_pkg_0"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-3 p-md-4 collapse show" id="pkg_body_0">
                <div class="text-center py-5 text-muted empty-package-msg d-none" id="empty_msg_0">
                    <i class="fa-solid fa-circle-check text-success fa-2x mb-2"></i>
                    <h6 class="fw-bold text-dark mb-1">All Subscribers Assigned!</h6>
                    <div class="small text-muted">There are no unassigned subscribers remaining.</div>
                </div>
                <div class="row g-3 subscribers-grid" id="pkg_grid_0">
                    <?php foreach ($unassigned_subs as $s): 
                        $avatarChar = strtoupper(substr(trim($s['username']), 0, 1) ?: 'U');
                    ?>
                    <div class="col-12 col-md-6 col-xl-4 subscriber-card-col" 
                         id="user_col_<?= $s['id'] ?>" 
                         data-username="<?= htmlspecialchars(strtolower($s['username'])) ?>" 
                         data-fullname="<?= htmlspecialchars(strtolower($s['full_name'] ?? '')) ?>"
                         data-phone="<?= htmlspecialchars($s['phone'] ?? '') ?>"
                         data-status="unassigned">
                        
                        <div class="sub-card h-100 d-flex flex-column justify-content-between" id="user_card_<?= $s['id'] ?>">
                            <div>
                                <div class="d-flex align-items-start gap-3 mb-2">
                                    <div class="sub-avatar" style="background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28);">
                                        <?= $avatarChar ?>
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.96rem;">
                                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" target="_blank" class="text-dark text-decoration-none">
                                                <?= htmlspecialchars($s['username']) ?>
                                                <i class="fa-solid fa-arrow-up-right-from-square text-muted ms-1" style="font-size: 0.65rem;"></i>
                                            </a>
                                        </div>
                                        <div class="small text-danger fw-bold mt-1">
                                            <i class="fa-solid fa-circle-exclamation me-1"></i>No Package Assigned
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="switch-plan-box">
                                <label class="form-label text-muted small fw-bold mb-1" style="font-size: 0.68rem; letter-spacing: 0.5px;">ASSIGN PACKAGE:</label>
                                <select class="form-select form-select-sm pkg-select-dropdown" 
                                        id="select_pkg_<?= $s['id'] ?>" 
                                        data-current-pkg="0" 
                                        onchange="onPackageSelectChange(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['username'])) ?>', this)">
                                    <option value="" disabled selected>-- Select a Package to Assign --</option>
                                    <?php foreach ($packages as $opt): ?>
                                        <option value="<?= $opt['id'] ?>">
                                            <?= htmlspecialchars($opt['name']) ?> (<?= htmlspecialchars($opt['rate_limit'] ?: 'Unltd') ?> - Rs <?= number_format($opt['price'], 0) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

</div>

<!-- ========================================== -->
<!-- JAVASCRIPT: LIVE PACKAGE SWITCH & FILTERS   -->
<!-- ========================================== -->
<script>
let currentStatusFilter = 'all';

// Expand/Collapse functions
function togglePackageCollapse(pkgId) {
    const bodyEl = document.getElementById('pkg_body_' + pkgId);
    const chevronEl = document.getElementById('chevron_pkg_' + pkgId);
    if (!bodyEl) return;

    if (bodyEl.classList.contains('show')) {
        bodyEl.classList.remove('show');
        if (chevronEl) chevronEl.classList.add('collapsed-icon');
    } else {
        bodyEl.classList.add('show');
        if (chevronEl) chevronEl.classList.remove('collapsed-icon');
    }
}

function expandAllPackages() {
    document.querySelectorAll('[id^="pkg_body_"]').forEach(el => el.classList.add('show'));
    document.querySelectorAll('[id^="chevron_pkg_"]').forEach(el => el.classList.remove('collapsed-icon'));
}

function collapseAllPackages() {
    document.querySelectorAll('[id^="pkg_body_"]').forEach(el => el.classList.remove('show'));
    document.querySelectorAll('[id^="chevron_pkg_"]').forEach(el => el.classList.add('collapsed-icon'));
}

// Quick status filter chips
function setSubFilter(filterType, btnEl) {
    currentStatusFilter = filterType;
    document.querySelectorAll('.filter-chip').forEach(el => el.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    filterSubscribersLive();
}

// Live Search Filter across all packages
function filterSubscribersLive() {
    const query = document.getElementById('liveUserSearch').value.toLowerCase().trim();
    const allCols = document.querySelectorAll('.subscriber-card-col');

    allCols.forEach(col => {
        const u = col.getAttribute('data-username') || '';
        const f = col.getAttribute('data-fullname') || '';
        const p = col.getAttribute('data-phone') || '';
        const status = col.getAttribute('data-status') || '';

        const matchesText = (query === '' || u.includes(query) || f.includes(query) || p.includes(query));
        let matchesStatus = true;

        if (currentStatusFilter === 'online') {
            matchesStatus = (status === 'online');
        } else if (currentStatusFilter === 'expired') {
            matchesStatus = (status === 'expired');
        } else if (currentStatusFilter === 'active') {
            matchesStatus = (status === 'active' || status === 'online');
        }

        if (matchesText && matchesStatus) {
            col.classList.remove('d-none');
        } else {
            col.classList.add('d-none');
        }
    });

    // Update empty state visibility per package section
    document.querySelectorAll('[id^="pkg_section_"]').forEach(sec => {
        const grid = sec.querySelector('.subscribers-grid');
        const emptyMsg = sec.querySelector('.empty-package-msg');
        if (grid && emptyMsg) {
            const visibleCards = grid.querySelectorAll('.subscriber-card-col:not(.d-none)').length;
            if (visibleCards === 0) {
                emptyMsg.classList.remove('d-none');
            } else {
                emptyMsg.classList.add('d-none');
            }
        }
    });
}

// Package Change Confirmation & AJAX Execution
function onPackageSelectChange(subscriberId, username, selectEl) {
    const oldPkgId = parseInt(selectEl.getAttribute('data-current-pkg')) || 0;
    const newPkgId = parseInt(selectEl.value) || 0;

    if (newPkgId === oldPkgId || newPkgId === 0) return;

    const newPkgText = selectEl.options[selectEl.selectedIndex].text;

    Swal.fire({
        title: 'Switch Package?',
        html: `
            <div class="text-center p-2">
                <div class="text-secondary small mb-1">Subscriber:</div>
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user text-primary me-1"></i>${username}</h5>
                <div class="p-3 rounded-4 bg-light border text-start mb-2">
                    <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size: 0.7rem;">Target Package:</div>
                    <div class="fw-bold text-primary fs-6">${newPkgText}</div>
                </div>
                <div class="small text-muted mt-2">
                    <i class="fa-solid fa-circle-check text-success me-1"></i> FreeRADIUS &amp; MikroTik simple queues will update live!
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fa-solid fa-bolt me-1"></i> Confirm &amp; Switch',
        cancelButtonText: 'Cancel',
        customClass: {
            popup: 'rounded-4 shadow-lg'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            executePackageChange(subscriberId, username, oldPkgId, newPkgId, selectEl);
        } else {
            // Revert back to old selection
            selectEl.value = oldPkgId || '';
        }
    });
}

function executePackageChange(subscriberId, username, oldPkgId, newPkgId, selectEl) {
    const cardCol = document.getElementById('user_col_' + subscriberId);
    const spinner = document.getElementById('loader_' + subscriberId);

    if (cardCol) cardCol.classList.add('card-moving');
    if (spinner) spinner.classList.remove('d-none');
    selectEl.disabled = true;

    const formData = new FormData();
    formData.append('action', 'change_package');
    formData.append('subscriber_id', subscriberId);
    formData.append('new_package_id', newPkgId);

    fetch('package_users.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (cardCol) cardCol.classList.remove('card-moving');
        if (spinner) spinner.classList.add('d-none');
        selectEl.disabled = false;

        if (data.status === 'success') {
            // Success Toast
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3500,
                timerProgressBar: true
            });
            Toast.fire({
                icon: 'success',
                title: data.message
            });

            // Update select's current pkg tracking
            selectEl.setAttribute('data-current-pkg', newPkgId);

            // Dynamically Move Card from Old Container to New Container!
            moveCardToNewPackage(cardCol, oldPkgId, newPkgId);

        } else {
            // Revert back
            selectEl.value = oldPkgId || '';
            Swal.fire({
                title: 'Error',
                text: data.message || 'Failed to change package.',
                icon: 'error',
                customClass: { popup: 'rounded-4' }
            });
        }
    })
    .catch(err => {
        if (cardCol) cardCol.classList.remove('card-moving');
        if (spinner) spinner.classList.add('d-none');
        selectEl.disabled = false;
        selectEl.value = oldPkgId || '';
        Swal.fire({
            title: 'Network Error',
            text: 'Could not connect to server. Please try again.',
            icon: 'error',
            customClass: { popup: 'rounded-4' }
        });
    });
}

// Reactive DOM card relocation
function moveCardToNewPackage(cardCol, oldPkgId, newPkgId) {
    const oldGrid = document.getElementById('pkg_grid_' + oldPkgId);
    const newGrid = document.getElementById('pkg_grid_' + newPkgId);

    if (!oldGrid || !newGrid || !cardCol) {
        // Fallback: If grids not found, reload softly
        location.reload();
        return;
    }

    // 1. Fade out from old grid
    cardCol.style.transition = 'all 0.3s ease';
    cardCol.style.opacity = '0';
    cardCol.style.transform = 'scale(0.85)';

    setTimeout(() => {
        // Detach from old grid
        cardCol.remove();

        // Check if old grid is now empty
        const oldRemaining = oldGrid.querySelectorAll('.subscriber-card-col').length;
        const oldEmptyMsg = document.getElementById('empty_msg_' + oldPkgId);
        if (oldEmptyMsg && oldRemaining === 0) {
            oldEmptyMsg.classList.remove('d-none');
        }

        // Update old package counter badge
        updateBadgeCount(oldPkgId, oldRemaining);

        // Prepend to new grid with animation
        cardCol.style.opacity = '0';
        cardCol.style.transform = 'scale(0.85)';
        newGrid.prepend(cardCol);

        // Hide new grid's empty message if shown
        const newEmptyMsg = document.getElementById('empty_msg_' + newPkgId);
        if (newEmptyMsg) {
            newEmptyMsg.classList.add('d-none');
        }

        // Update new package counter badge
        const newTotal = newGrid.querySelectorAll('.subscriber-card-col').length;
        updateBadgeCount(newPkgId, newTotal);

        // Animate into new grid with highlight glow
        setTimeout(() => {
            cardCol.style.opacity = '1';
            cardCol.style.transform = 'scale(1)';
            const innerCard = cardCol.querySelector('.sub-card');
            if (innerCard) {
                innerCard.style.borderColor = '#10b981';
                innerCard.style.boxShadow = '0 0 25px rgba(16, 185, 129, 0.4)';
                setTimeout(() => {
                    innerCard.style.borderColor = '';
                    innerCard.style.boxShadow = '';
                }, 2200);
            }
        }, 50);

    }, 300);
}

function updateBadgeCount(pkgId, count) {
    const badge = document.getElementById('badge_count_' + pkgId);
    if (badge) {
        const countSpan = badge.querySelector('.count-num');
        if (countSpan) {
            countSpan.innerText = count;
        } else {
            badge.innerText = count + ' Users';
        }
    }
}
</script>

<?php require_once 'footer.php'; ?>
