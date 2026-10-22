<?php
// Handle AJAX Identity Verification for Credential Vault Reveal
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reveal_db_credentials') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    header('Content-Type: application/json; charset=utf-8');

    $admin_id = $_SESSION['superadmin_id'] ?? 0;
    $verify_password = $_POST['verify_password'] ?? '';

    if (empty($verify_password)) {
        echo json_encode(['success' => false, 'message' => 'Please enter password to verify identity.']);
        exit;
    }

    $is_valid = false;

    // Check SuperAdmin password
    try {
        $stmt = $pdo->prepare("SELECT password FROM super_admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $hash = $stmt->fetchColumn();
        if ($hash && password_verify($verify_password, $hash)) {
            $is_valid = true;
        }
    } catch (Exception $e) {}

    // Also check against VPS DB password (e.g. Wasi1234)
    if (!$is_valid && !empty($pass) && $verify_password === $pass) {
        $is_valid = true;
    }

    if (!$is_valid) {
        echo json_encode(['success' => false, 'message' => 'Incorrect password. Verification failed.']);
        exit;
    }

    // Fetch settings for custom overrides
    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
    $settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $vps_host = $settings_raw['server_public_ip'] ?? ($_SERVER['SERVER_ADDR'] ?? '187.126.127.237');
    if ($vps_host === '127.0.0.1' || $vps_host === '::1') {
        $vps_host = '187.126.127.237';
    }

    $db_port = $settings_raw['server_db_port'] ?? '3306';
    $db_name = $settings_raw['server_db_name'] ?? ($db ?? 'radius');
    $db_user = $settings_raw['server_db_user'] ?? ($user ?? 'radius');
    $db_pass = $settings_raw['server_db_pass'] ?? ($pass ?? 'Wasi1234');

    // Saved Remote Old VPS Sync Credentials
    $saved_remote_host = $settings_raw['sync_remote_host'] ?? '';
    $saved_remote_port = $settings_raw['sync_remote_port'] ?? '3306';
    $saved_remote_db   = $settings_raw['sync_remote_db'] ?? 'radius';
    $saved_remote_user = $settings_raw['sync_remote_user'] ?? '';
    $saved_remote_pass = $settings_raw['sync_remote_pass'] ?? '';

    // FreeRADIUS Credentials
    $radius_secret    = $settings_raw['radius_secret'] ?? '123456';
    $radius_auth_port = $settings_raw['radius_auth_port'] ?? '1812';
    $radius_acct_port = $settings_raw['radius_acct_port'] ?? '1813';
    $radius_coa_port  = $settings_raw['radius_coa_port'] ?? '3799';

    echo json_encode([
        'success' => true,
        'message' => 'Identity verified successfully!',
        'credentials' => [
            // Current Server Live DB
            'host' => $vps_host,
            'port' => $db_port,
            'db'   => $db_name,
            'user' => $db_user,
            'pass' => $db_pass,

            // Saved Remote Sync DB
            'remote_host' => $saved_remote_host,
            'remote_port' => $saved_remote_port,
            'remote_db'   => $saved_remote_db,
            'remote_user' => $saved_remote_user,
            'remote_pass' => $saved_remote_pass,

            // FreeRADIUS & MikroTik
            'radius_secret'    => $radius_secret,
            'radius_auth_port' => $radius_auth_port,
            'radius_acct_port' => $radius_acct_port,
            'radius_coa_port'  => $radius_coa_port,
        ]
    ]);
    exit;
}

require_once 'header.php';

$success = '';
$error = '';
$admin_id = $_SESSION['superadmin_id'];

