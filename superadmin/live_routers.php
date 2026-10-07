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
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="fa-solid fa-network-wired text-primary me-2"></i>Live Routers & Users</h4>
        <div class="text-muted" style="font-size: 0.9rem;">Monitor and manage active connections across all global routers</div>
    </div>
</div>

<div class="accordion" id="routersAccordion">
    <?php foreach ($routers as $index => $router): 
        // Get online users for this router (using the latest session trick)
        // Since we are SuperAdmin, we can fetch all radacct where client_id matches the operator
        // Or where nasipaddress matches the router IP
        $nas_ip = $router['nasname'];
        $onlineStmt = $pdo->prepare("SELECT r.*, s.full_name, s.package_id, s.status, p.name as package_name,
                                     (SELECT COUNT(*) FROM radpostauth rp WHERE rp.username = r.username AND rp.reply = 'Access-Accept' AND rp.authdate >= r.acctstarttime - INTERVAL 1 HOUR AND rp.authdate <= r.acctstarttime + INTERVAL 1 HOUR LIMIT 1) as auth_by_us
                                     FROM radacct r 
                                     JOIN subscribers s ON r.username = s.username 
                                     LEFT JOIN packages p ON s.package_id = p.id
                                     INNER JOIN (
                                         SELECT username, MAX(radacctid) as max_id 
                                         FROM radacct 
                                         WHERE nasipaddress = ? AND acctstoptime IS NULL 
                                         GROUP BY username
                                     ) as latest ON r.radacctid = latest.max_id
                                     WHERE s.status != 'disabled'
                                     ORDER BY r.acctstarttime DESC");
        $onlineStmt->execute([$nas_ip]);
        $online_users = $onlineStmt->fetchAll();
        $online_count = count($online_users);
    ?>
    <div class="accordion-item border-0 shadow-sm mb-3 rounded overflow-hidden">
        <h2 class="accordion-header" id="heading<?= $index ?>">
            <button class="accordion-button <?= $index === 0 ? '' : 'collapsed' ?> bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $index ?>">
                <div class="d-flex justify-content-between align-items-center w-100 pe-3">
                    <div>
                        <span class="fw-bold fs-5 text-dark"><i class="fa-solid fa-server me-2 text-primary"></i> <?= htmlspecialchars($router['shortname'] ?: 'Unnamed Router') ?></span>
                        <div class="small text-muted mt-1"><i class="fa-solid fa-building me-1"></i> <?= htmlspecialchars($router['operator_name'] ?: 'No Operator') ?> | <i class="fa-solid fa-globe me-1"></i> <?= htmlspecialchars($nas_ip) ?></div>
                    </div>
                    <div>
                        <span class="badge bg-success rounded-pill px-3 py-2 shadow-sm"><i class="fa-solid fa-users me-1"></i> <?= $online_count ?> Online</span>
                    </div>
                </div>
            </button>
        </h2>
        <div id="collapse<?= $index ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#routersAccordion">
            <div class="accordion-body bg-light p-4">
                <div class="d-flex justify-content-end mb-3 gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary sync-btn shadow-sm" data-nas="<?= $router['id'] ?>" title="Sync Router (Clear Ghost Sessions)" onclick="syncRouter(this)">
                        <i class="fa-solid fa-rotate"></i> Sync
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger flush-btn shadow-sm" data-nas="<?= $router['id'] ?>" title="Force Clear All Database Sessions for this Router" onclick="flushRouter(this)">
                        <i class="fa-solid fa-broom"></i> Flush DB
                    </button>
                </div>
                
                <?php if ($online_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered bg-white shadow-sm align-middle" style="font-size: 0.9rem;">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>IP Address</th>
                                <th>Package</th>
                                <th>Session Start</th>
                                <th>Auth Source</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($online_users as $user): ?>
                            <tr id="row_<?= $router['id'] ?>_<?= md5($user['username']) ?>">
                                <td class="fw-bold text-primary"><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['full_name']) ?></td>
                                <td class="font-monospace text-muted"><?= htmlspecialchars($user['framedipaddress'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($user['package_name']) ?></td>
                                <td><?= date('d M Y h:i A', is_numeric($user['acctstarttime']) ? $user['acctstarttime'] : strtotime($user['acctstarttime'])) ?></td>
                                <td>
                                    <?php if ((isset($user['auth_by_us']) && $user['auth_by_us'] > 0) || (isset($user['status']) && $user['status'] != 'disabled')): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-check-circle me-1"></i> Our RADIUS</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Other/Local</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-danger fw-bold shadow-sm kick-btn" data-nas="<?= $router['id'] ?>" data-user="<?= htmlspecialchars($user['username']) ?>" data-row="row_<?= $router['id'] ?>_<?= md5($user['username']) ?>">
                                        <i class="fa-solid fa-gavel me-1"></i> Kick
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted fw-bold">
                        <i class="fa-solid fa-ghost fs-1 mb-3 opacity-50"></i><br>No online users on this router.
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($routers)): ?>
        <div class="alert alert-warning border-0 shadow-sm fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i> No routers found in the database.</div>
    <?php endif; ?>
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
