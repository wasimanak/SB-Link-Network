<?php
require_once 'header.php';

// Access Control
if (empty($_SESSION['operator_logged_in']) || empty($_SESSION['operator_id'])) {
    header("Location: ../login");
    exit;
}

$client_id = (int)$_SESSION['operator_id'];

// 1. AUTO-PRUNE: Strictly retain the latest 500 records in radpostauth; prune older
try {
    $minIdStmt = $pdo->query("SELECT id FROM radpostauth ORDER BY id DESC LIMIT 1 OFFSET 499");
    $minId = $minIdStmt->fetchColumn();
    if ($minId) {
        $delStmt = $pdo->prepare("DELETE FROM radpostauth WHERE id < ?");
        $delStmt->execute([$minId]);
    }
} catch (Throwable $ePrune) {}

// 2. Clear All Logs Action
$flash_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_all_logs') {
    try {
        $pdo->exec("TRUNCATE TABLE radpostauth");
        $flash_msg = '<div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-3 mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2 text-success fs-5 align-middle"></i>
            <strong>Success!</strong> All authentication logs have been cleared successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>';
    } catch (Throwable $eClr) {
        $flash_msg = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 rounded-3 mb-4" role="alert">
            <i class="fa-solid fa-circle-exclamation me-2 text-danger fs-5 align-middle"></i>
            <strong>Error:</strong> ' . htmlspecialchars($eClr->getMessage()) . '
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>';
    }
}

// 3. Helper for Relative Time (e.g. "5s ago", "2m ago")
if (!function_exists('time_ago_str')) {
    function time_ago_str($timestamp_str) {
        if (empty($timestamp_str)) return '';
        $ts = function_exists('parse_safe_timestamp') ? parse_safe_timestamp($timestamp_str) : strtotime($timestamp_str);
        if (!$ts) return '';
        $diff = time() - $ts;
        if ($diff < 5) return 'Just now';
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        return floor($diff / 86400) . 'd ago';
    }
}

// 4. Fetch the latest 500 Authentication Logs from FreeRADIUS radpostauth
$logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT rp.id, rp.username, rp.pass, rp.reply, rp.authdate,
               s.id as sub_id, s.full_name, s.status as sub_status, s.expiry_date,
               s.password as registered_pass,
               p.name as package_name
        FROM radpostauth rp
        LEFT JOIN subscribers s ON LOWER(TRIM(rp.username)) = LOWER(TRIM(s.username))
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.client_id = ? OR s.client_id IS NULL)
        ORDER BY rp.id DESC
        LIMIT 500
    ");
    $stmt->execute([$client_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e1) {
    try {
        $stmt = $pdo->prepare("
            SELECT rp.id, rp.username, rp.pass, rp.reply, rp.authdate,
                   s.id as sub_id, s.full_name, s.status as sub_status, s.expiry_date,
                   s.password as registered_pass,
                   p.name as package_name
            FROM radpostauth rp
            LEFT JOIN subscribers s ON CONVERT(rp.username USING utf8mb4) = CONVERT(s.username USING utf8mb4)
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE (s.client_id = ? OR s.client_id IS NULL)
            ORDER BY rp.id DESC
            LIMIT 500
        ");
        $stmt->execute([$client_id]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        try {
            $stmt = $pdo->query("SELECT id, username, pass, reply, authdate FROM radpostauth ORDER BY id DESC LIMIT 500");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e3) {
            $logs = [];
        }
    }
}

// 5. Compute Stats & Metrics
$total_count = count($logs);
$accept_count = 0;
$reject_count = 0;
$unique_users = [];

foreach ($logs as $l) {
    $reply_clean = trim($l['reply'] ?? '');
    if (strcasecmp($reply_clean, 'Access-Accept') === 0) {
        $accept_count++;
    } elseif (strcasecmp($reply_clean, 'Access-Reject') === 0) {
        $reject_count++;
    }
    $u_lower = strtolower(trim($l['username'] ?? ''));
    if (!empty($u_lower)) {
        $unique_users[$u_lower] = true;
    }
}
$unique_user_count = count($unique_users);

// 6. Return JSON if AJAX polling request
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'total' => $total_count,
        'accepted' => $accept_count,
        'rejected' => $reject_count,
        'unique_users' => $unique_user_count,
        'logs' => $logs
    ]);
    exit;
}
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
    .kpi-card {
        border-radius: 16px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        border: 1px solid rgba(0,0,0,0.06);
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08) !important;
    }
    .table-auth-logs thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e2e8f0;
        padding-top: 12px;
        padding-bottom: 12px;
    }
    .table-auth-logs tbody td {
        vertical-align: middle;
        font-size: 0.88rem;
        color: #1e293b;
        border-bottom: 1px solid #f1f5f9;
        padding-top: 10px;
        padding-bottom: 10px;
    }
    .badge-accept {
        background-color: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
        font-weight: 600;
    }
    .badge-reject {
        background-color: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
        font-weight: 600;
    }
    .pass-masked {
        font-family: monospace;
        letter-spacing: 1px;
    }
    .dt-buttons .btn {
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 5px 14px;
        margin-right: 5px;
    }
