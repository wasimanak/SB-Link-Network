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

foreach ($subscribers as $sub) {
    $pid = (int)($sub['package_id'] ?? 0);
    $package_subscribers[$pid][] = $sub;

    if ($sub['is_online']) {
        $total_online_count++;
    }
    if ($sub['status'] === 'expired' || (!empty($sub['expiry_date']) && strtotime($sub['expiry_date']) <= time())) {
        $total_expired_count++;
    }
}
?>

<!-- SweetAlert2 for modern confirmation and reactive toast alerts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .package-header-banner {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        transition: box-shadow 0.2s ease;
    }
    .package-header-banner:hover {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    }
    .sub-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        position: relative;
    }
    .sub-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        border-color: #cbd5e1;
    }
    .sub-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: #ffffff;
        font-weight: 800;
        font-size: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        position: relative;
    }
    .online-indicator-dot {
        position: absolute;
        bottom: 0;
        right: 0;
        width: 11px;
        height: 11px;
        background-color: #10b981;
        border: 2px solid #ffffff;
        border-radius: 50%;
    }
    .badge-soft-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
    .badge-soft-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .badge-soft-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    .badge-soft-primary { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .badge-soft-warning { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

    .pkg-select-dropdown {
        font-size: 0.84rem;
        font-weight: 600;
        background-color: #f8fafc;
        border-color: #cbd5e1;
        transition: all 0.2s ease;
    }
    .pkg-select-dropdown:focus {
        border-color: #2563eb;
        background-color: #ffffff;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }
    .card-moving {
        opacity: 0.4;
        transform: scale(0.96);
        pointer-events: none;
    }
</style>

<div class="container-fluid px-0">

    <!-- Top Header Banner -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 50px; height: 50px; background: linear-gradient(135deg, #2563eb, #1d4ed8); font-size: 1.35rem;">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
            <div>
                <h4 class="fw-bold text-dark mb-0">Package Wise Users Management</h4>
                <div class="text-secondary small">View subscribers grouped by their packages and switch user plans with 1-click FreeRADIUS &amp; MikroTik live sync.</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm" onclick="expandAllPackages()">
                <i class="fa-solid fa-up-right-and-down-left-from-center me-1"></i> Expand All
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm" onclick="collapseAllPackages()">
                <i class="fa-solid fa-down-left-and-up-right-to-center me-1"></i> Collapse All
            </button>
        </div>
    </div>

    <!-- Quick Stats Cards Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Total Packages</span>
                    <div class="rounded-circle bg-primary-subtle text-primary p-2" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-box text-primary"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-dark mb-0 font-monospace"><?= count($packages) ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Total Subscribers</span>
                    <div class="rounded-circle bg-info-subtle text-info p-2" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-users text-info"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-dark mb-0 font-monospace" id="stat_total_subscribers"><?= number_format($total_subscribers_count) ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Live Online</span>
                    <div class="rounded-circle bg-success-subtle text-success p-2" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-wifi text-success"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-success mb-0 font-monospace"><?= number_format($total_online_count) ?></h3>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Expired Users</span>
                    <div class="rounded-circle bg-danger-subtle text-danger p-2" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-clock-rotate-left text-danger"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-danger mb-0 font-monospace"><?= number_format($total_expired_count) ?></h3>
            </div>
        </div>
    </div>

    <!-- Live Search & Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
        <div class="row align-items-center g-2">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted px-3 rounded-start-pill">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <input type="text" id="liveUserSearch" class="form-control border-start-0 rounded-end-pill shadow-none" placeholder="Search subscriber by username or full name across all packages..." onkeyup="filterSubscribersLive()">
                </div>
            </div>
            <div class="col-md-6 text-md-end text-muted small">
                <i class="fa-solid fa-circle-info text-primary me-1"></i> Changing package from the dropdown updates FreeRADIUS, rate-limits &amp; clears session immediately.
            </div>
        </div>
    </div>

    <!-- PACKAGES & SUBSCRIBERS ACCORDION LIST -->
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

        <?php foreach ($packages as $pkg): 
            $pid = (int)$pkg['id'];
            $pkg_subs = $package_subscribers[$pid] ?? [];
            $subs_count = count($pkg_subs);
        ?>
        <div class="package-section card border-0 shadow-sm rounded-4 overflow-hidden bg-white" id="pkg_section_<?= $pid ?>">
            
            <!-- Package Header Banner -->
            <div class="card-header bg-white border-bottom p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3" style="cursor: pointer;" onclick="togglePackageCollapse(<?= $pid ?>)">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle text-primary bg-primary-subtle d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; font-size: 1.15rem;">
                        <i class="fa-solid fa-cube"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($pkg['name']) ?></h5>
                            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 font-monospace" style="font-size: 0.78rem;">
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
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="badge rounded-pill bg-dark text-white px-3 py-2 fw-bold font-monospace" id="badge_count_<?= $pid ?>" style="font-size: 0.85rem;">
                        <span class="count-num"><?= $subs_count ?></span> Users
                    </span>
                    <button type="button" class="btn btn-sm btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;" title="Toggle Expand">
                        <i class="fa-solid fa-chevron-down" id="chevron_pkg_<?= $pid ?>"></i>
                    </button>
                </div>
            </div>

            <!-- Package Users Grid (Collapsible Body) -->
            <div class="card-body p-3 p-md-4 collapse show" id="pkg_body_<?= $pid ?>">

                <!-- Empty State Message -->
                <div class="text-center py-4 text-muted empty-package-msg <?= $subs_count > 0 ? 'd-none' : '' ?>" id="empty_msg_<?= $pid ?>">
                    <i class="fa-solid fa-user-slash fa-2x mb-2 text-secondary opacity-50"></i>
                    <div class="small fw-semibold">No subscribers currently assigned to this package.</div>
                </div>

                <!-- Subscribers Grid -->
                <div class="row g-3 subscribers-grid" id="pkg_grid_<?= $pid ?>">
                    <?php foreach ($pkg_subs as $s): 
                        $avatarChar = strtoupper(substr(trim($s['username']), 0, 1) ?: 'U');
                        $isExp = ($s['status'] === 'expired') || (!empty($s['expiry_date']) && strtotime($s['expiry_date']) <= time());
                    ?>
                    <div class="col-12 col-md-6 col-xl-4 subscriber-card-col" id="user_col_<?= $s['id'] ?>" data-username="<?= htmlspecialchars(strtolower($s['username'])) ?>" data-fullname="<?= htmlspecialchars(strtolower($s['full_name'] ?? '')) ?>">
                        <div class="sub-card h-100 d-flex flex-column justify-content-between" id="user_card_<?= $s['id'] ?>">
                            
                            <div>
                                <!-- Top Row: Avatar + Details + Status -->
                                <div class="d-flex align-items-start gap-3 mb-2">
                                    <div class="sub-avatar">
                                        <?= $avatarChar ?>
                                        <?php if ($s['is_online']): ?>
                                            <span class="online-indicator-dot" title="User is currently Online"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between gap-1">
                                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" target="_blank" class="fw-bold text-dark text-truncate text-decoration-none" title="View details for <?= htmlspecialchars($s['username']) ?>" style="font-size: 0.95rem;">
                                                <?= htmlspecialchars($s['username']) ?>
                                            </a>
                                            <?php if ($s['is_online']): ?>
                                                <span class="badge badge-soft-success rounded-pill px-2 py-0" style="font-size: 0.68rem;"><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.45rem;"></i>Online</span>
                                            <?php elseif ($s['status'] === 'disabled'): ?>
                                                <span class="badge badge-soft-danger rounded-pill px-2 py-0" style="font-size: 0.68rem;">Disabled</span>
                                            <?php elseif ($isExp): ?>
                                                <span class="badge badge-soft-danger rounded-pill px-2 py-0" style="font-size: 0.68rem;">Expired</span>
                                            <?php else: ?>
                                                <span class="badge badge-soft-primary rounded-pill px-2 py-0" style="font-size: 0.68rem;">Active</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($s['full_name'])): ?>
                                            <div class="small text-muted text-truncate" style="font-size: 0.78rem;"><?= htmlspecialchars($s['full_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Expiry Row -->
                                <div class="d-flex align-items-center justify-content-between small text-muted mb-3 pt-1 border-top" style="font-size: 0.76rem;">
                                    <span><i class="fa-regular fa-clock me-1"></i>Expiry:</span>
                                    <span class="fw-semibold <?= $isExp ? 'text-danger' : 'text-dark' ?>">
                                        <?= !empty($s['expiry_date']) ? date('d M Y, h:i A', strtotime($s['expiry_date'])) : 'No Expiry Set' ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Bottom Row: Package Switch Dropdown Button -->
                            <div class="bg-light p-2 rounded-3 border">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="text-muted small fw-bold" style="font-size: 0.72rem; text-transform: uppercase;">Change Plan:</span>
                                    <span class="text-primary small fw-semibold spinner-grow spinner-grow-sm d-none" id="loader_<?= $s['id'] ?>"></span>
                                </div>
                                <select class="form-select form-select-sm pkg-select-dropdown rounded-pill" 
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
        <div class="package-section card border-0 shadow-sm rounded-4 overflow-hidden bg-white border-warning" id="pkg_section_0">
            <div class="card-header bg-warning-subtle border-bottom p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3" style="cursor: pointer;" onclick="togglePackageCollapse(0)">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle text-warning bg-white d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; font-size: 1.15rem;">
                        <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0">Unassigned / Custom Package Subscribers</h5>
                        <div class="text-muted small">These users do not have any master package assigned currently. Select a package below to assign them.</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge rounded-pill bg-warning text-dark px-3 py-2 fw-bold font-monospace" id="badge_count_0" style="font-size: 0.85rem;">
                        <span class="count-num"><?= count($unassigned_subs) ?></span> Users
                    </span>
                    <button type="button" class="btn btn-sm btn-light rounded-circle text-secondary d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="fa-solid fa-chevron-down" id="chevron_pkg_0"></i>
                    </button>
                </div>
            </div>

            <div class="card-body p-3 p-md-4 collapse show" id="pkg_body_0">
                <div class="text-center py-4 text-muted empty-package-msg d-none" id="empty_msg_0">
                    <div class="small fw-semibold">No unassigned users. All subscribers have a package assigned!</div>
                </div>
                <div class="row g-3 subscribers-grid" id="pkg_grid_0">
                    <?php foreach ($unassigned_subs as $s): 
                        $avatarChar = strtoupper(substr(trim($s['username']), 0, 1) ?: 'U');
                    ?>
                    <div class="col-12 col-md-6 col-xl-4 subscriber-card-col" id="user_col_<?= $s['id'] ?>" data-username="<?= htmlspecialchars(strtolower($s['username'])) ?>" data-fullname="<?= htmlspecialchars(strtolower($s['full_name'] ?? '')) ?>">
                        <div class="sub-card h-100 d-flex flex-column justify-content-between" id="user_card_<?= $s['id'] ?>">
                            <div>
                                <div class="d-flex align-items-start gap-3 mb-2">
                                    <div class="sub-avatar" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                                        <?= $avatarChar ?>
                                    </div>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.95rem;">
                                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" target="_blank" class="text-dark text-decoration-none">
                                                <?= htmlspecialchars($s['username']) ?>
                                            </a>
                                        </div>
                                        <div class="small text-danger fw-semibold">No Package Assigned</div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-light p-2 rounded-3 border">
                                <label class="form-label text-muted small fw-bold mb-1" style="font-size: 0.72rem;">ASSIGN PACKAGE:</label>
                                <select class="form-select form-select-sm pkg-select-dropdown rounded-pill" 
                                        id="select_pkg_<?= $s['id'] ?>" 
                                        data-current-pkg="0" 
                                        onchange="onPackageSelectChange(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['username'])) ?>', this)">
                                    <option value="" disabled selected>-- Select a Package --</option>
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
// Expand/Collapse functions
function togglePackageCollapse(pkgId) {
    const bodyEl = document.getElementById('pkg_body_' + pkgId);
    const chevronEl = document.getElementById('chevron_pkg_' + pkgId);
    if (!bodyEl) return;

    if (bodyEl.classList.contains('show')) {
        bodyEl.classList.remove('show');
        if (chevronEl) chevronEl.className = 'fa-solid fa-chevron-right';
    } else {
        bodyEl.classList.add('show');
        if (chevronEl) chevronEl.className = 'fa-solid fa-chevron-down';
    }
}

function expandAllPackages() {
    document.querySelectorAll('[id^="pkg_body_"]').forEach(el => el.classList.add('show'));
    document.querySelectorAll('[id^="chevron_pkg_"]').forEach(el => el.className = 'fa-solid fa-chevron-down');
}

function collapseAllPackages() {
    document.querySelectorAll('[id^="pkg_body_"]').forEach(el => el.classList.remove('show'));
    document.querySelectorAll('[id^="chevron_pkg_"]').forEach(el => el.className = 'fa-solid fa-chevron-right');
}

// Live Search Filter across all packages
function filterSubscribersLive() {
    const query = document.getElementById('liveUserSearch').value.toLowerCase().trim();
    const allCols = document.querySelectorAll('.subscriber-card-col');

    allCols.forEach(col => {
        const u = col.getAttribute('data-username') || '';
        const f = col.getAttribute('data-fullname') || '';
        if (query === '' || u.includes(query) || f.includes(query)) {
            col.classList.remove('d-none');
        } else {
            col.classList.add('d-none');
        }
    });

    // Update empty states
    document.querySelectorAll('[id^="pkg_section_"]').forEach(sec => {
        const grid = sec.querySelector('.subscribers-grid');
        const emptyMsg = sec.querySelector('.empty-package-msg');
        if (grid && emptyMsg) {
            const visibleCards = grid.querySelectorAll('.subscriber-card-col:not(.d-none)').length;
            if (visibleCards === 0 && query !== '') {
                emptyMsg.classList.remove('d-none');
            } else if (grid.querySelectorAll('.subscriber-card-col').length > 0) {
                emptyMsg.classList.add('d-none');
            }
        }
    });
}

// Package Change Confirmation & AJAX Execution
function onPackageSelectChange(subscriberId, username, selectEl) {
    const oldPkgId = parseInt(selectEl.getAttribute('data-current-pkg')) || 0;
    const newPkgId = parseInt(selectEl.value) || 0;

    if (newPkgId === oldPkgId) return;

    const newPkgText = selectEl.options[selectEl.selectedIndex].text;

    Swal.fire({
        title: 'Change Package?',
        html: `Are you sure you want to change package for <strong>${username}</strong> to:<br><span class="badge bg-primary fs-6 mt-2 p-2">${newPkgText}</span><br><small class="text-muted d-block mt-2">FreeRADIUS and MikroTik rate-limits will be synced live.</small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fa-solid fa-check me-1"></i> Yes, Switch Package',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            executePackageChange(subscriberId, username, oldPkgId, newPkgId, selectEl);
        } else {
            // Revert back to old selection
            selectEl.value = oldPkgId;
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
            selectEl.value = oldPkgId;
            Swal.fire('Error', data.message || 'Failed to change package.', 'error');
        }
    })
    .catch(err => {
        if (cardCol) cardCol.classList.remove('card-moving');
        if (spinner) spinner.classList.add('d-none');
        selectEl.disabled = false;
        selectEl.value = oldPkgId;
        Swal.fire('Network Error', 'Could not reach server. Please try again.', 'error');
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

        // Animate into new grid with highlight
        setTimeout(() => {
            cardCol.style.opacity = '1';
            cardCol.style.transform = 'scale(1)';
            const innerCard = cardCol.querySelector('.sub-card');
            if (innerCard) {
                innerCard.style.borderColor = '#10b981';
                innerCard.style.boxShadow = '0 0 15px rgba(16, 185, 129, 0.35)';
                setTimeout(() => {
                    innerCard.style.borderColor = '';
                    innerCard.style.boxShadow = '';
                }, 2000);
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
