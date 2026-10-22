<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Delete session logs older than 60 days (60 * 86400 = 5184000 seconds)
$pdo->exec("DELETE FROM radacct WHERE acctstoptime IS NOT NULL AND acctstoptime < (UNIX_TIMESTAMP() - 5184000)");

// Date & Search Filter Handling
$from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($per_page, [10, 25, 50, 100])) {
    $per_page = 25;
}
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Build query conditions
$where_clauses = ["s.client_id = ?"];
$params = [$client_id];

if (!empty($from_date)) {
    $from_ts = strtotime($from_date . ' 00:00:00');
    $where_clauses[] = "(
        (r.acctstarttime REGEXP '^[0-9]+$' AND CAST(r.acctstarttime AS UNSIGNED) >= ?) 
        OR (NOT (r.acctstarttime REGEXP '^[0-9]+$') AND r.acctstarttime >= ?)
    )";
    $params[] = $from_ts;
    $params[] = $from_date . ' 00:00:00';
}

if (!empty($to_date)) {
    $to_ts = strtotime($to_date . ' 23:59:59');
    $where_clauses[] = "(
        (r.acctstarttime REGEXP '^[0-9]+$' AND CAST(r.acctstarttime AS UNSIGNED) <= ?) 
        OR (NOT (r.acctstarttime REGEXP '^[0-9]+$') AND r.acctstarttime <= ?)
    )";
    $params[] = $to_ts;
    $params[] = $to_date . ' 23:59:59';
}

if ($status_filter === 'online') {
    $where_clauses[] = "(r.acctstoptime IS NULL OR r.acctstoptime = '' OR r.acctstoptime = '0')";
}

if (!empty($search_query)) {
    $where_clauses[] = "(r.username LIKE ? OR s.full_name LIKE ? OR r.framedipaddress LIKE ? OR r.callingstationid LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch KPI metrics for the current filtered scope
$kpi_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_count,
        COUNT(DISTINCT CASE WHEN (r.acctstoptime IS NULL OR r.acctstoptime = '' OR r.acctstoptime = '0') THEN r.username END) as live_count,
        COUNT(DISTINCT r.username) as unique_subscribers,
        SUM(COALESCE(r.acctinputoctets, 0) + COALESCE(r.acctoutputoctets, 0)) as total_bytes
    FROM radacct r
    JOIN subscribers s ON r.username = s.username
    WHERE {$where_sql}
");
$kpi_stmt->execute($params);
$kpi = $kpi_stmt->fetch(PDO::FETCH_ASSOC);

$total_sessions = (int)($kpi['total_count'] ?? 0);
$live_sessions = (int)($kpi['live_count'] ?? 0);
$unique_users_count = (int)($kpi['unique_subscribers'] ?? 0);
$total_traffic_bytes = (float)($kpi['total_bytes'] ?? 0);

$total_pages = ceil($total_sessions / $per_page);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

// Fetch exactly current page records (e.g. 25 rows)
$data_sql = "
    SELECT r.*, s.full_name 
    FROM radacct r
    JOIN subscribers s ON r.username = s.username
    WHERE {$where_sql}
    ORDER BY r.radacctid DESC
    LIMIT {$per_page} OFFSET {$offset}
";
$stmt = $pdo->prepare($data_sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatBytes($bytes) {
    if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 2) . ' TB';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function formatDuration($seconds) {
    if (!$seconds) return '-';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf("%02dh %02dm", $h, $m);
    }
    return sprintf("%02dm %02ds", $m, $s);
}

// Build query parameter string for pagination links
function getPageUrl($newPage) {
    $q = $_GET;
    $q['page'] = $newPage;
    return 'session_logs.php?' . http_build_query($q);
}
?>

<style>
/* Premium Dashboard Enhancements */
.session-card {
    border: none;
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.session-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
}

.metric-icon-box {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
}

.badge-live-pulse {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.25);
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 0.75rem;
}
.pulse-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background-color: #10b981;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulse-green 1.8s infinite;
}
@keyframes pulse-green {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.badge-offline-clean {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: rgba(100, 116, 139, 0.08);
    color: #475569;
    border: 1px solid rgba(100, 116, 139, 0.2);
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 0.75rem;
}

.user-avatar-circle {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4338ca;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.88rem;
    text-transform: uppercase;
}

.ip-pill {
    background: #f1f5f9;
    color: #0f172a;
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 6px;
    display: inline-block;
    border: 1px solid #e2e8f0;
}

.mac-subtext {
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size: 0.72rem;
    color: #64748b;
}

.traffic-badge {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 4px 10px;
    min-width: 125px;
}

/* Compact Filter Controls */
.filter-date-input {
    font-size: 0.8rem;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    color: #334155;
    background-color: #f8fafc;
    height: 33px;
    max-width: 135px;
}
.filter-date-input:focus {
    background-color: #ffffff;
    border-color: #3b82f6;
    outline: none;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
}

.table-toolbar-select {
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 5px 12px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #1e293b;
    background-color: #ffffff;
    cursor: pointer;
    min-width: 70px;
    height: 34px;
    outline: none;
}
.table-toolbar-search {
    border-radius: 20px;
    padding: 5px 14px;
    border: 1px solid #cbd5e1;
    outline: none;
    font-size: 0.82rem;
    width: 220px;
    height: 34px;
    background-color: #ffffff;
}
.table-toolbar-search:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
}

