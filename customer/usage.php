<?php 
require_once 'header.php'; 

// Current cycle start timestamp (Reset Point on Renewal)
$cycle_start = null;
if (!empty($current_user['last_renewed_at']) && $current_user['last_renewed_at'] !== '0000-00-00 00:00:00') {
    $cycle_start = $current_user['last_renewed_at'];
} elseif (!empty($current_user['created_at'])) {
    $cycle_start = $current_user['created_at'];
} else {
    $cycle_start = date('Y-m-01 00:00:00');
}

$username = $current_user['username'];
$now = date('Y-m-d H:i:s');

// Helper to format bytes
function formatUsageBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . " GB";
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . " MB";
    if ($bytes > 0) return round($bytes / 1024, 2) . " KB";
    return "0 MB";
}

// 1. Current Cycle Summary
$cycleStmt = $pdo->prepare("SELECT 
    SUM(acctinputoctets) as up_bytes, 
    SUM(acctoutputoctets) as down_bytes,
    SUM(acctsessiontime) as total_duration,
    COUNT(*) as total_sessions
FROM radacct 
WHERE username = ? AND (acctstarttime >= ? OR acctstoptime >= ?)");
$cycleStmt->execute([$username, $cycle_start, $cycle_start]);
$cycle_stats = $cycleStmt->fetch(PDO::FETCH_ASSOC);

$cycle_up_bytes = (float)($cycle_stats['up_bytes'] ?? 0);
$cycle_down_bytes = (float)($cycle_stats['down_bytes'] ?? 0);
$cycle_total_bytes = $cycle_up_bytes + $cycle_down_bytes;
$cycle_duration_sec = (int)($cycle_stats['total_duration'] ?? 0);
$cycle_sessions_count = (int)($cycle_stats['total_sessions'] ?? 0);

$cycle_hours = floor($cycle_duration_sec / 3600);
$cycle_mins = floor(($cycle_duration_sec % 3600) / 60);

// 2. Peak Speed Calculation in Current Cycle (or recent 30 days)
$peakDownStmt = $pdo->prepare("SELECT 
    radacctid,
    acctstarttime,
    acctstoptime,
    acctsessiontime,
    acctoutputoctets,
    acctinputoctets,
    framedipaddress,
    ((acctoutputoctets * 8) / GREATEST(acctsessiontime, 1)) as down_bps,
    ((acctinputoctets * 8) / GREATEST(acctsessiontime, 1)) as up_bps
FROM radacct 
WHERE username = ? 
  AND acctstarttime >= ? 
  AND acctsessiontime >= 15
  AND acctoutputoctets >= 1048576
ORDER BY down_bps DESC 
LIMIT 1");
$peakDownStmt->execute([$username, $cycle_start]);
$peak_down_session = $peakDownStmt->fetch(PDO::FETCH_ASSOC);

$peakUpStmt = $pdo->prepare("SELECT 
    radacctid,
    acctstarttime,
    acctstoptime,
    acctsessiontime,
    acctoutputoctets,
    acctinputoctets,
    framedipaddress,
    ((acctoutputoctets * 8) / GREATEST(acctsessiontime, 1)) as down_bps,
    ((acctinputoctets * 8) / GREATEST(acctsessiontime, 1)) as up_bps
FROM radacct 
WHERE username = ? 
  AND acctstarttime >= ? 
  AND acctsessiontime >= 15
  AND acctinputoctets >= 524288
ORDER BY up_bps DESC 
LIMIT 1");
$peakUpStmt->execute([$username, $cycle_start]);
$peak_up_session = $peakUpStmt->fetch(PDO::FETCH_ASSOC);

// Fallback to last 30 days if no peak in this cycle
if (!$peak_down_session) {
    $fallback30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $peakDownStmt->execute([$username, $fallback30]);
    $peak_down_session = $peakDownStmt->fetch(PDO::FETCH_ASSOC);
}
if (!$peak_up_session) {
    $fallback30 = date('Y-m-d H:i:s', strtotime('-30 days'));
    $peakUpStmt->execute([$username, $fallback30]);
    $peak_up_session = $peakUpStmt->fetch(PDO::FETCH_ASSOC);
}

$peak_down_mbps = 0;
$peak_down_time_str = "No speed record yet";
$peak_down_vol_str = "";
if ($peak_down_session && !empty($peak_down_session['down_bps'])) {
    $peak_down_mbps = round($peak_down_session['down_bps'] / 1000000, 2);
    $peak_down_time_str = date('d M Y, h:i A', strtotime($peak_down_session['acctstarttime']));
    $peak_down_vol_str = formatUsageBytes((float)$peak_down_session['acctoutputoctets']);
}

$peak_up_mbps = 0;
$peak_up_time_str = "No speed record yet";
$peak_up_vol_str = "";
if ($peak_up_session && !empty($peak_up_session['up_bps'])) {
    $peak_up_mbps = round($peak_up_session['up_bps'] / 1000000, 2);
    $peak_up_time_str = date('d M Y, h:i A', strtotime($peak_up_session['acctstarttime']));
    $peak_up_vol_str = formatUsageBytes((float)$peak_up_session['acctinputoctets']);
}

// 3. Hourly Usage (Past 24 Hours)
$hourly_slots = [];
for ($i = 23; $i >= 0; $i--) {
    $slot_time = strtotime("-{$i} hours");
    $key = date('Y-m-d H:00', $slot_time);
    $label = date('h A', $slot_time);
    $hourly_slots[$key] = [
        'label' => $label,
        'down_mb' => 0,
        'up_mb' => 0
    ];
}

$hStmt = $pdo->prepare("SELECT 
    DATE_FORMAT(acctstarttime, '%Y-%m-%d %H:00') as slot,
    SUM(acctoutputoctets) as down_b,
    SUM(acctinputoctets) as up_b
FROM radacct
WHERE username = ? AND acctstarttime >= NOW() - INTERVAL 24 HOUR
GROUP BY slot");
$hStmt->execute([$username]);
while ($hRow = $hStmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($hourly_slots[$hRow['slot']])) {
        $hourly_slots[$hRow['slot']]['down_mb'] = round(((float)$hRow['down_b']) / 1048576, 2);
        $hourly_slots[$hRow['slot']]['up_mb'] = round(((float)$hRow['up_b']) / 1048576, 2);
    }
}
$hourly_labels = array_column(array_values($hourly_slots), 'label');
$hourly_down = array_column(array_values($hourly_slots), 'down_mb');
$hourly_up = array_column(array_values($hourly_slots), 'up_mb');

// 4. Daily Usage (Current Cycle)
$dStmt = $pdo->prepare("SELECT 
    DATE(acctstarttime) as log_date,
    SUM(acctoutputoctets) as down_b,
    SUM(acctinputoctets) as up_b,
    SUM(acctsessiontime) as duration_sec,
    COUNT(*) as session_count
FROM radacct
WHERE username = ? AND acctstarttime >= ?
GROUP BY log_date
ORDER BY log_date ASC");
$dStmt->execute([$username, $cycle_start]);
$daily_rows = $dStmt->fetchAll(PDO::FETCH_ASSOC);

$daily_labels = [];
$daily_down_gb = [];
$daily_up_gb = [];
$daily_table_data = [];

foreach ($daily_rows as $dr) {
    $day_str = date('d M', strtotime($dr['log_date']));
    $daily_labels[] = $day_str;
    $d_gb = round(((float)$dr['down_b']) / 1073741824, 3);
    $u_gb = round(((float)$dr['up_b']) / 1073741824, 3);
    $daily_down_gb[] = $d_gb;
    $daily_up_gb[] = $u_gb;

    $daily_table_data[] = [
        'date' => date('d M Y (D)', strtotime($dr['log_date'])),
        'down' => formatUsageBytes((float)$dr['down_b']),
        'up' => formatUsageBytes((float)$dr['up_b']),
        'total' => formatUsageBytes((float)$dr['down_b'] + (float)$dr['up_b']),
        'sessions' => $dr['session_count'],
        'duration' => floor($dr['duration_sec'] / 3600) . 'h ' . floor(($dr['duration_sec'] % 3600) / 60) . 'm'
    ];
}
if (empty($daily_labels)) {
    $daily_labels = [date('d M')];
    $daily_down_gb = [0];
    $daily_up_gb = [0];
}

// 5. Weekly Usage (Current Cycle & Recent Weeks)
$wStmt = $pdo->prepare("SELECT 
    YEARWEEK(acctstarttime, 1) as yw,
    MIN(DATE(acctstarttime)) as week_start,
    MAX(DATE(acctstarttime)) as week_end,
    SUM(acctoutputoctets) as down_b,
    SUM(acctinputoctets) as up_b,
    COUNT(*) as session_count
FROM radacct
WHERE username = ? AND acctstarttime >= ?
GROUP BY yw
ORDER BY yw ASC");
$wStmt->execute([$username, $cycle_start]);
$weekly_rows = $wStmt->fetchAll(PDO::FETCH_ASSOC);

$weekly_labels = [];
$weekly_down_gb = [];
$weekly_up_gb = [];
$w_num = 1;
foreach ($weekly_rows as $wr) {
    $lbl = "W" . $w_num . " (" . date('d M', strtotime($wr['week_start'])) . " - " . date('d M', strtotime($wr['week_end'])) . ")";
    $weekly_labels[] = $lbl;
    $weekly_down_gb[] = round(((float)$wr['down_b']) / 1073741824, 2);
    $weekly_up_gb[] = round(((float)$wr['up_b']) / 1073741824, 2);
    $w_num++;
}
if (empty($weekly_labels)) {
    $weekly_labels = ["Current Week"];
    $weekly_down_gb = [0];
    $weekly_up_gb = [0];
}

// 6. Monthly Usage (Last 12 Months)
$mStmt = $pdo->prepare("SELECT 
    DATE_FORMAT(acctstarttime, '%Y-%m') as ym,
    SUM(acctoutputoctets) as down_b,
    SUM(acctinputoctets) as up_b,
    COUNT(*) as session_count
FROM radacct
WHERE username = ? AND acctstarttime >= NOW() - INTERVAL 12 MONTH
GROUP BY ym
ORDER BY ym ASC");
$mStmt->execute([$username]);
$monthly_rows = $mStmt->fetchAll(PDO::FETCH_ASSOC);

$monthly_labels = [];
$monthly_down_gb = [];
$monthly_up_gb = [];
foreach ($monthly_rows as $mr) {
    $monthly_labels[] = date('M Y', strtotime($mr['ym'] . '-01'));
    $monthly_down_gb[] = round(((float)$mr['down_b']) / 1073741824, 2);
    $monthly_up_gb[] = round(((float)$mr['up_b']) / 1073741824, 2);
}
if (empty($monthly_labels)) {
    $monthly_labels = [date('M Y')];
    $monthly_down_gb = [0];
    $monthly_up_gb = [0];
}

// 7. Recent Session Proof Table
$sessStmt = $pdo->prepare("SELECT 
    radacctid,
    acctstarttime,
    acctstoptime,
    acctsessiontime,
    acctoutputoctets,
    acctinputoctets,
    framedipaddress,
    acctterminatecause
FROM radacct
WHERE username = ?
ORDER BY radacctid DESC
LIMIT 12");
$sessStmt->execute([$username]);
$recent_sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    :root {
        --primary-blue: #2563eb;
        --primary-dark: #1e40af;
        --card-border: #eef2f6;
    }
    
    .usage-header-banner {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #3b82f6 100%);
        border-radius: 24px;
        color: #ffffff;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(37, 99, 235, 0.18);
    }
    .usage-header-banner::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 240px;
        height: 240px;
        background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .kpi-stat-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        padding: 22px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .kpi-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.06);
    }
    .kpi-icon-badge {
        width: 50px;
        height: 50px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
    }

    /* Segmented Control Buttons */
    .btn-period-pill {
        border-radius: 20px;
        padding: 7px 18px;
        font-size: 0.82rem;
        font-weight: 600;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        transition: all 0.2s ease;
    }
    .btn-period-pill:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    .btn-period-pill.active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }

    .card-clean-shell {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 22px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        overflow: hidden;
    }

    .proof-table thead th {
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        font-weight: 700;
        color: #64748b;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 12px 16px;
    }
    .proof-table tbody td {
        font-size: 0.85rem;
        padding: 13px 16px;
        color: #334155;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }
</style>

<!-- Top Breadcrumb & Actions Bar -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none text-muted"><i class="fa-solid fa-gauge me-1"></i> Dashboard</a></li>
                <li class="breadcrumb-item active text-primary fw-bold" aria-current="page">Usage &amp; Speeds</li>
            </ol>
        </nav>
        <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <span>Internet Usage &amp; Speed Analytics</span>
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fs-7">Self-Care</span>
        </h4>
    </div>
    <div class="d-flex gap-2">
        <a href="dashboard" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
        </a>
        <button type="button" onclick="window.print()" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold d-none d-md-inline-flex align-items-center gap-1">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- Header Banner Card -->
<div class="usage-header-banner p-4 p-md-5 mb-4">
    <div class="row align-items-center g-4">
        <div class="col-lg-8">
            <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-15 px-3 py-1 rounded-pill mb-3" style="backdrop-filter: blur(8px); font-size: 0.8rem;">
                <i class="fa-solid fa-wifi text-warning"></i>
                <span class="fw-semibold">Plan: <strong><?= htmlspecialchars($current_user['package_name'] ?? 'Unlimited') ?></strong> (<?= htmlspecialchars($current_user['rate_limit'] ?? 'Full Speed') ?>)</span>
            </div>
            <h2 class="fw-bold text-white mb-2" style="font-size: clamp(1.4rem, 2.5vw, 2rem);">
                Data Consumption &amp; Performance Record
            </h2>
            <p class="text-white-50 mb-3" style="max-width: 620px; font-size: 0.95rem;">
                Here is your complete internet performance record. Every byte transferred, peak speed reached, and connection uptime is tracked live from the network router.
            </p>
            <div class="d-flex align-items-center gap-2 flex-wrap text-white small">
                <span class="badge bg-white text-dark fw-bold px-3 py-1.5 rounded-pill shadow-sm">
                    <i class="fa-solid fa-calendar-check text-primary me-1"></i> Current Cycle: Since <?= date('d M Y', strtotime($cycle_start)) ?>
                </span>
                <span class="text-white-50" style="font-size: 0.82rem;">
                    <i class="fa-solid fa-arrows-rotate me-1"></i> Resets automatically upon package renewal.
                </span>
            </div>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="p-3.5 rounded-4 d-inline-block text-start border border-white border-opacity-25" style="background: rgba(255,255,255,0.12); backdrop-filter: blur(12px); min-width: 250px;">
                <div class="small text-white-50 text-uppercase fw-bold mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">Current Cycle Volume</div>
                <div class="fs-1 fw-bold text-white mb-2" style="line-height: 1.1;">
                    <?= formatUsageBytes($cycle_total_bytes) ?>
                </div>
                <div class="text-white-50 small d-flex justify-content-between border-top border-white border-opacity-20 pt-2" style="font-size: 0.78rem;">
                    <span><i class="fa-solid fa-arrow-down text-info me-1"></i> <?= formatUsageBytes($cycle_down_bytes) ?></span>
                    <span><i class="fa-solid fa-arrow-up text-warning me-1"></i> <?= formatUsageBytes($cycle_up_bytes) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 4 KPI Summary Cards (Peak Download, Peak Upload, Consumed Data, Connected Time) -->
<div class="row g-3 mb-4">
    <!-- Peak Download Speed Card -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-stat-card border-top border-4 border-info">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-circle-down text-info me-1"></i> Peak Download Speed
                    </div>
                    <div class="d-flex align-items-baseline gap-1 mt-1">
                        <span class="fs-2 fw-bolder font-monospace text-info"><?= $peak_down_mbps ?></span>
                        <span class="fw-bold small text-info">Mbps</span>
                    </div>
                </div>
                <div class="kpi-icon-badge bg-info bg-opacity-10 text-info">
                    <i class="fa-solid fa-bolt-lightning"></i>
                </div>
            </div>
            <div class="pt-2 border-top border-light-subtle">
                <div class="text-dark fw-bold small mb-0.5 d-flex align-items-center gap-1" style="font-size: 0.78rem;">
                    <i class="fa-regular fa-clock text-secondary"></i>
                    <span>Recorded: <?= $peak_down_time_str ?></span>
                </div>
                <?php if (!empty($peak_down_vol_str)): ?>
                    <div class="text-muted small" style="font-size: 0.72rem;">Session Volume: <strong><?= $peak_down_vol_str ?></strong> transferred</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Peak Upload Speed Card -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-stat-card border-top border-4" style="border-top-color: #8b5cf6 !important;">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-circle-up me-1" style="color: #8b5cf6;"></i> Peak Upload Speed
                    </div>
                    <div class="d-flex align-items-baseline gap-1 mt-1">
                        <span class="fs-2 fw-bolder font-monospace" style="color: #8b5cf6;"><?= $peak_up_mbps ?></span>
                        <span class="fw-bold small" style="color: #8b5cf6;">Mbps</span>
                    </div>
                </div>
                <div class="kpi-icon-badge" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6;">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                </div>
            </div>
            <div class="pt-2 border-top border-light-subtle">
                <div class="text-dark fw-bold small mb-0.5 d-flex align-items-center gap-1" style="font-size: 0.78rem;">
                    <i class="fa-regular fa-clock text-secondary"></i>
                    <span>Recorded: <?= $peak_up_time_str ?></span>
                </div>
                <?php if (!empty($peak_up_vol_str)): ?>
                    <div class="text-muted small" style="font-size: 0.72rem;">Session Volume: <strong><?= $peak_up_vol_str ?></strong> uploaded</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Total Consumed in Cycle -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-stat-card border-top border-4 border-success">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-chart-pie text-success me-1"></i> Data Consumed
                    </div>
                    <div class="fs-2 fw-bolder text-success mt-1">
                        <?= formatUsageBytes($cycle_total_bytes) ?>
                    </div>
                </div>
                <div class="kpi-icon-badge bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-chart-column"></i>
                </div>
            </div>
            <div class="pt-2 border-top border-light-subtle">
                <div class="d-flex justify-content-between small text-muted" style="font-size: 0.78rem;">
                    <span>Down: <strong class="text-dark"><?= formatUsageBytes($cycle_down_bytes) ?></strong></span>
                    <span>Up: <strong class="text-dark"><?= formatUsageBytes($cycle_up_bytes) ?></strong></span>
                </div>
                <div class="text-muted small mt-0.5" style="font-size: 0.72rem;">Current Package Cycle Consumption</div>
            </div>
        </div>
    </div>

    <!-- Online Session Time -->
    <div class="col-xl-3 col-md-6">
        <div class="kpi-stat-card border-top border-4 border-primary">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-regular fa-hourglass-half text-primary me-1"></i> Online Time
                    </div>
                    <div class="fs-2 fw-bolder text-primary mt-1">
                        <?= $cycle_hours ?>h <?= $cycle_mins ?>m
                    </div>
                </div>
                <div class="kpi-icon-badge bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
            </div>
            <div class="pt-2 border-top border-light-subtle">
                <div class="text-dark fw-bold small mb-0.5" style="font-size: 0.78rem;">
                    <span><?= $cycle_sessions_count ?> Connected Sessions</span>
                </div>
                <div class="text-muted small" style="font-size: 0.72rem;">Active internet uptime this cycle</div>
            </div>
        </div>
    </div>
</div>

<!-- Main Interactive Usage Graphs Section -->
<div class="card-clean-shell p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3 pb-3 border-bottom">
        <div>
            <h5 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="fa-solid fa-chart-area text-primary"></i>
                <span>Usage Consumption Graph</span>
            </h5>
            <small class="text-muted" style="font-size: 0.8rem;">Switch between Hourly, Daily, Weekly, and Monthly breakdown</small>
        </div>
        
        <!-- Period Switcher Pills -->
        <div class="d-flex gap-1.5 flex-wrap" role="group">
            <button type="button" class="btn-period-pill" onclick="switchUsageView('hourly', this)">
                <i class="fa-regular fa-clock me-1"></i> Hourly (24h)
            </button>
            <button type="button" class="btn-period-pill active" onclick="switchUsageView('daily', this)">
                <i class="fa-regular fa-calendar-days me-1"></i> Daily (Cycle)
            </button>
            <button type="button" class="btn-period-pill" onclick="switchUsageView('weekly', this)">
                <i class="fa-solid fa-chart-simple me-1"></i> Weekly
            </button>
            <button type="button" class="btn-period-pill" onclick="switchUsageView('monthly', this)">
                <i class="fa-solid fa-calendar-week me-1"></i> Monthly
            </button>
        </div>
    </div>

    <!-- Graph Canvas Container -->
    <div style="position: relative; height: 350px; width: 100%;">
        <canvas id="customerUsageChart"></canvas>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3 pt-3 border-top">
        <div class="d-flex align-items-center gap-4 text-muted small" style="font-size: 0.8rem;">
            <div class="d-flex align-items-center gap-1.5">
                <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: #2563eb;"></span>
                <span>Download Volume</span>
            </div>
            <div class="d-flex align-items-center gap-1.5">
                <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: #8b5cf6;"></span>
                <span>Upload Volume</span>
            </div>
        </div>
        <div id="chartUnitsBadge" class="badge bg-light text-secondary border px-2.5 py-1" style="font-size: 0.74rem;">
            Unit: Gigabytes (GB)
        </div>
    </div>
</div>

<!-- Two Column Row: Day-by-Day Breakdown Table & Recent Connection Proof -->
<div class="row g-4">
    <!-- Left Column: Day-by-Day Breakdown -->
    <div class="col-lg-6">
        <div class="card-clean-shell h-100">
            <div class="p-3.5 px-4 border-bottom bg-light bg-opacity-50 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-calendar-day text-primary"></i>
                    <span>Day-by-Day Usage (Current Cycle)</span>
                </h6>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                    <?= count($daily_table_data) ?> Days Active
                </span>
            </div>
            
            <div class="table-responsive" style="max-height: 440px; overflow-y: auto;">
                <?php if (empty($daily_table_data)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-regular fa-folder-open fs-2 mb-2 text-muted"></i>
                        <p class="mb-0 small">No usage recorded yet in the current package cycle.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-hover proof-table mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Download</th>
                                <th>Upload</th>
                                <th>Total</th>
                                <th>Online</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($daily_table_data) as $row): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($row['date']) ?></td>
                                    <td class="text-info fw-semibold font-monospace"><?= htmlspecialchars($row['down']) ?></td>
                                    <td class="fw-semibold font-monospace" style="color: #8b5cf6;"><?= htmlspecialchars($row['up']) ?></td>
                                    <td><span class="badge bg-light text-dark border fw-bold"><?= htmlspecialchars($row['total']) ?></span></td>
                                    <td class="text-muted small"><?= htmlspecialchars($row['duration']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Recent Verified Connection Sessions (Proof of Service) -->
    <div class="col-lg-6">
        <div class="card-clean-shell h-100">
            <div class="p-3.5 px-4 border-bottom bg-light bg-opacity-50 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-success"></i>
                    <span>Verified Session Logs (Proof of Service)</span>
                </h6>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                    Router Logs
                </span>
            </div>
            
            <div class="table-responsive" style="max-height: 440px; overflow-y: auto;">
                <?php if (empty($recent_sessions)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa-solid fa-server fs-2 mb-2 text-muted"></i>
                        <p class="mb-0 small">No session records found.</p>
                    </div>
                <?php else: ?>
                    <table class="table table-hover proof-table mb-0">
                        <thead>
                            <tr>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Duration</th>
                                <th>Data Used</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_sessions as $sess): ?>
                                <?php 
                                    $is_live = empty($sess['acctstoptime']) || $sess['acctstoptime'] === '0000-00-00 00:00:00';
                                    $dur_sec = (int)($sess['acctsessiontime'] ?? 0);
                                    $d_h = floor($dur_sec / 3600);
                                    $d_m = floor(($dur_sec % 3600) / 60);
                                    $sess_tot = (float)($sess['acctoutputoctets'] ?? 0) + (float)($sess['acctinputoctets'] ?? 0);
                                ?>
                                <tr>
                                    <td class="small fw-semibold text-dark">
                                        <?= !empty($sess['acctstarttime']) ? date('d M, h:i A', strtotime($sess['acctstarttime'])) : 'N/A' ?>
                                    </td>
                                    <td class="small">
                                        <?php if ($is_live): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5 fw-bold">Active Now</span>
                                        <?php else: ?>
                                            <?= date('d M, h:i A', strtotime($sess['acctstoptime'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted font-monospace"><?= "{$d_h}h {$d_m}m" ?></td>
                                    <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace"><?= formatUsageBytes($sess_tot) ?></span></td>
                                    <td class="small text-muted font-monospace"><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript to Initialize Chart.js with Hourly, Daily, Weekly, and Monthly Data -->
<script>
const usageDatasets = {
    hourly: {
        labels: <?= json_encode($hourly_labels) ?>,
        down: <?= json_encode($hourly_down) ?>,
        up: <?= json_encode($hourly_up) ?>,
        unit: 'Megabytes (MB)',
        chartType: 'bar'
    },
    daily: {
        labels: <?= json_encode($daily_labels) ?>,
        down: <?= json_encode($daily_down_gb) ?>,
        up: <?= json_encode($daily_up_gb) ?>,
        unit: 'Gigabytes (GB)',
        chartType: 'bar'
    },
    weekly: {
        labels: <?= json_encode($weekly_labels) ?>,
        down: <?= json_encode($weekly_down_gb) ?>,
        up: <?= json_encode($weekly_up_gb) ?>,
        unit: 'Gigabytes (GB)',
        chartType: 'bar'
    },
    monthly: {
        labels: <?= json_encode($monthly_labels) ?>,
        down: <?= json_encode($monthly_down_gb) ?>,
        up: <?= json_encode($monthly_up_gb) ?>,
        unit: 'Gigabytes (GB)',
        chartType: 'bar'
    }
};

let currentViewKey = 'daily';
let usageChart = null;

function renderUsageChart(viewKey) {
    const dataObj = usageDatasets[viewKey];
    if (!dataObj) return;

    const ctx = document.getElementById('customerUsageChart').getContext('2d');
    
    // Create subtle gradients
    let gradDown = ctx.createLinearGradient(0, 0, 0, 300);
    gradDown.addColorStop(0, 'rgba(37, 99, 235, 0.85)');
    gradDown.addColorStop(1, 'rgba(37, 99, 235, 0.15)');

    let gradUp = ctx.createLinearGradient(0, 0, 0, 300);
    gradUp.addColorStop(0, 'rgba(139, 92, 246, 0.85)');
    gradUp.addColorStop(1, 'rgba(139, 92, 246, 0.15)');

    if (usageChart) {
        usageChart.destroy();
    }

    usageChart = new Chart(ctx, {
        type: dataObj.chartType,
        data: {
            labels: dataObj.labels,
            datasets: [
                {
                    label: 'Download (' + dataObj.unit + ')',
                    data: dataObj.down,
                    backgroundColor: gradDown,
                    borderColor: '#2563eb',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    maxBarThickness: 36
                },
                {
                    label: 'Upload (' + dataObj.unit + ')',
                    data: dataObj.up,
                    backgroundColor: gradUp,
                    borderColor: '#8b5cf6',
                    borderWidth: 1.5,
                    borderRadius: 6,
                    maxBarThickness: 36
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.92)',
                    titleColor: '#ffffff',
                    bodyColor: '#e2e8f0',
                    padding: 12,
                    cornerRadius: 10,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + ' ' + (dataObj.unit.includes('MB') ? 'MB' : 'GB');
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#64748b',
                        font: {
                            size: 11,
                            weight: '500'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(226, 232, 240, 0.6)'
                    },
                    ticks: {
                        color: '#64748b',
                        font: {
                            size: 11
                        },
                        callback: function(val) {
                            return val + (dataObj.unit.includes('MB') ? ' MB' : ' GB');
                        }
                    }
                }
            }
        }
    });

    document.getElementById('chartUnitsBadge').innerText = 'Unit: ' + dataObj.unit;
}

function switchUsageView(viewKey, btnEl) {
    currentViewKey = viewKey;
    document.querySelectorAll('.btn-period-pill').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    renderUsageChart(viewKey);
}

// Initial Render on page load
document.addEventListener('DOMContentLoaded', function() {
    renderUsageChart('daily');
});
</script>

<?php require_once 'footer.php'; ?>
