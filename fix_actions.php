<?php
$f = 'operator/subscribers.php';
$c = file_get_contents($f);

// 1. ADD 'single_payment' PHP LOGIC
if (strpos($c, "if (\$action === 'single_payment')") === false) {
    $singlePaymentPHP = "
    // SINGLE PAYMENT
    if (\$action === 'single_payment') {
        \$id = (int)\$_POST['id'];
        \$amount = (float)\$_POST['amount'];
        if (\$amount != 0) {
            \$uStmt = \$pdo->prepare(\"SELECT username FROM subscribers WHERE id = ? AND client_id = ?\");
            \$uStmt->execute([\$id, \$client_id]);
            if (\$uStmt->fetchColumn()) {
                \$pdo->prepare(\"UPDATE subscribers SET balance = balance + ? WHERE id = ?\")->execute([\$amount, \$id]);
                echo \"<script>alert('Payment applied successfully!'); window.location='subscribers.php';</script>\";
                exit;
            }
        }
    }
";
    // Insert before "// DELETE"
    $c = str_replace("// DELETE", $singlePaymentPHP . "\n    // DELETE", $c);
}


// 2. UPDATE HTML BUTTONS
// Find the old Payment link: `<a href="#" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>`
// and Renew button: `<button type="button" class="badge rounded-pill badge-soft-success border-0 px-2 py-2 w-100 btn-renew" data-bs-toggle="modal" ...>`

$oldPayment = '<a href="#" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>';
$newPayment = '<button type="button" class="badge rounded-pill badge-soft-primary border-0 px-3 py-2 w-100 btn-payment" data-id="<?= $s[\'id\'] ?>" data-username="<?= htmlspecialchars($s[\'username\']) ?>" data-balance="<?= number_format($s[\'balance\'], 2) ?>"><i class="fa-brands fa-paypal"></i> Payment</button>';
$c = str_replace($oldPayment, $newPayment, $c);

// We need to strip data-bs-toggle from btn-renew to use our manual JS trigger (for DataTables safety)
$c = preg_replace('/class="([^"]*btn-renew[^"]*)"\s*data-bs-toggle="modal"\s*data-bs-target="#renewModal"/', 'class="$1"', $c);


// 3. ADD SINGLE PAYMENT MODAL
if (strpos($c, 'id="singlePaymentModal"') === false) {
    $singlePaymentModal = '
<!-- Single Payment Modal -->
<div class="modal fade" id="singlePaymentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-ui border-0">
      <div class="modal-header border-bottom border-secondary border-opacity-25">
        <h5 class="modal-title text-light"><i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="single_payment">
        <input type="hidden" name="id" id="pay_user_id" value="">
        <div class="modal-body">
            <div class="mb-3 text-center">
                <h5 class="text-accent fw-bold" id="pay_username_display">Username</h5>
                <div class="text-secondary small">Current Balance: <span class="text-success fw-bold">Rs <span id="pay_current_balance">0.00</span></span></div>
            </div>
            <div class="mb-3">
                <label class="form-label text-light">Amount to Add (Rs) <span class="text-danger">*</span></label>
                <input type="number" step="1" name="amount" class="form-control bg-dark border-secondary text-light" placeholder="e.g. 500" required>
                <small class="text-secondary">Use negative amount to deduct balance.</small>
            </div>
        </div>
        <div class="modal-footer border-top border-secondary border-opacity-25">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4">Apply Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>
';
    // Insert before <!-- Mass Payment Modal -->
    $c = str_replace('<!-- Mass Payment Modal -->', $singlePaymentModal . "\n<!-- Mass Payment Modal -->", $c);
}

// 4. UPDATE JS DELEGATION
// Remove old btn-renew JS
$oldJsPattern = "/\s*\\$\\('#usersTable tbody'\\)\\.on\\('click',\s*'.btn-renew'.*?\\}\\);/s";
$c = preg_replace($oldJsPattern, "", $c);

// Inject new robust JS
$newJs = "
    // Robust DataTables Modal Triggers
    $('#usersTable tbody').on('click', '.btn-renew', function(e) {
        e.preventDefault();
        $('#renew_user_id').val($(this).attr('data-id'));
        $('#renew_username_display').text($(this).attr('data-username'));
        $('#renew_package_id').val($(this).attr('data-pkg'));
        var exp = $(this).attr('data-expiry');
        $('#renew_expiry_date').val(exp ? exp : '');
        var m = new bootstrap.Modal(document.getElementById('renewModal'));
        m.show();
    });

    $('#usersTable tbody').on('click', '.btn-payment', function(e) {
        e.preventDefault();
        $('#pay_user_id').val($(this).attr('data-id'));
        $('#pay_username_display').text($(this).attr('data-username'));
        $('#pay_current_balance').text($(this).attr('data-balance'));
        var m = new bootstrap.Modal(document.getElementById('singlePaymentModal'));
        m.show();
    });
    
    // Append modals to body to prevent z-index backdrop bugs inside card containers
    $('.modal').appendTo('body');
";

// Insert right before `});` closing the `$(document).ready`
$c = preg_replace("/(\\$\\('#selectAll'\\)\\.on\\('click', function\\(\\)\\{.*?\\}\\);)/s", "$1\n" . $newJs, $c);

file_put_contents($f, $c);
echo "operator/subscribers.php modified successfully!\n";
?>
