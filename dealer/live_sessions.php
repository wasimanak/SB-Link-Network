<?php
require_once 'header.php';
require_once __DIR__ . '/../config/radius_kick_helper.php';

// Format bytes helper
function formatBytes($bytes, $precision = 2) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0.00 MB';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    if ($pow < 2) $pow = 2; // Default at least MB
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Format duration helper
function formatDuration($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0s';
    $days = floor($seconds / 86400);
    $seconds %= 86400;
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    
    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";
    if (empty($parts) || $secs > 0) $parts[] = "{$secs}s";
    return implode(' ', $parts);
}

// Handle Kick Session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'disconnect_user') {
        $u = trim($_POST['username'] ?? '');
        // Verify subscriber belongs to this dealer
        $chk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ? AND dealer_id = ?");
        $chk->execute([$u, $dealer_id]);
        if ($chk->fetchColumn()) {
            kick_subscriber_session($pdo, $u, $client_id);
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', 'Session Disconnected from Live Monitor')")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
            echo "<script>alert('User " . addslashes($u) . " disconnected successfully!'); window.location='live_sessions.php';</script>";
            exit;
        } else {
            echo "<script>alert('Unauthorized or user not found.'); window.location='live_sessions.php';</script>";
            exit;
        }
    }
}

// Fetch all active sessions strictly for this dealer
$sql = "
    SELECT r.*, 
           s.id as subscriber_id, s.full_name, s.mobile, s.package_id, s.expiry_date, s.status as sub_status,
           p.name as package_name
    FROM radacct r
    JOIN subscribers s ON r.username = s.username
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.dealer_id = ? AND r.acctstoptime IS NULL
    ORDER BY r.radacctid DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dealer_id]);
$all_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process sessions & flags
$count_total = count($all_sessions);
$count_expired_online = 0;

foreach ($all_sessions as $k => $s) {
    $is_exp = (!empty($s['sub_status']) && $s['sub_status'] === 'expired') 
              || (!empty($s['expiry_date']) && strtotime($s['expiry_date']) <= time());
    $all_sessions[$k]['is_expired_online'] = $is_exp;
    if ($is_exp) {
        $count_expired_online++;
    }
}