// Fetch current global settings
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
$settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// -------------------------------------------------------------
// POST HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Update Super Admin Account Credentials & Login Password
    if (isset($_POST['action']) && $_POST['action'] === 'update_credentials') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

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
                $success = "Super Admin account credentials & password updated successfully.";
            }
        }
    }
    
    // 2. Change Live MariaDB Password & Auto-Sync
    elseif (isset($_POST['action']) && $_POST['action'] === 'change_mariadb_password') {
        $current_pass = $_POST['current_mariadb_password'] ?? '';
        $new_pass     = $_POST['new_mariadb_password'] ?? '';
        $confirm_pass = $_POST['confirm_mariadb_password'] ?? '';

        if (empty($new_pass)) {
            $error = "New MariaDB password cannot be empty.";
        } elseif ($new_pass !== $confirm_pass) {
            $error = "New password and Confirm password do not match.";
        } elseif (strlen($new_pass) < 4) {
            $error = "New password must be at least 4 characters long.";
        } else {
            // Verify current password or authenticate
            $currentExpected = $settings_raw['server_db_pass'] ?? ($pass ?? 'Wasi1234');
            $authOk = false;

            if ($current_pass === $currentExpected || $current_pass === $pass) {
                $authOk = true;
            } else {
                // Also accept current logged-in SuperAdmin's account password
                $saCheck = $pdo->prepare("SELECT password FROM super_admins WHERE id = ?");
                $saCheck->execute([$admin_id]);
                $saHash = $saCheck->fetchColumn();
                if ($saHash && password_verify($current_pass, $saHash)) {
                    $authOk = true;
                }
            }

            if (!$authOk) {
                $error = "Verification failed: The current MariaDB password or SuperAdmin password you entered is incorrect.";
            } else {
                try {
                    $dbUser = $settings_raw['server_db_user'] ?? ($user ?? 'radius');
                    
                    // 1. Execute SQL to change password in MariaDB / MySQL
                    $executed = false;
                    $lastSqlError = '';

                    // Method 1: ALTER USER CURRENT_USER()
                    try {
                        $pdo->exec("ALTER USER CURRENT_USER() IDENTIFIED BY " . $pdo->quote($new_pass));
                        $executed = true;
                    } catch (Exception $e1) {
                        $lastSqlError = $e1->getMessage();
                    }

                    // Method 2: ALTER USER 'user'@'localhost' & 'user'@'%'
                    if (!$executed) {
                        try {
                            $pdo->exec("ALTER USER '{$dbUser}'@'localhost' IDENTIFIED BY " . $pdo->quote($new_pass));
                            try { $pdo->exec("ALTER USER '{$dbUser}'@'%' IDENTIFIED BY " . $pdo->quote($new_pass)); } catch(Exception $eIgnore) {}
                            $executed = true;
                        } catch (Exception $e2) {
                            $lastSqlError = $e2->getMessage();
                        }
                    }

                    // Method 3: SET PASSWORD = PASSWORD(...)
                    if (!$executed) {
                        try {
                            $pdo->exec("SET PASSWORD = PASSWORD(" . $pdo->quote($new_pass) . ")");
                            $executed = true;
                        } catch (Exception $e3) {
                            $lastSqlError = $e3->getMessage();
                        }
                    }

                    if (!$executed) {
                        throw new Exception("MariaDB rejected password update: " . $lastSqlError);
                    }

                    $pdo->exec("FLUSH PRIVILEGES;");

                    // 2. Automatically update config/db.php on disk
                    $dbConfigFile = __DIR__ . '/../config/db.php';
                    $configUpdated = false;
                    if (file_exists($dbConfigFile)) {
                        $configContent = @file_get_contents($dbConfigFile);
                        if ($configContent) {
                            $pattern = '/(else\s*\{[^}]*?\$pass\s*=\s*)([\'"].*?[\'"])/s';
                            if (preg_match($pattern, $configContent)) {
                                $escapedPass = var_export($new_pass, true);
                                $configContent = preg_replace($pattern, '${1}' . $escapedPass, $configContent);
                                @file_put_contents($dbConfigFile, $configContent);
                                $configUpdated = true;
                            }
                        }
                    }

                    // 3. Update restorebackup.php on disk if exists
                    $restoreFile = __DIR__ . '/../restorebackup.php';
                    if (file_exists($restoreFile)) {
                        $rc = @file_get_contents($restoreFile);
                        if ($rc) {
                            $pattern = '/(\$default_pass\s*=\s*)([\'"].*?[\'"])/';
                            if (preg_match($pattern, $rc)) {
                                $rc = preg_replace($pattern, '${1}' . var_export($new_pass, true), $rc);
                                @file_put_contents($restoreFile, $rc);
                            }
                        }
                    }

                    // 4. Update global_settings
                    $upStmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $upStmt->execute(['server_db_pass', $new_pass, $new_pass]);

                    // 5. Try updating FreeRADIUS sql config if accessible on Linux
                    $frSqlPaths = [
                        '/etc/freeradius/3.0/mods-available/sql',
                        '/etc/freeradius/mods-available/sql',
                        '/etc/raddb/mods-available/sql'
                    ];
                    $frUpdated = false;
                    foreach ($frSqlPaths as $frPath) {
                        if (file_exists($frPath) && is_writable($frPath)) {
                            $frText = @file_get_contents($frPath);
                            if ($frText) {
                                $frText = preg_replace('/(\bpassword\s*=\s*)(["\'].*?["\'])/', '${1}"' . addslashes($new_pass) . '"', $frText);
                                @file_put_contents($frPath, $frText);
                                $frUpdated = true;
                            }
                        }
                    }

                    $success = "MariaDB password successfully changed and updated in database, <code>config/db.php</code>, and system settings!" . 
                               ($frUpdated ? " FreeRADIUS SQL module configuration was also automatically updated." : "");

                    // Refresh settings
                    $setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
                    $settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                } catch (Exception $e) {
                    $error = "Failed to update MariaDB password: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }

    // 3. Update All Database & RADIUS Credentials
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_database_credentials') {
        $allowed = [
            'server_public_ip', 'server_db_port', 'server_db_name', 'server_db_user', 'server_db_pass',
            'sync_remote_host', 'sync_remote_port', 'sync_remote_db', 'sync_remote_user', 'sync_remote_pass',
            'radius_secret', 'radius_auth_port', 'radius_acct_port', 'radius_coa_port'
        ];
        try {
            $pdo->beginTransaction();
            foreach ($allowed as $k) {
                if (isset($_POST[$k])) {
                    $val = trim($_POST[$k]);
                    $stmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$k, $val, $val]);
                }
            }

            // If radius_secret updated and checkbox ticked, also update in nas table
            if (!empty($_POST['radius_secret']) && isset($_POST['sync_nas_secret'])) {
                $newSec = trim($_POST['radius_secret']);
                $nasUpStmt = $pdo->prepare("UPDATE nas SET secret = ?");
                $nasUpStmt->execute([$newSec]);
            }

            $pdo->commit();
            $success = "All database and FreeRADIUS connection credentials updated successfully!";

            // Refresh settings
            $setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
            $settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update database credentials: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Fetch SuperAdmin details
$adminStmt = $pdo->prepare("SELECT name, username, email FROM super_admins WHERE id = ?");
$adminStmt->execute([$admin_id]);
$currentAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => '', 'username' => '', 'email' => ''];

// Database & Credentials Configuration
$currentServerHost = $settings_raw['server_public_ip'] ?? ($_SERVER['SERVER_ADDR'] ?? '187.126.127.237');
if ($currentServerHost === '127.0.0.1' || $currentServerHost === '::1') {
    $currentServerHost = '187.126.127.237';
}
$currentServerPort = $settings_raw['server_db_port'] ?? '3306';
$currentServerDb   = $settings_raw['server_db_name'] ?? ($db ?? 'radius');
$currentServerUser = $settings_raw['server_db_user'] ?? ($user ?? 'radius');
$currentServerPass = $settings_raw['server_db_pass'] ?? ($pass ?? 'Wasi1234');

// Saved Remote VPS Sync Settings
$savedSyncHost = $settings_raw['sync_remote_host'] ?? '';
$savedSyncPort = $settings_raw['sync_remote_port'] ?? '3306';
$savedSyncDb   = $settings_raw['sync_remote_db'] ?? 'radius';
$savedSyncUser = $settings_raw['sync_remote_user'] ?? 'radius';
$savedSyncPass = $settings_raw['sync_remote_pass'] ?? '';

// FreeRADIUS Ports & Secret
$radiusSecret   = $settings_raw['radius_secret'] ?? '123456';
$radiusAuthPort = $settings_raw['radius_auth_port'] ?? '1812';
$radiusAcctPort = $settings_raw['radius_acct_port'] ?? '1813';
$radiusCoaPort  = $settings_raw['radius_coa_port'] ?? '3799';

// Database Metrics & Health
$dbMetrics = [
    'mysql_version'     => 'Unknown',
    'db_size_mb'        => '0.00',
    'total_tables'      => 0,
    'subscribers_count' => 0,
    'nas_count'         => 0
];
try {
    $vStmt = $pdo->query("SELECT VERSION()");
    $dbMetrics['mysql_version'] = $vStmt->fetchColumn() ?: 'MySQL/MariaDB';

    $sizeStmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE table_schema = DATABASE()");
    $dbMetrics['db_size_mb'] = $sizeStmt->fetchColumn() ?: '0.00';

    $tblStmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()");
    $dbMetrics['total_tables'] = (int)($tblStmt->fetchColumn() ?: 0);

    $subStmt = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct");
    $dbMetrics['subscribers_count'] = (int)($subStmt->fetchColumn() ?: 0);

    $nasStmt = $pdo->query("SELECT COUNT(*) FROM nas");
    $dbMetrics['nas_count'] = (int)($nasStmt->fetchColumn() ?: 0);
} catch (Exception $e) {}
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
        border: 1px solid #eef2f6;
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.03);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .card-ui:hover {
        box-shadow: 0 12px 30px -4px rgba(0, 0, 0, 0.06);
    }
    .form-control, .form-select {
        border-radius: 12px;
        border: 1.5px solid #e2e8f0;
        padding: 0.65rem 1rem;
        font-size: 0.95rem;
        background-color: #f8fafc;
        transition: all 0.2s ease;
    }
    .form-control:focus, .form-select:focus {
        background-color: #ffffff;
        border-color: #3b82f6;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    .shadow-xs {
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }
</style>

<div class="container-fluid px-4 py-4">

    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
                    <i class="fa-solid fa-shield-halved fs-5"></i>
                </div>
                <h3 class="fw-bold text-dark mb-0">Security &amp; Passwords Management</h3>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 ms-1">
                    <i class="fa-solid fa-lock me-1"></i> Highly Sensitive
                </span>
            </div>
            <p class="text-muted small mb-0">
                Super Admin authentication, Live MariaDB database root password, and FreeRADIUS shared secrets aik hi mahfooz jagah se manage karein.
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-warning rounded-pill px-3 py-2 fw-bold shadow-sm text-dark d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#changeMariaDbModal">
                <i class="fa-solid fa-key"></i> Change MariaDB Password
            </button>
            <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#editDbCredentialsModal">
                <i class="fa-solid fa-pen-to-square"></i> Edit All Credentials
            </button>
            <a href="settings" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-sliders"></i> Global Settings
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($success): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-3 p-3">
            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-check fs-5"></i>
            </div>
            <div>
                <strong class="d-block text-success">Success!</strong>
                <span class="small"><?= $success ?></span>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-3 p-3">
            <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-triangle-exclamation fs-5"></i>
            </div>
            <div>
                <strong class="d-block text-danger">Action Notice</strong>
                <span class="small"><?= $error ?></span>
            </div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- =================================================================== -->
    <!-- 1. DATABASE & RADIUS CREDENTIALS CONTROL CENTER (VAULT)            -->
    <!-- =================================================================== -->
    <div class="card-ui p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3 pb-3 border-bottom border-light-subtle">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; flex-shrink: 0;">
                    <i class="fa-solid fa-database fs-4"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h5 class="fw-bold text-dark mb-0 fs-5">Database &amp; RADIUS Credentials Vault</h5>
                        <span id="vault_badge" class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1">
                            <i class="fa-solid fa-lock me-1"></i> Hidden &amp; Locked
                        </span>
                    </div>
                    <p class="text-muted small mb-0">
                        Live MySQL parameters, saved remote VPS sync profiles, aur FreeRADIUS secrets. Defaults masked with eye verification.
                    </p>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" id="btn_toggle_vault" class="btn btn-dark rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm d-inline-flex align-items-center gap-1" onclick="handleVaultToggle()">
                    <i class="fa-solid fa-eye" id="vault_btn_icon"></i>
                    <span id="vault_btn_text">Reveal Credentials</span>
                </button>
                <button type="button" id="btn_change_mariadb_pwd" class="btn btn-warning rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm text-dark d-none align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#changeMariaDbModal">
                    <i class="fa-solid fa-key"></i>
                    <span>Change MariaDB Password</span>
                </button>
                <button type="button" id="btn_edit_credentials" class="btn btn-primary rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm d-none align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#editDbCredentialsModal">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Manage / Edit</span>
                </button>
                <button type="button" id="btn_copy_all" class="btn btn-outline-primary rounded-pill px-3 py-2 btn-sm fw-bold d-none align-items-center gap-1" onclick="copyAllVaultCredentials()">
                    <i class="fa-solid fa-copy"></i>
                    <span>Copy All</span>
                </button>
            </div>
        </div>

        <!-- Database Health & Engine Metrics Strip -->
        <div class="row g-2 mb-4">
            <div class="col-md-3 col-6">
                <div class="bg-light p-3 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                    <i class="fa-solid fa-microchip text-primary fs-4"></i>
                    <div class="overflow-hidden">
                        <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">DB Engine Version</span>
                        <strong class="text-dark small text-truncate d-block" title="<?= htmlspecialchars($dbMetrics['mysql_version'] ?: 'MySQL/MariaDB') ?>">
                            <?= htmlspecialchars(explode('-', $dbMetrics['mysql_version'])[0] ?: 'MySQL Ready') ?>
                        </strong>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="bg-light p-3 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                    <i class="fa-solid fa-hard-drive text-success fs-4"></i>
                    <div>
                        <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">DB Storage Size</span>
                        <strong class="text-dark small"><?= htmlspecialchars($dbMetrics['db_size_mb']) ?> MB (<?= $dbMetrics['total_tables'] ?> Tables)</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="bg-light p-3 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                    <i class="fa-solid fa-users text-info fs-4"></i>
                    <div>
                        <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">Total Subscribers</span>
                        <strong class="text-dark small"><?= number_format($dbMetrics['subscribers_count']) ?> Accounts</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="bg-light p-3 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                    <i class="fa-solid fa-network-wired text-warning fs-4"></i>
                    <div>
                        <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">Connected NAS Routers</span>
                        <strong class="text-dark small"><?= number_format($dbMetrics['nas_count']) ?> Routers</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section A: Current Live Server DB Credentials -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-primary text-white rounded-pill px-2 py-1" style="font-size: 0.7rem;">Active Profile</span>
                <strong class="text-dark small">This Server Live MySQL Database (Outgoing Sync Profile)</strong>
            </div>
            <div class="row g-2">
                <div class="col-md-3 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Server IP / Host</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_host', this)" title="Copy Host">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_host">••••••••••••••</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">MySQL Port</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_port', this)" title="Copy Port">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_port">••••</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Database Name</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_db', this)" title="Copy DB">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_db">••••••</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">DB Username</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_user', this)" title="Copy User">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_user">••••••</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-12">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">DB Password</span>
                            <div class="d-flex align-items-center gap-1">
                                <button type="button" class="btn btn-link p-0 text-warning d-none vault-change-db-pwd" data-bs-toggle="modal" data-bs-target="#changeMariaDbModal" title="Change MariaDB Password">
                                    <i class="fa-solid fa-key"></i>
                                </button>
                                <button type="button" class="btn btn-link p-0 text-muted d-none vault-pwd-toggle" onclick="toggleVaultPasswordVisibility(this)" title="Show/Hide">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_pass_plain', this)" title="Copy Password">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_pass">••••••••••••</div>
                        <input type="hidden" id="vault_pass_plain" value="">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section B: Saved Remote Old VPS Credentials -->
        <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-secondary text-white rounded-pill px-2 py-1" style="font-size: 0.7rem;">Saved Target</span>
                <strong class="text-dark small">Saved Remote (Old VPS) Sync Credentials</strong>
            </div>
            <div class="row g-2">
                <div class="col-md-3 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Remote Host / IP</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_remote_host', this)" title="Copy Remote IP">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_remote_host">••••••••••••••</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Remote Port</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_remote_port', this)" title="Copy Remote Port">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_remote_port">••••</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Remote DB</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_remote_db', this)" title="Copy Remote DB">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_remote_db">••••••</div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Remote User</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_remote_user', this)" title="Copy Remote User">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_remote_user">••••••</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-12">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Remote Password</span>
                            <div class="d-flex align-items-center gap-1">
                                <button type="button" class="btn btn-link p-0 text-muted d-none vault-remote-pwd-toggle" onclick="toggleVaultRemotePasswordVisibility(this)" title="Show/Hide">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_remote_pass_plain', this)" title="Copy Remote Password">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_remote_pass">••••••••••••</div>
                        <input type="hidden" id="vault_remote_pass_plain" value="">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section C: FreeRADIUS & MikroTik Shared Secret -->
        <div>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-warning text-dark rounded-pill px-2 py-1" style="font-size: 0.7rem;">FreeRADIUS AAA</span>
                <strong class="text-dark small">FreeRADIUS &amp; MikroTik Shared Secret &amp; Ports</strong>
            </div>
            <div class="row g-2">
                <div class="col-md-3 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">RADIUS Secret</span>
                            <div class="d-flex align-items-center gap-1">
                                <button type="button" class="btn btn-link p-0 text-muted d-none vault-radius-pwd-toggle" onclick="toggleVaultRadiusSecretVisibility(this)" title="Show/Hide">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_radius_secret_plain', this)" title="Copy Secret">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_radius_secret">••••••••</div>
                        <input type="hidden" id="vault_radius_secret_plain" value="">
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Auth Port (UDP)</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_radius_auth', this)" title="Copy Auth Port">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_radius_auth">••••</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Accounting Port (UDP)</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_radius_acct', this)" title="Copy Acct Port">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_radius_acct">••••</div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="bg-white p-2 px-3 rounded-3 border border-light-subtle shadow-xs h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">CoA / Disconnect Port</span>
                            <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_radius_coa', this)" title="Copy CoA Port">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace small" id="vault_radius_coa">••••</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- 2. TWO COLUMN CARDS: SUPER ADMIN CREDENTIALS & MARIADB CONTROLS     -->
    <!-- =================================================================== -->
    <div class="row g-4 mb-4">
        
        <!-- Left: Super Admin Account Authentication -->
        <div class="col-lg-6">
            <div class="card-ui p-4 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom border-light-subtle">
                    <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; flex-shrink: 0;">
                        <i class="fa-solid fa-user-shield fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0 fs-6">Super Admin Login Credentials</h5>
                        <span class="text-muted small">Update your name, username, email, and SuperAdmin login password</span>
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
                        <label class="form-label text-dark fw-bold small mb-1">Change Account Password</label>
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
                            <i class="fa-solid fa-shield-halved me-1"></i> Update Admin Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Live MariaDB Database Server Security -->
        <div class="col-lg-6">
            <div class="card-ui p-4 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom border-light-subtle">
                    <div class="rounded-circle bg-warning-subtle text-warning-emphasis d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; flex-shrink: 0;">
                        <i class="fa-solid fa-key fs-5 text-dark"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="fw-bold text-dark mb-0 fs-6">Live MariaDB / MySQL Server Password</h5>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0" style="font-size: 0.68rem;">Live Synced</span>
                        </div>
                        <span class="text-muted small">Database engine credentials and automatic web config synchronization</span>
                    </div>
                </div>

                <div class="flex-grow-1">
                    <div class="alert alert-light border border-light-subtle rounded-3 p-3 mb-3">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-circle-info text-primary mt-1"></i>
                            <div class="small">
                                <strong>Zero-Downtime Synchronization:</strong>
                                Jab aap yahan se MariaDB ka password change karte hain to system automatically 
                                <code>config/db.php</code>, <code>restorebackup.php</code>, aur Linux FreeRADIUS SQL configurations ko aik sath update kar deta hai ta k application disconnect na ho.
                            </div>
                        </div>
                    </div>

                    <div class="bg-light p-3 rounded-3 border border-light-subtle mb-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <span class="text-muted d-block small" style="font-size: 0.72rem; text-transform: uppercase;">Active DB User</span>
                                <strong class="text-dark small font-monospace"><?= htmlspecialchars($currentServerUser) ?>@localhost</strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block small" style="font-size: 0.72rem; text-transform: uppercase;">Port &amp; Connection</span>
                                <strong class="text-dark small font-monospace"><?= htmlspecialchars($currentServerPort) ?> (Active)</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-auto pt-3 border-top border-light-subtle d-flex justify-content-between align-items-center">
                    <span class="text-muted small">
                        <i class="fa-solid fa-lock text-success me-1"></i> Dual Auth Protected
                    </span>
                    <button type="button" class="btn btn-warning px-4 rounded-pill fw-bold shadow-sm text-dark d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#changeMariaDbModal">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Change MariaDB Password</span>
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- =================================================================== -->
    <!-- 3. FREERADIUS AAA & NETWORK SECRETS STRIP                           -->
    <!-- =================================================================== -->
    <div class="card-ui p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info-subtle text-info-emphasis d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; flex-shrink: 0;">
                    <i class="fa-solid fa-satellite-dish fs-5 text-primary"></i>
                </div>
                <div>
                    <h6 class="fw-bold text-dark mb-1">FreeRADIUS Shared Secrets &amp; MikroTik NAS</h6>
                    <span class="text-muted small">
                        MikroTik routers FreeRADIUS server se authenticate hone k liye shared secret istemal karte hain. Naye router add karte waqt ya secret change karne k liye credentials vault istemal karein.
                    </span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#editDbCredentialsModal">
                    <i class="fa-solid fa-pen-to-square"></i> Configure Secrets
                </button>
                <a href="routers" class="btn btn-outline-secondary rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-server"></i> View Routers (<?= $dbMetrics['nas_count'] ?>)
                </a>
            </div>
        </div>
    </div>

