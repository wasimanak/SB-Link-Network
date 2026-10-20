<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_gateway') {
        $gateway_name = trim($_POST['gateway_name']);
        $account_name = trim($_POST['account_name']);
        $merchant_id = trim($_POST['merchant_id']);
        $api_key = trim($_POST['api_key']);
        $api_secret = trim($_POST['api_secret']);
        $mode = $_POST['mode'] ?? 'sandbox';

        // Disable all other gateways AND bank accounts (Only 1 active method allowed globally)
        $pdo->prepare("UPDATE payment_gateways SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        $pdo->prepare("UPDATE operator_bank_accounts SET status='disabled' WHERE client_id=?")->execute([$client_id]);

        $stmt = $pdo->prepare("INSERT INTO payment_gateways (client_id, gateway_name, account_name, merchant_id, api_key, api_secret, mode, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$client_id, $gateway_name, $account_name, $merchant_id, $api_key, $api_secret, $mode]);
        echo "<script>alert('Gateway linked successfully and set as Active!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'edit_gateway') {
        $id = (int)$_POST['id'];
        $gateway_name = trim($_POST['gateway_name']);
        $account_name = trim($_POST['account_name']);
        $merchant_id = trim($_POST['merchant_id']);
        $api_key = trim($_POST['api_key']);
        $api_secret = trim($_POST['api_secret']);
        $mode = $_POST['mode'] ?? 'sandbox';
        $status = $_POST['status'] ?? 'disabled';

        if ($status === 'active') {
            // Disable all others globally
            $pdo->prepare("UPDATE payment_gateways SET status='disabled' WHERE client_id=?")->execute([$client_id]);
            $pdo->prepare("UPDATE operator_bank_accounts SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        }

        $stmt = $pdo->prepare("UPDATE payment_gateways SET gateway_name=?, account_name=?, merchant_id=?, api_key=?, api_secret=?, mode=?, status=? WHERE id=? AND client_id=?");
        $stmt->execute([$gateway_name, $account_name, $merchant_id, $api_key, $api_secret, $mode, $status, $id, $client_id]);
        echo "<script>alert('Gateway updated successfully!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'delete_gateway') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM payment_gateways WHERE id=? AND client_id=?")->execute([$id, $client_id]);
        echo "<script>alert('Gateway removed successfully!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'enable_gateway') {
        $id = (int)$_POST['id'];
        // Disable all others
        $pdo->prepare("UPDATE payment_gateways SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        $pdo->prepare("UPDATE operator_bank_accounts SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        // Enable this one
        $pdo->prepare("UPDATE payment_gateways SET status='active' WHERE id=? AND client_id=?")->execute([$id, $client_id]);
        echo "<script>alert('Gateway enabled!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    // --- BANK ACTIONS ---
    elseif ($action === 'add_bank') {
        $bank_name = trim($_POST['bank_name']);
        $account_title = trim($_POST['account_title']);
        $account_number = trim($_POST['account_number']);
        $iban = trim($_POST['iban']);

        // Disable all others globally
        $pdo->prepare("UPDATE payment_gateways SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        $pdo->prepare("UPDATE operator_bank_accounts SET status='disabled' WHERE client_id=?")->execute([$client_id]);

        $stmt = $pdo->prepare("INSERT INTO operator_bank_accounts (client_id, bank_name, account_title, account_number, iban, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$client_id, $bank_name, $account_title, $account_number, $iban]);
        echo "<script>alert('Bank Account added successfully and set as Active!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'edit_bank') {
        $id = (int)$_POST['id'];
        $bank_name = trim($_POST['bank_name']);
        $account_title = trim($_POST['account_title']);
        $account_number = trim($_POST['account_number']);
        $iban = trim($_POST['iban']);
        $status = $_POST['status'] ?? 'disabled';

        if ($status === 'active') {
            $pdo->prepare("UPDATE payment_gateways SET status='disabled' WHERE client_id=?")->execute([$client_id]);
            $pdo->prepare("UPDATE operator_bank_accounts SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        }

        $stmt = $pdo->prepare("UPDATE operator_bank_accounts SET bank_name=?, account_title=?, account_number=?, iban=?, status=? WHERE id=? AND client_id=?");
        $stmt->execute([$bank_name, $account_title, $account_number, $iban, $status, $id, $client_id]);
        echo "<script>alert('Bank Account updated!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'delete_bank') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM operator_bank_accounts WHERE id=? AND client_id=?")->execute([$id, $client_id]);
        echo "<script>alert('Bank Account removed!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'enable_bank') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE payment_gateways SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        $pdo->prepare("UPDATE operator_bank_accounts SET status='disabled' WHERE client_id=?")->execute([$client_id]);
        $pdo->prepare("UPDATE operator_bank_accounts SET status='active' WHERE id=? AND client_id=?")->execute([$id, $client_id]);
        echo "<script>alert('Bank Account enabled!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'sync_safepay') {
        require_once '../config/safepay_helper.php';
        $synced = sync_pending_safepay_payments($pdo, $client_id);
        $count = count($synced);
        if ($count > 0) {
            echo "<script>alert('Successfully verified and updated {$count} pending payment(s) from Safepay!'); window.location='payment_gateways.php';</script>";
        } else {
            echo "<script>alert('Safepay sync complete. Checked all pending transactions with Safepay API.'); window.location='payment_gateways.php';</script>";
        }
        exit;
    }
}

// Auto-create online_payments table and seed Safepay default if none exists
try {
    require_once '../config/safepay_helper.php';
    ensure_online_payments_table($pdo);

    $checkSp = $pdo->prepare("SELECT id FROM payment_gateways WHERE client_id = ? AND gateway_name = 'Safepay' LIMIT 1");
    $checkSp->execute([$client_id]);
    if (!$checkSp->fetch()) {
        // Pre-insert Safepay Sandbox credentials
        $insSp = $pdo->prepare("INSERT INTO payment_gateways (client_id, gateway_name, account_name, merchant_id, api_key, api_secret, mode, status) VALUES (?, 'Safepay', 'Safepay (All Bank Cards & Wallets)', '', 'sec_d45fe081-9ba5-4437-a8f6-96e157c0569f', 'b5559a16fde4d60797ff18e9b148dd6cdd18eb17888ff36bf7ec04b9adf4e3c2', 'sandbox', 'active')");
        $insSp->execute([$client_id]);
    }
} catch (Exception $e) {}

// Fetch Gateways
$stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$client_id]);
$gateways = $stmt->fetchAll();

// Fetch Banks
$bStmt = $pdo->prepare("SELECT * FROM operator_bank_accounts WHERE client_id = ? ORDER BY created_at DESC");
$bStmt->execute([$client_id]);
$banks = $bStmt->fetchAll();

// Fetch Recent Online Payments
$txStmt = $pdo->prepare("SELECT op.*, s.username, s.full_name, p.name as package_name 
                         FROM online_payments op 
                         LEFT JOIN subscribers s ON op.subscriber_id = s.id 
                         LEFT JOIN packages p ON op.package_id = p.id 
                         WHERE op.client_id = ? 
                         ORDER BY op.id DESC LIMIT 15");
$txStmt->execute([$client_id]);
$recent_txs = $txStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h4 class="m-0 text-secondary fw-bold mb-1"><i class="fa-solid fa-credit-card text-primary me-2"></i> Payment Methods</h4>
        <p class="text-muted small mb-0">Manage API Gateways & Bank Accounts. (Only ONE method can be active at a time).</p>
    </div>
</div>

<!-- Webhook Guidance Banner -->
<?php
$current_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$webhook_url = $current_protocol . $_SERVER['HTTP_HOST'] . '/safepay_webhook.php';
?>
<div class="alert alert-info border-0 rounded-4 shadow-sm mb-4 p-3 d-flex flex-wrap align-items-center justify-content-between gap-3 bg-primary-subtle text-primary-emphasis">
    <div>
        <div class="fw-bold mb-1"><i class="fa-solid fa-bolt text-warning me-1"></i> Safepay Automated Webhook URL:</div>
        <code class="small text-dark font-monospace user-select-all p-1 bg-white rounded border d-inline-block"><?= htmlspecialchars($webhook_url) ?></code>
        <div class="text-muted small mt-1">Safepay Dashboard &rarr; <strong>Developers &rarr; Webhooks</strong> mein ja kar yeh Webhook URL paste karein taake customer payment karte hi background me auto-verify ho jaye.</div>
    </div>
    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" onclick="navigator.clipboard.writeText('<?= addslashes($webhook_url) ?>'); alert('Webhook URL Copied: <?= addslashes($webhook_url) ?>');">
        <i class="fa-regular fa-clipboard me-1"></i> Copy Webhook URL
    </button>
</div>

<div class="row">
    <!-- LEFT SIDE: API GATEWAYS -->
    <div class="col-md-6 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0 text-secondary border-bottom border-primary border-3 pb-1 d-inline-block">API Gateways</h5>
            <button class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#addGatewayModal">
                <i class="fa-solid fa-plus me-1"></i> Add API
            </button>
        </div>
        
        <?php if (empty($gateways)): ?>
            <div class="alert alert-light border border-dashed text-center py-4 text-muted">
                <i class="fa-solid fa-link fa-2x mb-2 text-secondary opacity-50"></i>
                <p class="mb-0 small">No API gateways configured yet.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach($gateways as $g): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm <?= $g['status'] === 'active' ? 'border-start border-4 border-success' : '' ?>">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="d-flex align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 me-2"><?= htmlspecialchars($g['gateway_name']) ?></h6>
                                        <?php if($g['status'] === 'active'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2" style="font-size: 0.7rem;">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2" style="font-size: 0.7rem;">Disabled</span>
                                        <?php endif; ?>
                                        <span class="badge bg-dark bg-opacity-10 text-dark rounded-pill px-2 ms-1" style="font-size: 0.7rem;"><?= ucfirst($g['mode']) ?></span>
                                    </div>
                                    <p class="text-muted small mb-0"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($g['account_name'] ?: 'N/A') ?></p>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if($g['status'] !== 'active'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="enable_gateway">
                                        <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light border text-success" title="Enable"><i class="fa-solid fa-power-off"></i></button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-light border text-primary edit-gateway-btn" 
                                        data-id="<?= $g['id'] ?>"
                                        data-name="<?= htmlspecialchars($g['gateway_name']) ?>"
                                        data-account="<?= htmlspecialchars($g['account_name']) ?>"
                                        data-merchant="<?= htmlspecialchars($g['merchant_id']) ?>"
                                        data-key="<?= htmlspecialchars($g['api_key']) ?>"
                                        data-secret="<?= htmlspecialchars($g['api_secret']) ?>"
                                        data-mode="<?= $g['mode'] ?>"
                                        data-status="<?= $g['status'] ?>"
                                        data-bs-toggle="modal" data-bs-target="#editGatewayModal">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this gateway?');">
                                        <input type="hidden" name="action" value="delete_gateway">
                                        <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light border text-danger"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT SIDE: BANK ACCOUNTS -->
    <div class="col-md-6 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0 text-secondary border-bottom border-success border-3 pb-1 d-inline-block">Manual Bank Accounts</h5>
            <button class="btn btn-success btn-sm rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#addBankModal">
                <i class="fa-solid fa-plus me-1"></i> Add Bank
            </button>
        </div>

        <?php if (empty($banks)): ?>
            <div class="alert alert-light border border-dashed text-center py-4 text-muted">
                <i class="fa-solid fa-building-columns fa-2x mb-2 text-secondary opacity-50"></i>
                <p class="mb-0 small">No bank accounts added yet.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach($banks as $b): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm <?= $b['status'] === 'active' ? 'border-start border-4 border-success' : '' ?>">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="d-flex align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 me-2 text-success"><?= htmlspecialchars($b['bank_name']) ?></h6>
                                        <?php if($b['status'] === 'active'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2" style="font-size: 0.7rem;">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2" style="font-size: 0.7rem;">Disabled</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted small mb-0"><i class="fa-regular fa-user me-1"></i> Title: <strong><?= htmlspecialchars($b['account_title']) ?></strong></p>
                                    <p class="text-muted small mb-0"><i class="fa-solid fa-hashtag me-1"></i> A/C: <strong><?= htmlspecialchars($b['account_number']) ?></strong></p>
                                    <?php if(!empty($b['iban'])): ?>
                                        <p class="text-muted small mb-0"><i class="fa-solid fa-globe me-1"></i> IBAN: <?= htmlspecialchars($b['iban']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex gap-2 justify-content-end">
                                        <?php if($b['status'] !== 'active'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="enable_bank">
                                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light border text-success" title="Enable"><i class="fa-solid fa-power-off"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-light border text-primary edit-bank-btn" 
                                            data-id="<?= $b['id'] ?>"
                                            data-bank="<?= htmlspecialchars($b['bank_name']) ?>"
                                            data-title="<?= htmlspecialchars($b['account_title']) ?>"
                                            data-number="<?= htmlspecialchars($b['account_number']) ?>"
                                            data-iban="<?= htmlspecialchars($b['iban']) ?>"
                                            data-status="<?= $b['status'] ?>"
                                            data-bs-toggle="modal" data-bs-target="#editBankModal">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this bank account?');">
                                            <input type="hidden" name="action" value="delete_bank">
                                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light border text-danger"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 3. Recent Online Payments & Auto-Sync -->
<div class="card border-0 shadow-sm rounded-4 mb-5">
    <div class="card-header bg-white border-bottom-0 py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="m-0 fw-bold text-dark"><i class="fa-solid fa-list-check text-primary me-2"></i> Recent Safepay Online Payments</h6>
            <span class="text-muted small">Live log of online checkout attempts, payments, and instant activation status</span>
        </div>
        <form method="POST" class="m-0">
            <input type="hidden" name="action" value="sync_safepay">
            <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                <i class="fa-solid fa-rotate me-1"></i> Check & Sync Pending with Safepay
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent_txs)): ?>
            <div class="text-center py-4 text-muted small">
                <i class="fa-solid fa-receipt fa-2x mb-2 text-secondary opacity-50"></i>
                <p class="mb-0">No online payment transactions recorded yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Order ID</th>
                            <th>Subscriber</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Tracker (Safepay)</th>
                            <th>Status</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_txs as $tx): ?>
                        <tr>
                            <td class="ps-4 fw-bold font-monospace text-dark"><?= htmlspecialchars($tx['order_id']) ?></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= htmlspecialchars($tx['username'] ?? 'User #' . $tx['subscriber_id']) ?></div>
                                <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($tx['full_name'] ?? '') ?></div>
                            </td>
                            <td class="fw-bold text-success">Rs. <?= number_format($tx['amount'], 2) ?></td>
                            <td>
                                <?php if ($tx['purpose'] === 'package_renew'): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Renew: <?= htmlspecialchars($tx['package_name'] ?? 'Package') ?></span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Wallet Recharge</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="font-monospace text-muted" style="font-size: 0.75rem;" title="<?= htmlspecialchars($tx['tracker_token'] ?? '') ?>">
                                    <?= htmlspecialchars(substr($tx['tracker_token'] ?? 'N/A', 0, 18)) ?>...
                                </span>
                            </td>
                            <td>
                                <?php if ($tx['status'] === 'completed'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-check me-1"></i> Completed</span>
                                <?php elseif ($tx['status'] === 'pending'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fa-solid fa-clock me-1"></i> Pending</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1"><?= htmlspecialchars($tx['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($tx['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- 1. Add Gateway Modal -->
<div class="modal fade" id="addGatewayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-link text-primary me-2"></i> Link API Gateway</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_gateway">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Provider / Gateway Name</label>
                <select name="gateway_name" class="form-select" required>
                    <option value="">Select Provider...</option>
                    <option value="Safepay" selected>Safepay (All Bank Cards, EasyPaisa, JazzCash, 1Link)</option>
                    <option value="JazzCash">JazzCash</option>
                    <option value="EasyPaisa">EasyPaisa</option>
                    <option value="NayaPay">NayaPay</option>
                    <option value="Stripe">Stripe</option>
                    <option value="PayPal">PayPal</option>
                    <option value="Custom">Other (Custom)</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Account Name (Title)</label>
                <input type="text" name="account_name" class="form-control" placeholder="e.g. John Doe">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Merchant ID</label>
                <input type="text" name="merchant_id" class="form-control" placeholder="Optional for some providers">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Key / Client ID <span class="text-danger">*</span></label>
                <input type="text" name="api_key" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Secret / Password <span class="text-danger">*</span></label>
                <input type="password" name="api_secret" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Environment Mode</label>
                <select name="mode" class="form-select">
                    <option value="sandbox">Sandbox (Testing)</option>
                    <option value="live">Live (Production)</option>
                </select>
            </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4">Save Configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 2. Edit Gateway Modal -->
<div class="modal fade" id="editGatewayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-primary me-2"></i> Edit API Gateway</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_gateway">
        <input type="hidden" name="id" id="edit_gateway_id">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Provider / Gateway Name</label>
                <select name="gateway_name" id="edit_gateway_name" class="form-select" required>
                    <option value="Safepay">Safepay (All Bank Cards, EasyPaisa, JazzCash, 1Link)</option>
                    <option value="JazzCash">JazzCash</option>
                    <option value="EasyPaisa">EasyPaisa</option>
                    <option value="NayaPay">NayaPay</option>
                    <option value="Stripe">Stripe</option>
                    <option value="PayPal">PayPal</option>
                    <option value="Custom">Other (Custom)</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Account Name (Title)</label>
                <input type="text" name="account_name" id="edit_gateway_account" class="form-control" placeholder="e.g. John Doe">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Merchant ID</label>
                <input type="text" name="merchant_id" id="edit_gateway_merchant" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Key / Client ID <span class="text-danger">*</span></label>
                <input type="text" name="api_key" id="edit_gateway_key" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Secret / Password <span class="text-danger">*</span></label>
                <input type="text" name="api_secret" id="edit_gateway_secret" class="form-control" required>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">Environment Mode</label>
                    <select name="mode" id="edit_gateway_mode" class="form-select">
                        <option value="sandbox">Sandbox (Testing)</option>
                        <option value="live">Live (Production)</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">Status</label>
                    <select name="status" id="edit_gateway_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4">Update Configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 3. Add Bank Modal -->
<div class="modal fade" id="addBankModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-building-columns text-success me-2"></i> Add Bank Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_bank">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Bank Name <span class="text-danger">*</span></label>
                <input type="text" name="bank_name" class="form-control" placeholder="e.g. Meezan Bank, HBL, Allied Bank" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Account Title <span class="text-danger">*</span></label>
                <input type="text" name="account_title" class="form-control" placeholder="e.g. Ali Ahmed" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Account Number <span class="text-danger">*</span></label>
                <input type="text" name="account_number" class="form-control" placeholder="e.g. 01234567890123" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">IBAN (Optional)</label>
                <input type="text" name="iban" class="form-control" placeholder="e.g. PK00 MEZN 0123...">
            </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success px-4">Save Bank Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 4. Edit Bank Modal -->
<div class="modal fade" id="editBankModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-success me-2"></i> Edit Bank Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_bank">
        <input type="hidden" name="id" id="edit_bank_id">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Bank Name <span class="text-danger">*</span></label>
                <input type="text" name="bank_name" id="edit_bank_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Account Title <span class="text-danger">*</span></label>
                <input type="text" name="account_title" id="edit_bank_title" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Account Number <span class="text-danger">*</span></label>
                <input type="text" name="account_number" id="edit_bank_number" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">IBAN (Optional)</label>
                <input type="text" name="iban" id="edit_bank_iban" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Status</label>
                <select name="status" id="edit_bank_status" class="form-select">
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success px-4">Update Bank Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function() {
    // Populate API Gateway Edit Modal
    $('.edit-gateway-btn').on('click', function() {
        $('#edit_gateway_id').val($(this).data('id'));
        
        let gName = $(this).data('name');
        if($('#edit_gateway_name option[value="'+gName+'"]').length === 0) {
            $('#edit_gateway_name').val('Custom');
        } else {
            $('#edit_gateway_name').val(gName);
        }
        
        $('#edit_gateway_account').val($(this).data('account'));
        $('#edit_gateway_merchant').val($(this).data('merchant'));
        $('#edit_gateway_key').val($(this).data('key'));
        $('#edit_gateway_secret').val($(this).data('secret'));
        $('#edit_gateway_mode').val($(this).data('mode'));
        $('#edit_gateway_status').val($(this).data('status'));
    });

    // Populate Bank Edit Modal
    $('.edit-bank-btn').on('click', function() {
        $('#edit_bank_id').val($(this).data('id'));
        $('#edit_bank_name').val($(this).data('bank'));
        $('#edit_bank_title').val($(this).data('title'));
        $('#edit_bank_number').val($(this).data('number'));
        $('#edit_bank_iban').val($(this).data('iban'));
        $('#edit_bank_status').val($(this).data('status'));
    });
});
</script>

<?php require_once 'footer.php'; ?>
