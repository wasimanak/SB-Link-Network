<?php
$dir = 'C:/xampp/htdocs/SB Link Network/dealer';

// 1. login.php
$login = <<<'HTML'
<?php
session_start();
if (isset($_SESSION['dealer_id'])) { header("Location: dashboard.php"); exit; }
require_once '../config/db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $stmt = $pdo->prepare("SELECT * FROM dealers WHERE username = ? AND status = 'active'");
    $stmt->execute([$username]);
    $dealer = $stmt->fetch();
    if ($dealer && $dealer['password'] === $password) {
        $_SESSION['dealer_id'] = $dealer['id'];
        $_SESSION['client_id'] = $dealer['client_id'];
        header("Location: dashboard.php"); exit;
    } else { $error = 'Invalid credentials or inactive account.'; }
}
?>
<!DOCTYPE html>
<html>
<head><title>Dealer Login</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 400px;">
    <div class="card shadow">
        <div class="card-header bg-dark text-white text-center"><h4>Dealer Portal</h4></div>
        <div class="card-body">
            <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-3"><label>Username</label><input type="text" name="username" class="form-control" required></div>
                <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
HTML;
file_put_contents("$dir/login.php", $login);

// 2. logout.php
$logout = <<<'HTML'
<?php
session_start();
session_destroy();
header("Location: login.php");
exit;
HTML;
file_put_contents("$dir/logout.php", $logout);

// 3. header.php
$header = <<<'HTML'
<?php
session_start();
if (!isset($_SESSION['dealer_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

$dealer_id = $_SESSION['dealer_id'];
$client_id = $_SESSION['client_id'];

// Get Dealer Data
$stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = ?");
$stmt->execute([$dealer_id]);
$current_dealer = $stmt->fetch();

// Get Gateway Config
$stmt = $pdo->prepare("SELECT gateway_display_name, gateway_account_name FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_conf = $stmt->fetch();
$gateway_display_name = $client_conf['gateway_display_name'] ?? 'Bank Account';
$gateway_account_name = $client_conf['gateway_account_name'] ?? 'Account Holder';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dealer Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; }
        .sidebar { min-height: 100vh; background-color: #1e293b; color: white; padding-top: 20px; }
        .sidebar a { color: #cbd5e1; text-decoration: none; padding: 10px 20px; display: block; }
        .sidebar a:hover { background-color: #334155; color: white; }
        .content { padding: 20px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar">
            <h4 class="text-center mb-4">Dealer Panel</h4>
            <a href="dashboard.php"><i class="fa-solid fa-gauge me-2"></i> Dashboard</a>
            <a href="#" data-bs-toggle="modal" data-bs-target="#addFundsModal"><i class="fa-solid fa-wallet me-2"></i> Add Balance</a>
            <a href="logout.php"><i class="fa-solid fa-sign-out-alt me-2"></i> Logout</a>
        </div>
        <div class="col-md-10 content">
HTML;
file_put_contents("$dir/header.php", $header);

// 4. footer.php
$footer = <<<'HTML'
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
file_put_contents("$dir/footer.php", $footer);

// 5. dashboard.php
$dashboard = <<<'HTML'
<?php require_once 'header.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Welcome, <?= htmlspecialchars($current_dealer['full_name']) ?></h2>
    <div>
        <span class="badge bg-primary fs-5">Balance: Rs. <?= number_format($current_dealer['balance'], 2) ?></span>
        <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#addFundsModal"><i class="fa-solid fa-plus me-1"></i> Add Balance</button>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card shadow-sm border-0">
            <div class="card-body text-center">
                <i class="fa-solid fa-users fs-1 text-primary mb-2"></i>
                <h5>My Users</h5>
                <!-- Will fetch user count here -->
                <p class="text-muted">Manage your assigned subscribers</p>
            </div>
        </div>
    </div>
    <!-- Add more cards later -->
</div>

<!-- Add Funds Modal (Same as Customer) -->
<div class="modal fade" id="addFundsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
      <div class="modal-header border-bottom border-secondary p-4">
        <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-wallet me-2"></i> Recharge Wallet</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form action="fund_action.php" method="POST">
        <div class="modal-body p-3">
            <div class="text-center mb-3">
                <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                    <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label text-secondary fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                <input type="number" name="amount" id="fund_amount" class="form-control form-control-sm bg-dark border-secondary text-light fs-6" placeholder="e.g. 500" required onkeyup="updateFundQR()">
            </div>
            
            <div class="mb-2">
                <label class="form-label text-secondary fw-bold small mb-1">Transaction Reference ID</label>
                <input type="text" name="payment_reference" class="form-control form-control-sm bg-dark border-secondary text-light" placeholder="e.g. TID987654321" required>
            </div>
            <div class="text-secondary" style="font-size: 0.75rem;">Scan the QR Code to pay. After paying, submit the request.</div>
        </div>
        <div class="modal-footer border-top border-secondary p-2 d-flex justify-content-between">
          <button type="button" class="btn btn-sm btn-outline-secondary px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function updateFundQR() {
    var amount = document.getElementById('fund_amount').value || "0";
    var qrData = encodeURIComponent("FUNDS_DEALER_<?= $current_dealer['id'] ?>_AMT_" + amount + "_TS_" + Date.now());
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" + qrData;
    document.getElementById('fund_qr_code').src = qrUrl;
}
</script>

<?php require_once 'footer.php'; ?>
HTML;
file_put_contents("$dir/dashboard.php", $dashboard);

// 6. fund_action.php
$fund_action = <<<'HTML'
<?php
require_once 'header.php'; // Checks auth

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)$_POST['amount'];
    $payment_reference = trim($_POST['payment_reference']);
    
    if ($amount <= 0 || empty($payment_reference)) {
        echo "<script>alert('Invalid amount or missing transaction reference.'); window.history.back();</script>";
        exit;
    }
    
    try {
        // Insert pending fund request for DEALER
        $stmt = $pdo->prepare("INSERT INTO fund_requests (dealer_id, client_id, amount, payment_reference, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$dealer_id, $client_id, $amount, $payment_reference]);
        
        echo "<script>alert('Recharge request submitted successfully! Your balance will be updated once the operator verifies the transaction.'); window.location='dashboard.php';</script>";
        exit;
    } catch (Exception $e) {
        echo "<script>alert('Error submitting recharge request. Please try again.'); window.location='dashboard.php';</script>";
        exit;
    }
}
header("Location: dashboard.php");
exit;
HTML;
file_put_contents("$dir/fund_action.php", $fund_action);

echo "Dealer portal scaffolded successfully!\n";
?>
