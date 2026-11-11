<?php
require_once 'header.php';
require_once '../config/two_factor_helper.php';

init_two_factor_schema($pdo);

$admin_id = (int)($_SESSION['superadmin_id'] ?? 0);
if ($admin_id <= 0) {
    header("Location: login");
    exit;
}

// Fetch current SuperAdmin record
$stmt = $pdo->prepare("SELECT * FROM super_admins WHERE id = ? LIMIT 1");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

if (!$admin) {
    echo "<div class='container py-5'><div class='alert alert-danger'>SuperAdmin account not found.</div></div>";
    require_once 'footer.php';
    exit;
}

$successMsg = '';
$errorMsg = '';
$showRecoveryModal = false;
$newRecoveryCodes = [];

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // 1. Update Profile Information (Name, Username, Email)
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (empty($name) || empty($username) || empty($email)) {
            $errorMsg = "Name, Username, and Email are required fields.";
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM super_admins WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
            $checkStmt->execute([$username, $email, $admin_id]);
            if ($checkStmt->fetch()) {
                $errorMsg = "Username or Email is already registered to another account.";
            } else {
                $upd = $pdo->prepare("UPDATE super_admins SET name = ?, username = ?, email = ? WHERE id = ?");
                $upd->execute([$name, $username, $email, $admin_id]);
                
                $_SESSION['superadmin_name'] = $name;
                $_SESSION['superadmin_username'] = $username;
                $admin['name'] = $name;
                $admin['username'] = $username;
                $admin['email'] = $email;
                $successMsg = "Profile information updated successfully.";
            }
        }
    }

    // 2. Change Password
    elseif ($action === 'update_password') {
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
            $errorMsg = "Please fill in all password fields.";
        } elseif (!password_verify($current_pass, $admin['password']) && $admin['password'] !== $current_pass) {
            $errorMsg = "Incorrect current password entered.";
        } elseif ($new_pass !== $confirm_pass) {
            $errorMsg = "New password and Confirm password do not match.";
        } elseif (strlen($new_pass) < 6) {
            $errorMsg = "New password must be at least 6 characters long.";
        } else {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE super_admins SET password = ? WHERE id = ?")->execute([$new_hash, $admin_id]);
            $admin['password'] = $new_hash;
            $successMsg = "Login password changed successfully.";
        }
    }

    // 3. Enable 2FA (Verify TOTP code from Google Authenticator)
    elseif ($action === 'enable_2fa') {
        $secret = trim($_POST['setup_secret'] ?? '');
        $code = trim($_POST['verify_code'] ?? '');
        $code = preg_replace('/\s+/', '', $code);

        if (empty($secret) || strlen($secret) < 16) {
            $errorMsg = "Invalid secret key. Please try initiating 2FA setup again.";
        } elseif (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
            $errorMsg = "Please enter the 6-digit verification code from Google Authenticator.";
        } else {
            if (totp_verify_code($secret, $code)) {
                // Generate recovery backup codes
                $recoveryCodes = totp_generate_recovery_codes(8);
                $recoveryJson = json_encode($recoveryCodes);

                $upd = $pdo->prepare("
                    UPDATE super_admins 
                    SET two_factor_secret = ?, 
                        two_factor_enabled = 1, 
                        two_factor_recovery_codes = ?, 
                        two_factor_setup_at = NOW() 
                    WHERE id = ?
                ");
                $upd->execute([$secret, $recoveryJson, $admin_id]);

                // Register current device as trusted
                totp_register_or_renew_trusted_device($pdo, $admin_id);

                $admin['two_factor_enabled'] = 1;
                $admin['two_factor_secret'] = $secret;
                $admin['two_factor_recovery_codes'] = $recoveryJson;
                $admin['two_factor_setup_at'] = date('Y-m-d H:i:s');

                $newRecoveryCodes = $recoveryCodes;
                $showRecoveryModal = true;
                $successMsg = "Two-Factor Authentication (2FA) is now ENABLED! Your SuperAdmin account is now protected.";
            } else {
                $errorMsg = "Invalid 6-digit code. Please verify the code displayed in Google Authenticator and try again.";
            }
        }
    }

    // 4. Disable 2FA
    elseif ($action === 'disable_2fa') {
        $confirm_pass = $_POST['disable_password'] ?? '';
        if (empty($confirm_pass)) {
            $errorMsg = "Please enter your current password to disable Two-Factor Authentication.";
        } elseif (!password_verify($confirm_pass, $admin['password']) && $admin['password'] !== $confirm_pass) {
            $errorMsg = "Incorrect password. Cannot disable 2FA.";
        } else {
            $pdo->prepare("
                UPDATE super_admins 
                SET two_factor_secret = NULL, 
                    two_factor_enabled = 0, 
                    two_factor_recovery_codes = NULL, 
                    two_factor_setup_at = NULL 
                WHERE id = ?
            ")->execute([$admin_id]);

            // Clear all trusted devices
            $pdo->prepare("DELETE FROM superadmin_trusted_devices WHERE admin_id = ?")->execute([$admin_id]);
            setcookie('sa_trusted_device', '', time() - 3600, '/');

            $admin['two_factor_enabled'] = 0;
            $admin['two_factor_secret'] = null;
            $admin['two_factor_recovery_codes'] = null;
            $admin['two_factor_setup_at'] = null;

            $successMsg = "Two-Factor Authentication has been disabled.";
        }
    }

    // 5. Regenerate Recovery Codes
    elseif ($action === 'regenerate_recovery_codes') {
        $confirm_pass = $_POST['regen_password'] ?? '';
        if (empty($confirm_pass) || (!password_verify($confirm_pass, $admin['password']) && $admin['password'] !== $confirm_pass)) {
            $errorMsg = "Incorrect password. Cannot regenerate recovery codes.";
        } else {
            $recoveryCodes = totp_generate_recovery_codes(8);
            $recoveryJson = json_encode($recoveryCodes);

            $pdo->prepare("UPDATE super_admins SET two_factor_recovery_codes = ? WHERE id = ?")
                ->execute([$recoveryJson, $admin_id]);

            $admin['two_factor_recovery_codes'] = $recoveryJson;
            $newRecoveryCodes = $recoveryCodes;
            $showRecoveryModal = true;
            $successMsg = "New recovery backup codes generated successfully.";
        }
    }

    // 6. Revoke a Trusted Device
    elseif ($action === 'revoke_device') {
        $device_id = (int)($_POST['device_id'] ?? 0);
        if ($device_id > 0) {
            $pdo->prepare("DELETE FROM superadmin_trusted_devices WHERE id = ? AND admin_id = ?")
                ->execute([$device_id, $admin_id]);
            $successMsg = "Trusted device revoked successfully.";
        }
    }

    // 7. Revoke All Trusted Devices
    elseif ($action === 'revoke_all_devices') {
        $pdo->prepare("DELETE FROM superadmin_trusted_devices WHERE admin_id = ?")->execute([$admin_id]);
        setcookie('sa_trusted_device', '', time() - 3600, '/');
        $successMsg = "All trusted devices have been revoked. 2FA will be required on next login.";
    }
}

// 2FA Setup State
$is2FAEnabled = !empty($admin['two_factor_enabled']) && !empty($admin['two_factor_secret']);
$setupSecret = $is2FAEnabled ? '' : totp_generate_secret(16);
$issuerName = 'Lasani Network';
$otpauthUrl = $is2FAEnabled ? '' : totp_get_otpauth_url($setupSecret, $admin['username'], $issuerName);

// Fetch current trusted devices
$trustedDevices = [];
try {
    $devStmt = $pdo->prepare("
        SELECT * FROM superadmin_trusted_devices 
        WHERE admin_id = ? AND expires_at > NOW() 
        ORDER BY last_authenticated_at DESC
    ");
    $devStmt->execute([$admin_id]);
    $trustedDevices = $devStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Saved recovery codes count
$savedRecoveryCodes = [];
if (!empty($admin['two_factor_recovery_codes'])) {
    $savedRecoveryCodes = json_decode($admin['two_factor_recovery_codes'], true) ?: [];
}
?>

<div class="container-fluid px-4 py-4">

    <!-- Page Header Card -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 54px; height: 54px; font-size: 1.4rem;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-crown text-warning"></i> Super Admin Account
                    </div>
                    <h3 class="fw-bold text-dark mb-0">Profile &amp; Security Settings</h3>
                    <p class="text-muted small mb-0">Manage credentials, passwords, Google Authenticator 2FA, and trusted devices.</p>
                </div>
            </div>
            <div>
                <?php if ($is2FAEnabled): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fw-bold d-inline-flex align-items-center gap-1.5 fs-6 shadow-sm">
                        <i class="fa-solid fa-shield-halved"></i> 2FA Protected
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 rounded-pill fw-bold d-inline-flex align-items-center gap-1.5 fs-6 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation"></i> 2FA Not Enabled
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 d-flex align-items-center gap-2 mb-4">
            <i class="fa-solid fa-circle-check fs-5 text-success"></i>
            <div><?= htmlspecialchars($successMsg) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 d-flex align-items-center gap-2 mb-4">
            <i class="fa-solid fa-circle-exclamation fs-5 text-danger"></i>
            <div><?= htmlspecialchars($errorMsg) ?></div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <!-- Left Column: Two-Factor Authentication (Primary Focus) -->
        <div class="col-lg-7">
            
            <!-- 2FA Main Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-3 bg-primary bg-opacity-10 text-primary p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-mobile-screen fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-0">Two-Factor Authentication (2FA)</h5>
                            <span class="text-muted small">Google Authenticator (TOTP) Security</span>
                        </div>
                    </div>
                    <?php if ($is2FAEnabled): ?>
                        <span class="badge bg-success rounded-pill px-3 py-1.5 fw-bold">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary rounded-pill px-3 py-1.5 fw-bold">Disabled</span>
                    <?php endif; ?>
                </div>

                <div class="card-body p-4">
                    <?php if (!$is2FAEnabled): ?>
                        <!-- 2FA DISABLED STATE: Guided Setup -->
                        <div class="text-center py-2 mb-4">
                            <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-inline-flex align-items-center justify-content-center mb-3" style="width: 72px; height: 72px;">
                                <i class="fa-solid fa-shield-halved fs-1"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-2">Secure Your SuperAdmin Account</h5>
                            <p class="text-muted small mx-auto mb-3" style="max-width: 520px;">
                                Two-Factor Authentication adds an extra layer of security to your account. In addition to your username and password, you will be required to enter a 6-digit code from <strong>Google Authenticator</strong> whenever logging in.
                            </p>
                        </div>

                        <!-- Step by Step Setup Box -->
                        <div class="bg-light rounded-4 p-4 border mb-4">
                            <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">1</span>
                                Scan QR Code with Google Authenticator
                            </h6>

                            <div class="row align-items-center g-4">
                                <div class="col-md-5 text-center">
                                    <div class="bg-white p-3 rounded-3 d-inline-block border shadow-sm">
                                        <div id="qrcodeCanvas" style="display: flex; justify-content: center;"></div>
                                        <img id="qrFallbackImg" src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($otpauthUrl) ?>" alt="QR Code" class="img-fluid rounded d-none" style="max-width: 180px;">
                                    </div>
                                    <div class="form-text text-muted small mt-2">Scan with camera / app</div>
                                </div>
                                <div class="col-md-7">
                                    <p class="text-muted small mb-2">
                                        Open <strong>Google Authenticator</strong> (or Microsoft Authenticator / 1Password) on your mobile phone, tap <strong>"+"</strong> and select <strong>"Scan a QR code"</strong>.
                                    </p>
                                    
                                    <div class="mt-3">
                                        <label class="form-label text-muted small fw-semibold text-uppercase mb-1" style="font-size: 0.72rem;">
                                            Can't scan? Enter Key manually:
                                        </label>
                                        <div class="input-group">
                                            <input type="text" id="manualSecretInput" class="form-control font-monospace fw-bold bg-white text-primary" value="<?= htmlspecialchars($setupSecret) ?>" readonly>
                                            <button class="btn btn-outline-secondary" type="button" onclick="copySecretKey()" title="Copy to clipboard">
                                                <i class="fa-solid fa-copy me-1"></i> Copy
                                            </button>
                                        </div>
                                        <div id="copySuccessMsg" class="text-success small fw-semibold mt-1 d-none">
                                            <i class="fa-solid fa-check me-1"></i> Key copied to clipboard!
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: Verification Form -->
                        <form method="POST">
                            <input type="hidden" name="action" value="enable_2fa">
                            <input type="hidden" name="setup_secret" value="<?= htmlspecialchars($setupSecret) ?>">

                            <div class="bg-light rounded-4 p-4 border mb-3">
                                <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
                                    <span class="badge bg-primary rounded-circle" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center;">2</span>
                                    Verify Code to Activate
                                </h6>
                                <p class="text-muted small mb-3">
                                    Enter the 6-digit code currently showing in your Google Authenticator app for <strong><?= htmlspecialchars($issuerName) ?></strong>:
                                </p>

                                <div class="row g-2 align-items-center">
                                    <div class="col-sm-6">
                                        <div class="input-group input-group-lg">
                                            <span class="input-group-text bg-white"><i class="fa-solid fa-key text-primary"></i></span>
                                            <input type="text" 
                                                   name="verify_code" 
                                                   class="form-control font-monospace fw-bold text-center fs-4" 
                                                   placeholder="000000" 
                                                   maxlength="6" 
                                                   pattern="[0-9]*" 
                                                   inputmode="numeric" 
                                                   required>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                            <i class="fa-solid fa-shield-check"></i>
                                            <span>Verify &amp; Enable 2FA</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>

                    <?php else: ?>
                        <!-- 2FA ENABLED STATE -->
                        <div class="d-flex align-items-center gap-3 p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-4 mb-4">
                            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px;">
                                <i class="fa-solid fa-shield-halved fs-4"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="fw-bold text-success mb-1">Google Authenticator is Active &amp; Protecting Your Account</h6>
                                <div class="text-muted small">
                                    Enabled on: <strong><?= !empty($admin['two_factor_setup_at']) ? date('d M Y, h:i A', strtotime($admin['two_factor_setup_at'])) : 'Active' ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Recovery Codes Info -->
                        <div class="p-3 bg-light rounded-4 border mb-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <div class="fw-bold text-dark mb-0">Emergency Recovery Codes</div>
                                    <div class="text-muted small">
                                        <?= count($savedRecoveryCodes) ?> single-use recovery code(s) remaining for account recovery if you lose your phone.
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#viewRecoveryCodesModal">
                                        <i class="fa-solid fa-eye me-1"></i> View Codes
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#regenRecoveryCodesModal">
                                        <i class="fa-solid fa-rotate me-1"></i> Regenerate
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Disable 2FA Section -->
                        <div class="p-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-4">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div>
                                    <div class="fw-bold text-danger mb-0">Disable Two-Factor Authentication</div>
                                    <div class="text-muted small">
                                        Turn off Google Authenticator requirement. Not recommended for Super Admin security.
                                    </div>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-danger btn-sm rounded-pill px-3 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#disable2FAModal">
                                        <i class="fa-solid fa-shield-xmark me-1"></i> Disable 2FA
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Trusted Devices Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">Trusted Devices (1x Daily 2FA)</h5>
                        <span class="text-muted small">Devices configured to require Authenticator only once every 24 hours</span>
                    </div>
                    <?php if (!empty($trustedDevices)): ?>
                        <form method="POST" onsubmit="return confirm('Revoke all trusted devices? 2FA will be required on all browsers immediately.');">
                            <input type="hidden" name="action" value="revoke_all_devices">
                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-semibold">
                                <i class="fa-solid fa-trash-can me-1"></i> Revoke All
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($trustedDevices)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-laptop fs-1 opacity-25 mb-2 d-block"></i>
                            <h6 class="fw-bold text-dark mb-1">No Trusted Devices Registered</h6>
                            <p class="small text-muted mb-0" style="max-width: 440px; margin: 0 auto;">
                                When logging in, check <strong>"Trust this device (Ask once a day)"</strong> to remember your device and avoid entering 2FA on every single login.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Device / Browser</th>
                                        <th>IP Address</th>
                                        <th>Last Verified</th>
                                        <th>Daily Status</th>
                                        <th class="text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($trustedDevices as $dev): 
                                        $lastAuth = strtotime($dev['last_authenticated_at']);
                                        $hoursAgo = round((time() - $lastAuth) / 3600, 1);
                                        $isWithin24h = ($hoursAgo < 24);
                                    ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="d-flex align-items-center gap-2.5">
                                                    <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center text-primary" style="width: 36px; height: 36px;">
                                                        <?php if (stripos($dev['device_name'], 'Phone') !== false || stripos($dev['device_name'], 'Android') !== false || stripos($dev['device_name'], 'iPhone') !== false): ?>
                                                            <i class="fa-solid fa-mobile-screen"></i>
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-laptop"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($dev['device_name'] ?: 'Desktop Browser') ?></div>
                                                        <div class="text-muted" style="font-size: 0.72rem;">Registered: <?= date('d M Y', strtotime($dev['created_at'])) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="font-monospace small text-muted">
                                                <?= htmlspecialchars($dev['ip_address'] ?: '127.0.0.1') ?>
                                            </td>
                                            <td class="small">
                                                <div class="fw-semibold text-dark"><?= date('d M Y, h:i A', $lastAuth) ?></div>
                                                <div class="text-muted" style="font-size: 0.72rem;"><?= $hoursAgo ?> hr(s) ago</div>
                                            </td>
                                            <td>
                                                <?php if ($isWithin24h): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small">
                                                        <i class="fa-solid fa-check me-1"></i> Active (No 2FA today)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2.5 py-1 small">
                                                        <i class="fa-solid fa-clock me-1"></i> 2FA due next login
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Revoke this device?');">
                                                    <input type="hidden" name="action" value="revoke_device">
                                                    <input type="hidden" name="device_id" value="<?= $dev['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3" title="Revoke Trust">
                                                        <i class="fa-solid fa-xmark me-1"></i> Revoke
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Right Column: Profile & Password Management -->
        <div class="col-lg-5">
            
            <!-- Profile Details Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom p-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-user text-primary me-2"></i>Account Details</h5>
                    <span class="text-muted small">Update your Super Admin identity and contact email</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($admin['name'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">@</span>
                                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($admin['username'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($admin['email'] ?? '') ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom p-4">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-key text-primary me-2"></i>Change Password</h5>
                    <span class="text-muted small">Update your Super Admin login password</span>
                </div>
                <div class="card-body p-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_password">

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">Current Password <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">New Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" minlength="6" required autocomplete="new-password">
                            <div class="form-text text-muted small">Minimum 6 characters</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark small">Confirm New Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required autocomplete="new-password">
                        </div>

                        <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold shadow-sm">
                            <i class="fa-solid fa-lock me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- Modal: View Saved Recovery Codes -->
<div class="modal fade" id="viewRecoveryCodesModal" tabindex="-1" aria-labelledby="viewRecoveryCodesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h6 class="modal-title fw-bold" id="viewRecoveryCodesModalLabel"><i class="fa-solid fa-key me-2"></i>Emergency Backup Recovery Codes</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3">
                    Store these single-use recovery codes in a safe place. If you ever lose access to your phone or Google Authenticator, you can use any of these codes to log in.
                </p>

                <?php if (!empty($savedRecoveryCodes)): ?>
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="row g-2">
                            <?php foreach ($savedRecoveryCodes as $idx => $rcode): ?>
                                <div class="col-6">
                                    <div class="bg-white border rounded px-2.5 py-1.5 font-monospace fw-bold text-dark text-center small shadow-sm">
                                        <?= htmlspecialchars($rcode) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="text-center">
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="copyAllCodes('<?= implode('\n', $savedRecoveryCodes) ?>')">
                            <i class="fa-solid fa-copy me-1"></i> Copy All Codes
                        </button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning small mb-0">No active recovery codes remaining. Please regenerate a new set.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer py-2 px-4 bg-light border-top">
                <button type="button" class="btn btn-secondary rounded-pill btn-sm px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Newly Generated Recovery Codes (First time or regenerated) -->
<div class="modal fade" id="newRecoveryCodesModal" tabindex="-1" aria-labelledby="newRecoveryCodesModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-success text-white py-3 px-4">
                <h6 class="modal-title fw-bold" id="newRecoveryCodesModalLabel"><i class="fa-solid fa-shield-check me-2"></i>Save Your Emergency Recovery Codes</h6>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning small mb-3">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Important:</strong> Save these backup recovery codes now. They will not be shown again. Each code can only be used once.
                </div>

                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="row g-2">
                        <?php foreach ($newRecoveryCodes as $idx => $rcode): ?>
                            <div class="col-6">
                                <div class="bg-white border rounded px-2.5 py-1.5 font-monospace fw-bold text-dark text-center small shadow-sm">
                                    <?= htmlspecialchars($rcode) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" onclick="copyAllCodes('<?= implode('\n', $newRecoveryCodes) ?>')">
                        <i class="fa-solid fa-copy me-1"></i> Copy Codes
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" onclick="downloadCodesTxt('<?= implode("\r\n", $newRecoveryCodes) ?>')">
                        <i class="fa-solid fa-download me-1"></i> Download .txt
                    </button>
                </div>
            </div>
            <div class="modal-footer py-2 px-4 bg-light border-top">
                <button type="button" class="btn btn-success rounded-pill btn-sm px-4 fw-bold" data-bs-dismiss="modal">I Have Saved My Codes</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Regenerate Recovery Codes -->
<div class="modal fade" id="regenRecoveryCodesModal" tabindex="-1" aria-labelledby="regenRecoveryCodesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h6 class="modal-title fw-bold" id="regenRecoveryCodesModalLabel"><i class="fa-solid fa-rotate me-2"></i>Regenerate Recovery Codes</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="regenerate_recovery_codes">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        Regenerating will invalidate all existing recovery codes. Enter your current password to continue:
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Super Admin Password <span class="text-danger">*</span></label>
                        <input type="password" name="regen_password" class="form-control" required placeholder="Enter password">
                    </div>
                </div>
                <div class="modal-footer py-2 px-4 bg-light border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill btn-sm px-4 fw-bold">Generate New Codes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Disable 2FA -->
<div class="modal fade" id="disable2FAModal" tabindex="-1" aria-labelledby="disable2FAModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white py-3 px-4">
                <h6 class="modal-title fw-bold" id="disable2FAModalLabel"><i class="fa-solid fa-triangle-exclamation me-2"></i>Disable Two-Factor Authentication</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="disable_2fa">
                <div class="modal-body p-4">
                    <div class="text-danger small mb-3">
                        <strong>Warning:</strong> Disabling 2FA makes your SuperAdmin account vulnerable to credential stuffing and unauthorized access.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-dark">Enter Password to Confirm <span class="text-danger">*</span></label>
                        <input type="password" name="disable_password" class="form-control" required placeholder="Enter Super Admin password">
                    </div>
                </div>
                <div class="modal-footer py-2 px-4 bg-light border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill btn-sm px-4 fw-bold">Disable 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- QRCode.js Library from CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if (!$is2FAEnabled && !empty($otpauthUrl)): ?>
    try {
        const qrContainer = document.getElementById("qrcodeCanvas");
        if (typeof QRCode !== 'undefined' && qrContainer) {
            new QRCode(qrContainer, {
                text: <?= json_encode($otpauthUrl) ?>,
                width: 180,
                height: 180,
                colorDark: "#0f172a",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            // Fallback to image
            document.getElementById("qrFallbackImg").classList.remove("d-none");
        }
    } catch (e) {
        document.getElementById("qrFallbackImg").classList.remove("d-none");
    }
    <?php endif; ?>

    <?php if ($showRecoveryModal && !empty($newRecoveryCodes)): ?>
    const recModalEl = document.getElementById('newRecoveryCodesModal');
    if (recModalEl) {
        const modal = new bootstrap.Modal(recModalEl);
        modal.show();
    }
    <?php endif; ?>
});

function copySecretKey() {
    const input = document.getElementById('manualSecretInput');
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(() => {
        const msg = document.getElementById('copySuccessMsg');
        if (msg) {
            msg.classList.remove('d-none');
            setTimeout(() => { msg.classList.add('d-none'); }, 3000);
        }
    });
}

function copyAllCodes(codesText) {
    const cleanText = codesText.replace(/\\n/g, '\n');
    navigator.clipboard.writeText(cleanText).then(() => {
        alert('All recovery codes copied to clipboard!');
    });
}

function downloadCodesTxt(codesText) {
    const element = document.createElement('a');
    const cleanText = codesText.replace(/\\r\\n/g, '\r\n');
    const file = new Blob(["SUPERADMIN EMERGENCY 2FA RECOVERY CODES\r\nAccount: <?= addslashes($admin['username']) ?>\r\nGenerated: " + new Date().toLocaleString() + "\r\n----------------------------------------\r\n\r\n" + cleanText + "\r\n\r\nKeep these codes safe and private."], {type: 'text/plain'});
    element.href = URL.createObjectURL(file);
    element.download = "superadmin_2fa_recovery_codes.txt";
    document.body.appendChild(element);
    element.click();
    document.body.removeChild(element);
}
</script>

<?php require_once 'footer.php'; ?>
