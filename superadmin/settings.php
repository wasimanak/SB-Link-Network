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
            $success = "Global settings updated successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error updating settings: " . htmlspecialchars($e->getMessage());
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
                $_SESSION['superadmin_name'] = $name;
                $success = "Super Admin credentials updated successfully.";
            }
        }
    }
}

// Fetch settings
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
$settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fallbacks
$appName = $settings_raw['app_name'] ?? 'SB Link Network';
$timezone = $settings_raw['timezone'] ?? ($settings_raw['system_timezone'] ?? 'Asia/Karachi');
$currency = $settings_raw['currency'] ?? 'PKR';

// Fetch current superadmin details
$adminStmt = $pdo->prepare("SELECT name, username, email FROM super_admins WHERE id = ?");
$adminStmt->execute([$admin_id]);
$currentAdmin = $adminStmt->fetch();

// Database metrics
$dbName = $db ?? 'radius';
try {
    $tblCountStmt = $pdo->query("SHOW TABLES");
    $allTables = $tblCountStmt->fetchAll(PDO::FETCH_COLUMN);
    $totalTables = count($allTables);
} catch (Exception $e) {
    $totalTables = 0;
}
?>

<style>
    body {
        background-color: #f8fafc !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        color: #0f172a !important;
    }

    .card-ui {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        border: 1px solid #eef2f6;
    }

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
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
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
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
</style>

<div class="container-fluid px-4 py-4">

    <!-- Flash Messages -->
    <?php if($success): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check fs-5"></i> <div><?= $success ?></div>
        </div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-exclamation fs-5"></i> <div><?= $error ?></div>
        </div>
    <?php endif; ?>

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-gears"></i> Platform Preferences & Profile
                </div>
                <h3 class="fw-bold text-dark mb-1">Global System Settings</h3>
                <p class="text-muted small mb-0">Configure system parameters, update super admin credentials, and generate instant database disaster recovery dumps.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="download_backup.php" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-download me-1"></i> Download Backup (.sql)
                </a>
            </div>
        </div>
    </div>

    <!-- 3 Stat Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. System Identity -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Application Branding</div>
                    <div class="stat-pill-val text-truncate" title="<?= htmlspecialchars($appName) ?>"><?= htmlspecialchars($appName) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">TZ: <?= htmlspecialchars($timezone) ?></div>
                </div>
            </div>
        </div>

        <!-- 2. Super Admin Account -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Active Administrator</div>
                    <div class="stat-pill-val text-truncate"><?= htmlspecialchars($currentAdmin['name'] ?: 'Super Admin') ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">@<?= htmlspecialchars($currentAdmin['username']) ?></div>
                </div>
            </div>
        </div>

        <!-- 3. Database Status -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-database"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Database Tables</div>
                    <div class="stat-pill-val"><?= $totalTables ?> Tables Ready</div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;"><i class="fa-solid fa-circle-check"></i> FreeRADIUS & Portal DB</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Settings Grid -->
    <div class="row g-4 mb-4">
        
        <!-- 1. General Configuration -->
        <div class="col-lg-6">
            <div class="card-ui p-4 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom border-light-subtle">
                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0 fs-6">General System Configuration</h5>
                        <span class="text-muted small">Global labels, currency, and server timezone</span>
                    </div>
                </div>

                <form method="POST" class="flex-grow-1 d-flex flex-column">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">Application Title</label>
                        <input type="text" name="settings[app_name]" class="form-control" value="<?= htmlspecialchars($appName) ?>" required>
                        <div class="form-text text-muted" style="font-size: 0.74rem;">Brand name displayed across client and operator login screens.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">Server Timezone</label>
                        <select name="settings[timezone]" class="form-select">
                            <option value="Asia/Karachi" <?= $timezone === 'Asia/Karachi' ? 'selected' : '' ?>>Asia/Karachi (PKT +05:00)</option>
                            <option value="UTC" <?= $timezone === 'UTC' ? 'selected' : '' ?>>UTC (Coordinated Universal Time)</option>
                            <option value="Asia/Dubai" <?= $timezone === 'Asia/Dubai' ? 'selected' : '' ?>>Asia/Dubai (GST +04:00)</option>
                            <option value="America/New_York" <?= $timezone === 'America/New_York' ? 'selected' : '' ?>>America/New_York (EST -05:00)</option>
                            <option value="Europe/London" <?= $timezone === 'Europe/London' ? 'selected' : '' ?>>Europe/London (GMT +00:00)</option>
                        </select>
                        <div class="form-text text-muted" style="font-size: 0.74rem;">Affects subscriber expiration calculations and log timestamps.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-dark fw-bold small mb-1">Billing Currency Symbol</label>
                        <input type="text" name="settings[currency]" class="form-control" value="<?= htmlspecialchars($currency) ?>">
                        <div class="form-text text-muted" style="font-size: 0.74rem;">Currency code or symbol (e.g. PKR, Rs, USD).</div>
                    </div>

                    <div class="mt-auto pt-3 border-top border-light-subtle text-end">
                        <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 2. Super Admin Credentials -->
        <div class="col-lg-6">
            <div class="card-ui p-4 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom border-light-subtle">
                    <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0 fs-6">Super Admin Credentials</h5>
                        <span class="text-muted small">Update your name, login username, email, and password</span>
                    </div>
                </div>

                <form method="POST" class="flex-grow-1 d-flex flex-column">
                    <input type="hidden" name="action" value="update_credentials">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($currentAdmin['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">Login Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($currentAdmin['username']) ?>" required autocomplete="off">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($currentAdmin['email']) ?>" required autocomplete="off">
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-dark fw-bold small mb-1">Change Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="admin_password" class="form-control" placeholder="Leave empty to retain current password" autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('admin_password'); p.type = p.type === 'password' ? 'text' : 'password';">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.74rem;">Leave blank if you do not want to change your current password.</div>
                    </div>

                    <div class="mt-auto pt-3 border-top border-light-subtle text-end">
                        <button type="submit" class="btn btn-danger px-4 rounded-pill fw-bold shadow-sm">
                            <i class="fa-solid fa-shield-halved me-1"></i> Update Credentials
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 3. Database Disaster Recovery & Backup Card -->
        <div class="col-12">
            <div class="card-ui p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; flex-shrink: 0;">
                            <i class="fa-solid fa-database fs-4"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-1">Full Database Backup & Disaster Recovery</h5>
                            <p class="text-muted small mb-0" style="max-width: 750px;">
                                Generates and streams a complete <strong>.SQL</strong> dump of all FreeRADIUS tables (`radcheck`, `radreply`, `radacct`, `nas`), tenant profiles, subscribers, packages, ledgers, and configurations directly to your local computer.
                            </p>
                        </div>
                    </div>
                    <div>
                        <a href="download_backup.php" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-cloud-arrow-down fs-5"></i>
                            <span>Download Full Backup (.SQL)</span>
                        </a>
                    </div>
                </div>

                <div class="row g-3 mt-3 pt-3 border-top border-light-subtle">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <i class="fa-solid fa-check-double text-success"></i>
                            <span>Pure PHP Native Exporter (Works on any VPS/Hostinger)</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <i class="fa-solid fa-shield-virus text-primary"></i>
                            <span>Includes complete table structures & data records</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <i class="fa-solid fa-bolt text-warning"></i>
                            <span>Restorable via phpMyAdmin or MySQL CLI</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<?php require_once 'footer.php'; ?>
