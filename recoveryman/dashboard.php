<?php
session_start();
if (!isset($_SESSION['rm_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['client_id'];
$rm_name = $_SESSION['rm_name'];

// Handle Receive Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = $_POST['subscriber_username'];
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note']);

    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            
            // Fetch current expiry and package price
            $stmt = $pdo->prepare("SELECT s.expiry_date, p.price FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
            $stmt->execute([$sub_id]);
            $subData = $stmt->fetch();
            
            $package_price = (float)($subData['price'] ?? 0);
            $days_to_add = 0;
            if ($package_price > 0) {
                // E.g. (1000 / 2000) * 30 = 15 days
                $days_to_add = round(($amount / $package_price) * 30);
            }
            
            $new_expiry_db = null;
            $new_expiry_rad = null;
            
            if ($days_to_add > 0) {
                $current_expiry = strtotime($subData['expiry_date']);
                $now = time();
                
                if ($current_expiry && $current_expiry > $now) {
                    // Unexpired: Extend from existing expiry date
                    $new_expiry_time = $current_expiry + ($days_to_add * 86400);
                } else {
                    // Expired or null: Extend from right NOW
                    $new_expiry_time = $now + ($days_to_add * 86400);
                }
                
                $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
                $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);
            }

            // Update subscriber balance AND expiry date
            if ($new_expiry_db) {
                $pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = 'active' WHERE id = ?")->execute([$new_expiry_db, $sub_id]);
                
                // Update FreeRADIUS radcheck
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Expiration'")->execute([$sub_username]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$sub_username, $new_expiry_rad]);
                
                $activity_msg = "Collected Payment Rs. $amount. Expiry extended by $days_to_add days to " . date('d M', strtotime($new_expiry_db));
            } else {
                // No balance deduction as requested by user
                $activity_msg = "Collected Payment Rs. $amount";
            }
            
            // Get new balance
            $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $bStmt->execute([$sub_id]);
            $new_balance = $bStmt->fetchColumn();

            // Insert into user_ledger
            $desc = "Cash collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            if ($days_to_add > 0) {
                $desc .= " (Added $days_to_add days)";
            }
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $new_balance, $desc]);
            
            // Insert into activity log
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, $activity_msg]);

            $pdo->commit();
            
            $alert_msg = "Payment collected successfully!\\nNew Balance: Rs. $new_balance";
            if ($days_to_add > 0) {
                $alert_msg .= "\\nExpiry extended by $days_to_add days!";
            }
            echo "<script>alert('$alert_msg'); window.location='dashboard.php?search_query=" . urlencode($_GET['search_query']??'') . "';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error processing payment.');</script>";
        }
    }
}

