<?php
require_once 'header.php';

$success = '';
$error = '';
$admin_id = $_SESSION['superadmin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_settings') {
        try {
            $pdo->beginTransaction();
            foreach ($_POST['settings'] as $key => $value) {
                $stmt = $pdo->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$value, $key]);
            }
            $pdo->commit();
            $success = "Settings updated successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error updating settings: " . $e->getMessage();
        }
    } 
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_credentials') {
        $name = trim($_POST['name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        if (empty($name) || empty($username) || empty($email)) {
            $error = "Name, Username, and Email are required.";
        } else {
            // Check if username or email already exists for another admin
            $check = $pdo->prepare("SELECT id FROM super_admins WHERE (username = ? OR email = ?) AND id != ?");
            $check->execute([$username, $email, $admin_id]);
            if ($check->rowCount() > 0) {
                $error = "Username or Email already belongs to another account.";
            } else {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE super_admins SET name = ?, username = ?, email = ?, password = ? WHERE id = ?");
                    $stmt->execute([$name, $username, $email, $hash, $admin_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE super_admins SET name = ?, username = ?, email = ? WHERE id = ?");
                    $stmt->execute([$name, $username, $email, $admin_id]);
                }
                $_SESSION['superadmin_name'] = $name; // Update session with new name
                $success = "Credentials updated successfully.";
            }
        }
    }
}

// Fetch settings
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
$settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fallbacks
$appName = $settings_raw['app_name'] ?? 'SB Link Network';
$timezone = $settings_raw['timezone'] ?? 'Asia/Karachi';
$currency = $settings_raw['currency'] ?? 'PKR';

// Fetch current superadmin details
$adminStmt = $pdo->prepare("SELECT name, username, email FROM super_admins WHERE id = ?");
$adminStmt->execute([$admin_id]);
$currentAdmin = $adminStmt->fetch();
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold mb-1">Global Settings</h3>
        <p class="text-secondary">Configure system-wide settings and manage your profile.</p>
    </div>
</div>

<?php if($success): ?><div class="alert alert-success shadow-sm"><i class="fa-solid fa-check-circle me-2"></i><?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger shadow-sm"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?></div><?php endif; ?>

<div class="row">
    <!-- General Settings -->
    <div class="col-md-6 mb-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-2">
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-sliders me-2 text-primary"></i> General Configuration</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_settings">
                    <div class="mb-3">
                        <label class="form-label text-secondary fw-semibold">Application Name</label>
                        <input type="text" name="settings[app_name]" class="form-control" value="<?= htmlspecialchars($appName) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-secondary fw-semibold">Timezone</label>
                        <select name="settings[timezone]" class="form-select">
                            <option value="Asia/Karachi" <?= $timezone === 'Asia/Karachi' ? 'selected' : '' ?>>Asia/Karachi</option>
                            <option value="UTC" <?= $timezone === 'UTC' ? 'selected' : '' ?>>UTC</option>
                            <option value="America/New_York" <?= $timezone === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-secondary fw-semibold">Currency Symbol</label>
                        <input type="text" name="settings[currency]" class="form-control" value="<?= htmlspecialchars($currency) ?>">
                    </div>

                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Settings</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Super Admin Credentials -->
    <div class="col-md-6 mb-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-2">
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-user-shield me-2 text-danger"></i> Update Credentials</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_credentials">
                    <div class="mb-3">
                        <label class="form-label text-secondary fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($currentAdmin['name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-secondary fw-semibold">Username</label>
                        <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($currentAdmin['username']) ?>" required autocomplete="off">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-secondary fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($currentAdmin['email']) ?>" required autocomplete="off">
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-secondary fw-semibold">New Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="admin_password" class="form-control" placeholder="Leave blank to keep current password" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('admin_password'); p.type = p.type === 'password' ? 'text' : 'password';"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger px-4 fw-semibold"><i class="fa-solid fa-floppy-disk me-2"></i> Update Credentials</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Database Backup -->
    <div class="col-md-6 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-2">
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-database me-2 text-success"></i> Database Backup</h5>
            </div>
            <div class="card-body">
                <p class="text-secondary">Download a full backup of the RADIUS and Portal database.</p>
                <button type="button" class="btn btn-outline-dark fw-semibold" onclick="alert('Backup feature will trigger a mysqldump download in production.')">
                    <i class="fa-solid fa-download me-2"></i> Download Full Backup
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