</div>

<!-- ======================================================================= -->
<!-- MODAL 1: UNLOCK VAULT VERIFICATION                                      -->
<!-- ======================================================================= -->
<div class="modal fade" id="unlockVaultModal" tabindex="-1" aria-labelledby="unlockVaultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-warning"></i>
                    <h6 class="modal-title fw-bold mb-0" id="unlockVaultModalLabel">Verify Identity</h6>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form onsubmit="handleVaultUnlockSubmit(event)">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        Enter your <strong>Super Admin Password</strong> or <strong>VPS Password</strong> to reveal this server's live database credentials.
                    </p>
                    <div class="mb-2">
                        <label class="form-label text-dark fw-bold small mb-1">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" id="vault_verify_input" class="form-control" placeholder="Enter password" required autocomplete="current-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('vault_verify_input'); p.type = p.type === 'password' ? 'text' : 'password';">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div id="vault_error_msg" class="text-danger small mt-2 d-none"></div>
                </div>
                <div class="modal-footer bg-light border-top border-light-subtle py-2 px-3 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="btn_verify_vault_submit" class="btn btn-primary rounded-pill btn-sm px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-lock-open me-1"></i> Verify &amp; Reveal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 2: CHANGE LIVE MARIADB PASSWORD                                   -->
<!-- ======================================================================= -->
<div class="modal fade" id="changeMariaDbModal" tabindex="-1" aria-labelledby="changeMariaDbModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning text-dark py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-key fs-5"></i>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="changeMariaDbModalLabel">Change Live MariaDB Password</h6>
                        <span class="small text-muted" style="font-size: 0.73rem;">Updates MariaDB server, <code>config/db.php</code>, and FreeRADIUS</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" id="formChangeMariaDb" onsubmit="handleChangeDbPassSubmit(event)">
                <input type="hidden" name="action" value="change_mariadb_password">

                <div class="modal-body p-4">
                    <div class="alert alert-warning border-0 rounded-3 p-3 small mb-3">
                        <div class="d-flex gap-2">
                            <i class="fa-solid fa-triangle-exclamation fs-5 flex-shrink-0 mt-1"></i>
                            <div>
                                <strong>System Notice:</strong> Yeh process MariaDB me user password update karega aur live <code>config/db.php</code> file aur FreeRADIUS SQL configuration ko automatically sync karega ta k system disconnect na ho.
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">
                            Current MariaDB Password or Super Admin Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="current_mariadb_password" id="current_mariadb_password" class="form-control" placeholder="Enter current MySQL or SuperAdmin password" required autocomplete="current-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('current_mariadb_password'); p.type = p.type === 'password' ? 'text' : 'password';">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.73rem;">
                            Identity verification k liye mojooda MariaDB password ya apna Super Admin login password enter karein.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">
                            New MariaDB Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="new_mariadb_password" id="new_mariadb_password" class="form-control" placeholder="Enter new strong password" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('new_mariadb_password'); p.type = p.type === 'password' ? 'text' : 'password';">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.73rem;">
                            Kam az kam 4 characters ka naya password darj karein.
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label text-dark fw-bold small mb-1">
                            Confirm New MariaDB Password <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="confirm_mariadb_password" id="confirm_mariadb_password" class="form-control" placeholder="Re-type new password" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('confirm_mariadb_password'); p.type = p.type === 'password' ? 'text' : 'password';">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" id="btn_submit_change_mariadb" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-2 text-dark">
                        <i class="fa-solid fa-rotate"></i>
                        <span>Update &amp; Sync Password</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- MODAL 3: EDIT ALL DATABASE & RADIUS CREDENTIALS                         -->
