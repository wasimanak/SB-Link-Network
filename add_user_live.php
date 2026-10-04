<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

// 1. Inject IDs into Add User Modal
$c = str_replace('<select name="package_id" class="form-select" required>', '<select name="package_id" id="add_package_id" class="form-select" required>', $c);
$c = str_replace('<input type="datetime-local" name="expiry_date" class="form-control">', '<input type="datetime-local" name="expiry_date" id="add_expiry" class="form-control">', $c);

// 2. Inject live calculation box inside modal body
$liveBox = '
            <?php if(!$can_custom_expiry): ?>
                <input type="hidden" id="add_expiry" value="">
            <?php endif; ?>
            <div class="mt-4 p-3 bg-light border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Est. Initial Deduction</div>
                <h4 class="mb-0 fw-bold text-danger" id="add_live_deduction">Rs. 0.00</h4>
            </div>
';

// Put it right before the modal-footer ends modal-body
$c = preg_replace('/(<\/form>\s*<\/div>\s*<\/div>\s*<\/div>\s*<!-- Edit User Modal -->)/s', $liveBox . "\n" . '          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Create User</button>
          </div>
$1', $c);

// Wait, the regex might be tricky. Let's do it safer.
$oldFooterAddUser = '</div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create User</button>
        </div>
      </form>';

if (strpos($c, 'add_live_deduction') === false) {
    $c = str_replace($oldFooterAddUser, $liveBox . "\n" . $oldFooterAddUser, $c);
}

// 3. Add the JS calculation for Add User
$js = '
    function calculateAddDeduction() {
        let pkg = document.getElementById("add_package_id").value;
        let expiry_el = document.getElementById("add_expiry");
        let expiry_val = expiry_el ? expiry_el.value : "";

        let price = parseFloat(pkgPrices[pkg] || 0);
        if (price === 0) {
            document.getElementById("add_live_deduction").innerText = "Rs. 0.00";
            return;
        }

        let now_sec = Math.floor(Date.now() / 1000);
        let target_ts = 0;

        if (expiry_val) {
            target_ts = Math.floor(new Date(expiry_val).getTime() / 1000);
        } else {
            target_ts = now_sec + (30 * 86400);
        }

        let deduction = 0;
        if (target_ts > now_sec) {
            let days = Math.ceil((target_ts - now_sec) / 86400);
            deduction = days * (price / 30);
        }

        document.getElementById("add_live_deduction").innerText = "Rs. " + deduction.toFixed(2);
    }

    $("#add_package_id, #add_expiry").on("change input", calculateAddDeduction);
';

if (strpos($c, 'calculateAddDeduction') === false) {
    $c = str_replace('// Bind events', $js . "\n    // Bind events", $c);
    file_put_contents($f, $c);
    echo "Added live deduction visualizer to Add User modal.\n";
} else {
    echo "Already added.\n";
}
?>
