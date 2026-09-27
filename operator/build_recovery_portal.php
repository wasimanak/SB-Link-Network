<?php
$dir = 'C:/xampp/htdocs/SB Link Network/recoveryman';

// 1. login.php
$login = <<<'PHP'
<?php
session_start();
if (isset($_SESSION['rm_id'])) { header("Location: dashboard.php"); exit; }
require_once '../config/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $stmt = $pdo->prepare("SELECT * FROM recovery_men WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && $user['password'] === $password) {
        $_SESSION['rm_id'] = $user['id'];
        $_SESSION['client_id'] = $user['client_id'];
        $_SESSION['rm_name'] = $user['full_name'];
        header("Location: dashboard.php"); exit;
    } else { $error = 'Invalid credentials or inactive account.'; }
}
?>
<!DOCTYPE html>
<html>
<head><title>Recovery Man Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
<div class="container" style="max-width: 400px;">
    <div class="card shadow border-0 rounded-4">
        <div class="card-header bg-danger text-white text-center py-3 border-0 rounded-top-4">
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-motorcycle me-2"></i> Recovery Man Portal</h5>
        </div>
        <div class="card-body p-4">
            <?php if($error): ?><div class="alert alert-danger small"><?= $error ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-3"><label class="fw-bold small text-secondary">Username</label><input type="text" name="username" class="form-control bg-light" required></div>
                <div class="mb-4"><label class="fw-bold small text-secondary">Password</label><input type="password" name="password" class="form-control bg-light" required></div>
                <button type="submit" class="btn btn-danger w-100 fw-bold py-2">Login</button>
            </form>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>
PHP;
file_put_contents("$dir/login.php", $login);

// 2. logout.php
$logout = <<<'PHP'
<?php
session_start();
session_destroy();
header("Location: login.php");
exit;
PHP;
file_put_contents("$dir/logout.php", $logout);

// 3. dashboard.php
$dashboard = <<<'PHP'
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
            // Update subscriber balance
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            // Get new balance
            $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $bStmt->execute([$sub_id]);
            $new_balance = $bStmt->fetchColumn();

            // Insert into user_ledger
            $desc = "Cash collected by RM: {$rm_name}. " . ($note ? " Note: $note" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $new_balance, $desc]);
            
            // Insert into activity log
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, "Collected Payment Rs. $amount"]);

            $pdo->commit();
            echo "<script>alert('Payment collected successfully! New Balance: Rs. $new_balance'); window.location='dashboard.php?search_query=" . urlencode($_GET['search_query']??'') . "';</script>";
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
        SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name
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
$collStmt->execute([$client_id, "Cash collected by RM: {$rm_name}%"]);
$today_collection = $collStmt->fetchColumn() ?: 0;
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
                        <div class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($u['full_name']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u['username']) ?></span></h6>
                                <div class="text-secondary small">
                                    <i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($u['package_name']) ?> | 
                                    <i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($u['phone'] ?: 'N/A') ?>
                                </div>
                                <div class="text-secondary small mt-1">
                                    <i class="fa-solid fa-wallet text-success me-1"></i> Balance: <strong class="<?= $u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= $u['balance'] ?></strong>
                                </div>
                            </div>
                            <button class="btn btn-danger btn-sm px-3 fw-bold shadow-sm" onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>')">
                                <i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect
                            </button>
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
PHP;
file_put_contents("$dir/dashboard.php", $dashboard);

// 4. api_user_details.php (Returns small HTML for the ledger tab)
$api_user = <<<'PHP'
<?php
session_start();
require_once '../config/db.php';
if (!isset($_SESSION['rm_id']) || !isset($_GET['username'])) exit;

$username = $_GET['username'];
$client_id = $_SESSION['client_id'];

$stmt = $pdo->prepare("SELECT * FROM user_ledger WHERE username = ? AND client_id = ? ORDER BY id DESC LIMIT 5");
$stmt->execute([$username, $client_id]);
$ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$ledger) {
    echo "<div class='text-muted small'>No recent transactions found.</div>";
    exit;
}

echo '<ul class="list-group text-start shadow-sm border-0">';
foreach ($ledger as $l) {
    $color = $l['type'] === 'credit' ? 'text-success' : 'text-danger';
    $sign = $l['type'] === 'credit' ? '+' : '-';
    $icon = $l['type'] === 'credit' ? 'fa-arrow-down' : 'fa-arrow-up';
    $date = date('d M Y, h:i A', strtotime($l['created_at']));
    
    echo "<li class='list-group-item d-flex justify-content-between align-items-center p-3'>
            <div>
                <div class='fw-bold text-dark small'>{$l['description']}</div>
                <div class='text-muted' style='font-size:0.75rem;'>{$date}</div>
            </div>
            <div class='text-end'>
                <div class='fw-bold {$color}'><i class='fa-solid {$icon} me-1'></i> {$sign} Rs. {$l['amount']}</div>
                <div class='text-secondary' style='font-size:0.75rem;'>Bal: Rs. {$l['balance_after']}</div>
            </div>
          </li>";
}
echo '</ul>';
PHP;
file_put_contents("$dir/api_user_details.php", $api_user);

echo "Recovery Man Portal scaffolded!\n";
?>