</style>

<div class="container-fluid px-3 px-md-4 py-4">
    
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="subscribers" class="text-decoration-none">Users Profile</a></li>
            <li class="breadcrumb-item active" aria-current="page">Auth Logs</li>
        </ol>
    </nav>

    <?= $flash_msg ?>

    <!-- Page Title & Header Actions -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-fingerprint text-warning me-2"></i> MikroTik Authentication Logs
            </h3>
            <p class="text-muted mb-0 small">
                Live FreeRADIUS authentication attempts sent by MikroTik routers. Showing max <strong>500 logs</strong> (older records auto-pruned).
            </p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Live Auto Refresh Toggle -->
            <div class="form-check form-switch bg-white border rounded-pill px-3 py-2 shadow-sm d-flex align-items-center mb-0">
                <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="autoRefreshSwitch">
                <label class="form-check-label small fw-semibold text-secondary mb-0" for="autoRefreshSwitch" style="cursor: pointer;">
                    <i class="fa-solid fa-arrows-rotate me-1 text-primary" id="autoRefreshIcon"></i> Live Auto-Refresh (5s)
                </label>
            </div>

            <!-- Manual Refresh -->
            <button type="button" class="btn btn-outline-primary bg-white rounded-pill px-3 shadow-sm fw-semibold" onclick="window.location.reload();">
                <i class="fa-solid fa-rotate-right me-1"></i> Refresh
            </button>

            <!-- Clear All Logs Modal Trigger -->
            <button type="button" class="btn btn-outline-danger bg-white rounded-pill px-3 shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#clearLogsModal">
                <i class="fa-solid fa-trash-can me-1"></i> Clear Logs
            </button>
        </div>
    </div>

    <!-- KPI Metric Cards (Max 500 Scope) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card kpi-card bg-white shadow-sm p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width: 48px; height: 48px;">
                        <i class="fa-solid fa-list-ol fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase">Total Captured</div>
                        <div class="fs-4 fw-bold text-dark" id="statTotal"><?= number_format($total_count) ?> <span class="fs-6 text-muted fw-normal">/ 500</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card kpi-card bg-white shadow-sm p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success flex-shrink-0" style="width: 48px; height: 48px;">
                        <i class="fa-solid fa-circle-check fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase">Access Accepted</div>
                        <div class="fs-4 fw-bold text-success" id="statAccepted"><?= number_format($accept_count) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card kpi-card bg-white shadow-sm p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger flex-shrink-0" style="width: 48px; height: 48px;">
                        <i class="fa-solid fa-circle-xmark fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase">Access Rejected</div>
                        <div class="fs-4 fw-bold text-danger" id="statRejected"><?= number_format($reject_count) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="card kpi-card bg-white shadow-sm p-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning flex-shrink-0" style="width: 48px; height: 48px;">
                        <i class="fa-solid fa-users-viewfinder fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold text-uppercase">Unique Users</div>
                        <div class="fs-4 fw-bold text-dark" id="statUnique"><?= number_format($unique_user_count) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Buttons Bar & Global Reveal Toggle -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <!-- Status Filter Pills -->
                <div class="btn-group rounded-pill p-1 bg-light border" role="group">
                    <button type="button" class="btn btn-sm rounded-pill px-3 fw-semibold active btn-primary" id="btnFilterAll" onclick="filterByReply('')">
                        All Logs (<?= $total_count ?>)
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill px-3 fw-semibold text-success btn-light" id="btnFilterAccept" onclick="filterByReply('Access-Accept')">
                        <i class="fa-solid fa-check me-1"></i> Accepted (<?= $accept_count ?>)
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill px-3 fw-semibold text-danger btn-light" id="btnFilterReject" onclick="filterByReply('Access-Reject')">
                        <i class="fa-solid fa-xmark me-1"></i> Rejected (<?= $reject_count ?>)
                    </button>
                </div>

                <!-- Global Password Visibility Toggle -->
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" id="toggleGlobalPassBtn" onclick="toggleAllPasswords()">
                        <i class="fa-regular fa-eye me-1" id="globalEyeIcon"></i> Show All Passwords
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Logs DataTable Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-body p-0 p-md-3">
            <div class="table-responsive">
                <table class="table table-hover table-auth-logs align-middle mb-0 w-100" id="authLogsTable">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width: 80px;">Log ID</th>
                            <th>Username</th>
                            <th>Sent Password</th>
                            <th>Auth Status</th>
                            <th>Subscriber Details</th>
                            <th class="text-end pe-3">Request Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $is_accept = (strcasecmp(trim($log['reply'] ?? ''), 'Access-Accept') === 0);
                            $is_reject = (strcasecmp(trim($log['reply'] ?? ''), 'Access-Reject') === 0);
                            
                            $sent_pass = trim($log['pass'] ?? '');
                            $reg_pass = trim($log['registered_pass'] ?? '');
                            $display_pass = !empty($sent_pass) ? $sent_pass : (!empty($reg_pass) ? $reg_pass : '—');
                            $is_registered_fallback = empty($sent_pass) && !empty($reg_pass);
                            
                            // Check mismatch
                            $has_mismatch = (!empty($sent_pass) && !empty($reg_pass) && $sent_pass !== $reg_pass);

                            $raw_date = $log['authdate'] ?? '';
                            $formatted_date = function_exists('safe_date') ? safe_date($raw_date, 'd M Y, h:i:s A') : date('d M Y, h:i:s A', strtotime($raw_date));
                            $rel_time = time_ago_str($raw_date);
                        ?>
                        <tr>
                            <!-- 1. Log ID -->
                            <td class="ps-3 fw-bold text-secondary">
                                #<?= htmlspecialchars($log['id']) ?>
                            </td>

                            <!-- 2. Username -->
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-dark" style="font-size: 0.95rem;">
                                        <?= htmlspecialchars($log['username']) ?>
                                    </span>
                                    <button class="btn btn-sm btn-link text-muted p-0" title="Copy Username" onclick="navigator.clipboard.writeText('<?= addslashes($log['username']) ?>'); showToast('Username copied!');">
                                        <i class="fa-regular fa-copy" style="font-size: 0.75rem;"></i>
                                    </button>
                                </div>
                                <?php if (!empty($log['full_name'])): ?>
                                    <div class="text-muted" style="font-size: 0.76rem;"><?= htmlspecialchars($log['full_name']) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- 3. Password Sent by MikroTik -->
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="pass-text pass-masked fw-semibold text-secondary" data-pass="<?= htmlspecialchars($display_pass) ?>">
                                        ••••••••
                                    </span>
                                    <?php if ($display_pass !== '—'): ?>
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle p-0 d-inline-flex align-items-center justify-content-center" style="width: 24px; height: 24px;" onclick="toggleSinglePass(this)" title="Toggle Password">
                                        <i class="fa-regular fa-eye text-muted" style="font-size: 0.7rem;"></i>
                                    </button>
                                    <?php endif; ?>

                                    <?php if ($is_registered_fallback): ?>
                                        <span class="badge bg-light text-secondary border" style="font-size: 0.68rem;" title="Password retrieved from subscriber registration record">Registered</span>
                                    <?php endif; ?>

                                    <?php if ($has_mismatch): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size: 0.68rem;" title="Warning: Password sent by user does not match registered subscriber password!">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Mismatch
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 4. Auth Status / Reply -->
                            <td>
                                <?php if ($is_accept): ?>
                                    <span class="badge badge-accept px-2 py-1 rounded-pill d-inline-flex align-items-center">
                                        <i class="fa-solid fa-circle-check me-1 fs-6"></i> Access-Accept
                                    </span>
                                <?php elseif ($is_reject): ?>
                                    <span class="badge badge-reject px-2 py-1 rounded-pill d-inline-flex align-items-center">
                                        <i class="fa-solid fa-circle-xmark me-1 fs-6"></i> Access-Reject
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-1 rounded-pill">
                                        <?= htmlspecialchars($log['reply'] ?: 'Unknown') ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- 5. Subscriber Details -->
                            <td>
                                <?php if (!empty($log['sub_id'])): ?>
                                    <div class="d-flex align-items-center gap-1">
                                        <a href="subscriber_view?id=<?= $log['sub_id'] ?>" class="text-decoration-none fw-semibold text-primary">
                                            <?= htmlspecialchars($log['package_name'] ?: 'Sub #' . $log['sub_id']) ?>
                                        </a>
                                        <?php if (!empty($log['sub_status'])): 
                                            $st_badge = ($log['sub_status'] === 'active') ? 'bg-success' : (($log['sub_status'] === 'expired') ? 'bg-danger' : 'bg-warning text-dark');
                                        ?>
                                            <span class="badge <?= $st_badge ?> ms-1" style="font-size: 0.65rem;"><?= ucfirst($log['sub_status']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border" style="font-size: 0.72rem;">Unknown User / Not Registered</span>
                                <?php endif; ?>
                            </td>

                            <!-- 6. Request Date & Time -->
                            <td class="text-end pe-3">
                                <div class="fw-bold text-dark font-monospace" style="font-size: 0.82rem;">
                                    <?= $formatted_date ?>
                                </div>
                                <?php if (!empty($rel_time)): ?>
                                    <span class="badge bg-light text-muted border" style="font-size: 0.7rem;">
                                        <i class="fa-regular fa-clock me-1"></i><?= $rel_time ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-fingerprint fs-1 d-block mb-3 opacity-25"></i>
                                <h5>No Authentication Requests Found</h5>
                                <p class="small mb-0">MikroTik routers have not sent any PPPoE authentication packets to FreeRADIUS recently.</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Clear Logs Confirmation Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Clear Authentication Logs
                </h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-3">
                <p class="text-secondary mb-2">
                    Kya aap waqai tamam past RADIUS authentication logs ko clear karna chahte hain?
                </p>
                <div class="alert alert-light border rounded-3 p-3 small text-muted mb-0">
                    <i class="fa-solid fa-info-circle text-primary me-1"></i> <strong>Note:</strong> Yeh sirf <code>radpostauth</code> history table ko flush karega. Kisi subscriber ka internet ya active connection disconnect nahi hoga.
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <form method="POST">
                    <input type="hidden" name="action" value="clear_all_logs">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-trash-can me-1"></i> Yes, Clear All Logs
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1090">
    <div id="authToast" class="toast align-items-center text-white bg-dark border-0 rounded-3 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body small fw-semibold" id="toastMsg">Action completed.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Scripts: jQuery, DataTables, Buttons -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
let dataTableInstance = null;
let allPasswordsRevealed = false;
let autoRefreshTimer = null;

$(document).ready(function() {
    // Initialize DataTable
    dataTableInstance = $('#authLogsTable').DataTable({
        pageLength: 50,
        lengthMenu: [[25, 50, 100, 250, 500], [25, 50, 100, 250, 500]],
        order: [[0, 'desc']], // Sort by ID descending
        responsive: true,
        dom: "<'row mb-3'<'col-sm-12 col-md-6 d-flex align-items-center gap-2'B><'col-sm-12 col-md-6 d-flex justify-content-md-end align-items-center gap-2'f l>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row mt-3'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [
            { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn-light border' },
            { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn-light border' }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search username, status, details...",
            lengthMenu: "_MENU_ entries"
        }
    });

    // Auto Refresh Switch Event
    $('#autoRefreshSwitch').on('change', function() {
        if ($(this).is(':checked')) {
            $('#autoRefreshIcon').addClass('fa-spin');
            showToast('Live Auto-Refresh enabled (5s)');
            autoRefreshTimer = setInterval(function() {
                window.location.reload();
            }, 5000);
        } else {
            $('#autoRefreshIcon').removeClass('fa-spin');
            showToast('Auto-Refresh disabled');
            if (autoRefreshTimer) clearInterval(autoRefreshTimer);
        }
    });
});

// Single Password Toggle
function toggleSinglePass(btn) {
    let container = $(btn).closest('td').find('.pass-text');
    let realPass = container.attr('data-pass');
    let icon = $(btn).find('i');
    
    if (container.text().trim() === '••••••••') {
        container.text(realPass);
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
    } else {
        container.text('••••••••');
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
    }
}

// Global Toggle All Passwords
function toggleAllPasswords() {
    allPasswordsRevealed = !allPasswordsRevealed;
    let icon = $('#globalEyeIcon');
    let btn = $('#toggleGlobalPassBtn');

    if (allPasswordsRevealed) {
        $('.pass-text').each(function() {
            let pass = $(this).attr('data-pass');
            if (pass && pass !== '—') {
                $(this).text(pass);
            }
        });
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
        btn.html('<i class="fa-regular fa-eye-slash me-1"></i> Hide All Passwords');
    } else {
        $('.pass-text').each(function() {
            let pass = $(this).attr('data-pass');
            if (pass && pass !== '—') {
                $(this).text('••••••••');
            }
        });
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
        btn.html('<i class="fa-regular fa-eye me-1"></i> Show All Passwords');
    }
}

// Filter by Status (Accepted / Rejected)
function filterByReply(replyStr) {
    $('#btnFilterAll, #btnFilterAccept, #btnFilterReject').removeClass('active btn-primary').addClass('btn-light');
    if (replyStr === '') {
        $('#btnFilterAll').addClass('active btn-primary').removeClass('btn-light');
    } else if (replyStr === 'Access-Accept') {
        $('#btnFilterAccept').addClass('active btn-primary').removeClass('btn-light');
    } else if (replyStr === 'Access-Reject') {
        $('#btnFilterReject').addClass('active btn-primary').removeClass('btn-light');
    }

    if (dataTableInstance) {
        dataTableInstance.column(3).search(replyStr).draw();
    }
}

// Show Toast
function showToast(msg) {
    $('#toastMsg').text(msg);
    let toastEl = document.getElementById('authToast');
    let toast = new bootstrap.Toast(toastEl, { delay: 2500 });
    toast.show();
}
</script>

<?php require_once 'footer.php'; ?>
