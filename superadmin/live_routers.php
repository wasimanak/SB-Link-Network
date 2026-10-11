<?php
require_once 'auth_check.php';
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';



// Handle AJAX Flush Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'flush_router') {
    header('Content-Type: application/json');
    $nas_id = (int)$_POST['nas_id'];
    
    try {
        $nStmt = $pdo->prepare("SELECT * FROM nas WHERE id = ?");
        $nStmt->execute([$nas_id]);
        $nas = $nStmt->fetch();
        if (!$nas) throw new Exception("Router not found.");
        
        // Force all active sessions for this router to offline in DB
        $updateStmt = $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Flush' WHERE nasipaddress = ? AND acctstoptime IS NULL");
        $updateStmt->execute([$nas['nasname']]);
        $flushed = $updateStmt->rowCount();
        
        echo json_encode(['success' => true, 'msg' => "Database flushed! $flushed sessions forcefully marked as offline."]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Sync Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_router') {
    header('Content-Type: application/json');
    $nas_id = (int)$_POST['nas_id'];
    
    try {
        $nStmt = $pdo->prepare("SELECT * FROM nas WHERE id = ?");
        $nStmt->execute([$nas_id]);
        $nas = $nStmt->fetch();
        
        if (!$nas) throw new Exception("Router not found.");
        
        $api = new RouterosAPI();
        $api->timeout = 3;
        if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
            // Get active PPPoE
            $api->write('/ppp/active/print');
            $ppp = $api->read();
            // Get active Hotspot
            $api->write('/ip/hotspot/active/print');
            $hotspot = $api->read();
            
            $api->disconnect();
            
            $active_users = [];
            if (!empty($ppp)) {
                foreach ($ppp as $p) { if(isset($p['name'])) $active_users[] = strtolower($p['name']); }
            }
            if (!empty($hotspot)) {
                foreach ($hotspot as $h) { if(isset($h['user'])) $active_users[] = strtolower($h['user']); }
            }
            
            // Get database active users for this router
            $dbUsersStmt = $pdo->prepare("SELECT radacctid, username FROM radacct WHERE nasipaddress = ? AND acctstoptime IS NULL");
            $dbUsersStmt->execute([$nas['nasname']]);
            $db_users = $dbUsersStmt->fetchAll();
            
            $ghosts_cleared = 0;
            $updateStmt = $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Ghost-Cleared' WHERE radacctid = ?");
            
            foreach ($db_users as $dbu) {
                if (!in_array(strtolower($dbu['username']), $active_users)) {
                    $updateStmt->execute([$dbu['radacctid']]);
                    $ghosts_cleared++;
                }
            }
            
            echo json_encode(['success' => true, 'msg' => "Sync complete! Cleared $ghosts_cleared ghost sessions."]);
        } else {
            throw new Exception("Could not connect to MikroTik router via API to sync.");
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Kick Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'kick_user') {
    
    header('Content-Type: application/json');
    $nas_id = (int)$_POST['nas_id'];
    $username = trim($_POST['username']);
    
    try {
        $nStmt = $pdo->prepare("SELECT * FROM nas WHERE id = ?");
        $nStmt->execute([$nas_id]);
        $nas = $nStmt->fetch();
        
        if (!$nas) throw new Exception("Router not found.");
        
        $api = new RouterosAPI();
        $api->timeout = 2;
        if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
            // Find active session
            $api->write('/ppp/active/print', false);
            $api->write('?name=' . $username, true);
            $ppp = $api->read();
            
            if (!empty($ppp) && isset($ppp[0]['.id'])) {
                $api->write('/ppp/active/remove', false);
                $api->write('=.id=' . $ppp[0]['.id'], true);
                $api->read();
                $api->disconnect();
                
                // Update radius database manually to close the ghost session
                $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL")->execute([$username]);
                
                echo json_encode(['success' => true, 'msg' => 'User kicked successfully!']);
            } else {
                $api->disconnect();
                // Also close ghost session in DB anyway
                $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL")->execute([$username]);
                echo json_encode(['success' => true, 'msg' => 'User was not active in router, but ghost session cleared from database!']);
            }
        } else {
            throw new Exception("Could not connect to MikroTik router via API.");
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

require_once 'header.php';





// Fetch all routers
$routersStmt = $pdo->query("SELECT n.*, c.company_name as operator_name FROM nas n LEFT JOIN clients c ON n.client_id = c.id ORDER BY n.id DESC");
$routers = $routersStmt->fetchAll();

// Pre-calculate Global Quick Network Stats
$total_routers_count = count($routers);
$global_online_users_stmt = $pdo->query("
    SELECT COUNT(DISTINCT r.username) 
    FROM radacct r 
    JOIN subscribers s ON r.username = s.username 
    WHERE (r.acctstoptime IS NULL OR r.acctstoptime = '' OR r.acctstoptime = '0') 
    AND s.status != 'disabled'
");
$global_online_count = (int)$global_online_users_stmt->fetchColumn();

// Total Active Subscribers in System
$total_subs_count = (int)$pdo->query("SELECT COUNT(*) FROM subscribers WHERE status != 'disabled'")->fetchColumn();
?>

<style>
/* Ultra-Premium Live Routers Dashboard Styles */
.router-stat-card {
    border: none;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.router-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.07);
}

.stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

/* Accordion Custom Styling */
.router-accordion-item {
    border: 1px solid #e2e8f0;
    border-radius: 16px !important;
    background: #ffffff;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.03);
    margin-bottom: 1.25rem;
    overflow: hidden;
    transition: all 0.25s ease;
}
.router-accordion-item:hover {
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
    border-color: #cbd5e1;
}

.router-accordion-header .accordion-button {
    background: #ffffff;
    padding: 1.15rem 1.4rem;
    box-shadow: none !important;
    border: none;
}
.router-accordion-header .accordion-button:not(.collapsed) {
    background: #fafbfc;
    border-bottom: 1px solid #f1f5f9;
}
.router-accordion-header .accordion-button::after {
    background-size: 1rem;
    transition: transform 0.25s ease;
}

/* Router Hardware Avatar Box */
.router-avatar-box {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
    border: 1px solid rgba(37, 99, 235, 0.15);
}

/* Pulse Glow & Tech Pills */
.badge-live-pulse {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.25);
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 30px;
    font-size: 0.82rem;
}
.pulse-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background-color: #10b981;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulse-green 1.8s infinite;
}
@keyframes pulse-green {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.tech-pill {
    background: #f1f5f9;
    color: #0f172a;
    font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    font-size: 0.8rem;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 6px;
    display: inline-block;
    border: 1px solid #e2e8f0;
}

.user-avatar-circle {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    color: #4338ca;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
    text-transform: uppercase;
}

/* Action Buttons Styling */
.btn-action-pill {
    border-radius: 30px;
    padding: 5px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    transition: all 0.2s ease;
}
.btn-action-pill:hover {
    transform: translateY(-1px);
}
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width: 44px; height: 44px;">
                    <i class="fa-solid fa-network-wired fs-5"></i>
                </div>
                <div>
                    <h3 class="fw-bold mb-0 text-dark">Live Routers & Network Sessions</h3>
                    <p class="text-muted small mb-0">Real-time MikroTik monitoring, ghost session sync & instant disconnects</p>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-white bg-white border shadow-sm rounded-pill px-3 py-2 text-secondary fw-semibold small" onclick="location.reload()">
                <i class="fa-solid fa-rotate-right me-1 text-primary"></i> Refresh Routers
            </button>
        </div>
    </div>

    <!-- Quick Network KPI Stats Row -->
    <div class="row g-3 mb-4">
        <!-- Total Active Online -->
        <div class="col-sm-6 col-xl-4">
            <div class="card router-stat-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase tracking-wider">Live Online PPPoE</span>
                        <h3 class="fw-bold text-success mb-0 mt-1"><?= number_format($global_online_count) ?></h3>
                    </div>
                    <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success">
                        <i class="fa-solid fa-satellite-dish"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted small">
                    <span class="badge-live-pulse"><span class="pulse-dot"></span> Authenticated on MikroTik</span>
                </div>
            </div>
        </div>

        <!-- Total Monitored Routers -->
        <div class="col-sm-6 col-xl-4">
            <div class="card router-stat-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase tracking-wider">Connected NAS / Routers</span>
                        <h3 class="fw-bold text-dark mb-0 mt-1"><?= number_format($total_routers_count) ?></h3>
                    </div>
                    <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                        <i class="fa-solid fa-server"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted small">
                    <i class="fa-solid fa-circle-check text-success me-1"></i> Global RouterOS API Gateways
                </div>
            </div>
        </div>

        <!-- Total Subscribers Base -->
        <div class="col-sm-6 col-xl-4">
            <div class="card router-stat-card h-100 p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase tracking-wider">Network Capacity</span>
                        <h3 class="fw-bold text-info mb-0 mt-1"><?= number_format($total_subs_count) ?></h3>
                    </div>
                    <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="mt-2 text-muted small">
                    <i class="fa-solid fa-chart-pie text-muted me-1"></i> Active Subscribers Registered
                </div>
            </div>
        </div>
    </div>

    <!-- Routers Accordion List -->
    <div class="accordion" id="routersAccordion">
        <?php foreach ($routers as $index => $router): 
            $nas_ip = $router['nasname'];
            $onlineStmt = $pdo->prepare("SELECT r.*, s.full_name, s.package_id, s.status, p.name as package_name,
                                         (SELECT COUNT(*) FROM radpostauth rp WHERE rp.username = r.username AND rp.reply = 'Access-Accept' AND rp.authdate >= r.acctstarttime - INTERVAL 1 HOUR AND rp.authdate <= r.acctstarttime + INTERVAL 1 HOUR LIMIT 1) as auth_by_us
                                         FROM radacct r 
                                         JOIN subscribers s ON r.username = s.username 
                                         LEFT JOIN packages p ON s.package_id = p.id
                                         INNER JOIN (
                                             SELECT username, MAX(radacctid) as max_id 
                                             FROM radacct 
                                             WHERE nasipaddress = ? AND (acctstoptime IS NULL OR acctstoptime = '' OR acctstoptime = '0') 
                                             GROUP BY username
                                         ) as latest ON r.radacctid = latest.max_id
                                         WHERE s.status != 'disabled'
                                         ORDER BY r.acctstarttime DESC");
            $onlineStmt->execute([$nas_ip]);
            $online_users = $onlineStmt->fetchAll();
            $online_count = count($online_users);
        ?>
        <div class="router-accordion-item">
            <div class="router-accordion-header" id="heading<?= $index ?>">
                <button class="accordion-button <?= $index === 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $index ?>">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center w-100 pe-3 gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="router-avatar-box">
                                <i class="fa-solid fa-server"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($router['shortname'] ?: 'Unnamed Router') ?></span>
                                    <span class="tech-pill"><i class="fa-solid fa-globe text-primary me-1"></i><?= htmlspecialchars($nas_ip) ?></span>
                                </div>
                                <div class="small text-muted mt-1">
                                    <i class="fa-solid fa-building text-secondary me-1"></i> <strong><?= htmlspecialchars($router['operator_name'] ?: 'All Operators / Direct Network') ?></strong>
                                    <?php if(!empty($router['api_port'])): ?>
                                        <span class="ms-2 text-secondary">• Port: <?= htmlspecialchars($router['api_port']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-live-pulse"><span class="pulse-dot"></span> <?= number_format($online_count) ?> Online</span>
                        </div>
                    </div>
                </button>
            </div>

            <div id="collapse<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#routersAccordion">
                <div class="p-3 p-md-4 bg-white">
                    
                    <!-- Action Toolbar -->
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom flex-wrap gap-2">
                        <div class="text-muted small fw-medium">
                            <i class="fa-solid fa-list-check text-primary me-1"></i> Active PPPoE & Hotspot Connections on <strong><?= htmlspecialchars($router['shortname'] ?: $nas_ip) ?></strong>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light bg-white border btn-action-pill text-primary shadow-sm" data-nas="<?= $router['id'] ?>" title="Sync Router (Clear Ghost Sessions)" onclick="syncRouter(this)">
                                <i class="fa-solid fa-rotate me-1 text-primary"></i> Sync with MikroTik
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-action-pill shadow-sm" data-nas="<?= $router['id'] ?>" title="Force Clear All Database Sessions for this Router" onclick="flushRouter(this)">
                                <i class="fa-solid fa-broom me-1"></i> Flush Stale DB
                            </button>
                        </div>
                    </div>
                    
                    <?php if ($online_count > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                            <thead>
                                <tr style="background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b;">
                                    <th class="ps-3 py-3 border-bottom-0">Subscriber</th>
                                    <th class="py-3 border-bottom-0">Framed IP Address</th>
                                    <th class="py-3 border-bottom-0">Package Name</th>
                                    <th class="py-3 border-bottom-0">Session Started</th>
                                    <th class="py-3 border-bottom-0">Authentication</th>
                                    <th class="pe-3 py-3 border-bottom-0 text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($online_users as $user): ?>
                                <?php $initial = strtoupper(substr($user['username'], 0, 1)); ?>
                                <tr id="row_<?= $router['id'] ?>_<?= md5($user['username']) ?>">
                                    <!-- User Column -->
                                    <td class="ps-3 py-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="user-avatar-circle flex-shrink-0">
                                                <?= htmlspecialchars($initial) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark font-monospace" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($user['username']) ?>
                                                </div>
                                                <div class="text-muted small" style="font-size: 0.78rem;">
                                                    <?= htmlspecialchars($user['full_name'] ?: 'No Name Available') ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- IP Column -->
                                    <td class="py-3">
                                        <span class="tech-pill">
                                            <i class="fa-solid fa-network-wired text-primary me-1" style="font-size: 0.7rem;"></i>
                                            <?= htmlspecialchars($user['framedipaddress'] ?: 'N/A') ?>
                                        </span>
                                    </td>

                                    <!-- Package Column -->
                                    <td class="py-3">
                                        <span class="badge bg-light text-dark border px-2 py-1 rounded-pill small fw-medium">
                                            <i class="fa-solid fa-box text-secondary me-1"></i><?= htmlspecialchars($user['package_name'] ?: 'Custom Limit') ?>
                                        </span>
                                    </td>

                                    <!-- Start Time -->
                                    <td class="py-3 text-secondary small">
                                        <i class="fa-solid fa-clock text-muted me-1"></i>
                                        <?= date('d M Y, h:i A', is_numeric($user['acctstarttime']) ? $user['acctstarttime'] : strtotime($user['acctstarttime'])) ?>
                                    </td>

                                    <!-- Auth Source -->
                                    <td class="py-3">
                                        <?php if ((isset($user['auth_by_us']) && $user['auth_by_us'] > 0) || (isset($user['status']) && $user['status'] != 'disabled')): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill small">
                                                <i class="fa-solid fa-shield-check me-1"></i> RADIUS Accept
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1 rounded-pill small">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> Local / Other
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Action -->
                                    <td class="pe-3 py-3 text-end">
                                        <button class="btn btn-sm btn-outline-danger btn-action-pill shadow-sm kick-btn" data-nas="<?= $router['id'] ?>" data-user="<?= htmlspecialchars($user['username']) ?>" data-row="row_<?= $router['id'] ?>_<?= md5($user['username']) ?>" title="Disconnect user from MikroTik">
                                            <i class="fa-solid fa-power-off me-1"></i> Kick
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-network-wired fs-1 mb-3 text-secondary opacity-25 d-block"></i>
                            <h6 class="fw-bold text-dark mb-1">No Active Users on this Router</h6>
                            <p class="small text-muted mb-0">Currently no PPPoE or Hotspot sessions are active on this gateway.</p>
                        </div>
                    <?php endif; ?>
                    
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($routers)): ?>
            <div class="card router-stat-card text-center p-5">
                <i class="fa-solid fa-server fs-1 text-secondary opacity-50 mb-3"></i>
                <h5 class="fw-bold text-dark">No Routers Found</h5>
                <p class="text-muted small">Please configure your NAS / MikroTik routers in the admin portal.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
document.querySelectorAll('.kick-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const username = this.getAttribute('data-user');
        const nas_id = this.getAttribute('data-nas');
        const row_id = this.getAttribute('data-row');
        
        if (confirm(`Are you sure you want to kick user '${username}' from this router?`)) {
            const originalHtml = this.innerHTML;
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Kicking...';
            this.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'kick_user');
            formData.append('nas_id', nas_id);
            formData.append('username', username);
            
            fetch('live_routers.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.msg);
                    document.getElementById(row_id).remove();
                } else {
                    alert('Error: ' + data.error);
                    this.innerHTML = originalHtml;
                    this.disabled = false;
                }
            })
            .catch(err => {
                alert('Network error while kicking user.');
                this.innerHTML = originalHtml;
                this.disabled = false;
            });
        }
    });
});

function syncRouter(btn) {
    
    if (confirm("Are you sure you want to sync this router? This will scan MikroTik and automatically clear all ghost sessions from the database.")) {
        const nas_id = btn.getAttribute("data-nas");
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        btn.disabled = true;
        
        const formData = new FormData();
        formData.append("action", "sync_router");
        formData.append("nas_id", nas_id);
        
        fetch("live_routers.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.msg);
                location.reload();
            } else {
                alert("Error: " + data.error);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert("Network error while syncing router.");
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
}

function flushRouter(btn) {
    
    if (confirm("WARNING: This will forcefully clear ALL online users for this router from the dashboard database. Use this ONLY if your RADIUS is disabled in MikroTik but users are still stuck as online. Proceed?")) {
        const nas_id = btn.getAttribute("data-nas");
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        btn.disabled = true;
        
        const formData = new FormData();
        formData.append("action", "flush_router");
        formData.append("nas_id", nas_id);
        
        fetch("live_routers.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.msg);
                location.reload();
            } else {
                alert("Error: " + data.error);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert("Network error while flushing router.");
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
}
</script>



<?php require_once 'footer.php'; ?>
