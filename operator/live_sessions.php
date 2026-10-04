<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Get Live Sessions mapped to this operator
$sql = "SELECT r.*, s.package_id, p.name as package_name 
        FROM radacct r 
        JOIN subscribers s ON r.username = s.username 
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ? AND r.acctstoptime IS NULL 
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
        <div class="bg-white shadow-sm border px-4 py-2 rounded-3 d-flex align-items-center gap-3">
            <div class="live-indicator"></div>
            <div>
                <div class="text-muted fw-bold" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Active Users</div>
                <div class="fs-4 fw-bold text-dark lh-1"><?= $total_live ?></div>
            </div>
        </div>
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
        $hrs = floor($upTime / 3600);
        $mins = floor(($upTime % 3600) / 60);
        $dl = round($sess['acctoutputoctets'] / 1048576, 2) . " MB";
        $ul = round($sess['acctinputoctets'] / 1048576, 2) . " MB";
        
        // Random avatar color based on username
        $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'];
        $color = $colors[crc32($sess['username']) % count($colors)];
    ?>
    <div class="col-xl-4 col-lg-6 col-md-6">
        <div class="card border-0 shadow-sm h-100 live-card" style="border-radius: 12px; transition: all 0.2s;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="text-white fw-bold d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; border-radius: 12px; background-color: <?= $color ?>; font-size: 1.3rem;">
                            <?= strtoupper(substr($sess['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="fw-bold fs-5 text-dark lh-1 mb-1"><?= htmlspecialchars($sess['username']) ?></div>
                            <div class="text-muted" style="font-size: 0.8rem;"><i class="fa-solid fa-box text-secondary me-1"></i> <?= htmlspecialchars($sess['package_name'] ?? 'Custom Package') ?></div>
                        </div>
                    </div>
                    <form action="subscriber_action.php" method="POST" onsubmit="return confirm('Are you sure you want to kick this user from the router?');">
                        <input type="hidden" name="action" value="kick">
                        <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                        <button class="btn btn-light btn-sm text-danger border shadow-sm rounded-circle d-flex align-items-center justify-content-center" style="width: 35px; height: 35px;" title="Kick User">
                            <i class="fa-solid fa-power-off"></i>
                        </button>
                    </form>
                </div>
                
                <div class="mb-3 bg-light rounded p-2 border">
                    <div class="d-flex justify-content-between border-bottom pb-1 mb-1">
                        <span class="text-muted" style="font-size: 0.8rem;"><i class="fa-solid fa-server me-1"></i> IP Address</span>
                        <span class="fw-bold font-monospace text-dark" style="font-size: 0.85rem;"><?= htmlspecialchars($sess['framedipaddress'] ?? 'N/A') ?></span>
                    </div>
                    <div class="d-flex justify-content-between pt-1">
                        <span class="text-muted" style="font-size: 0.8rem;"><i class="fa-solid fa-microchip me-1"></i> MAC Address</span>
                        <span class="fw-bold font-monospace text-secondary" style="font-size: 0.8rem;"><?= htmlspecialchars($sess['callingstationid'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-4">
                        <div class="stat-box border-primary" style="background: #eff6ff;">
                            <div class="title"><i class="fa-regular fa-clock me-1 text-primary"></i>Uptime</div>
                            <div class="value text-primary mt-1"><?= sprintf("%02d:%02d", $hrs, $mins) ?>h</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-box border-info" style="background: #ecfeff;">
                            <div class="title"><i class="fa-solid fa-arrow-down me-1 text-info"></i>Down</div>
                            <div class="value text-info mt-1"><?= $dl ?></div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="stat-box border-warning" style="background: #fffbeb;">
                            <div class="title"><i class="fa-solid fa-arrow-up me-1 text-warning"></i>Up</div>
                            <div class="value text-warning mt-1"><?= $ul ?></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once 'footer.php'; ?>