// Search Logic
$search_results = [];
if (isset($_GET['search_query']) && !empty(trim($_GET['search_query']))) {
    $search = trim($_GET['search_query']);
    $sStmt = $pdo->prepare("
        SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name, p.price as package_price,
               (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
               (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
               (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ?) AND s.client_id = ?
        LIMIT 20
    ");
    $sStmt->execute(["%$search%", "%$search%", "%$search%", $client_id]);
    $search_results = $sStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Today's Collection for this RM
$collStmt = $pdo->prepare("
    SELECT SUM(amount) FROM user_ledger 
    WHERE client_id = ? AND description LIKE ? AND DATE(created_at) = CURDATE()
");
$collStmt->execute([$client_id, "Cash collected by {$rm_name} RM%"]);
$today_collection = $collStmt->fetchColumn() ?: 0;
function formatBytes($bytes) {
    if ($bytes <= 0) return "0 MB";
    $bytes = $bytes / (1024 * 1024);
    if ($bytes > 1024) return round($bytes/1024, 2) . " GB";
    return round($bytes, 2) . " MB";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Recovery Man Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: #0f172a; color: white; padding: 15px 20px; border-bottom: 3px solid #dc2626; display: flex; justify-content: space-between; align-items: center; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="top-navbar">
    <div class="fw-bold fs-5"><i class="fa-solid fa-motorcycle me-2 text-danger"></i> Recovery Portal</div>
    <div>
        <span class="me-3 small text-light"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($rm_name) ?></span>
        <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</div>

<div class="container mt-4">
    <div class="row">
        <!-- Collection Stat -->
        <div class="col-md-12 mb-3">
            <div class="card card-custom bg-danger text-white">
                <div class="card-body d-flex justify-content-between align-items-center p-3">
                    <div>
                        <h6 class="mb-0 text-white-50 fw-bold">Today's Collection</h6>
                        <h3 class="mb-0 fw-bold">Rs. <?= number_format($today_collection, 2) ?></h3>
                    </div>
                    <i class="fa-solid fa-wallet fs-1 opacity-50"></i>
                </div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="col-md-12 mb-4">
            <div class="card card-custom">
                <div class="card-body p-4">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search_query" class="form-control form-control-lg bg-light" placeholder="Search by Name, Username or Phone..." value="<?= isset($_GET['search_query']) ? htmlspecialchars($_GET['search_query']) : '' ?>" required>
                        <button type="submit" class="btn btn-danger px-4 fw-bold"><i class="fa-solid fa-magnifying-glass me-2"></i> Search</button>
                    </form>
                </div>
            </div>

            <!-- Search Results -->
            <?php if(isset($_GET['search_query'])): ?>
                <?php if($search_results): ?>
                    <div class="list-group shadow-sm border-0 rounded-4 mb-4">
                        <?php foreach($search_results as $u): ?>
<div class="list-group-item p-3 border border-secondary border-opacity-25 rounded-3 mb-2 shadow-sm">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($u['full_name']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u['username']) ?></span></h6>
                                    <div class="text-secondary small fw-bold"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($u['package_name']) ?> (Rs. <?= number_format($u['package_price'], 0) ?>)</div>
                                </div>
                                <div>
                                    <?php if($u['live_ip']): ?>
                                        <span class="badge bg-success rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">Offline</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row g-2 text-secondary small mb-3">
                                <div class="col-6">
                                    <i class="fa-solid fa-calendar-xmark text-danger me-1"></i> Expiry: <strong class="text-dark"><?= $u['expiry_date'] ? date('d M Y', strtotime($u['expiry_date'])) : 'N/A' ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-phone text-primary me-1"></i> Phone: <strong class="text-dark"><?= htmlspecialchars($u['phone'] ?: 'N/A') ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-down text-success me-1"></i> Download: <strong class="text-dark"><?= formatBytes($u['download_bytes']??0) ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-up text-primary me-1"></i> Upload: <strong class="text-dark"><?= formatBytes($u['upload_bytes']??0) ?></strong>
                                </div>
                                <div class="col-12 mt-1">
                                    <i class="fa-solid fa-location-dot text-warning me-1"></i> Address: <?= htmlspecialchars($u['address'] ?: 'N/A') ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong class="fs-6 <?= $u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($u['balance'], 2) ?></strong>
                                </div>
                                <button class="btn btn-danger btn-sm px-4 fw-bold shadow-sm" onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>')">
                                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect Payment
                                </button>
                            </div>
                        </div>
<?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning fw-bold shadow-sm"><i class="fa-solid fa-circle-exclamation me-2"></i> No users found matching your search.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Collect Payment Modal -->
<div class="modal fade" id="collectModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-danger text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-dollar me-2"></i> User Dashboard & Collection</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopLiveSpeed()"></button>
      </div>
      <div class="modal-body p-0 bg-light">
          
          <!-- Tabs -->
          <ul class="nav nav-tabs nav-fill bg-white pt-2 border-bottom shadow-sm" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active fw-bold text-dark" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab"><i class="fa-solid fa-money-bill me-1"></i> Receive Payment</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link fw-bold text-dark" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab" onclick="loadHistory()"><i class="fa-solid fa-clock-rotate-left me-1"></i> History & Usage</button>
            </li>
          </ul>

          <div class="tab-content p-4" id="myTabContent">
            
            <!-- Payment Tab -->
            <div class="tab-pane fade show active" id="payment" role="tabpanel">
                <div class="text-center mb-4">
                    <h4 class="fw-bold text-dark mb-1" id="m_fullname"></h4>
                    <span class="badge bg-primary mb-2" id="m_username"></span>
                    <div class="p-3 bg-white border rounded shadow-sm d-inline-block mt-2">
                        <div class="text-secondary small fw-bold">Current Balance</div>
                        <h3 class="mb-0 fw-bold" id="m_balance"></h3>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="receive_payment">
                    <input type="hidden" name="subscriber_id" id="m_subid">
                    <input type="hidden" name="subscriber_username" id="m_subuser">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary">Enter Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold text-success text-center" placeholder="e.g. 1500" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small">Notes / Remarks (Optional)</label>
                        <input type="text" name="note" class="form-control bg-white" placeholder="Month of October, etc.">
                    </div>
                    <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm"><i class="fa-solid fa-check-circle me-2"></i> Confirm Payment Receipt</button>
                </form>
            </div>

            <!-- History & Usage Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="p-3 bg-white border border-success rounded text-center shadow-sm">
                            <div class="text-secondary small fw-bold"><i class="fa-solid fa-arrow-down text-success"></i> Live Download</div>
                            <h4 class="mb-0 fw-bold text-success mt-1"><span id="live_down">0.00</span> <small class="fs-6 text-muted">Mbps</small></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white border border-primary rounded text-center shadow-sm">
                            <div class="text-secondary small fw-bold"><i class="fa-solid fa-arrow-up text-primary"></i> Live Upload</div>
                            <h4 class="mb-0 fw-bold text-primary mt-1"><span id="live_up">0.00</span> <small class="fs-6 text-muted">Mbps</small></h4>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-receipt me-2"></i> Last 5 Transactions</h6>
                <div id="ledger_content" class="text-center py-4">
                    <div class="spinner-border text-danger spinner-border-sm" role="status"></div> Loading history...
                </div>

            </div>

          </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let speedInterval = null;
let currentUsername = '';

function openCollectModal(username, id, fullname, balance) {
    document.getElementById('m_username').innerText = username;
    document.getElementById('m_subuser').value = username;
    document.getElementById('m_subid').value = id;
    document.getElementById('m_fullname').innerText = fullname;
    
    let balColor = parseFloat(balance) < 0 ? 'text-danger' : 'text-success';
    document.getElementById('m_balance').innerHTML = `<span class="${balColor}">Rs. ${balance}</span>`;
    
    currentUsername = username;
    
    // Switch to payment tab by default
    var triggerEl = document.querySelector('#payment-tab');
    bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl).show();

    var modal = new bootstrap.Modal(document.getElementById('collectModal'));
    modal.show();
}

function loadHistory() {
    if(!currentUsername) return;
    
    // Fetch Ledger History
    fetch(`api_user_details.php?username=${encodeURIComponent(currentUsername)}`)
        .then(res => res.text())
        .then(html => { document.getElementById('ledger_content').innerHTML = html; })
        .catch(err => { document.getElementById('ledger_content').innerHTML = '<span class="text-danger">Failed to load history.</span>'; });

    // Start live speed polling
    startLiveSpeed();
}

let lastBytesIn = null;
let lastBytesOut = null;
let lastTime = null;

function startLiveSpeed() {
    stopLiveSpeed();
    lastBytesIn = null; lastBytesOut = null; lastTime = null;
    
    function fetchSpeed() {
        fetch(`../lineman/api_bandwidth.php?username=${encodeURIComponent(currentUsername)}`)
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
                    let rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576; 
                    let tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576; 
                    document.getElementById('live_up').innerText = rx_mbps.toFixed(2);
                    document.getElementById('live_down').innerText = tx_mbps.toFixed(2);
                }
            }
            lastBytesIn = currentBytesIn; lastBytesOut = currentBytesOut; lastTime = currentTime;
        }).catch(err => {});
    }
    
    speedInterval = setInterval(fetchSpeed, 3000);
    fetchSpeed();
}

function stopLiveSpeed() {
    if(speedInterval) clearInterval(speedInterval);
}
</script>
</body>
</html>