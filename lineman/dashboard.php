<?php
session_start();
if (!isset($_SESSION['lineman_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

$lineman_id = $_SESSION['lineman_id'];
$client_id = $_SESSION['client_id'];
$lineman_name = $_SESSION['lineman_name'];

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
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
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: #0f172a; color: white; padding: 15px 20px; border-bottom: 3px solid #3b82f6; display: flex; justify-content: space-between; align-items: center; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; text-align: center; }
    </style>
</head>
<body>

<div class="top-navbar">
    <div class="fw-bold fs-5"><i class="fa-solid fa-hard-hat me-2 text-primary"></i> Line Man Portal</div>
    <div>
        <span class="me-3 small text-light"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($lineman_name) ?></span>
        <a href="logout.php" class="btn btn-sm btn-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<div class="container mt-4">
    <div class="row">
        
        <!-- Search User -->
        <div class="col-md-12 mb-4">
            <div class="card card-custom">
                <div class="card-body p-4">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search_username" class="form-control form-control-lg bg-light" placeholder="Enter Username to search..." value="<?= isset($_GET['search_username']) ? htmlspecialchars($_GET['search_username']) : '' ?>" required>
                        <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="fa-solid fa-magnifying-glass me-2"></i> Search</button>
                    </form>
                </div>
            </div>

            <!-- Search Result -->
            <?php if(isset($_GET['search_username'])): ?>
                <?php if($search_result): ?>
                    <div class="card card-custom border-primary" style="border-width: 2px !important;">
                        <div class="card-body p-4">
                            <h5 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa-solid fa-user-check me-2"></i> User Details</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="text-secondary small fw-bold">Name</div>
                                    <div class="fs-5 text-dark"><?= htmlspecialchars($search_result['full_name']) ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="text-secondary small fw-bold">Username</div>
                                    <div class="fs-5 text-dark"><span class="badge bg-primary"><?= htmlspecialchars($search_result['username']) ?></span></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="text-secondary small fw-bold">Package</div>
                                    <div class="text-dark fw-bold"><?= htmlspecialchars($search_result['package_name']) ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="text-secondary small fw-bold">Status</div>
                                    <div class="text-dark fw-bold">
                                        <?php if($search_result['live_ip']): ?>
                                            <span class="text-success"><i class="fa-solid fa-circle text-success small me-1"></i> Online (<?= $search_result['live_ip'] ?>)</span>
                                        <?php else: ?>
                                            <span class="text-secondary"><i class="fa-solid fa-circle text-secondary small me-1"></i> Offline</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2 g-3">
                                <div class="col-6">
                                    <div class="stat-box border-success bg-opacity-10">
                                        <i class="fa-solid fa-arrow-down text-success fs-4 mb-2"></i>
                                        <div class="text-secondary small fw-bold">Total Download Usage</div>
                                        <div class="fs-5 fw-bold text-success"><?= formatBytes($search_result['download_bytes']) ?></div>
                                        <div class="mt-2 border-top pt-2">
                                            <span class="badge bg-success"><i class="fa-solid fa-bolt"></i> Live: <span id="live_down">0.00</span> Mbps</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box border-primary bg-opacity-10">
                                        <i class="fa-solid fa-arrow-up text-primary fs-4 mb-2"></i>
                                        <div class="text-secondary small fw-bold">Total Upload Usage</div>
                                        <div class="fs-5 fw-bold text-primary"><?= formatBytes($search_result['upload_bytes']) ?></div>
                                        <div class="mt-2 border-top pt-2">
                                            <span class="badge bg-primary"><i class="fa-solid fa-bolt"></i> Live: <span id="live_up">0.00</span> Mbps</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning fw-bold shadow-sm"><i class="fa-solid fa-triangle-exclamation me-2"></i> User not found in this network!</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Create User -->
        <div class="col-md-5">
            <div class="card card-custom">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-plus text-success me-2"></i> Create New User</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_user">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Full Name</label>
                            <input type="text" name="full_name" class="form-control bg-light" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Username</label>
                            <input type="text" name="username" class="form-control bg-light" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Password</label>
                            <input type="text" name="password" class="form-control bg-light" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary">Select Package</label>
                            <select name="package_id" class="form-select bg-light" required>
                                <option value="">Choose...</option>
                                <?php foreach($packages as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success w-100 fw-bold py-2">Create & Activate User</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- 7 Days History -->
        <div class="col-md-7">
            <div class="card card-custom h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> My History (Last 7 Days)</h5>
                    <span class="badge bg-primary rounded-pill"><?= count($history) ?> Users Created</span>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Username</th>
                                    <th>Package</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($history as $h): ?>
                                <tr>
                                    <td class="text-secondary small fw-bold"><?= date('d M Y, h:i A', strtotime($h['created_at'])) ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($h['username']) ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($h['package_name']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($history)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">No users created in the last 7 days.</td></tr>
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

                    // Sometimes queue bytes reset or router restarts
                    if (bytesInDiff < 0) bytesInDiff = 0;
                    if (bytesOutDiff < 0) bytesOutDiff = 0;

                    // Calculate Mbps (Megabits per second)
                    // Note: In Mikrotik Hotspot/Queue:
                    // bytes-in is Traffic FROM User to Router (Upload)
                    // bytes-out is Traffic FROM Router to User (Download)
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

// Fetch every 3 seconds
setInterval(fetchLiveBandwidth, 3000);
fetchLiveBandwidth(); // initial call
</script>
<?php endif; ?>
</body>
</html>