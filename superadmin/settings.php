<?php
require_once 'header.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

// Fetch settings
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
$settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fallbacks
$appName = $settings_raw['app_name'] ?? 'SB Link Network';
$timezone = $settings_raw['timezone'] ?? 'Asia/Karachi';
$currency = $settings_raw['currency'] ?? 'PKR';
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold mb-1">Global Settings</h3>
        <p class="text-secondary">Configure system-wide settings.</p>
    </div>
</div>

<?php if($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-2">
                <h5 class="fw-bold mb-0">General Configuration</h5>
            </div>
            <div class="card-body">
                <form method="POST">
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
    
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-2">
                <h5 class="fw-bold mb-0">Database Backup</h5>
            </div>
            <div class="card-body">
                <p class="text-secondary">Download a full backup of the RADIUS and Portal database (`radius_admin`).</p>
                <button type="button" class="btn btn-outline-dark fw-semibold" onclick="alert('Backup feature will trigger a mysqldump download in production.')">
                    <i class="fa-solid fa-download me-2"></i> Download Full Backup
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
