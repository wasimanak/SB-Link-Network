<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_gateway') {
        $gateway_name = trim($_POST['gateway_name']);
        $merchant_id = trim($_POST['merchant_id']);
        $api_key = trim($_POST['api_key']);
        $api_secret = trim($_POST['api_secret']);
        $mode = $_POST['mode'] ?? 'sandbox';

        $stmt = $pdo->prepare("INSERT INTO payment_gateways (client_id, gateway_name, merchant_id, api_key, api_secret, mode, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$client_id, $gateway_name, $merchant_id, $api_key, $api_secret, $mode]);
        
        echo "<script>alert('Payment Gateway added successfully!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'edit_gateway') {
        $id = (int)$_POST['id'];
        $gateway_name = trim($_POST['gateway_name']);
        $merchant_id = trim($_POST['merchant_id']);
        $api_key = trim($_POST['api_key']);
        $api_secret = trim($_POST['api_secret']);
        $mode = $_POST['mode'];
        $status = $_POST['status'];

        $stmt = $pdo->prepare("UPDATE payment_gateways SET gateway_name=?, merchant_id=?, api_key=?, api_secret=?, mode=?, status=? WHERE id=? AND client_id=?");
        $stmt->execute([$gateway_name, $merchant_id, $api_key, $api_secret, $mode, $status, $id, $client_id]);
        
        echo "<script>alert('Payment Gateway updated successfully!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'delete_gateway') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM payment_gateways WHERE id=? AND client_id=?")->execute([$id, $client_id]);
        echo "<script>alert('Payment Gateway deleted!'); window.location='payment_gateways.php';</script>";
        exit;
    }
    elseif ($action === 'toggle_status') {
        $id = (int)$_POST['id'];
        $new_status = $_POST['new_status'];
        $pdo->prepare("UPDATE payment_gateways SET status=? WHERE id=? AND client_id=?")->execute([$new_status, $id, $client_id]);
        echo "<script>window.location='payment_gateways.php';</script>";
        exit;
    }
}

// Fetch gateways
$gateways = $pdo->query("SELECT * FROM payment_gateways WHERE client_id = $client_id ORDER BY id DESC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-brands fa-cc-stripe text-primary me-2"></i> Payment Gateways</h4>
    <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addGatewayModal"><i class="fa-solid fa-plus me-1"></i> Add Gateway</button>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        
        <?php if(empty($gateways)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-wallet fa-4x mb-3 text-light"></i>
                <h5>No Payment Gateways Linked</h5>
                <p>Add a payment gateway API to allow your users to pay online.</p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGatewayModal">Link a Gateway Now</button>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($gateways as $g): ?>
                <div class="col-md-6 mb-4">
                    <div class="card border <?= $g['status'] == 'active' ? 'border-primary' : 'border-secondary' ?> h-100" style="border-radius: 12px; transition: 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center border-bottom-0 pt-3 pb-0">
                            <h5 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
                                <?php
                                    $icon = 'fa-credit-card';
                                    if(stripos($g['gateway_name'], 'stripe') !== false) $icon = 'fa-stripe text-primary';
                                    elseif(stripos($g['gateway_name'], 'paypal') !== false) $icon = 'fa-paypal text-info';
                                ?>
                                <i class="fa-brands <?= $icon ?> fs-4"></i>
                                <?= htmlspecialchars($g['gateway_name']) ?>
                            </h5>
                            <div>
                                <?php if($g['status'] == 'active'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">Disabled</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-2">
                                <span class="text-muted small fw-bold">Merchant ID:</span><br>
                                <span class="text-dark font-monospace"><?= htmlspecialchars($g['merchant_id'] ?: 'N/A') ?></span>
                            </div>
                            <div class="mb-2">
                                <span class="text-muted small fw-bold">API Key:</span><br>
                                <span class="text-dark font-monospace"><?= substr($g['api_key'], 0, 8) ?>••••••••••••</span>
                            </div>
                            <div class="mb-3">
                                <span class="text-muted small fw-bold">Mode:</span> 
                                <?php if($g['mode'] == 'live'): ?>
                                    <span class="badge bg-danger rounded-pill px-2">LIVE</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark rounded-pill px-2">SANDBOX</span>
                                <?php endif; ?>
                            </div>

                            <hr class="text-muted opacity-25">

                            <div class="d-flex justify-content-between align-items-center">
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $g['status'] == 'active' ? 'disabled' : 'active' ?>">
                                    <button type="submit" class="btn btn-sm <?= $g['status'] == 'active' ? 'btn-outline-warning' : 'btn-outline-success' ?> rounded-pill px-3">
                                        <?= $g['status'] == 'active' ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-light border text-primary edit-btn" 
                                        data-id="<?= $g['id'] ?>"
                                        data-name="<?= htmlspecialchars($g['gateway_name']) ?>"
                                        data-merchant="<?= htmlspecialchars($g['merchant_id']) ?>"
                                        data-key="<?= htmlspecialchars($g['api_key']) ?>"
                                        data-secret="<?= htmlspecialchars($g['api_secret']) ?>"
                                        data-mode="<?= $g['mode'] ?>"
                                        data-status="<?= $g['status'] ?>"
                                        data-bs-toggle="modal" data-bs-target="#editGatewayModal">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Delete this gateway permanently?');" class="m-0">
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
</div>

<!-- Add Gateway Modal -->
<div class="modal fade" id="addGatewayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-link text-primary me-2"></i> Link Payment Gateway</h5>
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
          <button type="submit" class="btn btn-dark px-4">Save Configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Gateway Modal -->
<div class="modal fade" id="editGatewayModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Gateway</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_gateway">
        <input type="hidden" name="id" id="edit_id">
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
                <label class="form-label fw-bold text-secondary small">Merchant ID</label>
                <input type="text" name="merchant_id" id="edit_merchant_id" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Key / Client ID <span class="text-danger">*</span></label>
                <input type="text" name="api_key" id="edit_api_key" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Secret / Password <span class="text-danger">*</span></label>
                <input type="text" name="api_secret" id="edit_api_secret" class="form-control" required>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">Environment Mode</label>
                    <select name="mode" id="edit_mode" class="form-select">
                        <option value="sandbox">Sandbox (Testing)</option>
                        <option value="live">Live (Production)</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">Status</label>
                    <select name="status" id="edit_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark px-4">Update Configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function() {
    $('.edit-btn').on('click', function() {
        $('#edit_id').val($(this).data('id'));
        
        let gName = $(this).data('name');
        if($('#edit_gateway_name option[value="'+gName+'"]').length === 0) {
            $('#edit_gateway_name').val('Custom');
        } else {
            $('#edit_gateway_name').val(gName);
        }
        
        $('#edit_merchant_id').val($(this).data('merchant'));
        $('#edit_api_key').val($(this).data('key'));
        $('#edit_api_secret').val($(this).data('secret'));
        $('#edit_mode').val($(this).data('mode'));
        $('#edit_status').val($(this).data('status'));
    });
});
</script>

<?php require_once 'footer.php'; ?>