.page-item.active .page-link {
    background-color: #3b82f6;
    border-color: #3b82f6;
    border-radius: 8px;
}
.page-link {
    border-radius: 8px;
    margin: 0 2px;
    color: #475569;
    border: 1px solid #e2e8f0;
    font-size: 0.82rem;
    padding: 5px 11px;
}
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Sleek Clean Top Bar -->
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mb-3">
        <div class="d-flex align-items-center gap-2">
            <div class="metric-icon-box bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width: 40px; height: 40px; font-size: 1.1rem;">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.25rem;">Session Logs</h4>
                <p class="text-muted small mb-0" style="font-size: 0.78rem;">Subscriber connectivity, duration & bandwidth audit</p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-white bg-white border btn-sm rounded-pill px-3 py-1 text-secondary fw-semibold shadow-sm" style="font-size: 0.8rem; height: 34px;" onclick="location.reload()" title="Refresh">
                <i class="fa-solid fa-rotate-right text-primary me-1"></i> Refresh
            </button>
            <button class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 fw-semibold shadow-sm" style="font-size: 0.8rem; height: 34px;" onclick="window.print()" title="Print / Export">
                <i class="fa-solid fa-print me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Quick Metrics KPI Row -->
    <div class="row g-2 g-md-3 mb-3">
        <!-- Live Sessions -->
        <div class="col-6 col-xl-3">
            <div class="card session-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Active Online</span>
                        <h4 class="fw-bold text-success mb-0 mt-1"><?= number_format($live_sessions) ?></h4>
                    </div>
                    <div class="metric-icon-box bg-success bg-opacity-10 text-success">
                        <i class="fa-solid fa-satellite-dish"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 0.72rem;">
                    <span class="badge-live-pulse"><span class="pulse-dot"></span> Live Sessions</span>
                </div>
            </div>
        </div>

        <!-- Total Recorded Sessions -->
        <div class="col-6 col-xl-3">
            <div class="card session-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Sessions Logged</span>
                        <h4 class="fw-bold text-dark mb-0 mt-1"><?= number_format($total_sessions) ?></h4>
                    </div>
                    <div class="metric-icon-box bg-primary bg-opacity-10 text-primary">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 0.72rem;">
                    <i class="fa-solid fa-clock text-muted me-1"></i> <?= (!empty($from_date) || !empty($to_date)) ? 'Filtered period' : 'Past 60 days' ?>
                </div>
            </div>
        </div>

        <!-- Unique Active Users -->
        <div class="col-6 col-xl-3">
            <div class="card session-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Subscribers</span>
                        <h4 class="fw-bold text-info mb-0 mt-1"><?= number_format($unique_users_count) ?></h4>
                    </div>
                    <div class="metric-icon-box bg-info bg-opacity-10 text-info">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 0.72rem;">
                    <i class="fa-solid fa-network-wired text-muted me-1"></i> Active in this view
                </div>
            </div>
        </div>

        <!-- Total Data Consumed -->
        <div class="col-6 col-xl-3">
            <div class="card session-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Bandwidth</span>
                        <h4 class="fw-bold mb-0 mt-1" style="color: #8b5cf6;"><?= formatBytes($total_traffic_bytes) ?></h4>
                    </div>
                    <div class="metric-icon-box bg-purple bg-opacity-10" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6;">
                        <i class="fa-solid fa-chart-line-up"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted" style="font-size: 0.72rem;">
                    <i class="fa-solid fa-arrows-up-down text-muted me-1"></i> Up & Down traffic
                </div>
            </div>
        </div>
    </div>

    <!-- Date Filter Bar (Placed neatly under Bandwidth / Metrics on the Right) -->
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
        <div>
            <?php if(!empty($from_date) || !empty($to_date) || !empty($search_query) || !empty($status_filter)): ?>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill small">
                    <i class="fa-solid fa-filter text-primary me-1"></i> Filters active: 
                    <?php if(!empty($from_date) || !empty($to_date)): ?>
                        <?= !empty($from_date) ? htmlspecialchars($from_date) : 'Start' ?> to <?= !empty($to_date) ? htmlspecialchars($to_date) : 'Today' ?>
                    <?php endif; ?>
                    <?= (!empty($status_filter)) ? ' (' . ucfirst($status_filter) . ')' : '' ?>
                    <?= (!empty($search_query)) ? ' [Search: ' . htmlspecialchars($search_query) . ']' : '' ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="ms-auto">
            <form method="GET" action="" class="d-inline-flex flex-wrap align-items-center gap-1 bg-white p-2 rounded-3 border shadow-sm">
                <?php if(!empty($status_filter)): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
                <?php if(!empty($search_query)): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search_query) ?>"><?php endif; ?>
                <input type="hidden" name="per_page" value="<?= $per_page ?>">

                <span class="text-muted small px-1 fw-bold" style="font-size: 0.78rem;">
                    <i class="fa-solid fa-calendar-days text-primary me-1"></i>Date:
                </span>
                <input type="date" name="from_date" class="form-control filter-date-input" value="<?= htmlspecialchars($from_date) ?>" title="From Date">
                <span class="text-muted small px-1" style="font-size: 0.78rem;">to</span>
                <input type="date" name="to_date" class="form-control filter-date-input" value="<?= htmlspecialchars($to_date) ?>" title="To Date">
                
                <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 py-1 fw-semibold shadow-none ms-1" style="font-size: 0.78rem;">
                    <i class="fa-solid fa-filter me-1"></i> Filter
                </button>

                <?php if(!empty($from_date) || !empty($to_date) || !empty($search_query) || !empty($status_filter)): ?>
                    <a href="session_logs.php" class="btn btn-outline-secondary btn-sm rounded-pill px-2 py-1 ms-1" style="font-size: 0.78rem;" title="Clear All Filters">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card session-card overflow-hidden mb-4">
        <div class="card-header bg-white border-bottom border-light py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold text-dark mb-0" style="font-size: 0.92rem;"><i class="fa-solid fa-table-list text-primary me-2"></i> Session Records</h6>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= getPageUrl(1) ?>&status=" class="btn btn-sm <?= empty($status_filter) ? 'btn-secondary text-white' : 'btn-outline-secondary' ?> rounded-pill px-3 py-1" style="font-size: 0.78rem;">
                    Show All
                </a>
                <a href="<?= getPageUrl(1) ?>&status=online" class="btn btn-sm <?= $status_filter === 'online' ? 'btn-success text-white' : 'btn-outline-success' ?> rounded-pill px-3 py-1" style="font-size: 0.78rem;">
                    <span class="pulse-dot me-1 d-inline-block"></span> Online Only
                </a>
            </div>
        </div>

        <div class="card-body p-3 p-md-4">

            <!-- Server-Side Toolbar: Show Entries Dropdown & Search Bar -->
            <div class="row align-items-center justify-content-between mb-3 g-2">
                <div class="col-sm-6">
                    <form method="GET" action="" class="d-inline-flex align-items-center gap-2">
                        <?php if(!empty($from_date)): ?><input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>"><?php endif; ?>
                        <?php if(!empty($to_date)): ?><input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>"><?php endif; ?>
                        <?php if(!empty($status_filter)): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
                        <?php if(!empty($search_query)): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search_query) ?>"><?php endif; ?>

                        <label class="text-secondary small fw-medium d-flex align-items-center gap-2 mb-0" style="font-size: 0.85rem;">
                            Show 
                            <select name="per_page" class="table-toolbar-select" onchange="this.form.submit()">
                                <option value="10" <?= $per_page === 10 ? 'selected' : '' ?>>10</option>
                                <option value="25" <?= $per_page === 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $per_page === 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $per_page === 100 ? 'selected' : '' ?>>100</option>
                            </select> 
                            entries
                        </label>
                    </form>
                </div>
                <div class="col-sm-6 text-sm-end">
                    <form method="GET" action="" class="d-inline-flex align-items-center gap-2">
                        <?php if(!empty($from_date)): ?><input type="hidden" name="from_date" value="<?= htmlspecialchars($from_date) ?>"><?php endif; ?>
                        <?php if(!empty($to_date)): ?><input type="hidden" name="to_date" value="<?= htmlspecialchars($to_date) ?>"><?php endif; ?>
                        <?php if(!empty($status_filter)): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>"><?php endif; ?>
                        <input type="hidden" name="per_page" value="<?= $per_page ?>">

                        <label class="text-secondary small fw-medium d-flex align-items-center gap-2 mb-0" style="font-size: 0.85rem;">
                            Search:
                            <input type="text" name="q" class="table-toolbar-search" placeholder="Username, IP, MAC..." value="<?= htmlspecialchars($search_query) ?>">
                        </label>
                    </form>
                </div>
            </div>

            <!-- Table View -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="sessionLogsTable" style="width: 100%;">
                    <thead>
                        <tr style="background: #f8fafc; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b;">
                            <th class="ps-3 py-3 border-bottom-0">Subscriber</th>
                            <th class="py-3 border-bottom-0">IP / MAC Address</th>
                            <th class="py-3 border-bottom-0">Connection Timeline</th>
                            <th class="py-3 border-bottom-0">Duration</th>
                            <th class="py-3 border-bottom-0">Data Usage (Up / Down)</th>
                            <th class="pe-3 py-3 border-bottom-0">Session State</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.88rem;">
                        <?php if(empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-folder-open fs-2 mb-2 d-block text-secondary opacity-50"></i>
                                    No session logs found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): ?>
                            <?php 
                                $is_live = empty($log['acctstoptime']) || $log['acctstoptime'] === '0';
                                $up = formatBytes($log['acctinputoctets'] ?? 0);
                                $down = formatBytes($log['acctoutputoctets'] ?? 0);
                                $initial = strtoupper(substr($log['username'], 0, 1));
                            ?>
                            <tr>
                                <!-- Subscriber Column -->
                                <td class="ps-3 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="user-avatar-circle flex-shrink-0">
                                            <?= htmlspecialchars($initial) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark font-monospace" style="font-size: 0.92rem;">
                                                <?= htmlspecialchars($log['username']) ?>
                                            </div>
                                            <div class="text-muted small" style="font-size: 0.78rem;">
                                                <?= htmlspecialchars($log['full_name'] ?: 'No Name Provided') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- IP / MAC Address -->
                                <td class="py-3">
                                    <div>
                                        <span class="ip-pill">
                                            <i class="fa-solid fa-network-wired text-primary me-1" style="font-size: 0.7rem;"></i>
                                            <?= htmlspecialchars($log['framedipaddress'] ?? 'N/A') ?>
                                        </span>
                                    </div>
                                    <div class="mac-subtext mt-1">
                                        <i class="fa-solid fa-laptop-code text-muted me-1"></i>
                                        <?= htmlspecialchars($log['callingstationid'] ?? 'N/A') ?>
                                    </div>
                                </td>

                                <!-- Timeline -->
                                <td class="py-3">
                                    <div class="d-flex align-items-center gap-2 text-success small fw-medium">
                                        <i class="fa-solid fa-arrow-right-to-bracket text-success" style="font-size: 0.75rem;"></i>
                                        <span><?= date('d M Y, h:i A', is_numeric($log['acctstarttime']) ? $log['acctstarttime'] : strtotime($log['acctstarttime'])) ?></span>
                                    </div>
                                    <?php if(!$is_live): ?>
                                        <div class="d-flex align-items-center gap-2 text-secondary small mt-1" style="font-size: 0.78rem;">
                                            <i class="fa-solid fa-arrow-right-from-bracket text-danger" style="font-size: 0.75rem;"></i>
                                            <span><?= date('d M Y, h:i A', is_numeric($log['acctstoptime']) ? $log['acctstoptime'] : strtotime($log['acctstoptime'])) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center gap-2 text-primary small mt-1" style="font-size: 0.78rem;">
                                            <i class="fa-solid fa-spinner fa-spin text-primary"></i>
                                            <span>Session Active</span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Duration -->
                                <td class="py-3 font-monospace">
                                    <?php if($is_live): ?>
                                        <span class="badge-live-pulse"><span class="pulse-dot"></span> Live Now</span>
                                    <?php else: ?>
                                        <span class="fw-semibold text-dark"><?= formatDuration($log['acctsessiontime']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Data Usage -->
                                <td class="py-3">
                                    <div class="traffic-badge">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="text-secondary small" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-up text-danger me-1"></i>Up</span>
                                            <span class="fw-bold font-monospace text-dark" style="font-size: 0.8rem;"><?= $up ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-secondary small" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-down text-success me-1"></i>Down</span>
                                            <span class="fw-bold font-monospace text-primary" style="font-size: 0.8rem;"><?= $down ?></span>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status / Termination Reason -->
                                <td class="pe-3 py-3">
                                    <?php if($is_live): ?>
                                        <span class="badge-live-pulse"><span class="pulse-dot"></span> Online</span>
                                    <?php else: ?>
                                        <div>
                                            <span class="badge-offline-clean"><i class="fa-solid fa-power-off text-muted"></i> Offline</span>
                                        </div>
                                        <?php if(!empty($log['acctterminatecause'])): ?>
                                            <div class="text-muted mt-1 font-monospace" style="font-size: 0.72rem;" title="Termination Cause">
                                                <i class="fa-solid fa-circle-info text-secondary me-1"></i><?= htmlspecialchars($log['acctterminatecause']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Server-Side Pagination Bar (Rock Solid Bootstrap Pagination) -->
            <?php 
                $start_record = ($total_sessions > 0) ? $offset + 1 : 0;
                $end_record = min($offset + $per_page, $total_sessions);
            ?>
            <div class="row align-items-center justify-content-between mt-3 g-2">
                <div class="col-sm-6 text-muted small" style="font-size: 0.82rem;">
                    Showing <strong><?= number_format($start_record) ?></strong> to <strong><?= number_format($end_record) ?></strong> of <strong><?= number_format($total_sessions) ?></strong> entries
                </div>
                <div class="col-sm-6">
                    <?php if($total_pages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm justify-content-sm-end mb-0">
                                <!-- Previous -->
                                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= ($page > 1) ? getPageUrl($page - 1) : '#' ?>">Previous</a>
                                </li>

                                <!-- First Page -->
                                <?php if($page > 3): ?>
                                    <li class="page-item"><a class="page-link" href="<?= getPageUrl(1) ?>">1</a></li>
                                    <?php if($page > 4): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                <?php endif; ?>

                                <!-- Middle Pages Window -->
                                <?php 
                                    $start_p = max(1, $page - 2);
                                    $end_p = min($total_pages, $page + 2);
                                    for($p = $start_p; $p <= $end_p; $p++): 
                                ?>
                                    <li class="page-item <?= ($p == $page) ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= getPageUrl($p) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>

                                <!-- Last Page -->
                                <?php if($page < $total_pages - 2): ?>
                                    <?php if($page < $total_pages - 3): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?= getPageUrl($total_pages) ?>"><?= $total_pages ?></a></li>
                                <?php endif; ?>

                                <!-- Next -->
                                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= ($page < $total_pages) ? getPageUrl($page + 1) : '#' ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>

<?php require_once 'footer.php'; ?>
