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
}

// Fetch Gateways
$stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$client_id]);
$gateways = $stmt->fetchAll();

// Fetch Banks
$bStmt = $pdo->prepare("SELECT * FROM operator_bank_accounts WHERE client_id = ? ORDER BY created_at DESC");
$bStmt->execute([$client_id]);
$banks = $bStmt->fetchAll();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h4 class="m-0 text-secondary fw-bold mb-1"><i class="fa-solid fa-credit-card text-primary me-2"></i> Payment Methods</h4>
        <p class="text-muted small mb-0">Manage API Gateways & Bank Accounts. (Only ONE method can be active at a time).</p>
    </div>
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
