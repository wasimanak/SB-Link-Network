<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. AJAX Sync Endpoint (must run before header.php so output is pure JSON)
if (isset($_GET['action']) && $_GET['action'] === 'ajax_sync_mikrotik') {
    header('Content-Type: application/json');
    if (empty($_SESSION['operator_logged_in']) && empty($_SESSION['operator_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $client_id = (int)$_SESSION['operator_id'];
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/mikrotik_sync_helper.php';
    $res = sync_operator_mikrotik_active_sessions($pdo, $client_id);
    echo json_encode($res);
    exit;
}

// 1b. AJAX Endpoint for Recent Connection Requests / Auth Logs (radpostauth)
if (isset($_GET['action']) && $_GET['action'] === 'ajax_get_auth_logs') {
    header('Content-Type: application/json');
    if (empty($_SESSION['operator_logged_in']) && empty($_SESSION['operator_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    $client_id = (int)$_SESSION['operator_id'];
    require_once __DIR__ . '/../config/db.php';
    try {
        $logStmt = $pdo->prepare("
            SELECT rp.id, rp.username, rp.reply, rp.authdate, s.id as sub_id, s.full_name, p.name as package_name
            FROM radpostauth rp
            INNER JOIN subscribers s ON LOWER(TRIM(rp.username)) = LOWER(TRIM(s.username))
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE s.client_id = ?
            ORDER BY rp.id DESC
            LIMIT 60
        ");
        $logStmt->execute([$client_id]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'logs' => $logs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

require_once 'header.php';
$client_id = (int)$_SESSION['operator_id'];
require_once __DIR__ . '/../config/mikrotik_sync_helper.php';

// 2. Auto-Sync with MikroTik Router(s) (throttled to once every 20 seconds, or forced if ?sync=1)
$sync_feedback = null;
if (!isset($_SESSION['last_mt_session_sync']) || (time() - $_SESSION['last_mt_session_sync']) > 20 || isset($_GET['sync'])) {
    $sync_feedback = sync_operator_mikrotik_active_sessions($pdo, $client_id);
    $_SESSION['last_mt_session_sync'] = time();
}

// 3. Auto-Sync & Block Expired Users (runs once every 30 seconds)
if (!isset($_SESSION['last_expiry_sync']) || (time() - $_SESSION['last_expiry_sync']) > 30) {
    require_once __DIR__ . '/../config/radius_kick_helper.php';
    sync_expired_subscribers($pdo, $client_id, true);
    $_SESSION['last_expiry_sync'] = time();
}

function formatMikroTikUptime($seconds) {
    $seconds = max(0, (int)$seconds);
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    
    $out = "";
    if ($d > 0) $out .= $d . "d ";
    if ($h > 0 || $d > 0) $out .= $h . "h ";
    $out .= $m . "m";
    if ($d === 0 && $h === 0) $out .= " " . $s . "s";
    return trim($out);
}

// Get Live Sessions mapped strictly to this operator's subscribers with radpostauth correlation
$sql = "SELECT r.*, 
               s.id as subscriber_id, 
               s.package_id, 
               p.name as package_name, 
               s.expiry_date, 
               s.status as sub_status, 
               s.full_name, 
               s.mobile,
               -- 1. Check if our FreeRADIUS accepted this connection around session start (+/- 2 hours)
               (
                   SELECT COUNT(*) 
                   FROM radpostauth rp 
                   WHERE LOWER(TRIM(rp.username)) = LOWER(TRIM(r.username)) 
                     AND rp.reply = 'Access-Accept' 
                     AND rp.authdate >= IF(r.acctstarttime REGEXP '^[0-9]+$', FROM_UNIXTIME(r.acctstarttime), r.acctstarttime) - INTERVAL 2 HOUR 
                     AND rp.authdate <= IF(r.acctstarttime REGEXP '^[0-9]+$', FROM_UNIXTIME(r.acctstarttime), r.acctstarttime) + INTERVAL 2 HOUR
                   LIMIT 1
               ) as auth_accepted_by_us,
               -- 2. Latest reply from radpostauth for this user
               (
                   SELECT rp.reply 
                   FROM radpostauth rp 
                   WHERE LOWER(TRIM(rp.username)) = LOWER(TRIM(r.username)) 
                   ORDER BY rp.authdate DESC 
                   LIMIT 1
               ) as latest_auth_reply,
               -- 3. Latest auth date
               (
                   SELECT rp.authdate 
                   FROM radpostauth rp 
                   WHERE LOWER(TRIM(rp.username)) = LOWER(TRIM(r.username)) 
                   ORDER BY rp.authdate DESC 
                   LIMIT 1
               ) as latest_auth_date
        FROM radacct r 
        INNER JOIN (
            SELECT username, MAX(radacctid) as max_id 
            FROM radacct 
            WHERE acctstoptime IS NULL 
            GROUP BY username
        ) as latest ON r.radacctid = latest.max_id
        INNER JOIN subscribers s ON LOWER(TRIM(r.username)) = LOWER(TRIM(s.username)) COLLATE utf8mb4_general_ci
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ?
        ORDER BY r.acctstarttime DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$all_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count_total = count($all_sessions);
$count_our_radius = 0;
$count_other_radius = 0;
$count_expired_online = 0;

foreach ($all_sessions as $k => $s) {
    // Determine if authenticated by our FreeRADIUS
    $is_our = (!empty($s['auth_accepted_by_us']) && (int)$s['auth_accepted_by_us'] > 0) 
              || ($s['latest_auth_reply'] === 'Access-Accept');
    $is_exp = (!empty($s['sub_status']) && $s['sub_status'] === 'expired') 
              || (!empty($s['expiry_date']) && strtotime($s['expiry_date']) <= time());

    $all_sessions[$k]['is_our_radius'] = $is_our;
    $all_sessions[$k]['is_expired_online'] = $is_exp;

    if ($is_our) {
        $count_our_radius++;
    } else {
        $count_other_radius++;
    }
    if ($is_exp) {
        $count_expired_online++;
    }
}

// Current Filter
$active_filter = $_GET['filter'] ?? 'all';
$sessions = [];
foreach ($all_sessions as $s) {
    if ($active_filter === 'our_radius' && !$s['is_our_radius']) continue;
    if ($active_filter === 'other_radius' && $s['is_our_radius']) continue;
    if ($active_filter === 'expired_online' && !$s['is_expired_online']) continue;
    $sessions[] = $s;
}
$total_live = count($sessions);
?>

<style>
.live-indicator {
    display: inline-block;
    width: 12px;
    height: 12px;
    background-color: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulse-green 1.5s infinite;
}
@keyframes pulse-green {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.stat-box {
    background: #f8fafc;
    border-radius: 8px;
    padding: 10px 5px;
    text-align: center;
    border: 1px solid #e2e8f0;
}
.stat-box .title { font-size: 0.65rem; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
.stat-box .value { font-size: 0.9rem; color: #0f172a; font-weight: 700; font-family: monospace; }
.live-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important; }
</style>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="fa-solid fa-tower-broadcast text-primary me-2"></i>Live Sessions</h4>
        <div class="text-muted" style="font-size: 0.9rem;">Real-time MikroTik Active Connections &amp; RADIUS Sessions</div>
    </div>
    <div class="d-flex gap-2 gap-md-3 align-items-center flex-wrap">
        <div class="input-group shadow-sm" style="max-width: 220px;">
            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search text-muted"></i></span>
            <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search user...">
        </div>
        <div class="bg-white shadow-sm border px-3 py-2 rounded-3 d-flex align-items-center gap-2">
            <div class="live-indicator"></div>
            <div>
                <div class="text-muted fw-bold" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px;">Active Users</div>
                <div class="fs-4 fw-bold text-dark lh-1" id="liveCountDisp"><?= $total_live ?></div>
            </div>
        </div>
        <button type="button" class="btn btn-success shadow-sm h-100 px-3 fw-bold d-inline-flex align-items-center gap-1" id="btnSyncMikrotik" onclick="syncWithMikrotik()" title="Direct Two-Way Sync with MikroTik Router(s)">
            <i class="fa-solid fa-arrows-rotate"></i> <span>Sync MikroTik</span>
        </button>
        <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Disconnect and kick all expired active sessions from MikroTik and block in FreeRADIUS?');" class="m-0">
            <input type="hidden" name="action" value="kick_all_expired">
            <button type="submit" class="btn btn-outline-danger shadow-sm h-100 px-3 fw-bold" title="Kick all expired users and block them">
                <i class="fa-solid fa-power-off me-1"></i> Kick Expired
            </button>
        </form>
        <button class="btn btn-outline-secondary shadow-sm h-100 px-3" onclick="location.reload()" title="Refresh Page">
            <i class="fa-solid fa-rotate-right me-1"></i> Refresh
        </button>
    </div>
</div>

<?php if (!empty($sync_feedback['success']) && (!empty($sync_feedback['synced_new']) || !empty($sync_feedback['ghosts_cleared']))): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 mb-3 d-flex align-items-center gap-2" role="alert" style="font-size: 0.88rem;">
        <i class="fa-solid fa-circle-check fs-5 text-success"></i>
        <div><?= htmlspecialchars($sync_feedback['msg']) ?></div>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
    </div>
<?php elseif (!empty($sync_feedback['error'])): ?>
    <div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 mb-3 d-flex align-items-center gap-2" role="alert" style="font-size: 0.88rem;">
        <i class="fa-solid fa-triangle-exclamation fs-5 text-warning"></i>
        <div><strong>MikroTik Connection Note:</strong> <?= htmlspecialchars($sync_feedback['error']) ?></div>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Interactive Authentication Source & Status Filter Tabs -->
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <a href="live_sessions.php" class="btn btn-sm <?= ($active_filter === 'all') ? 'btn-dark' : 'btn-outline-secondary bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        All Active <span class="badge bg-secondary ms-1"><?= $count_total ?></span>
    </a>
    <a href="live_sessions.php?filter=our_radius" class="btn btn-sm <?= ($active_filter === 'our_radius') ? 'btn-success' : 'btn-outline-success bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm" title="Connections accepted by this FreeRADIUS server">
        <i class="fa-solid fa-circle-check me-1"></i>Our RADIUS (Accepted) <span class="badge bg-light text-success border border-success ms-1"><?= $count_our_radius ?></span>
    </a>
    <a href="live_sessions.php?filter=other_radius" class="btn btn-sm <?= ($active_filter === 'other_radius') ? 'btn-secondary' : 'btn-outline-secondary bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm" title="Connections authenticated by another RADIUS server or local secrets">
        <i class="fa-solid fa-server me-1"></i>Other RADIUS / External <span class="badge bg-light text-dark border ms-1"><?= $count_other_radius ?></span>
    </a>
    <a href="live_sessions.php?filter=expired_online" class="btn btn-sm <?= ($active_filter === 'expired_online') ? 'btn-danger' : 'btn-outline-danger bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm" title="Subscribers currently online but package is expired">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>Expired Online <span class="badge bg-danger ms-1"><?= $count_expired_online ?></span>
    </a>
    <button type="button" class="btn btn-sm btn-outline-primary bg-white rounded-pill px-3 fw-semibold shadow-sm ms-auto" data-bs-toggle="modal" data-bs-target="#authLogsModal" onclick="loadAuthLogs()" title="View live connection requests from radpostauth">
        <i class="fa-solid fa-list-check me-1"></i>Connection Requests
    </button>
</div>

<div class="row g-3">
    <?php if(!$sessions): ?>
        <div class="col-12 text-center py-5">
            <div class="text-muted fs-5"><i class="fa-solid fa-ghost fs-1 mb-3 text-secondary opacity-50"></i><br>No active sessions found.</div>
            <div class="mt-2 text-secondary small">Click <strong>"Sync MikroTik"</strong> above to poll your router directly.</div>
        </div>
    <?php endif; ?>
  <?php foreach($sessions as $sess): 
    $start_time = is_numeric($sess['acctstarttime']) ? $sess['acctstarttime'] : strtotime($sess['acctstarttime']);
    $upTime = time() - $start_time;
    if ($upTime < 0 && !empty($sess['acctsessiontime'])) {
        $upTime = (int)$sess['acctsessiontime'];
    }
    $dl = round(($sess['acctoutputoctets'] ?? 0) / 1048576, 2) . " MB";
    $ul = round(($sess['acctinputoctets'] ?? 0) / 1048576, 2) . " MB";
    
    $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'];
    $color = $colors[crc32($sess['username']) % count($colors)];
?>
<div class="col-12 session-card-container mb-2" data-username="<?= htmlspecialchars($sess['username']) ?>">
    <div class="card border-0 shadow-sm live-card" style="border-radius: 8px;">
        <div class="card-body p-2 p-md-3">
            <div class="row align-items-center g-3">
                <div class="col-12 col-xl-4 col-md-5 d-flex align-items-center gap-3">
                    <div class="text-white fw-bold d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width: 45px; height: 45px; border-radius: 10px; background-color: <?= $color ?>; font-size: 1.2rem;">
                        <?= strtoupper(substr($sess['username'], 0, 1)) ?>
                    </div>
                    <div style="min-width: 0;">
                        <div class="d-flex align-items-center gap-1 mb-1">
                            <?php if (!empty($sess['subscriber_id'])): ?>
                                <a href="subscriber_view.php?id=<?= $sess['subscriber_id'] ?>" class="fw-bold text-dark lh-1 text-truncate text-decoration-none" style="font-size: 1.1rem;"><?= htmlspecialchars($sess['username']) ?></a>
                            <?php else: ?>
                                <span class="fw-bold text-dark lh-1 text-truncate" style="font-size: 1.1rem;"><?= htmlspecialchars($sess['username']) ?></span>
                            <?php endif; ?>
                            <button type="button" class="copy-user-btn ms-1" onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($sess['username'])) ?>', this)" title="Copy Username"><i class="fa-regular fa-copy" style="font-size: 0.75rem;"></i></button>
                        </div>
                        <div class="text-muted small text-truncate d-flex align-items-center flex-wrap gap-1">
                            <i class="fa-solid fa-box text-secondary me-1"></i> <?= htmlspecialchars($sess['package_name'] ?? 'Active Package') ?>
                            <?php if (!empty($sess['full_name'])): ?>
                                <span class="text-secondary">(<?= htmlspecialchars($sess['full_name']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($sess['is_our_radius'])): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 fw-semibold" style="font-size: 0.68rem;" title="Access-Accept confirmed in radpostauth by this FreeRADIUS server">
                                    <i class="fa-solid fa-circle-check me-1"></i>Our RADIUS (Accept)
                                </span>
                            <?php elseif (!empty($sess['latest_auth_reply']) && $sess['latest_auth_reply'] === 'Access-Reject'): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 fw-semibold" style="font-size: 0.68rem;" title="This FreeRADIUS returned Access-Reject. User connected via Other RADIUS or local secret">
                                    <i class="fa-solid fa-circle-xmark me-1"></i>Auth Rejected (Other RADIUS Connected)
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1 fw-semibold" style="font-size: 0.68rem;" title="No Access-Accept found in our database for this session (Authenticated via Other/Old RADIUS or local)">
                                    <i class="fa-solid fa-server me-1"></i>Other RADIUS / External
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($sess['sub_status']) && ($sess['sub_status'] === 'expired' || (!empty($sess['expiry_date']) && strtotime($sess['expiry_date']) <= time()))): ?>
                                <span class="badge bg-danger" style="font-size: 0.65rem;">Expired (Online)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-2 col-md-3">
                    <div class="text-dark fw-bold font-monospace small mb-1"><i class="fa-solid fa-network-wired text-primary me-1"></i><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?></div>
                    <div class="text-secondary font-monospace small mb-1"><i class="fa-solid fa-microchip text-muted me-1"></i><?= htmlspecialchars($sess['callingstationid'] ?? 'N/A') ?></div>
                    <div class="text-muted" style="font-size: 0.72rem;" title="Exact connection start time recorded by RADIUS">
                        <i class="fa-regular fa-clock me-1 text-secondary"></i>Connected: <?= !empty($sess['acctstarttime']) ? date('d M, h:i A', strtotime($sess['acctstarttime'])) : 'N/A' ?>
                    </div>
                    <?php if (!empty($sess['latest_auth_date'])): ?>
                        <div class="text-muted" style="font-size: 0.68rem;" title="Latest connection request evaluated by this FreeRADIUS">
                            <i class="fa-solid fa-shield-halved me-1 text-primary"></i>Req: <?= date('d M, h:i A', strtotime($sess['latest_auth_date'])) ?> 
                            (<strong><?= htmlspecialchars($sess['latest_auth_reply'] ?? '') ?></strong>)
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-xl-4 col-md-4">
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="p-1 rounded h-100 d-flex flex-column justify-content-center" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                                <div class="text-primary fw-bold" style="font-size:0.65rem; letter-spacing:0.5px;">UPTIME</div>
                                <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= formatMikroTikUptime($upTime) ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-1 rounded h-100 d-flex flex-column justify-content-center" style="background: #ecfeff; border: 1px solid #a5f3fc;">
                                <div class="text-info fw-bold" style="font-size:0.65rem; letter-spacing:0.5px;">DOWN</div>
                                <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= $dl ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-1 rounded h-100 d-flex flex-column justify-content-center" style="background: #fffbeb; border: 1px solid #fde68a;">
                                <div class="text-warning fw-bold" style="font-size:0.65rem; letter-spacing:0.5px;">UP</div>
                                <div class="fw-bold text-dark" style="font-size: 0.85rem;"><?= $ul ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-2 col-md-12 text-xl-end text-center mt-3 mt-xl-0">
                    <div class="d-flex flex-row flex-xl-column gap-2">
                        <?php if(!empty($sess['subscriber_id'])): ?>
                            <a href="subscriber_view.php?id=<?= $sess['subscriber_id'] ?>" class="btn btn-outline-primary btn-sm fw-semibold shadow-sm w-100 py-1" style="font-size: 0.78rem;">
                                <i class="fa-solid fa-user me-1"></i> Profile
                            </a>
                        <?php endif; ?>
                        <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Are you sure you want to kick this user from MikroTik?');" class="w-100 m-0">
                            <input type="hidden" name="action" value="kick">
                            <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                            <button class="btn btn-danger btn-sm fw-bold shadow-sm w-100 py-1" style="font-size: 0.78rem;"><i class="fa-solid fa-power-off me-1"></i> Kick</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

</div>

<script>
function syncWithMikrotik() {
    var btn = document.getElementById('btnSyncMikrotik');
    if (!btn) return;
    var origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Syncing...</span>';
    
    fetch('live_sessions.php?action=ajax_sync_mikrotik')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                btn.innerHTML = '<i class="fa-solid fa-circle-check text-white"></i> <span>Synced!</span>';
                setTimeout(() => { location.reload(); }, 600);
            } else {
                alert('MikroTik Sync: ' + (data.error || data.msg || 'Could not connect to router. Check API port 8728 in Routers settings.'));
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        })
        .catch(err => {
            console.error('Sync error:', err);
            location.reload();
        });
}

// Background Auto-Sync every 30 seconds
setInterval(function() {
    fetch('live_sessions.php?action=ajax_sync_mikrotik')
        .then(r => r.json())
        .then(data => {
            if (data.success && (data.synced_new > 0 || data.ghosts_cleared > 0)) {
                location.reload();
            }
        })
        .catch(() => {});
}, 30000);

<!-- Live Connection Requests (radpostauth) Modal -->
<div class="modal fade" id="authLogsModal" tabindex="-1" aria-labelledby="authLogsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light py-2">
                <h6 class="modal-title fw-bold" id="authLogsModalLabel">
                    <i class="fa-solid fa-shield-halved text-primary me-2"></i>Live Connection Requests (FreeRADIUS radpostauth)
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-2 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <div class="small text-muted">
                        <i class="fa-solid fa-circle-info me-1 text-primary"></i> Real-time incoming authentication attempts evaluated by this FreeRADIUS server.
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadAuthLogs()"><i class="fa-solid fa-rotate me-1"></i>Refresh</button>
                </div>
                <div class="table-responsive" style="max-height: 480px;">
                    <table class="table table-hover table-striped align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light sticky-top" style="z-index: 1;">
                            <tr>
                                <th class="ps-3 py-2">Time</th>
                                <th class="py-2">Username</th>
                                <th class="py-2">Subscriber Name</th>
                                <th class="py-2">Decision / Reply</th>
                                <th class="pe-3 py-2 text-end">Auth Result</th>
                            </tr>
                        </thead>
                        <tbody id="authLogsTableBody">
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading live connection requests...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2 bg-light">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function loadAuthLogs() {
    var tbody = document.getElementById('authLogsTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading live connection requests...</td></tr>';
    
    fetch('live_sessions.php?action=ajax_get_auth_logs')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.logs || data.logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No recent authentication logs found in database.</td></tr>';
                return;
            }
            let html = '';
            data.logs.forEach(row => {
                let isAccept = (row.reply === 'Access-Accept');
                let badge = isAccept 
                    ? '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-check me-1"></i>Access-Accept</span>'
                    : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1"><i class="fa-solid fa-xmark me-1"></i>Access-Reject</span>';
                let resultText = isAccept
                    ? '<span class="text-success fw-semibold"><i class="fa-solid fa-circle-check me-1"></i>Accepted by Our RADIUS</span>'
                    : '<span class="text-danger fw-semibold"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected (Bad Pass/Expired)</span>';
                
                html += `<tr>
                    <td class="ps-3 py-2 font-monospace text-secondary" style="font-size: 0.8rem;">${row.authdate}</td>
                    <td class="py-2 fw-bold font-monospace">${row.username}</td>
                    <td class="py-2 text-muted">${row.full_name || 'N/A'}</td>
                    <td class="py-2">${badge}</td>
                    <td class="pe-3 py-2 text-end">${resultText}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Error loading authentication logs.</td></tr>';
        });
}
</script>

<script>
document.getElementById('searchInput').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let cards = document.querySelectorAll('.session-card-container');
    cards.forEach(card => {
        let username = card.getAttribute('data-username').toLowerCase();
        if (username.includes(filter)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>