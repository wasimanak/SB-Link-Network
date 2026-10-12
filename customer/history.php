<?php 
require_once 'header.php'; 

// Fetch past requests
$reqStmt = $pdo->prepare("SELECT pr.*, p.name FROM package_requests pr JOIN packages p ON pr.package_id = p.id WHERE pr.subscriber_id = ? ORDER BY pr.id DESC LIMIT 10");
$reqStmt->execute([$current_user['id']]);
$requests = $reqStmt->fetchAll();

// Fetch past sessions
$radStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL ORDER BY radacctid DESC LIMIT 10");
$radStmt->execute([$current_user['username']]);
$sessions = $radStmt->fetchAll();

// Fetch past fund requests
$fundStmt = $pdo->prepare("SELECT * FROM fund_requests WHERE subscriber_id = ? ORDER BY id DESC LIMIT 10");
$fundStmt->execute([$current_user['id']]);
$fund_requests = $fundStmt->fetchAll();

function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// Calculate summary stats
$total_pkg_requests = count($requests);
$total_sessions_count = count($sessions);
$total_fund_recharges = count($fund_requests);
$total_approved_funds = 0;
foreach ($fund_requests as $fr) {
    if ($fr['status'] === 'approved') {
        $total_approved_funds += (float)$fr['amount'];
    }
}
?>

<script>
    // Harmonize Bootstrap Theme to clean modern mode
    document.documentElement.setAttribute('data-bs-theme', 'light');
</script>

