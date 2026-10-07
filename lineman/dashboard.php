<?php
session_start();
if (!isset($_SESSION['lineman_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

// Auto-upgrade subscribers table to support linemen
try {
    $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `lineman_id` int(11) DEFAULT 0");
} catch (PDOException $e) {
    // Silently ignore if already exists
}



$lineman_id = $_SESSION['lineman_id'];
$client_id = $_SESSION['client_id'];
$lineman_name = $_SESSION['lineman_name'];

// Check lineman permissions
$chkStmt = $pdo->prepare("SELECT status, can_create_users FROM linemen WHERE id = ?");
$chkStmt->execute([$lineman_id]);
$lm_data = $chkStmt->fetch();
if (!$lm_data || $lm_data['status'] === 'disabled') {
    session_destroy();
    header("Location: login.php");
    exit;
}
$can_create = (isset($lm_data['can_create_users']) && $lm_data['can_create_users'] == 1) ? true : false;


// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    if (!$can_create) { echo "<script>alert('Permission Denied: You do not have permission to create users.'); window.location='dashboard.php';</script>"; exit; }
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $package_id = (int)$_POST['package_id'];
    $full_name = trim($_POST['full_name']);
    $expiry_date = date('Y-m-d\TH:i', strtotime('+30 days')); // Default 30 days

    $p = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ? AND client_id = ?");
    $p->execute([$package_id, $client_id]);
    $pkg = $p->fetch();

    if ($pkg) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO subscribers (client_id, lineman_id, package_id, username, password, service_type, full_name, expiry_date) VALUES (?, ?, ?, ?, ?, 'pppoe', ?, ?)")
                ->execute([$client_id, $lineman_id, $package_id, $username, $password, $full_name, $expiry_date]);
            
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
            
            if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
            }
            
            $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
            
            $pdo->commit();
            echo "<script>alert('User created successfully!'); window.location='dashboard.php';</script>";
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo "<script>alert('Error: Username might already exist.');</script>";
        }
    }
}