<!-- ======================================================================= -->
<div class="modal fade" id="editDbCredentialsModal" tabindex="-1" aria-labelledby="editDbCredentialsModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-database fs-5"></i>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="editDbCredentialsModalLabel">Manage Database &amp; RADIUS Credentials</h6>
                        <span class="text-white-50 small" style="font-size: 0.73rem;">Update connection parameters and saved sync profiles</span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="update_database_credentials">

                <div class="modal-body p-4">
                    <!-- Tab navigation -->
                    <ul class="nav nav-pills mb-3 gap-2" id="editDbTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active rounded-pill px-3 py-1 small fw-bold" id="tab-server-btn" data-bs-toggle="pill" data-bs-target="#tab-server" type="button">
                                <i class="fa-solid fa-server me-1"></i> Live Server MySQL
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link rounded-pill px-3 py-1 small fw-bold" id="tab-remote-btn" data-bs-toggle="pill" data-bs-target="#tab-remote" type="button">
                                <i class="fa-solid fa-arrows-rotate me-1"></i> Remote Old VPS Sync
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link rounded-pill px-3 py-1 small fw-bold" id="tab-radius-btn" data-bs-toggle="pill" data-bs-target="#tab-radius" type="button">
                                <i class="fa-solid fa-key me-1"></i> FreeRADIUS &amp; Secrets
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content pt-2">
                        <!-- Tab 1: Live Server MySQL -->
                        <div class="tab-pane fade show active" id="tab-server" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-dark fw-bold small mb-1">Server Public IP / Host</label>
                                    <input type="text" name="server_public_ip" class="form-control" value="<?= htmlspecialchars($currentServerHost) ?>" required>
                                    <div class="form-text text-muted" style="font-size: 0.73rem;">Public IP address of this VPS server.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-dark fw-bold small mb-1">MySQL Port</label>
                                    <input type="number" name="server_db_port" class="form-control" value="<?= htmlspecialchars($currentServerPort) ?>" required>
                                    <div class="form-text text-muted" style="font-size: 0.73rem;">Default MySQL listening port (3306).</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">Database Name</label>
                                    <input type="text" name="server_db_name" class="form-control" value="<?= htmlspecialchars($currentServerDb) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">DB Username</label>
                                    <input type="text" name="server_db_user" class="form-control" value="<?= htmlspecialchars($currentServerUser) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">DB Password</label>
                                    <div class="input-group">
                                        <input type="password" name="server_db_pass" id="modal_server_pass" class="form-control" value="<?= htmlspecialchars($currentServerPass) ?>">
                                        <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('modal_server_pass'); p.type = p.type === 'password' ? 'text' : 'password';">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab 2: Saved Remote Old VPS Sync -->
                        <div class="tab-pane fade" id="tab-remote" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-dark fw-bold small mb-1">Old VPS IP / Host</label>
                                    <input type="text" name="sync_remote_host" class="form-control" value="<?= htmlspecialchars($savedSyncHost) ?>" placeholder="e.g. 123.45.67.89">
                                    <div class="form-text text-muted" style="font-size: 0.73rem;">IP address of your Old VPS.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-dark fw-bold small mb-1">Remote MySQL Port</label>
                                    <input type="number" name="sync_remote_port" class="form-control" value="<?= htmlspecialchars($savedSyncPort) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">Remote DB Name</label>
                                    <input type="text" name="sync_remote_db" class="form-control" value="<?= htmlspecialchars($savedSyncDb) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">Remote DB Username</label>
                                    <input type="text" name="sync_remote_user" class="form-control" value="<?= htmlspecialchars($savedSyncUser) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">Remote DB Password</label>
                                    <div class="input-group">
                                        <input type="password" name="sync_remote_pass" id="modal_remote_pass" class="form-control" value="<?= htmlspecialchars($savedSyncPass) ?>">
                                        <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('modal_remote_pass'); p.type = p.type === 'password' ? 'text' : 'password';">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab 3: FreeRADIUS & Secrets -->
                        <div class="tab-pane fade" id="tab-radius" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-dark fw-bold small mb-1">FreeRADIUS Shared Secret</label>
                                    <div class="input-group">
                                        <input type="password" name="radius_secret" id="modal_radius_secret" class="form-control" value="<?= htmlspecialchars($radiusSecret) ?>" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('modal_radius_secret'); p.type = p.type === 'password' ? 'text' : 'password';">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text text-muted" style="font-size: 0.73rem;">Secret used by MikroTik routers to authenticate with FreeRADIUS.</div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="form-check bg-light p-3 rounded-3 border border-light-subtle w-100 mt-md-3">
                                        <input class="form-check-input ms-0 me-2" type="checkbox" name="sync_nas_secret" id="sync_nas_secret" value="1" checked>
                                        <label class="form-check-label text-dark small fw-bold" for="sync_nas_secret">
                                            Apply this secret to all connected Mikrotik NAS routers (nas table)
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">Authentication Port (UDP)</label>
                                    <input type="number" name="radius_auth_port" class="form-control" value="<?= htmlspecialchars($radiusAuthPort) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">Accounting Port (UDP)</label>
                                    <input type="number" name="radius_acct_port" class="form-control" value="<?= htmlspecialchars($radiusAcctPort) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label text-dark fw-bold small mb-1">CoA / Disconnect Port (UDP)</label>
                                    <input type="number" name="radius_coa_port" class="form-control" value="<?= htmlspecialchars($radiusCoaPort) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Save All Credentials</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let vaultUnlocked = false;
