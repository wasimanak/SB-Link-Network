<?php
require_once 'header.php';

try {
    // Fetch last 150 authentication & session logs from radacct
    $stmt = $pdo->query("SELECT * FROM radacct ORDER BY radacctid DESC LIMIT 150");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div class='alert alert-danger m-4'>Error loading logs. Make sure radacct table exists.</div>");
}

// Compute metrics
$totalLogs = count($logs);
$countOnline = 0;
$countTerminated = 0;
$uniqueUsers = [];

foreach ($logs as $log) {
    if (empty($log['acctstoptime'])) {
        $countOnline++;
    } else {
        $countTerminated++;
    }
    $uniqueUsers[$log['username']] = true;
}
$uniqueUsersCount = count($uniqueUsers);

function formatLogBytes($bytes) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body {
        background-color: #f8fafc !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        color: #0f172a !important;
    }

    .card-ui {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        border: 1px solid #eef2f6;
    }

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
    }
    .stat-pill-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.45rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
    }
    .stat-pill-lbl {
        font-size: 0.74rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Filter Pills */
    .filter-pill-btn {
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        color: #64748b;
        font-size: 0.85rem;
        font-weight: 600;
        padding: 8px 18px;
        border-radius: 50px;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .filter-pill-btn:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #0f172a;
    }
    .filter-pill-btn.active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .filter-pill-btn.active .badge-count {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }
    .badge-count {
        background: #f1f5f9;
        color: #475569;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    /* Modern Table */
    .modern-table {
        margin-bottom: 0;
        width: 100% !important;
    }
    .modern-table thead th {
        background: #f8fafc !important;
        color: #475569 !important;
        font-size: 0.74rem !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        font-weight: 700 !important;
        padding: 14px 20px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        white-space: nowrap !important;
    }
    .modern-table tbody td {
        padding: 16px 20px !important;
        color: #1e293b !important;
        font-size: 0.88rem !important;
        border-bottom: 1px solid #f1f5f9 !important;
        vertical-align: middle !important;
    }
    .modern-table tbody tr:hover {
        background-color: #f8fafc !important;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none !important;
    }

    /* Copy Button */
    .btn-copy-chip {
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-copy-chip:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
</style>

<div class="container-fluid px-4 py-4">

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-file-shield"></i> FreeRADIUS Real-time Telemetry
                </div>
                <h3 class="fw-bold text-dark mb-1">System Accounting Logs</h3>
                <p class="text-muted small mb-0">Live FreeRADIUS accounting stream (radacct) showing subscriber sessions, NAS origins, and termination events.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <button class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm" onclick="location.reload()">
                    <i class="fa-solid fa-rotate-right me-1"></i> Refresh Stream
                </button>
            </div>
        </div>
    </div>

    <!-- 4 Stat Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Captured Sessions -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Captured Sessions</div>
                    <div class="stat-pill-val"><?= number_format($totalLogs) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Recent radacct events</div>
                </div>
            </div>
        </div>

        <!-- 2. Currently Active Sessions -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-wifi"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Active Online</div>
                    <div class="stat-pill-val">
                        <?= number_format($countOnline) ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 ms-1" style="font-size: 0.68rem; vertical-align: middle;">
                            <span class="spinner-grow spinner-grow-sm me-1" style="width:0.35rem; height:0.35rem;"></span>Live
                        </span>
                    </div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;">Unstopped connections</div>
                </div>
            </div>
        </div>

        <!-- 3. Closed / Disconnected -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fefce8; color: #ca8a04;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Terminated Sessions</div>
                    <div class="stat-pill-val"><?= number_format($countTerminated) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Completed accounting records</div>
                </div>
            </div>
        </div>

        <!-- 4. Distinct Users -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Distinct Users</div>
                    <div class="stat-pill-val"><?= number_format($uniqueUsersCount) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Unique active PPPoE/IPoE</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Pills Row -->
    <div class="d-flex align-items-center gap-2 mb-3 overflow-x-auto pb-1">
        <button type="button" class="filter-pill-btn active" onclick="filterLogs('all', this)">
            <span>All Logs</span>
            <span class="badge-count"><?= $totalLogs ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterLogs('online', this)">
            <span><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.6rem;"></i> Active Online</span>
            <span class="badge-count"><?= $countOnline ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterLogs('terminated', this)">
            <span><i class="fa-solid fa-circle text-secondary me-1" style="font-size: 0.6rem;"></i> Terminated</span>
            <span class="badge-count"><?= $countTerminated ?></span>
        </button>
    </div>

    <!-- Logs Table Card -->
    <div class="card-ui overflow-hidden mb-5">
        
        <!-- Loading Dialog State -->
        <div id="logsLoadingBox" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" style="width: 2.75rem; height: 2.75rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h6 class="fw-bold text-dark mb-1">Loading FreeRADIUS Logs...</h6>
            <div class="text-muted small">Parsing accounting packets, NAS IPs, and disconnect events</div>
        </div>

        <!-- Hidden Table Container until DataTables initComplete -->
        <div id="logsTableContainer" style="display: none;">
            <div class="table-responsive">
                <table class="modern-table" id="logsTable">
                    <thead>
                        <tr>
                            <th class="ps-4">Subscriber</th>
                            <th>NAS Gateway IP</th>
                            <th>Framed IP</th>
                            <th>Session Start</th>
                            <th>Status / Stop Time</th>
                            <th>Duration</th>
                            <th class="pe-4 text-end">Termination Cause</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $isOnline = empty($log['acctstoptime']);
                            $totalBytes = (float)($log['acctinputoctets'] ?? 0) + (float)($log['acctoutputoctets'] ?? 0);
                        ?>
                        <tr class="log-row" data-status="<?= $isOnline ? 'online' : 'terminated' ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center border" style="width: 34px; height: 34px; font-size: 0.82rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($log['username']) ?></div>
                                        <div class="text-muted font-monospace" style="font-size: 0.72rem;">Acct ID: #<?= $log['radacctid'] ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="d-inline-flex align-items-center gap-2 bg-light px-2 py-1 rounded-2 border">
                                    <span class="font-monospace text-dark small"><?= htmlspecialchars($log['nasipaddress']) ?></span>
                                    <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($log['nasipaddress']) ?>', this)">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>
                            </td>

                            <td>
                                <?php if(!empty($log['framedipaddress'])): ?>
                                    <span class="badge bg-light text-primary border rounded-pill px-3 py-1 font-monospace" style="font-size: 0.76rem;">
                                        <?= htmlspecialchars($log['framedipaddress']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="text-dark small fw-semibold"><?= date('d M Y', strtotime($log['acctstarttime'])) ?></div>
                                <div class="text-muted" style="font-size: 0.74rem;"><?= date('H:i:s', strtotime($log['acctstarttime'])) ?></div>
                            </td>

                            <td>
                                <?php if($isOnline): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <span class="spinner-grow spinner-grow-sm me-1" style="width:0.35rem; height:0.35rem;"></span>Online
                                    </span>
                                <?php else: ?>
                                    <div class="text-dark small"><?= date('d M Y', strtotime($log['acctstoptime'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.74rem;"><?= date('H:i:s', strtotime($log['acctstoptime'])) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if(!empty($log['acctsessiontime'])): ?>
                                    <span class="badge bg-light text-dark border rounded-pill px-2 py-1 font-monospace" style="font-size: 0.75rem;">
                                        <i class="fa-regular fa-clock text-muted me-1"></i><?= gmdate("H:i:s", $log['acctsessiontime']) ?>
                                    </span>
                                    <div class="text-muted mt-1" style="font-size: 0.72rem;">
                                        <?= formatLogBytes($totalBytes) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="pe-4 text-end">
                                <?php if(!empty($log['acctterminatecause'])): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($log['acctterminatecause']) ?>
                                    </span>
                                <?php elseif($isOnline): ?>
                                    <span class="text-success small fw-semibold"><i class="fa-solid fa-bolt me-1"></i>Active Stream</span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
var logTable;

$(document).ready(function() {
    logTable = $('#logsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[3, 'desc']],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search user, IP, cause...",
            lengthMenu: "Show _MENU_ entries"
        },
        initComplete: function() {
            $('#logsLoadingBox').fadeOut(120, function() {
                $('#logsTableContainer').fadeIn(180, function() {
                    logTable.columns.adjust().draw();
                });
            });
        }
    });
});

function filterLogs(status, btnElement) {
    $('.filter-pill-btn').removeClass('active');
    $(btnElement).addClass('active');

    if (status === 'all') {
        logTable.column(4).search('').draw();
    } else if (status === 'online') {
        logTable.column(4).search('Online').draw();
    } else if (status === 'terminated') {
        logTable.column(4).search('^(?!.*Online).*$', true, false).draw();
    }
}

function copyText(text, btnElement) {
    if (!navigator.clipboard) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    } else {
        navigator.clipboard.writeText(text);
    }
    
    var originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
    setTimeout(function() {
        btnElement.innerHTML = originalHTML;
    }, 2000);
}
</script>

<?php require_once 'footer.php'; ?>
