<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/dealer_view.php';
$content = file_get_contents($file);

// 1. Update the POST handler for edit_profile
$oldHandler = <<<'PHP'
// Handle Edit Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=? WHERE id=?");
    $stmt->execute([
        $_POST['full_name'], $_POST['username'], $_POST['national_id'], $_POST['email'], 
        $_POST['phone'], $_POST['franchise'], $_POST['address'], $_POST['city'], $dealer_id
    ]);
    echo "<script>alert('Profile updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
PHP;

$newHandler = <<<'PHP'
// Handle Edit Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    if (!empty($_POST['password'])) {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, password=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=? WHERE id=?");
        $stmt->execute([
            $_POST['full_name'], $_POST['username'], $_POST['password'], $_POST['national_id'], $_POST['email'], 
            $_POST['phone'], $_POST['franchise'], $_POST['address'], $_POST['city'], $dealer_id
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=? WHERE id=?");
        $stmt->execute([
            $_POST['full_name'], $_POST['username'], $_POST['national_id'], $_POST['email'], 
            $_POST['phone'], $_POST['franchise'], $_POST['address'], $_POST['city'], $dealer_id
        ]);
    }
    echo "<script>alert('Profile updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
PHP;

$content = str_replace($oldHandler, $newHandler, $content);

// 2. Update the HTML in editProfileModal
$oldHtml = '<div class="col-md-6 mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($dealer[\'username\']) ?>" required></div>';
$newHtml = '<div class="col-md-6 mb-3"><label class="form-label">Username <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($dealer[\'username\']) ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Password</label><input type="text" name="password" class="form-control" placeholder="Leave blank to keep unchanged"></div>';

$content = str_replace($oldHtml, $newHtml, $content);

file_put_contents($file, $content);
echo "Updated Edit Profile functionality in dealer_view.php";