let vaultData = null;
let vaultPwdVisible = false;
let vaultRemotePwdVisible = false;
let vaultRadiusSecretVisible = false;

function handleVaultToggle() {
    if (vaultUnlocked) {
        lockVault();
    } else {
        document.getElementById('vault_verify_input').value = '';
        document.getElementById('vault_error_msg').classList.add('d-none');
        document.getElementById('vault_error_msg').innerText = '';
        const modal = new bootstrap.Modal(document.getElementById('unlockVaultModal'));
        modal.show();
        setTimeout(() => {
            document.getElementById('vault_verify_input').focus();
        }, 400);
    }
}

function handleVaultUnlockSubmit(e) {
    e.preventDefault();
    const pwd = document.getElementById('vault_verify_input').value;
    const btn = document.getElementById('btn_verify_vault_submit');
    const errDiv = document.getElementById('vault_error_msg');

    if (!pwd) return;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Verifying...';
    errDiv.classList.add('d-none');

    const formData = new FormData();
    formData.append('ajax_action', 'reveal_db_credentials');
    formData.append('verify_password', pwd);

    fetch('security.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-lock-open me-1"></i> Verify &amp; Reveal';
        if (data.success) {
            vaultUnlocked = true;
            vaultData = data.credentials;
            unlockVaultUI(data.credentials);
            const modalEl = document.getElementById('unlockVaultModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        } else {
            errDiv.innerText = data.message || 'Verification failed.';
            errDiv.classList.remove('d-none');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-lock-open me-1"></i> Verify &amp; Reveal';
        errDiv.innerText = 'Network error: ' + err.message;
        errDiv.classList.remove('d-none');
    });
}

function unlockVaultUI(creds) {
    // 1. Live Server DB
    document.getElementById('vault_host').innerText = creds.host;
    document.getElementById('vault_port').innerText = creds.port;
    document.getElementById('vault_db').innerText = creds.db;
    document.getElementById('vault_user').innerText = creds.user;
    document.getElementById('vault_pass').innerText = '••••••••••••';
    document.getElementById('vault_pass_plain').value = creds.pass;

    // 2. Saved Remote Sync DB
    document.getElementById('vault_remote_host').innerText = creds.remote_host || 'Not configured';
    document.getElementById('vault_remote_port').innerText = creds.remote_port || '3306';
    document.getElementById('vault_remote_db').innerText = creds.remote_db || 'radius';
    document.getElementById('vault_remote_user').innerText = creds.remote_user || 'Not configured';
    document.getElementById('vault_remote_pass').innerText = creds.remote_pass ? '••••••••••••' : 'Not set';
    document.getElementById('vault_remote_pass_plain').value = creds.remote_pass || '';

    // 3. FreeRADIUS Credentials
    document.getElementById('vault_radius_secret').innerText = '••••••••';
    document.getElementById('vault_radius_secret_plain').value = creds.radius_secret || '123456';
    document.getElementById('vault_radius_auth').innerText = creds.radius_auth_port || '1812';
    document.getElementById('vault_radius_acct').innerText = creds.radius_acct_port || '1813';
    document.getElementById('vault_radius_coa').innerText = creds.radius_coa_port || '3799';

    vaultPwdVisible = false;
    vaultRemotePwdVisible = false;
    vaultRadiusSecretVisible = false;

    // Badges & Buttons
    const badge = document.getElementById('vault_badge');
    badge.className = 'badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1';
    badge.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Unlocked';

    document.getElementById('vault_btn_icon').className = 'fa-solid fa-eye-slash';
    document.getElementById('vault_btn_text').innerText = 'Hide Credentials';
    document.getElementById('btn_toggle_vault').className = 'btn btn-outline-secondary rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm d-inline-flex align-items-center gap-1';

    // Show Edit, Change DB Pass, and Copy buttons
    if (document.getElementById('btn_change_mariadb_pwd')) {
        document.getElementById('btn_change_mariadb_pwd').classList.remove('d-none');
        document.getElementById('btn_change_mariadb_pwd').classList.add('d-inline-flex');
    }
    document.getElementById('btn_edit_credentials').classList.remove('d-none');
    document.getElementById('btn_edit_credentials').classList.add('d-inline-flex');
    document.getElementById('btn_copy_all').classList.remove('d-none');
    document.getElementById('btn_copy_all').classList.add('d-inline-flex');

    // Show all copy buttons & eye toggles & change pwd shortcuts
    document.querySelectorAll('.vault-copy-btn, .vault-pwd-toggle, .vault-remote-pwd-toggle, .vault-radius-pwd-toggle, .vault-change-db-pwd').forEach(el => el.classList.remove('d-none'));
}

function lockVault() {
    vaultUnlocked = false;
    vaultData = null;
    vaultPwdVisible = false;
    vaultRemotePwdVisible = false;
    vaultRadiusSecretVisible = false;

    // Reset all displays to masked
    document.getElementById('vault_host').innerText = '••••••••••••••';
    document.getElementById('vault_port').innerText = '••••';
    document.getElementById('vault_db').innerText = '••••••';
    document.getElementById('vault_user').innerText = '••••••';
    document.getElementById('vault_pass').innerText = '••••••••••••';
    document.getElementById('vault_pass_plain').value = '';

    document.getElementById('vault_remote_host').innerText = '••••••••••••••';
    document.getElementById('vault_remote_port').innerText = '••••';
    document.getElementById('vault_remote_db').innerText = '••••••';
    document.getElementById('vault_remote_user').innerText = '••••••';
    document.getElementById('vault_remote_pass').innerText = '••••••••••••';
    document.getElementById('vault_remote_pass_plain').value = '';

    document.getElementById('vault_radius_secret').innerText = '••••••••';
    document.getElementById('vault_radius_secret_plain').value = '';
    document.getElementById('vault_radius_auth').innerText = '••••';
    document.getElementById('vault_radius_acct').innerText = '••••';
    document.getElementById('vault_radius_coa').innerText = '••••';

    const badge = document.getElementById('vault_badge');
    badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1';
    badge.innerHTML = '<i class="fa-solid fa-lock me-1"></i> Hidden &amp; Locked';

    document.getElementById('vault_btn_icon').className = 'fa-solid fa-eye';
    document.getElementById('vault_btn_text').innerText = 'Reveal Credentials';
    document.getElementById('btn_toggle_vault').className = 'btn btn-dark rounded-pill px-3 py-2 btn-sm fw-bold shadow-sm d-inline-flex align-items-center gap-1';

    if (document.getElementById('btn_change_mariadb_pwd')) {
        document.getElementById('btn_change_mariadb_pwd').classList.add('d-none');
        document.getElementById('btn_change_mariadb_pwd').classList.remove('d-inline-flex');
    }
    document.getElementById('btn_edit_credentials').classList.add('d-none');
    document.getElementById('btn_edit_credentials').classList.remove('d-inline-flex');
    document.getElementById('btn_copy_all').classList.add('d-none');
    document.getElementById('btn_copy_all').classList.remove('d-inline-flex');

    document.querySelectorAll('.vault-copy-btn, .vault-pwd-toggle, .vault-remote-pwd-toggle, .vault-radius-pwd-toggle, .vault-change-db-pwd').forEach(el => el.classList.add('d-none'));
}

function toggleVaultPasswordVisibility(btn) {
    if (!vaultUnlocked || !vaultData) return;
    vaultPwdVisible = !vaultPwdVisible;
    const passDisplay = document.getElementById('vault_pass');
    if (vaultPwdVisible) {
        passDisplay.innerText = vaultData.pass;
        btn.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
    } else {
        passDisplay.innerText = '••••••••••••';
        btn.innerHTML = '<i class="fa-regular fa-eye"></i>';
    }
}

function toggleVaultRemotePasswordVisibility(btn) {
    if (!vaultUnlocked || !vaultData) return;
    vaultRemotePwdVisible = !vaultRemotePwdVisible;
    const passDisplay = document.getElementById('vault_remote_pass');
    if (vaultRemotePwdVisible) {
        passDisplay.innerText = vaultData.remote_pass || 'Empty';
        btn.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
    } else {
        passDisplay.innerText = '••••••••••••';
        btn.innerHTML = '<i class="fa-regular fa-eye"></i>';
    }
}

function toggleVaultRadiusSecretVisibility(btn) {
    if (!vaultUnlocked || !vaultData) return;
    vaultRadiusSecretVisible = !vaultRadiusSecretVisible;
    const secDisplay = document.getElementById('vault_radius_secret');
    if (vaultRadiusSecretVisible) {
        secDisplay.innerText = vaultData.radius_secret || 'Empty';
        btn.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
    } else {
        secDisplay.innerText = '••••••••';
        btn.innerHTML = '<i class="fa-regular fa-eye"></i>';
    }
}

function copyVaultValue(elementId, btn) {
    let val = '';
    const el = document.getElementById(elementId);
    if (!el) return;
    if (el.tagName === 'INPUT') {
        val = el.value;
    } else {
        val = el.innerText.trim();
    }
    if (!val || val.includes('••') || val === 'Not set' || val === 'Not configured') return;

    navigator.clipboard.writeText(val).then(() => {
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
        setTimeout(() => { btn.innerHTML = origHtml; }, 1500);
    });
}

function copyAllVaultCredentials() {
    if (!vaultData) return;
    const text = `--- [This Server Live MySQL DB] ---\n` +
                 `Server IP / Host: ${vaultData.host}\n` +
                 `MySQL Port: ${vaultData.port}\n` +
                 `DB Name: ${vaultData.db}\n` +
                 `DB Username: ${vaultData.user}\n` +
                 `DB Password: ${vaultData.pass}\n\n` +
                 `--- [Saved Remote Old VPS Sync] ---\n` +
                 `Old VPS IP / Host: ${vaultData.remote_host}\n` +
                 `Remote Port: ${vaultData.remote_port}\n` +
                 `Remote DB Name: ${vaultData.remote_db}\n` +
                 `Remote Username: ${vaultData.remote_user}\n` +
                 `Remote Password: ${vaultData.remote_pass}\n\n` +
                 `--- [FreeRADIUS Configuration] ---\n` +
                 `RADIUS Secret: ${vaultData.radius_secret}\n` +
                 `Auth Port: ${vaultData.radius_auth_port}\n` +
                 `Acct Port: ${vaultData.radius_acct_port}\n` +
                 `CoA Port: ${vaultData.radius_coa_port}`;

    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btn_copy_all');
        const origText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> Copied All!';
        setTimeout(() => { btn.innerHTML = origText; }, 2000);
    });
}

function handleChangeDbPassSubmit(e) {
    const p1 = document.getElementById('new_mariadb_password').value;
    const p2 = document.getElementById('confirm_mariadb_password').value;
    if (p1 !== p2) {
        alert('New Password and Confirm Password do not match.');
        e.preventDefault();
        return false;
    }
    if (p1.length < 4) {
        alert('New Password must be at least 4 characters long.');
        e.preventDefault();
        return false;
    }
    if (!confirm('Are you sure you want to change the MariaDB password?\n\nThis will update MariaDB and automatically synchronize config/db.php.')) {
        e.preventDefault();
        return false;
    }
    const btn = document.getElementById('btn_submit_change_mariadb');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Updating MariaDB & Config...';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const changeModalEl = document.getElementById('changeMariaDbModal');
    if (changeModalEl) {
        changeModalEl.addEventListener('show.bs.modal', function () {
            if (vaultUnlocked && vaultData && vaultData.pass) {
                const curField = document.getElementById('current_mariadb_password');
                if (curField && !curField.value) {
                    curField.value = vaultData.pass;
                }
            }
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
