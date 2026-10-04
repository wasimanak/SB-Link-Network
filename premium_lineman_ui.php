<?php
$f = 'lineman/dashboard.php';
$c = file_get_contents($f);

// Separate PHP logic from HTML
$parts = explode('?>', $c, 2);
if (count($parts) < 2) {
    echo "Could not parse file structure.";
    exit;
}

$phpLogic = $parts[0] . "?>";

$premiumHtml = '
<!DOCTYPE html>
<html>
<head>
    <title>Line Man Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; }
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
                <input type="text" name="search_username" class="form-control form-control-lg border-0 bg-transparent" placeholder="Enter Username to search..." value="<?= isset($_GET[\'search_username\']) ? htmlspecialchars($_GET[\'search_username\']) : \'\' ?>" required>
                <button type="submit" class="btn btn-primary btn-search fw-bold shadow-sm"><i class="fa-solid fa-magnifying-glass me-2 d-none d-md-inline"></i> Search</button>
            </form>
        </div>
    </div>

    <!-- Search Result -->
    <?php if(isset($_GET[\'search_username\'])): ?>
        <?php if($search_result): ?>
            <div class="card card-custom border-top border-primary border-4 mb-4">
                <div class="card-body p-4 p-md-5">
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
                        <h5 class="fw-bold text-primary mb-0"><i class="fa-solid fa-user-check me-2"></i> User Details</h5>
                        <?php if($search_result[\'live_ip\']): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill"><i class="fa-solid fa-circle text-success small me-1"></i> Online (<?= $search_result[\'live_ip\'] ?>)</span>
                        <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-2 rounded-pill"><i class="fa-solid fa-circle text-secondary small me-1"></i> Offline</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row g-4 mb-4">
                        <div class="col-6 col-md-4">
                            <div class="text-secondary small fw-bold text-uppercase tracking-wide mb-1"><i class="fa-solid fa-id-card me-1"></i> Name</div>
                            <div class="fs-5 text-dark fw-bold"><?= htmlspecialchars($search_result[\'full_name\']) ?></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="text-secondary small fw-bold text-uppercase tracking-wide mb-1"><i class="fa-solid fa-at me-1"></i> Username</div>
                            <div class="fs-5"><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($search_result[\'username\']) ?></span></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-secondary small fw-bold text-uppercase tracking-wide mb-1"><i class="fa-solid fa-box me-1"></i> Package</div>
                            <div class="fs-6 text-dark fw-bold mt-1"><?= htmlspecialchars($search_result[\'package_name\']) ?></div>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="stat-box">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 50px; height: 50px;">
                                    <i class="fa-solid fa-arrow-down fs-4"></i>
                                </div>
                                <div class="text-secondary small fw-bold text-uppercase mb-1">Total Download</div>
                                <div class="fs-3 fw-bold text-dark"><?= formatBytes($search_result[\'download_bytes\']) ?></div>
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
                                <div class="fs-3 fw-bold text-dark"><?= formatBytes($search_result[\'upload_bytes\']) ?></div>
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
                                    <option value="<?= $p[\'id\'] ?>"><?= htmlspecialchars($p[\'name\']) ?></option>
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
        <div class="col-md-<?= $can_create ? \'7\' : \'12\' ?>">
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
                                    <td class="text-secondary small fw-bold"><?= date(\'d M Y\', strtotime($h[\'created_at\'])) ?><br><span class="text-muted" style="font-size:0.7rem;"><?= date(\'h:i A\', strtotime($h[\'created_at\'])) ?></span></td>
                                    <td class="fw-bold text-dark"><i class="fa-solid fa-user text-muted small me-1"></i> <?= htmlspecialchars($h[\'username\']) ?></td>
                                    <td><span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($h[\'package_name\']) ?></span></td>
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
<?php if(isset($_GET[\'search_username\']) && $search_result): ?>
<script>
let lastBytesIn = null;
let lastBytesOut = null;
let lastTime = null;

function fetchLiveBandwidth() {
    fetch(\'api_bandwidth.php?username=<?= urlencode($search_result[\'username\']) ?>\')
        .then(response => response.json())
        .then(data => {
            if (data.error || data.msg) {
                document.getElementById(\'live_down\').innerText = "0.00";
                document.getElementById(\'live_up\').innerText = "0.00";
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

                    document.getElementById(\'live_up\').innerText = rx_mbps.toFixed(2);
                    document.getElementById(\'live_down\').innerText = tx_mbps.toFixed(2);
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
</body>
</html>
';

file_put_contents($f, $phpLogic . "\n" . $premiumHtml);
echo "Premium UI applied to lineman/dashboard.php!\n";
?>