// Search Logic
$search_result = null;
if (isset($_GET['search_username']) && !empty(trim($_GET['search_username']))) {
    $search = trim($_GET['search_username']);
    $sStmt = $pdo->prepare("
        SELECT s.*, p.name as package_name,
               (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
               (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
               (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.username = ? AND s.client_id = ?
    ");
    $sStmt->execute([$search, $client_id]);
    $search_result = $sStmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch 7 Days History created by this lineman
$hStmt = $pdo->prepare("
    SELECT s.username, s.full_name, s.created_at, p.name as package_name
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.lineman_id = ? AND s.created_at >= NOW() - INTERVAL 7 DAY
    ORDER BY s.created_at DESC
");
$hStmt->execute([$lineman_id]);
$history = $hStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Packages for Create User Form
$pkgStmt = $pdo->prepare("SELECT id, name FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 MB";
    $bytes = $bytes / (1024 * 1024);
    if ($bytes > 1024) return round($bytes/1024, 2) . ' GB';
    return round($bytes, 2) . ' MB';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Line Man Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: linear-gradient(135deg, #1e3a8a, #0f172a); color: white; padding: 18px 20px; border-bottom: 4px solid #3b82f6; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        
        .card-custom { border: none; border-radius: 16px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s ease, box-shadow 0.2s ease; margin-bottom: 20px; overflow: hidden; }
        .card-custom:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
        
        .stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; height: 100%; transition: all 0.3s ease; }
        .stat-box:hover { background: white; box-shadow: 0 5px 15px rgba(0,0,0,0.05); border-color: #cbd5e1; }
        
        .form-control-lg { border-radius: 50px; padding-left: 25px; border: 2px solid #e2e8f0; }
        .form-control-lg:focus { box-shadow: none; border-color: #3b82f6; }
        .btn-search { border-radius: 50px; padding: 10px 30px; }
        
        .form-control, .form-select { border-radius: 10px; padding: 12px 15px; border: 1px solid #e2e8f0; background-color: #f8fafc; }
        .form-control:focus, .form-select:focus { background-color: white; border-color: #3b82f6; box-shadow: 0 0 0 0.25rem rgba(59, 130, 246, 0.1); }
        
        .table-custom th { border-bottom-width: 1px; color: #64748b; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; padding: 15px; }
        .table-custom td { padding: 15px; vertical-align: middle; border-bottom-color: #f1f5f9; }
        .table-custom tbody tr:hover { background-color: #f8fafc; }
    </style>
</head>
<body>

<div class="top-navbar sticky-top">
    <div class="d-flex align-items-center justify-content-between">
        <div class="fw-bold fs-5 tracking-wide"><i class="fa-solid fa-hard-hat me-2 text-info"></i> Line Man Portal</div>
        <div>
            <span class="me-3 small text-light d-none d-md-inline"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($lineman_name) ?></span>
            <a href="logout.php" class="btn btn-sm btn-outline-light rounded-pill px-3"><i class="fa-solid fa-right-from-bracket me-1"></i> Logout</a>
        </div>
    </div>
</div>

<div class="container mt-4 pb-5">
    
    <!-- Search User -->
    <div class="row justify-content-center mb-4">
        <div class="col-md-8">
            <form method="GET" class="d-flex shadow-sm rounded-pill bg-white p-1 border">
                <input type="text" name="search_username" class="form-control form-control-lg border-0 bg-transparent" placeholder="Enter Username to search..." value="<?= isset($_GET['search_username']) ? htmlspecialchars($_GET['search_username']) : '' ?>" required>
                <button type="submit" class="btn btn-primary btn-search fw-bold shadow-sm"><i class="fa-solid fa-magnifying-glass me-2 d-none d-md-inline"></i> Search</button>
            </form>
        </div>
    </div>

    <!-- Search Result -->
    <?php if(isset($_GET['search_username'])): ?>
        <?php if($search_result): ?>
            <div class="card card-custom border-top border-primary border-4 mb-4">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                        <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-user-check me-2"></i> User Details</h5>
                        <?php 
                        $is_expired = ($search_result['expiry_date'] && strtotime($search_result['expiry_date']) < time());
                        $is_disabled = ($search_result['status'] === 'disabled');
                        
                        if ($is_disabled): ?>
                            <span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-ban me-1"></i> Disabled (Admin Blocked)</span>
                        <?php elseif ($is_expired): ?>
                            <span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-triangle-exclamation me-1"></i> Expired (Needs Renewal)</span>
                        <?php elseif ($search_result['live_ip']): ?>
                            <span class="badge bg-success px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-circle-check me-1"></i> Online (<?= $search_result['live_ip'] ?>) - Status OK</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-plug-circle-xmark me-1"></i> Offline (Check Power/Cable)</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <div class="col-6 col-md-4">
                            <div class="text-secondary small fw-bold text-uppercase tracking-wide mb-1"><i class="fa-solid fa-id-card me-1"></i> Name</div>
                            <div class="fs-5 text-dark fw-bold"><?= htmlspecialchars($search_result['full_name']) ?></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-secondary small fw-bold text-uppercase tracking-wide mb-1"><i class="fa-solid fa-at me-1"></i> Username</div>
                            <div class="fs-5"><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($search_result['username']) ?></span></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-secondary small fw-bold text-uppercase tracking-wide mb-1"><i class="fa-solid fa-box me-1"></i> Package</div>
                            <div class="fs-6 text-dark fw-bold mt-1"><?= htmlspecialchars($search_result['package_name']) ?></div>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="stat-box">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px;">
                                    <i class="fa-solid fa-arrow-down fs-4"></i>
                                </div>
                                <div class="text-secondary small fw-bold text-uppercase mb-1">Total Download</div>
                                <div class="fs-3 fw-bold text-dark"><?= formatBytes($search_result['download_bytes']) ?></div>
                                <div class="mt-3 pt-3 border-top">
                                    <div class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 w-100 py-2"><i class="fa-solid fa-bolt me-1"></i> Live: <span id="live_down" class="fs-6">0.00</span> Mbps</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-box">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px;">
                                    <i class="fa-solid fa-arrow-up fs-4"></i>
                                </div>
                                <div class="text-secondary small fw-bold text-uppercase mb-1">Total Upload</div>
                                <div class="fs-3 fw-bold text-dark"><?= formatBytes($search_result['upload_bytes']) ?></div>
                                <div class="mt-3 pt-3 border-top">
                                    <div class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 w-100 py-2"><i class="fa-solid fa-bolt me-1"></i> Live: <span id="live_up" class="fs-6">0.00</span> Mbps</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning border-0 border-start border-4 border-warning shadow-sm fw-bold p-4 mb-4 bg-white"><i class="fa-solid fa-triangle-exclamation text-warning me-2 fs-5"></i> User not found in this network!</div>
        <?php endif; ?>
    <?php endif; ?>
    
    <div class="row g-4">
        <!-- Create User -->
        <?php if($can_create): ?>
        <div class="col-md-5">
            <div class="card card-custom h-100">
                <div class="card-header bg-transparent border-bottom-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-plus text-success me-2"></i> Create New User</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_user">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary text-uppercase tracking-wide">Full Name</label>
                            <input type="text" name="full_name" class="form-control fw-bold text-dark" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary text-uppercase tracking-wide">Username</label>
                            <input type="text" name="username" class="form-control fw-bold text-primary" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary text-uppercase tracking-wide">Password</label>
                            <input type="text" name="password" class="form-control font-monospace" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary text-uppercase tracking-wide">Select Package</label>
                            <select name="package_id" class="form-select fw-bold text-dark" required>
                                <option value="">Choose...</option>
                                <?php foreach($packages as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success w-100 fw-bold py-3 rounded-pill shadow-sm"><i class="fa-solid fa-check-circle me-1"></i> Create & Activate User</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 7 Days History -->
        <div class="col-md-<?= $can_create ? '7' : '12' ?>">
            <div class="card card-custom h-100">
                <div class="card-header bg-transparent border-bottom-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> History <span class="text-muted fs-6 fw-normal">(Last 7 Days)</span></h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-2"><?= count($history) ?> Users</span>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-custom table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Username</th>
                                    <th>Package</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($history as $h): ?>
                                <tr>
                                    <td class="text-secondary small fw-bold"><?= date('d M Y', strtotime($h['created_at'])) ?><br><span class="text-muted" style="font-size:0.7rem;"><?= date('h:i A', strtotime($h['created_at'])) ?></span></td>
                                    <td class="fw-bold text-dark"><i class="fa-solid fa-user text-muted small me-1"></i> <?= htmlspecialchars($h['username']) ?></td>
                                    <td><span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($h['package_name']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($history)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5">
                                        <div class="text-muted mb-2"><i class="fa-solid fa-folder-open fs-1 opacity-50"></i></div>
                                        <div class="fw-bold text-secondary">No users created in the last 7 days.</div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if(isset($_GET['search_username']) && $search_result): ?>
<script>
let lastBytesIn = null;
let lastBytesOut = null;
let lastTime = null;

function fetchLiveBandwidth() {
    fetch('api_bandwidth.php?username=<?= urlencode($search_result['username']) ?>')
        .then(response => response.json())
        .then(data => {
            if (data.error || data.msg) {
                document.getElementById('live_down').innerText = "0.00";
                document.getElementById('live_up').innerText = "0.00";
                return;
            }

            let currentBytesIn = data.bytes_in;
            let currentBytesOut = data.bytes_out;
            let currentTime = Date.now();

            if (lastBytesIn !== null && lastBytesOut !== null && lastTime !== null) {
                let timeDiffSecs = (currentTime - lastTime) / 1000;
                
                if (timeDiffSecs > 0) {
                    let bytesInDiff = currentBytesIn - lastBytesIn;
                    let bytesOutDiff = currentBytesOut - lastBytesOut;

                    if (bytesInDiff < 0) bytesInDiff = 0;
                    if (bytesOutDiff < 0) bytesOutDiff = 0;

                    let rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576; // Upload
                    let tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576; // Download

                    document.getElementById('live_up').innerText = rx_mbps.toFixed(2);
                    document.getElementById('live_down').innerText = tx_mbps.toFixed(2);
                }
            }

            lastBytesIn = currentBytesIn;
            lastBytesOut = currentBytesOut;
            lastTime = currentTime;
        })
        .catch(err => console.error("Error fetching bandwidth:", err));
}

setInterval(fetchLiveBandwidth, 3000);
fetchLiveBandwidth();
</script>
<?php endif; ?>

<script>
function testLineQuality(ip) {
    document.getElementById('ping_results').classList.remove('d-none');
    document.getElementById('ping_stats').style.opacity = '0.5';
    document.getElementById('p_msg').className = 'alert alert-secondary mb-0 mt-3 small fw-bold border-0';
    document.getElementById('p_msg').innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Analyzing optical line stability... please wait (takes ~4 seconds).';
    
    fetch('api_ping.php?ip=' + ip)
    .then(res => res.json())
    .then(data => {
        document.getElementById('ping_stats').style.opacity = '1';
        if (data.error) {
            document.getElementById('p_msg').className = 'alert alert-danger mb-0 mt-3 small fw-bold border-0';
            document.getElementById('p_msg').innerHTML = '<i class="fa-solid fa-triangle-exclamation me-2"></i> ' + data.error;
            return;
        }
        
        document.getElementById('p_loss').innerHTML = data.loss + '%';
        document.getElementById('p_latency').innerHTML = data.avg_rtt + ' ms';
        
        if (data.status === 'ok') {
            document.getElementById('p_health').innerHTML = '<span class="text-success"><i class="fa-solid fa-face-smile"></i> Excellent</span>';
            document.getElementById('p_msg').className = 'alert alert-success mb-0 mt-3 small fw-bold border-0';
        } else if (data.status === 'warning') {
            document.getElementById('p_health').innerHTML = '<span class="text-warning"><i class="fa-solid fa-face-frown"></i> Weak Signal</span>';
            document.getElementById('p_msg').className = 'alert alert-warning text-dark mb-0 mt-3 small fw-bold border-0';
        } else {
            document.getElementById('p_health').innerHTML = '<span class="text-danger"><i class="fa-solid fa-face-dizzy"></i> Disconnected</span>';
            document.getElementById('p_msg').className = 'alert alert-danger mb-0 mt-3 small fw-bold border-0';
        }
        
        document.getElementById('p_msg').innerHTML = '<i class="fa-solid fa-circle-info me-2"></i> <strong>Result:</strong> ' + data.msg;
    })
    .catch(err => {
        document.getElementById('ping_stats').style.opacity = '1';
        document.getElementById('p_msg').className = 'alert alert-danger mb-0 mt-3 small fw-bold border-0';
        document.getElementById('p_msg').innerHTML = 'Network error checking line quality.';
    });
}
</script>

</body>
</html>