<style>
    :root {
        --primary-blue: #2563eb;
        --surface-bg: #f8fafc;
        --card-border: #eef2f6;
        --text-dark: #0f172a;
        --text-muted: #64748b;
    }

    body {
        background-color: var(--surface-bg) !important;
        color: var(--text-dark) !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
    }

    /* Modern Navbar Override */
    .navbar {
        background: #ffffff !important;
        border-bottom: 1px solid #e2e8f0 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03) !important;
    }
    .navbar-brand {
        color: #1e3a8a !important;
        font-weight: 800 !important;
        letter-spacing: -0.5px;
    }
    .navbar .nav-link {
        color: #475569 !important;
        font-weight: 500;
        border-radius: 10px;
        padding: 8px 14px !important;
        transition: all 0.2s ease;
    }
    .navbar .nav-link:hover, .navbar .nav-link.active {
        color: #2563eb !important;
        background: #eff6ff !important;
    }

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
    }
    .stat-pill-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.35rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
    }
    .stat-pill-lbl {
        font-size: 0.74rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Content Cards */
    .clean-table-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .clean-table-card .card-header-bar {
        padding: 18px 24px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #ffffff;
    }

    /* Modern Table */
    .modern-table {
        margin-bottom: 0;
        width: 100%;
    }
    .modern-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
        padding: 12px 20px;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .modern-table tbody td {
        padding: 14px 20px;
        color: #1e293b;
        font-size: 0.88rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .modern-table tbody tr:hover {
        background-color: #f8fafc;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Badges */
    .badge-status {
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.72rem;
        letter-spacing: 0.3px;
        text-transform: uppercase;
    }
    .badge-approved { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .badge-pending { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
    .badge-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

    /* Copy Button */
    .btn-copy-chip {
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-copy-chip:hover {
        background: #f1f5f9;
        color: #0f172a;
    }

    /* Footer modern look */
    footer.bg-black {
        background: #0f172a !important;
        border-top: 1px solid #1e293b !important;
    }
</style>

<!-- Top Capsule Header -->
<div class="page-header-card">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                <i class="fa-solid fa-clock-rotate-left"></i> Activity Logs
            </div>
            <h3 class="fw-bold text-dark mb-1">Billing & Usage History</h3>
            <p class="text-muted small mb-0">Track your package purchase history, internet connectivity logs, and wallet recharge requests.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
            </a>
            <a href="dashboard.php#available-packages" class="btn btn-primary rounded-pill px-3 py-2 fw-bold">
                <i class="fa-solid fa-cart-shopping me-1"></i> Buy Packages
            </a>
        </div>
    </div>
</div>

<!-- Quick Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="fa-solid fa-box-archive"></i>
            </div>
            <div>
                <div class="stat-pill-lbl">Package Requests</div>
                <div class="stat-pill-val"><?= $total_pkg_requests ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;">Recent upgrade & renewals</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                <i class="fa-solid fa-wallet"></i>
            </div>
            <div>
                <div class="stat-pill-lbl">Approved Recharges</div>
                <div class="stat-pill-val">Rs <?= number_format($total_approved_funds, 2) ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;"><?= $total_fund_recharges ?> total recharge requests</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                <i class="fa-solid fa-tower-broadcast"></i>
            </div>
            <div>
                <div class="stat-pill-lbl">Past Sessions</div>
                <div class="stat-pill-val"><?= $total_sessions_count ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;">Logged network connections</div>
            </div>
        </div>
    </div>
</div>

<!-- Row 1: Package Requests & Data Sessions -->
<div class="row g-4 mb-4">
    
    <!-- 1. Recent Package Requests -->
    <div class="col-lg-6">
        <div class="clean-table-card">
            <div class="card-header-bar">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-box-open fs-6"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0 fs-6">Recent Package Requests</h5>
                </div>
                <span class="badge bg-light text-muted border rounded-pill px-3 py-1">Last 10</span>
            </div>

            <div class="table-responsive flex-grow-1">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Package</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-inbox fs-3 d-block mb-2 text-muted opacity-50"></i>
                                    No package requests found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($requests as $r): 
                                $statusClass = 'badge-pending';
                                $statusIcon = 'fa-clock';
                                if ($r['status'] == 'approved') {
                                    $statusClass = 'badge-approved';
                                    $statusIcon = 'fa-check';
                                } elseif ($r['status'] == 'rejected') {
                                    $statusClass = 'badge-rejected';
                                    $statusIcon = 'fa-xmark';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($r['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="fw-bold text-primary"><?= htmlspecialchars($r['name']) ?></span>
                                </td>
                                <td class="text-end">
                                    <span class="badge-status <?= $statusClass ?>">
                                        <i class="fa-solid <?= $statusIcon ?> me-1"></i><?= htmlspecialchars($r['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 2. Recent Data Sessions -->
    <div class="col-lg-6">
        <div class="clean-table-card">
            <div class="card-header-bar">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-info-subtle text-info d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-network-wired fs-6"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0 fs-6">Recent Data Sessions</h5>
                </div>
                <span class="badge bg-light text-muted border rounded-pill px-3 py-1">Last 10</span>
            </div>

            <div class="table-responsive flex-grow-1">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Start Time</th>
                            <th>Duration</th>
                            <th class="text-end">Data Usage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-wifi fs-3 d-block mb-2 text-muted opacity-50"></i>
                                    No past sessions found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($sessions as $s): 
                                $dur = (int)$s['acctsessiontime'];
                                $h = floor($dur / 3600);
                                $m = floor(($dur % 3600) / 60);
                                $total = $s['acctinputoctets'] + $s['acctoutputoctets'];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($s['acctstarttime'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= date('H:i:s', strtotime($s['acctstarttime'])) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border rounded-pill px-2 py-1" style="font-size: 0.75rem;">
                                        <i class="fa-regular fa-clock me-1 text-muted"></i><?= $h ?>h <?= $m ?>m
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="fw-bold text-dark font-monospace"><?= formatBytes($total) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Row 2: Recent Fund Requests (Recharges) -->
<div class="row">
    <div class="col-12">
        <div class="clean-table-card">
            <div class="card-header-bar">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                        <i class="fa-solid fa-wallet fs-6"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-0 fs-6">Recent Wallet Recharge Requests</h5>
                </div>
                <button type="button" data-bs-toggle="modal" data-bs-target="#quickAddFundsModal" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                    <i class="fa-solid fa-plus me-1"></i> New Recharge
                </button>
            </div>

            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Amount</th>
                            <th>Transaction Reference (TID)</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fund_requests)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-receipt fs-3 d-block mb-2 text-muted opacity-50"></i>
                                    No wallet recharge requests found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($fund_requests as $fr): 
                                $statusClass = 'badge-pending';
                                $statusIcon = 'fa-clock';
                                if ($fr['status'] == 'approved') {
                                    $statusClass = 'badge-approved';
                                    $statusIcon = 'fa-check';
                                } elseif ($fr['status'] == 'rejected') {
                                    $statusClass = 'badge-rejected';
                                    $statusIcon = 'fa-xmark';
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($fr['created_at'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($fr['created_at'])) ?></div>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">Rs <?= number_format($fr['amount'], 2) ?></span>
                                </td>
                                <td>
                                    <div class="d-inline-flex align-items-center gap-2 bg-light px-2 py-1 rounded-2 border">
                                        <span class="font-monospace text-dark" style="font-size: 0.85rem;"><?= htmlspecialchars($fr['payment_reference']) ?></span>
                                        <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($fr['payment_reference']) ?>', this)">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <span class="badge-status <?= $statusClass ?>">
                                        <i class="fa-solid <?= $statusIcon ?> me-1"></i><?= htmlspecialchars($fr['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Optional Quick Recharge Modal -->
<div class="modal fade" id="quickAddFundsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 24px; border: 1px solid #e2e8f0; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
      <div class="modal-header border-bottom border-light-subtle p-4">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-wallet text-success me-2"></i>Recharge Wallet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="fund_action.php" method="POST">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label text-dark fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                    <input type="number" name="amount" class="form-control" placeholder="e.g. 1500" required>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-dark fw-bold small mb-1">Transaction Reference / TID</label>
                <input type="text" name="payment_reference" class="form-control" placeholder="e.g. TRX982736451" required>
                <div class="form-text text-muted" style="font-size: 0.74rem;">Enter the Transaction ID provided by your banking app after transfer.</div>
            </div>
        </div>
        <div class="modal-footer border-top border-light-subtle p-3 d-flex justify-content-between">
          <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold">
            <i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function copyText(text, btnElement) {
    if (!navigator.clipboard) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    } else {
        navigator.clipboard.writeText(text);
    }
    
    var originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
    setTimeout(function() {
        btnElement.innerHTML = originalHTML;
    }, 2000);
}
</script>

<?php require_once 'footer.php'; ?>
