<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Auto-Sync & Block Expired Users (runs once every 30 seconds)
if (!isset($_SESSION['last_expiry_sync']) || (time() - $_SESSION['last_expiry_sync']) > 30) {
    require_once __DIR__ . '/../config/radius_kick_helper.php';
    sync_expired_subscribers($pdo, $client_id, true);
    $_SESSION['last_expiry_sync'] = time();
}

function formatMikroTikUptime($seconds) {
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    
    $out = "";
    if ($d > 0) $out .= $d . "d";
    if ($h > 0 || $d > 0) $out .= $h . "h";
    $out .= $m . "m";
    return $out;
}


// Get Live Sessions mapped to this operator
$sql = "SELECT r.*, s.package_id, p.name as package_name, s.expiry_date, s.status as sub_status 
        FROM radacct r 
        JOIN subscribers s ON r.username = s.username 
        LEFT JOIN packages p ON s.package_id = p.id
        INNER JOIN (
            SELECT username, MAX(radacctid) as max_id 
            FROM radacct 
            WHERE acctstoptime IS NULL 
            GROUP BY username
        ) as latest ON r.radacctid = latest.max_id
          WHERE s.client_id = ?" . (isset($_GET['filter']) && $_GET['filter'] === 'expired_online' ? " AND (s.expiry_date <= NOW() OR s.status = 'expired')" : "") . "
        ORDER BY r.acctstarttime DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$sessions = $stmt->fetchAll();
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
        <div class="text-muted" style="font-size: 0.9rem;">Monitor currently active network connections</div>
    </div>
    <div class="d-flex gap-3 align-items-center">
        <div class="input-group shadow-sm" style="max-width: 250px;">
            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search text-muted"></i></span>
            <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search user...">
        </div>
        <div class="bg-white shadow-sm border px-4 py-2 rounded-3 d-flex align-items-center gap-3">
            <div class="live-indicator"></div>
            <div>
                <div class="text-muted fw-bold" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Active Users</div>
                <div class="fs-4 fw-bold text-dark lh-1"><?= $total_live ?></div>
            </div>
        </div>
        <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Disconnect and kick all expired active sessions from MikroTik and block in FreeRADIUS?');" class="m-0">
            <input type="hidden" name="action" value="kick_all_expired">
            <button type="submit" class="btn btn-outline-danger shadow-sm h-100 px-3 fw-bold" title="Kick all expired users and block them">
                <i class="fa-solid fa-power-off me-1"></i> Kick Expired
            </button>
        </form>
        <button class="btn btn-primary shadow-sm h-100 px-4" onclick="location.reload()">
            <i class="fa-solid fa-rotate-right me-1"></i> Refresh
        </button>
    </div>
</div>

<div class="row g-3">
    <?php if(!$sessions): ?>
        <div class="col-12 text-center py-5">
            <div class="text-muted fs-5"><i class="fa-solid fa-ghost fs-1 mb-3 text-secondary opacity-50"></i><br>No active sessions found.</div>
        </div>
    <?php endif; ?>
  <?php foreach($sessions as $sess): 
    $start_time = is_numeric($sess['acctstarttime']) ? $sess['acctstarttime'] : strtotime($sess['acctstarttime']);
    $upTime = time() - $start_time;
    $dl = round($sess['acctoutputoctets'] / 1048576, 2) . " MB";
    $ul = round($sess['acctinputoctets'] / 1048576, 2) . " MB";
    
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
                        <div class="fw-bold text-dark lh-1 mb-1 text-truncate" style="font-size: 1.1rem;"><?= htmlspecialchars($sess['username']) ?></div>
                        <div class="text-muted small text-truncate"><i class="fa-solid fa-box text-secondary"></i> <?= htmlspecialchars($sess['package_name'] ?? 'Custom Package') ?></div>
                    </div>
                </div>
                <div class="col-12 col-xl-2 col-md-3">
                    <div class="text-dark fw-bold font-monospace small mb-1"><i class="fa-solid fa-server text-muted me-1"></i><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?></div>
                    <div class="text-secondary font-monospace small"><i class="fa-solid fa-microchip text-muted me-1"></i><?= htmlspecialchars($sess['callingstationid'] ?? 'N/A') ?></div>

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
                    <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Are you sure you want to kick this user?');">
                        <input type="hidden" name="action" value="kick">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                        <button class="btn btn-danger btn-sm fw-bold shadow-sm w-100 py-2"><i class="fa-solid fa-power-off me-1"></i> Kick</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

</div>

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
