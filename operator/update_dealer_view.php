<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/dealer_view.php';
$content = file_get_contents($file);

// 1. Add POST handlers if they don't exist
$handlers = <<<'PHP'
// Handle Edit Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=? WHERE id=?");
    $stmt->execute([
        $_POST['full_name'], $_POST['username'], $_POST['national_id'], $_POST['email'], 
        $_POST['phone'], $_POST['franchise'], $_POST['address'], $_POST['city'], $dealer_id
    ]);
    echo "<script>alert('Profile updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Change Photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_photo') {
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target = '../uploads/dealers/' . time() . '_' . basename($_FILES['photo']['name']);
        if (!is_dir('../uploads/dealers/')) mkdir('../uploads/dealers/', 0777, true);
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            $pdo->prepare("UPDATE dealers SET photo=? WHERE id=?")->execute([$target, $dealer_id]);
            echo "<script>alert('Photo updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
        }
    }
}
// Handle Add Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $_POST['note']]);
    echo "<script>alert('Note added!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dealer_payment') {
    $amount = (float)$_POST['amount'];
    if ($_POST['payment_type'] === 'deduct') $amount = -$amount;
    $pdo->prepare("UPDATE dealers SET balance = balance + ? WHERE id=?")->execute([$amount, $dealer_id]);
    echo "<script>alert('Payment processed!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $pdo->prepare("UPDATE dealers SET password=? WHERE id=?")->execute([$_POST['new_password'], $dealer_id]);
    echo "<script>alert('Password updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Add Document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_document') {
    if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
        $target = '../uploads/dealer_docs/' . time() . '_' . basename($_FILES['document']['name']);
        if (!is_dir('../uploads/dealer_docs/')) mkdir('../uploads/dealer_docs/', 0777, true);
        if (move_uploaded_file($_FILES['document']['tmp_name'], $target)) {
            $pdo->prepare("INSERT INTO dealer_documents (dealer_id, title, file_path) VALUES (?, ?, ?)")->execute([$dealer_id, $_POST['title'], $target]);
            echo "<script>alert('Document added!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
        }
    }
}
// Handle Delete Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_profile') {
    $pdo->prepare("DELETE FROM dealers WHERE id=?")->execute([$dealer_id]);
    echo "<script>alert('Dealer deleted!'); window.location.href='dealers.php';</script>";
}
PHP;

if (strpos($content, "action === 'edit_profile'") === false) {
    $content = preg_replace('/(\/\/ Handle Permissions Update)/', $handlers . "\n\n$1", $content);
}

// 2. Modals HTML
$modals = <<<'HTML'
<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i> Edit Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-body">
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($dealer['full_name']) ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($dealer['username']) ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">National ID</label><input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($dealer['national_id']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($dealer['email']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($dealer['phone']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Franchise</label><input type="text" name="franchise" class="form-control" value="<?= htmlspecialchars($dealer['franchise']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($dealer['city']??'') ?>"></div>
                <div class="col-md-12 mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($dealer['address']??'') ?>"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Change Photo Modal -->
<div class="modal fade" id="changePhotoModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Change Photo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="change_photo">
        <div class="modal-body"><input type="file" name="photo" class="form-control" accept="image/*" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Upload</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Add Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body"><textarea name="note" class="form-control" rows="4" placeholder="Type note here..." required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save Note</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="dealer_payment">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Action</label>
                <select name="payment_type" class="form-select"><option value="add">Add Balance (+)</option><option value="deduct">Deduct Balance (-)</option></select>
            </div>
            <div class="mb-3">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" name="amount" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Confirm</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Change Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body"><input type="text" name="new_password" class="form-control" placeholder="New Password" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Update Password</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Add Document Modal -->
<div class="modal fade" id="addDocumentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Add Document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_document">
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Document Title</label><input type="text" name="title" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">File</label><input type="file" name="document" class="form-control" required></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Upload Document</button></div>
      </form>
    </div>
  </div>
</div>
HTML;

if (strpos($content, 'id="editProfileModal"') === false) {
    $content = str_replace('<!-- Settings Modal -->', $modals . "\n<!-- Settings Modal -->", $content);
}

// 3. Update CSS styling and buttons to match screenshot closely
// The buttons container currently is: <div class="action-btns">
// Let's replace the action-btns block completely with a more accurate grid.
$newActionBtns = <<<'HTML'
            <style>
            .action-btns {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-top: 20px;
            }
            .action-btns .btn {
                font-size: 0.85rem;
                font-weight: 600;
                padding: 12px 10px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                background: #fff;
                color: #334155;
                border: 1px solid #e2e8f0;
                box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                transition: 0.2s;
                text-align: left;
                white-space: normal;
                line-height: 1.2;
            }
            .action-btns .btn:hover { border-color: #cbd5e1; background: #f8fafc; }
            .action-btns .btn-dark-custom {
                background: #0f172a;
                color: #fff;
                border-color: #0f172a;
            }
            .action-btns .btn-dark-custom:hover { background: #1e293b; color: #fff; border-color: #1e293b; }
            .action-btns .btn i {
                font-size: 1.1rem;
                width: 28px;
                text-align: center;
                margin-right: 8px;
                color: #64748b;
            }
            .action-btns .btn-dark-custom i { color: #fff; }
            </style>
            
            <div class="action-btns">
                <button class="btn btn-dark-custom" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-pen-to-square"></i> Edit Profile</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#changePhotoModal"><i class="fa-regular fa-image"></i> Change<br>Photo</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#addNoteModal"><i class="fa-solid fa-file-lines"></i> Add Note</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="fa-brands fa-paypal"></i> Payment</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="fa-solid fa-lock"></i> Change<br>Password</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#setNewPackageModal"><i class="fa-solid fa-plus-square"></i> Set New<br>Package</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#addDocumentModal"><i class="fa-solid fa-file-arrow-up"></i> Add<br>Document</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="fa-solid fa-gear"></i> Settings</button>
                
                <form method="POST" class="m-0 p-0" onsubmit="return confirm('Are you sure you want to completely delete this dealer profile?');">
                    <input type="hidden" name="action" value="delete_profile">
                    <button type="submit" class="btn w-100 h-100"><i class="fa-solid fa-ban"></i> Delete<br>Profile</button>
                </form>
            </div>
HTML;

$content = preg_replace('/<div class="action-btns">.*?<\/div>\s*<\/div>\s*<\/div>\s*<!-- Right Content Area -->/s', $newActionBtns . "\n        </div>\n    </div>\n\n    <!-- Right Content Area -->", $content);

file_put_contents($file, $content);
echo "Updated dealer_view.php";