// Filter logic
$active_filter = $_GET['filter'] ?? 'all';
$sessions = [];
foreach ($all_sessions as $s) {
    if ($active_filter === 'expired_online' && !$s['is_expired_online']) continue;
    $sessions[] = $s;
}
$total_displayed = count($sessions);
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
    padding: 8px 12px;
    text-align: center;
    border: 1px solid #e2e8f0;
}
.stat-box .title { font-size: 0.65rem; color: #64748b; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
.stat-box .value { font-size: 0.9rem; color: #0f172a; font-weight: 700; font-family: monospace; }
.live-card { border-radius: 12px; transition: all 0.2s ease; border: 1px solid #f1f5f9; }
.live-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06) !important; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="users" class="text-decoration-none">Users Profile</a></li>
                <li class="breadcrumb-item active">Online Users</li>
            </ol>
        </nav>
        <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <span>Online Live Sessions</span>
            <span class="live-indicator"></span>
        </h4>
        <small class="text-muted">Real-time connected users strictly for your franchise</small>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="input-group shadow-sm" style="width: 250px;">
            <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-search"></i></span>
            <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search user, IP, MAC...">
        </div>
        <div class="bg-white shadow-sm border px-3 py-2 rounded-3 d-flex align-items-center gap-2">
            <div class="live-indicator"></div>
            <div>
                <div class="text-muted fw-bold" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.5px;">Active Count</div>
                <div class="fs-5 fw-bold text-dark lh-1" id="liveCountDisp"><?= $total_displayed ?></div>
            </div>
        </div>
        <a href="users?filter=online" class="btn btn-outline-primary shadow-sm rounded-pill px-3 fw-semibold" title="View in full subscribers table">
            <i class="fa-solid fa-table me-1"></i> Table View
        </a>
        <button class="btn btn-outline-secondary shadow-sm rounded-pill px-3" onclick="location.reload()" title="Refresh Page">
            <i class="fa-solid fa-rotate-right me-1"></i> Refresh
        </button>
    </div>
</div>

<!-- Filter Tabs -->
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <a href="live_sessions" class="btn btn-sm <?= ($active_filter === 'all') ? 'btn-dark' : 'btn-outline-secondary bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        <i class="fa-solid fa-wifi me-1"></i> All Active <span class="badge bg-secondary ms-1"><?= $count_total ?></span>
    </a>
    <a href="live_sessions?filter=expired_online" class="btn btn-sm <?= ($active_filter === 'expired_online') ? 'btn-danger' : 'btn-outline-danger bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm" title="Subscribers currently online but package is expired">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> Expired Online <span class="badge bg-danger ms-1"><?= $count_expired_online ?></span>
    </a>
</div>

<!-- Sessions List -->
<div class="row g-3" id="sessionsContainer">
    <?php if(empty($sessions)): ?>
        <div class="col-12 text-center py-5">
            <div class="text-muted fs-5"><i class="fa-solid fa-ghost fs-1 mb-3 text-secondary opacity-50"></i><br>No active sessions found for this filter.</div>
            <div class="mt-2 text-secondary small">Your customers are currently offline or disconnected.</div>
        </div>
    <?php endif; ?>

    <?php foreach($sessions as $sess): 
        $start_ts = is_numeric($sess['acctstarttime']) ? (int)$sess['acctstarttime'] : strtotime($sess['acctstarttime']);
        $upTime = time() - $start_ts;
        if ($upTime < 0 && !empty($sess['acctsessiontime'])) {
            $upTime = (int)$sess['acctsessiontime'];
        }
        $dl = formatBytes($sess['acctoutputoctets'] ?? 0);
        $ul = formatBytes($sess['acctinputoctets'] ?? 0);
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'];
        $color = $colors[crc32($sess['username']) % count($colors)];
    ?>
    <div class="col-12 session-card-container mb-1" 
         data-username="<?= strtolower(htmlspecialchars($sess['username'])) ?>" 
         data-ip="<?= htmlspecialchars($sess['framedipaddress'] ?? '') ?>" 
         data-mac="<?= strtolower(htmlspecialchars($sess['callingstationid'] ?? '')) ?>">
        <div class="card border-0 shadow-sm live-card bg-white p-3">
            <div class="row align-items-center g-3">
                <!-- User Avatar & Identity -->
                <div class="col-12 col-xl-4 col-md-5 d-flex align-items-center gap-3">
                    <div class="text-white fw-bold d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width: 46px; height: 46px; border-radius: 12px; background-color: <?= $color ?>; font-size: 1.25rem;">
                        <?= strtoupper(substr($sess['username'], 0, 1)) ?>
                    </div>
                    <div style="min-width: 0;">
                        <div class="d-flex align-items-center gap-1 mb-1">
                            <a href="subscriber_view.php?id=<?= $sess['subscriber_id'] ?>" class="fw-bold text-dark text-truncate text-decoration-none fs-6">
                                <?= htmlspecialchars($sess['username']) ?>
                            </a>
                            <button type="button" class="copy-user-btn ms-1" onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($sess['username'])) ?>', this)" title="Copy Username">
                                <i class="fa-regular fa-copy" style="font-size: 0.75rem;"></i>
                            </button>
                        </div>
                        <div class="text-muted small text-truncate d-flex align-items-center flex-wrap gap-1">
                            <i class="fa-solid fa-box text-secondary me-1"></i> <?= htmlspecialchars($sess['package_name'] ?? 'Active Package') ?>
                            <?php if (!empty($sess['full_name'])): ?>
                                <span class="text-secondary">(<?= htmlspecialchars($sess['full_name']) ?>)</span>
                            <?php endif; ?>
                            <?php if (!empty($sess['is_expired_online'])): ?>
                                <span class="badge bg-danger" style="font-size: 0.65rem;">Expired (Online)</span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size: 0.65rem;">Active</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Network IP & MAC -->
                <div class="col-12 col-xl-2 col-md-3">
                    <div class="text-dark fw-bold font-monospace small mb-1">
                        <i class="fa-solid fa-network-wired text-primary me-1"></i><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?>
                    </div>
                    <div class="text-secondary font-monospace small">
                        <i class="fa-solid fa-microchip text-muted me-1"></i><?= htmlspecialchars($sess['callingstationid'] ?? 'N/A') ?>
                    </div>
                </div>

                <!-- Session Uptime -->
                <div class="col-6 col-xl-2 col-md-2 text-start text-md-center">
                    <div class="text-muted fw-bold small text-uppercase" style="font-size: 0.7rem;">Session Uptime</div>
                    <div class="fw-bold text-dark font-monospace"><?= formatDuration($upTime) ?></div>
                </div>

                <!-- Live Bandwidth / Data -->
                <div class="col-6 col-xl-2 col-md-2 text-start text-md-center">
                    <div class="small fw-semibold text-success"><i class="fa-solid fa-arrow-down me-1"></i><?= $dl ?></div>
                    <div class="small fw-semibold text-primary"><i class="fa-solid fa-arrow-up me-1"></i><?= $ul ?></div>
                </div>

                <!-- Actions -->
                <div class="col-12 col-xl-2 text-end d-flex justify-content-end align-items-center gap-2">
                    <a href="subscriber_view.php?id=<?= $sess['subscriber_id'] ?>" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold" title="View Profile">
                        <i class="fa-solid fa-user me-1"></i> View
                    </a>
                    <form method="POST" onsubmit="return confirm('Disconnect active session for user <?= addslashes($sess['username']) ?>?');" class="m-0">
                        <input type="hidden" name="action" value="disconnect_user">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold" title="Disconnect Session">
                            <i class="fa-solid fa-power-off me-1"></i> Kick
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchInput");
    if (!searchInput) return;

    searchInput.addEventListener("keyup", function() {
        const val = this.value.toLowerCase().trim();
        const cards = document.querySelectorAll(".session-card-container");
        let visibleCount = 0;

        cards.forEach(function(card) {
            const username = card.getAttribute("data-username") || "";
            const ip = card.getAttribute("data-ip") || "";
            const mac = card.getAttribute("data-mac") || "";

            if (username.includes(val) || ip.includes(val) || mac.includes(val)) {
                card.style.display = "";
                visibleCount++;
            } else {
                card.style.display = "none";
            }
        });

        const disp = document.getElementById("liveCountDisp");
        if (disp) disp.innerText = visibleCount;
    });
});
</script>

<?php require_once 'footer.php'; ?>
