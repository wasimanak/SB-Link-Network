<?php
session_start();
if (!isset($_SESSION['rm_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['client_id'];
$rm_name = $_SESSION['rm_name'];

// Fetch User Collections by this RM
$userLedgerStmt = $pdo->prepare("
    SELECT * FROM user_ledger 
    WHERE client_id = ? AND description LIKE ? 
    ORDER BY created_at DESC 
    LIMIT 100
");
$userLedgerStmt->execute([$client_id, "Cash collected by {$rm_name} RM%"]);
$user_collections = $userLedgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Cash Submitted to Operator
$submissionStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND against_role = 'RecoveryMan' AND against_to = ? AND activity LIKE 'Collected Cash Rs.%'
    ORDER BY created_at DESC 
    LIMIT 100
");
$submissionStmt->execute([$client_id, $rm_name]);
$submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>
<head>
    <title>My History - Recovery Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: linear-gradient(135deg, #1e293b, #0f172a); color: white; padding: 18px 20px; border-bottom: 4px solid #ef4444; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .icon-circle { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        
        /* Premium Custom Tabs */
        .custom-tabs-container { background: white; padding: 6px; border-radius: 50px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .nav-pills .nav-link { color: #64748b; font-weight: 600; border-radius: 50px; padding: 12px 0; transition: all 0.3s ease; border: none; }
        .nav-pills .nav-link:hover { color: #0f172a; }
        .nav-pills .nav-link.active { background: #ef4444; color: white; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3); }
        
        /* Transaction Cards */
        .tx-card { border: none; border-radius: 16px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: transform 0.2s ease, box-shadow 0.2s ease; margin-bottom: 15px; }
        .tx-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
    </style>
</head>
<body>

<div class="top-navbar sticky-top">
    <div class="d-flex align-items-center">
        <a href="dashboard.php" class="text-white text-decoration-none me-3 fs-4 transition-transform hover-scale"><i class="fa-solid fa-arrow-left"></i></a>
        <h4 class="mb-0 fw-bold tracking-wide"><i class="fa-solid fa-clock-rotate-left text-danger me-2"></i> My History</h4>
    </div>
</div>

<div class="container mt-4 pb-5">
    
    <!-- Tab Navigation -->
    <div class="custom-tabs-container mb-4 mx-auto" style="max-width: 500px;">
        <ul class="nav nav-pills w-100" id="statementTabs" role="tablist">
            <li class="nav-item w-50 text-center" role="presentation">
                <button class="nav-link active w-100" id="users-tab" data-bs-toggle="pill" data-bs-target="#users" type="button" role="tab"><i class="fa-solid fa-users me-1"></i> From Users</button>
            </li>
            <li class="nav-item w-50 text-center" role="presentation">
                <button class="nav-link w-100" id="operator-tab" data-bs-toggle="pill" data-bs-target="#operator" type="button" role="tab"><i class="fa-solid fa-building-columns me-1"></i> To Operator</button>
            </li>
        </ul>
    </div>

    <!-- Tab Content -->
    <div class="tab-content" id="statementTabsContent">
        
        <!-- Tab 1: User Collections -->
        <div class="tab-pane fade show active" id="users" role="tabpanel">
            <?php if(!empty($user_collections)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($user_collections as $uc): ?>
                    <div class="card tx-card">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-success bg-opacity-10 text-success me-3">
                                <i class="fa-solid fa-arrow-down"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-user text-muted small me-1"></i> <?= htmlspecialchars($uc['username']) ?>
                                </h6>
                                <div class="text-muted small text-truncate mb-1"><?= htmlspecialchars($uc['description']) ?></div>
                                <div class="text-secondary small fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($uc['created_at'])) ?></div>
                            </div>
                            <div class="text-end ms-2">
                                <h5 class="fw-bold text-success mb-0">+ Rs. <br class="d-md-none"><span class="fs-4"><?= number_format($uc['amount']) ?></span></h5>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5 mt-4">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <i class="fa-solid fa-box-open fs-1 text-secondary opacity-50"></i>
                    </div>
                    <h5 class="fw-bold">No Collections Yet</h5>
                    <p class="small">You haven't collected any cash from users.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Tab 2: Submissions to Operator -->
        <div class="tab-pane fade" id="operator" role="tabpanel">
            <?php if(!empty($submissions)): ?>
                <div class="mx-auto" style="max-width: 600px;">
                <?php foreach($submissions as $sub): 
                    $amount_text = "0";
                    if (preg_match('/Rs\.\s*([\d,.]+)/', $sub['activity'], $m)) {
                        $amount_text = $m[1];
                    }
                ?>
                    <div class="card tx-card">
                        <div class="card-body p-3 d-flex align-items-center">
                            <div class="icon-circle bg-danger bg-opacity-10 text-danger me-3">
                                <i class="fa-solid fa-arrow-up"></i>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <h6 class="fw-bold text-dark mb-1 text-truncate">
                                    <i class="fa-solid fa-building-columns text-muted small me-1"></i> Cash Submitted
                                </h6>
                                <div class="text-muted small text-truncate mb-1"><i class="fa-solid fa-user-shield me-1"></i> Received by <?= htmlspecialchars($sub['by_user']) ?></div>
                                <div class="text-secondary small fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, h:i A', strtotime($sub['created_at'])) ?></div>
                            </div>
                            <div class="text-end ms-2">
                                <h5 class="fw-bold text-danger mb-0">- Rs. <br class="d-md-none"><span class="fs-4"><?= $amount_text ?></span></h5>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-5 mt-4">
                    <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                        <i class="fa-solid fa-box-open fs-1 text-secondary opacity-50"></i>
                    </div>
                    <h5 class="fw-bold">No Submissions Yet</h5>
                    <p class="small">You haven't submitted any cash to the operator.</p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
