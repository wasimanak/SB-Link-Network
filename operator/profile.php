<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Block Manager from accessing Company Profile - redirect directly to their own profile and recoveries
if (!empty($_SESSION['is_manager'])) {
    $my_mgr_id = (int)($_SESSION['manager_id'] ?? 0);
    header("Location: manager_view.php?id=" . $my_mgr_id);
    exit;
}
require_once 'header.php';

$client_id = (int)($_SESSION['operator_id'] ?? 1);

// Auto-verify and migrate nas table columns if missing (Prevents 1054 Unknown column errors)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM nas")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('client_id', $cols)) {
        $pdo->exec("ALTER TABLE nas ADD COLUMN client_id INT NOT NULL DEFAULT 1");
    }
    if (!in_array('api_port', $cols)) {
        $pdo->exec("ALTER TABLE nas ADD COLUMN api_port INT DEFAULT 8728");
    }
    if (!in_array('api_user', $cols)) {
        $pdo->exec("ALTER TABLE nas ADD COLUMN api_user VARCHAR(50) DEFAULT 'admin'");
    }
    if (!in_array('api_password', $cols)) {
        $pdo->exec("ALTER TABLE nas ADD COLUMN api_password VARCHAR(255) DEFAULT NULL");
    }
    if (!in_array('coa_port', $cols)) {
        $pdo->exec("ALTER TABLE nas ADD COLUMN coa_port INT DEFAULT 3799");
    }
} catch (Exception $e) {}

// Auto-verify clients table for photo column
try {
    $clientCols = $pdo->query("SHOW COLUMNS FROM clients")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('photo', $clientCols)) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
    }
} catch (Exception $e) {}

// Determine server IP/domain for MikroTik RADIUS client commands
$radius_server_ip = $_SERVER['SERVER_ADDR'] ?? '';
if (empty($radius_server_ip) || in_array($radius_server_ip, ['127.0.0.1', '::1'])) {
    $radius_server_ip = $_SERVER['HTTP_HOST'] ?? '187.126.127.237';
    $radius_server_ip = preg_replace('/:\d+$/', '', $radius_server_ip);
}

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Protect Router modifications against Manager
    if (in_array($action, ['add_router', 'update_router', 'delete_router']) && !empty($_SESSION['is_manager'])) {
        echo "<script>alert('Permission Denied: Managers cannot add, edit, or delete routers.'); window.location='profile';</script>";
        exit;
    }
    
    if ($action === 'add_router') {
        $nasname = trim($_POST['nasname'] ?? '');
        $shortname = trim($_POST['shortname'] ?? 'Router');
        $secret = trim($_POST['secret'] ?? '123456');
        $api_user = trim($_POST['api_user'] ?? '');
        $api_pass = trim($_POST['api_password'] ?? '');
        
        try {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM nas WHERE client_id = ?");
            $chk->execute([$client_id]);
            $current_routers = $chk->fetchColumn();
            
            $uStmt = $pdo->prepare("SELECT max_routers FROM clients WHERE id = ?");
            $uStmt->execute([$client_id]);
            $max_r = $uStmt->fetchColumn() ?: 10;
            
            if ($current_routers >= $max_r) {
                echo "<script>alert('Router limit reached ({$max_r})! You cannot add more routers.'); window.location='profile';</script>";
                exit;
            }
            
            $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, ?, ?, 8728, ?, ?)")
                ->execute([$client_id, $nasname, $shortname, $secret, $api_user, $api_pass]);
            
            // Auto-provision network defaults & sync pools from router
            if (file_exists('../config/mikrotik_sync_helper.php')) {
                require_once '../config/mikrotik_sync_helper.php';
                try {
                    init_operator_network_defaults($pdo, $client_id);
                    if (!empty($api_user)) {
                        @sync_operator_mikrotik_pools($pdo, $client_id);
                    }
                } catch (Throwable $t) {}
            }

            // Silently restart FreeRADIUS so the new router is active immediately
            @exec("sudo systemctl restart freeradius 2>&1");
            @exec("systemctl restart freeradius 2>&1");
            echo "<script>alert('Router added successfully! RADIUS Secret is set to 123456.'); window.location='profile';</script>";
        } catch(Throwable $e) {
            echo "<script>alert('Error adding router: " . addslashes($e->getMessage()) . "'); window.location='profile';</script>";
        }
        exit;
    }
    
    if ($action === 'update_router') {
        $nas_id = (int)$_POST['nas_id'];
        $nasname = trim($_POST['nasname'] ?? '');
        $shortname = trim($_POST['shortname'] ?? 'Router');
        $secret = trim($_POST['secret'] ?? '123456');
        $api_user = trim($_POST['api_user'] ?? '');
        $api_pass = trim($_POST['api_password'] ?? '');
        
        try {
            if (empty($api_pass)) {
                $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=? WHERE id=? AND client_id=?")->execute([$nasname, $shortname, $secret, $api_user, $nas_id, $client_id]);
            } else {
                $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=?, api_password=? WHERE id=? AND client_id=?")->execute([$nasname, $shortname, $secret, $api_user, $api_pass, $nas_id, $client_id]);
            }

            // Auto-provision network defaults & sync pools from router
            if (file_exists('../config/mikrotik_sync_helper.php')) {
                require_once '../config/mikrotik_sync_helper.php';
                try {
                    init_operator_network_defaults($pdo, $client_id);
                    if (!empty($api_user)) {
                        @sync_operator_mikrotik_pools($pdo, $client_id);
                    }
                } catch (Throwable $t) {}
            }

            @exec("sudo systemctl restart freeradius 2>&1");
            echo "<script>alert('Router updated successfully!'); window.location='profile';</script>";
        } catch(Throwable $e) {
            echo "<script>alert('Error updating router: " . addslashes($e->getMessage()) . "'); window.location='profile';</script>";
        }
        exit;
    }
    
    if ($action === 'delete_router') {
        $nas_id = (int)$_POST['nas_id'];
        try {
            $pdo->prepare("DELETE FROM nas WHERE id=? AND client_id=?")->execute([$nas_id, $client_id]);
            @exec("sudo systemctl restart freeradius 2>&1");
            echo "<script>alert('Router deleted successfully!'); window.location='profile';</script>";
        } catch(Throwable $e) {
            echo "<script>alert('Error deleting router: " . addslashes($e->getMessage()) . "'); window.location='profile';</script>";
        }
        exit;
    }
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        
        $stmt = $pdo->prepare("SELECT password FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client_pass = $stmt->fetchColumn();
        
        if (password_verify($current, $client_pass)) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE clients SET password = ? WHERE id = ?")->execute([$hashed, $client_id]);
            echo "<script>alert('Password updated successfully!'); window.location='profile';</script>";
        } else {
            echo "<script>alert('Current password is incorrect.');</script>";
        }
    }
    elseif ($action === 'add_note') {
        $notes = trim($_POST['notes']);
        $pdo->prepare("UPDATE clients SET notes = ? WHERE id = ?")->execute([$notes, $client_id]);
        echo "<script>alert('Notes updated successfully!'); window.location='profile';</script>";
        exit;
    }
    elseif ($action === 'upload_photo') {
        if (!isset($_FILES['profile_photo']) || empty($_FILES['profile_photo']['name'])) {
            echo "<script>alert('Please select an image file to upload.'); window.location='profile';</script>";
            exit;
        }

        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['profile_photo']['error'];
            echo "<script>alert('Upload error code: " . intval($err) . "'); window.location='profile';</script>";
            exit;
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            echo "<script>alert('Invalid file format. Only JPG, JPEG, PNG, WEBP, and GIF are allowed.'); window.location='profile';</script>";
            exit;
        }

        if ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
            echo "<script>alert('File size must be less than 5MB.'); window.location='profile';</script>";
            exit;
        }

        $tmp = $_FILES['profile_photo']['tmp_name'];
        if (@getimagesize($tmp) === false) {
            echo "<script>alert('Uploaded file is not a valid image.'); window.location='profile';</script>";
            exit;
        }

        $uploadDir = '../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $filename = 'client_' . $client_id . '_' . time() . '.' . $ext;
        $dest = $uploadDir . $filename;

        if (move_uploaded_file($tmp, $dest)) {
            // Delete old photo if exists
            try {
                $oldStmt = $pdo->prepare("SELECT photo FROM clients WHERE id = ?");
                $oldStmt->execute([$client_id]);
                $oldPhoto = $oldStmt->fetchColumn();
                if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
                    @unlink($uploadDir . $oldPhoto);
                }
            } catch (Exception $e) {}

            $pdo->prepare("UPDATE clients SET photo = ? WHERE id = ?")->execute([$filename, $client_id]);
            $_SESSION['operator_photo'] = $filename;

            try {
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'Client', 'Updated Profile Photo')")->execute([$client_id, $client_id]);
            } catch (Exception $e) {}

            echo "<script>alert('Profile photo updated successfully!'); window.location='profile';</script>";
            exit;
        } else {
            echo "<script>alert('Server error: Failed to save file. Check directory permissions for uploads/profiles/'); window.location='profile';</script>";
            exit;
        }
    }
    elseif ($action === 'remove_photo') {
        $uploadDir = '../uploads/profiles/';
        try {
            $oldStmt = $pdo->prepare("SELECT photo FROM clients WHERE id = ?");
            $oldStmt->execute([$client_id]);
            $oldPhoto = $oldStmt->fetchColumn();
            if ($oldPhoto && file_exists($uploadDir . $oldPhoto)) {
                @unlink($uploadDir . $oldPhoto);
            }
        } catch (Exception $e) {}

        $pdo->prepare("UPDATE clients SET photo = NULL WHERE id = ?")->execute([$client_id]);
        unset($_SESSION['operator_photo']);

        try {
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'Client', 'Removed Profile Photo')")->execute([$client_id, $client_id]);
        } catch (Exception $e) {}

        echo "<script>alert('Profile photo removed successfully!'); window.location='profile';</script>";
        exit;
    }
}

// --- FETCH DATA ---
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$user = $stmt->fetch();

$total_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id")->fetchColumn();
$total_packages = $pdo->query("SELECT COUNT(*) FROM packages WHERE client_id = $client_id")->fetchColumn();
$total_advance = $pdo->query("SELECT SUM(amount) FROM user_ledger WHERE client_id = $client_id AND type = 'credit'")->fetchColumn() ?: 0;
$total_dealers = $pdo->query("SELECT COUNT(*) FROM dealers WHERE client_id = $client_id")->fetchColumn() ?: 0;
$total_subdealers = 0; // Placeholder as per UI

// Fetch packages list for the tab
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ? ORDER BY id DESC");
$pkgStmt->execute([$client_id]);
$packages_list = $pkgStmt->fetchAll();

// Fetch NAS
$nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$routers = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
    :root {
        --prof-primary: #3b82f6;
        --prof-primary-dark: #1d4ed8;
        --prof-bg-card: #ffffff;
        --prof-border: #f1f5f9;
        --prof-text-main: #0f172a;
        --prof-text-sub: #64748b;
    }

    .live-dot {
        display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse-green 1.5s infinite;
    }
    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    /* Page Header */
    .profile-hero-header {
        background: #fff;
        border-radius: 16px;
        padding: 20px 26px;
        border: 1px solid var(--prof-border);
        box-shadow: 0 2px 12px rgba(15, 23, 42, 0.03);
        margin-bottom: 24px;
    }

    /* Left Profile Card */
    .profile-main-card {
        background: #fff;
        border-radius: 16px;
        border: 1px solid var(--prof-border);
        box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .profile-cover-banner {
        height: 100px;
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 50%, #3b82f6 100%);
        position: relative;
    }

    .avatar-wrapper {
        position: relative;
        margin-top: -50px;
        margin-left: 24px;
        width: 96px;
        height: 96px;
        border-radius: 20px;
        background: #fff;
        padding: 4px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        cursor: pointer;
        display: inline-block;
    }

    .avatar-inner {
        width: 100%;
        height: 100%;
        border-radius: 16px;
        overflow: hidden;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.4rem;
        color: #94a3b8;
        position: relative;
    }

    .avatar-inner img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .avatar-cam-badge {
        position: absolute;
        bottom: -4px;
        right: -4px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #3b82f6;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        border: 3px solid #fff;
        box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        transition: transform 0.2s;
    }
    .avatar-wrapper:hover .avatar-cam-badge {
        transform: scale(1.1);
        background: #2563eb;
    }

    .profile-name-block {
        padding: 16px 24px 12px 24px;
    }

    .profile-info-list {
        list-style: none;
        padding: 0 20px 16px 20px;
        margin: 0;
    }

    .profile-info-item {
        display: flex;
        align-items: center;
        padding: 10px 12px;
        border-radius: 10px;
        margin-bottom: 4px;
        transition: background 0.15s ease;
    }
    .profile-info-item:hover {
        background: #f8fafc;
    }
    .profile-info-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        flex-shrink: 0;
        margin-right: 12px;
    }
    .profile-info-content {
        flex-grow: 1;
        overflow: hidden;
    }
    .profile-info-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        font-weight: 600;
        color: #94a3b8;
        letter-spacing: 0.4px;
        line-height: 1;
        margin-bottom: 2px;
    }
    .profile-info-val {
        font-size: 0.88rem;
        font-weight: 600;
        color: #1e293b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .profile-action-btns {
        padding: 16px 20px 24px 20px;
        border-top: 1px solid var(--prof-border);
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
    }
    .profile-action-btn {
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #334155;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 0.82rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.2s ease;
    }
    .profile-action-btn:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #0f172a;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.04);
    }
    .profile-action-btn.dark-btn {
        background: #0f172a;
        color: #fff;
        border-color: #0f172a;
        grid-column: span 2;
    }
    .profile-action-btn.dark-btn:hover {
        background: #1e293b;
        color: #fff;
    }

    /* Top Metric Stat Cards */
    .stat-card-modern {
        background: #fff;
        border-radius: 16px;
        padding: 20px 22px;
        border: 1px solid var(--prof-border);
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .stat-card-modern:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.07);
        border-color: #cbd5e1;
    }
    .stat-card-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-card-modern.blue .stat-card-icon { background: #eff6ff; color: #2563eb; }
    .stat-card-modern.purple .stat-card-icon { background: #faf5ff; color: #9333ea; }
    .stat-card-modern.emerald .stat-card-icon { background: #ecfdf5; color: #059669; }
    .stat-card-modern.amber .stat-card-icon { background: #fffbeb; color: #d97706; }

    /* Modern Tabs */
    .modern-tabs {
        border-bottom: 2px solid #e2e8f0;
        gap: 10px;
    }
    .modern-tabs .nav-link {
        border: none;
        background: transparent;
        color: #64748b;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 12px 18px;
        border-radius: 10px 10px 0 0;
        display: flex;
        align-items: center;
        gap: 8px;
        position: relative;
        transition: all 0.2s;
    }
    .modern-tabs .nav-link:hover {
        color: #1e293b;
    }
    .modern-tabs .nav-link.active {
        color: #2563eb;
        background: #fff;
    }
    .modern-tabs .nav-link.active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        right: 0;
        height: 2px;
        background: #2563eb;
        border-radius: 2px 2px 0 0;
    }

    /* Router Cards */
    .router-box {
        background: #fff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        padding: 18px;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .router-box:hover {
        border-color: #93c5fd;
        box-shadow: 0 6px 18px rgba(59, 130, 246, 0.08);
        transform: translateY(-2px);
    }
</style>

<!-- Hero Top Header -->
<div class="profile-hero-header d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
        <div class="text-muted small fw-semibold mb-1 d-flex align-items-center gap-1.5">
            <i class="fa-solid fa-house-chimney text-muted"></i>
            <span>Dashboard</span>
            <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.65rem;"></i>
            <span class="text-primary">Company Profile</span>
        </div>
        <h3 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
            <span><?= htmlspecialchars($user['company_name']) ?></span>
            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1 rounded-pill fs-7 fw-semibold">
                <span class="live-dot me-1"></span> Active Franchise
            </span>
        </h3>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if (empty($_SESSION['is_manager'])): ?>
        <button type="button" class="btn btn-dark rounded-pill px-3.5 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#routerModal">
            <i class="fa-solid fa-server text-primary"></i>
            <span>Routers</span>
            <span class="badge bg-primary text-white rounded-pill px-2" style="font-size: 0.72rem;"><?= count($routers) ?></span>
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3.5 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
            <i class="fa-solid fa-key me-1"></i> Password
        </button>
        <button type="button" class="btn btn-outline-primary rounded-pill px-3.5 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#changePhotoModal">
            <i class="fa-solid fa-camera me-1"></i> Photo
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Profile Card -->
    <div class="col-xl-4 col-lg-5">
        <div class="profile-main-card">
            <!-- Cover Top -->
            <div class="profile-cover-banner"></div>
            
            <!-- Avatar & Badge -->
            <div class="d-flex justify-content-between align-items-end pe-4">
                <div class="avatar-wrapper" data-bs-toggle="modal" data-bs-target="#changePhotoModal" title="Click to update photo">
                    <div class="avatar-inner">
                        <?php if (!empty($user['photo']) && file_exists("../uploads/profiles/" . $user['photo'])): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($user['photo']) ?>?v=<?= time() ?>" alt="Operator Profile Photo">
                        <?php else: ?>
                            <i class="fa-solid fa-user-tie"></i>
                        <?php endif; ?>
                    </div>
                    <div class="avatar-cam-badge">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                </div>
                <div class="mb-2">
                    <span class="badge bg-light text-secondary border px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.75rem;">
                        ID #<?= $user['id'] ?>
                    </span>
                </div>
            </div>

            <!-- Profile Info Block -->
            <div class="profile-name-block">
                <h4 class="fw-bold text-dark mb-0"><?= htmlspecialchars($user['full_name'] ?: $user['company_name']) ?></h4>
                <div class="text-muted small fw-medium mt-0.5">
                    <i class="fa-solid fa-shield-halved text-primary me-1"></i>
                    <span>Franchise Owner</span> &bull; <code><?= htmlspecialchars($user['username'] ?: $user['email']) ?></code>
                </div>
            </div>

            <!-- Details List -->
            <ul class="profile-info-list">
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-solid fa-building"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Company / Brand</div>
                        <div class="profile-info-val"><?= htmlspecialchars($user['company_name']) ?></div>
                    </div>
                </li>
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-regular fa-id-card"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">National ID / CNIC</div>
                        <div class="profile-info-val font-monospace"><?= htmlspecialchars($user['national_id'] ?: 'N/A') ?></div>
                    </div>
                </li>
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Contact Phone</div>
                        <div class="profile-info-val"><?= htmlspecialchars($user['phone'] ?: 'N/A') ?></div>
                    </div>
                </li>
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Email Address</div>
                        <div class="profile-info-val"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                </li>
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-solid fa-map-pin"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Subarea / Territory</div>
                        <div class="profile-info-val"><?= htmlspecialchars($user['subarea'] ?: 'N/A') ?></div>
                    </div>
                </li>
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Full Address</div>
                        <div class="profile-info-val"><?= htmlspecialchars($user['address'] ?: 'N/A') ?></div>
                    </div>
                </li>
                <li class="profile-info-item">
                    <div class="profile-info-icon"><i class="fa-regular fa-calendar-check"></i></div>
                    <div class="profile-info-content">
                        <div class="profile-info-label">Registered Since</div>
                        <div class="profile-info-val"><?= date('d M Y, h:i A', strtotime($user['created_at'])) ?></div>
                    </div>
                </li>
            </ul>

            <!-- Quick Action Grid -->
            <div class="profile-action-btns">
                <?php if (empty($_SESSION['is_manager'])): ?>
                <button type="button" class="profile-action-btn dark-btn" data-bs-toggle="modal" data-bs-target="#routerModal">
                    <i class="fa-solid fa-server text-info"></i>
                    <span>Manage MikroTik Routers</span>
                    <span class="badge bg-primary text-white ms-auto"><?= count($routers) ?>/<?= $user['max_routers'] ?></span>
                </button>
                <?php endif; ?>
                <button type="button" class="profile-action-btn" data-bs-toggle="modal" data-bs-target="#changePhotoModal">
                    <i class="fa-regular fa-image text-primary"></i> Change Photo
                </button>
                <button type="button" class="profile-action-btn" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fa-solid fa-lock text-warning"></i> Change Password
                </button>
                <button type="button" class="profile-action-btn" data-bs-toggle="modal" data-bs-target="#noteModal">
                    <i class="fa-regular fa-note-sticky text-success"></i> Edit Notes
                </button>
                <a href="activity_logs.php" class="profile-action-btn text-decoration-none">
                    <i class="fa-solid fa-clock-rotate-left text-danger"></i> Activity Logs
                </a>
            </div>
        </div>
    </div>

    <!-- Right Column: Metrics & Tabs -->
    <div class="col-xl-8 col-lg-7">
        
        <!-- Top Metrics 4-Grid -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <a href="subscribers.php" class="text-decoration-none">
                    <div class="stat-card-modern blue">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase letter-spacing-1">Subscribers</div>
                            <h3 class="fw-bold text-dark mt-1 mb-0"><?= number_format($total_users) ?></h3>
                            <div class="text-primary small fw-semibold mt-1">View list <i class="fa-solid fa-arrow-right fs-8"></i></div>
                        </div>
                        <div class="stat-card-icon"><i class="fa-solid fa-users"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-sm-6 col-xl-3">
                <a href="dealers.php" class="text-decoration-none">
                    <div class="stat-card-modern purple">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase letter-spacing-1">Dealers</div>
                            <h3 class="fw-bold text-dark mt-1 mb-0"><?= number_format($total_dealers) ?></h3>
                            <div class="text-purple small fw-semibold mt-1">Manage <i class="fa-solid fa-arrow-right fs-8"></i></div>
                        </div>
                        <div class="stat-card-icon"><i class="fa-solid fa-user-tie"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-sm-6 col-xl-3">
                <a href="packages.php" class="text-decoration-none">
                    <div class="stat-card-modern emerald">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase letter-spacing-1">Packages</div>
                            <h3 class="fw-bold text-dark mt-1 mb-0"><?= number_format($total_packages) ?></h3>
                            <div class="text-success small fw-semibold mt-1">View all <i class="fa-solid fa-arrow-right fs-8"></i></div>
                        </div>
                        <div class="stat-card-icon"><i class="fa-solid fa-box-open"></i></div>
                    </div>
                </a>
            </div>
            <div class="col-sm-6 col-xl-3">
                <a href="statement.php" class="text-decoration-none">
                    <div class="stat-card-modern amber">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase letter-spacing-1">Recharge Total</div>
                            <h3 class="fw-bold <?= $total_advance < 0 ? 'text-danger' : 'text-success' ?> mt-1 mb-0">Rs. <?= number_format($total_advance, 0) ?></h3>
                            <div class="text-warning small fw-semibold mt-1">Statement <i class="fa-solid fa-arrow-up-right-from-square fs-8"></i></div>
                        </div>
                        <div class="stat-card-icon"><i class="fa-solid fa-wallet"></i></div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Modern Content Card with Tabs -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white pt-3 px-4 border-bottom-0">
                <ul class="nav modern-tabs" id="profileTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="routers-tab" data-bs-toggle="tab" data-bs-target="#tabRouters" type="button" role="tab">
                            <i class="fa-solid fa-server text-primary"></i>
                            <span>Routers</span>
                            <span class="badge bg-light text-primary border rounded-pill px-2 py-0.5"><?= count($routers) ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="packages-tab" data-bs-toggle="tab" data-bs-target="#tabPackages" type="button" role="tab">
                            <i class="fa-solid fa-boxes-stacked text-success"></i>
                            <span>Packages</span>
                            <span class="badge bg-light text-success border rounded-pill px-2 py-0.5"><?= count($packages_list) ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#tabNotes" type="button" role="tab">
                            <i class="fa-regular fa-note-sticky text-warning"></i>
                            <span>Remarks / Notes</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body p-4 bg-light bg-opacity-50">
                <div class="tab-content" id="profileTabContent">
                    
                    <!-- Tab 1: Routers -->
                    <div class="tab-pane fade show active" id="tabRouters" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <h6 class="fw-bold text-dark mb-0">Linked MikroTik Routers</h6>
                                <p class="text-muted small mb-0">RADIUS Port: <strong>1812</strong> | Accounting: <strong>1813</strong> | Server IP: <code><?= htmlspecialchars($radius_server_ip) ?></code></p>
                            </div>
                            <?php if (empty($_SESSION['is_manager']) && count($routers) < $user['max_routers']): ?>
                                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addRouterModal">
                                    <i class="fa-solid fa-plus me-1"></i> Add Router
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if(empty($routers)): ?>
                            <div class="p-4 text-center bg-white rounded-4 border">
                                <i class="fa-solid fa-server text-muted fs-1 mb-2 d-block"></i>
                                <h6 class="fw-bold text-dark">No Routers Configured Yet</h6>
                                <p class="text-muted small mb-3">Add your MikroTik router to enable PPPoE / Hotspot authentication and real-time bandwidth monitoring.</p>
                                <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addRouterModal">
                                    <i class="fa-solid fa-plus me-1"></i> Add First Router
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach($routers as $r): ?>
                                <div class="col-md-6">
                                    <div class="router-box">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="fw-bold text-dark mb-1 d-flex align-items-center gap-1.5">
                                                    <i class="fa-solid fa-microchip text-primary"></i>
                                                    <span><?= htmlspecialchars($r['shortname'] ?: 'Router') ?></span>
                                                </h6>
                                                <div class="router-status" data-id="<?= $r['id'] ?>">
                                                    <span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span>
                                                </div>
                                            </div>
                                            <div class="dropdown">
                                                <button class="btn btn-light btn-sm rounded-circle shadow-xs" style="width:30px; height:30px; padding:0;" data-bs-toggle="dropdown">
                                                    <i class="fa-solid fa-ellipsis-vertical text-muted"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                                    <li>
                                                        <a class="dropdown-item fw-semibold text-primary edit-router-btn py-2" href="#" data-id="<?= $r['id'] ?>" data-ip="<?= htmlspecialchars($r['nasname']) ?>" data-short="<?= htmlspecialchars($r['shortname']) ?>" data-sec="<?= htmlspecialchars($r['secret']) ?>" data-usr="<?= htmlspecialchars($r['api_user']) ?>" data-bs-toggle="modal" data-bs-target="#editRouterModal">
                                                            <i class="fa-solid fa-pen me-2"></i> Edit Config
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider my-1"></li>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('WARNING: Deleting this router will disconnect RADIUS authentication for users connected to it. Continue?');">
                                                            <input type="hidden" name="action" value="delete_router">
                                                            <input type="hidden" name="nas_id" value="<?= $r['id'] ?>">
                                                            <button type="submit" class="dropdown-item fw-semibold text-danger py-2">
                                                                <i class="fa-solid fa-trash-can me-2"></i> Remove Router
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>

                                        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                                            <span class="badge bg-light text-dark border px-2.5 py-1 font-monospace">
                                                <i class="fa-solid fa-network-wired text-muted me-1"></i> <?= htmlspecialchars($r['nasname']) ?>
                                            </span>
                                            <span class="badge bg-light text-muted border px-2 py-1 font-monospace">
                                                Port: <?= (int)($r['api_port'] ?: 8728) ?>
                                            </span>
                                        </div>

                                        <?php
                                        $r_secret = !empty($r['secret']) ? $r['secret'] : '123456';
                                        $script_cmd = "/radius add address=" . $radius_server_ip . " secret=\"" . $r_secret . "\" service=ppp,hotspot authentication-port=1812 accounting-port=1813 timeout=3000ms\n"
                                                    . "/radius incoming set accept=yes port=3799\n"
                                                    . "/ppp aaa set use-radius=yes interim-update=1m\n"
                                                    . "/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n";
                                        ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 rounded-pill script-btn py-1.5 fw-semibold" data-script="<?= htmlspecialchars($script_cmd, ENT_QUOTES) ?>">
                                            <i class="fa-solid fa-terminal me-1 text-dark"></i> View MikroTik Setup Script
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab 2: Packages -->
                    <div class="tab-pane fade" id="tabPackages" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-dark mb-0">Active Packages Portfolio</h6>
                            <a href="packages.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold">
                                <i class="fa-solid fa-box-open me-1"></i> Manage Packages
                            </a>
                        </div>
                        <?php if(empty($packages_list)): ?>
                            <div class="p-4 text-center bg-white rounded-4 border">
                                <p class="text-muted mb-0">No packages created yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive bg-white rounded-4 border shadow-xs">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr class="text-muted small text-uppercase">
                                            <th class="ps-3">Package Name</th>
                                            <th>Bandwidth Rate</th>
                                            <th>Price</th>
                                            <th class="pe-3 text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($packages_list as $p): ?>
                                        <tr>
                                            <td class="ps-3">
                                                <span class="fw-bold text-dark"><?= htmlspecialchars($p['name']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1 rounded-pill font-monospace">
                                                    <?= htmlspecialchars($p['rate_limit'] ?: 'Unlimited') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong class="text-success">Rs. <?= number_format($p['price'], 0) ?></strong>
                                            </td>
                                            <td class="pe-3 text-end">
                                                <a href="packages.php" class="btn btn-sm btn-light border rounded-pill px-2.5 py-0.5 text-muted hover-dark">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tab 3: Notes -->
                    <div class="tab-pane fade" id="tabNotes" role="tabpanel">
                        <div class="bg-white p-4 rounded-4 border shadow-xs">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold text-dark mb-0"><i class="fa-regular fa-note-sticky text-warning me-1"></i> Operator Notes & Remarks</h6>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#noteModal">
                                    <i class="fa-solid fa-pen me-1"></i> Edit Notes
                                </button>
                            </div>
                            <?php if (!empty($user['notes'])): ?>
                                <div class="p-3 bg-light rounded-3 text-dark font-monospace" style="white-space: pre-wrap; font-size: 0.88rem;">
                                    <?= htmlspecialchars($user['notes']) ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted small mb-0">No special notes added. Click "Edit Notes" to keep internal records, IP blocks, or contact details.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<!-- ================= MODALS ================= -->

<?php if (empty($_SESSION['is_manager'])): ?>
<!-- Router List Modal -->
<div class="modal fade" id="routerModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header bg-white border-bottom p-3 px-4">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-server text-primary me-2"></i> Manage MikroTik Routers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 bg-light">
          
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
              <span class="text-secondary fw-bold small text-uppercase">
                  <i class="fa-solid fa-chart-pie me-1 text-primary"></i> Quota: <?= count($routers) ?> / <?= $user['max_routers'] ?> Routers Configured
              </span>
              <?php if(count($routers) < $user['max_routers']): ?>
                  <button type="button" class="btn btn-primary btn-sm rounded-pill shadow-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#addRouterModal">
                      <i class="fa-solid fa-plus me-1"></i> Add New Router
                  </button>
              <?php else: ?>
                  <span class="badge bg-danger rounded-pill px-3 py-1.5"><i class="fa-solid fa-ban me-1"></i> Limit Reached</span>
              <?php endif; ?>
          </div>

          <div class="alert alert-info py-2 px-3 small rounded-3 mb-3 border-0 bg-primary bg-opacity-10 text-dark">
              <i class="fa-solid fa-circle-info text-primary me-1"></i>
              <strong>Tip:</strong> FreeRADIUS listens on Port 1812 UDP. Make sure router allows API port 8728 for real-time speed graphs.
          </div>

          <?php if(empty($routers)): ?>
              <div class="alert alert-warning text-center border-warning border-opacity-25 shadow-sm fw-bold">
                  <i class="fa-solid fa-triangle-exclamation fs-4 mb-2 d-block"></i> No routers linked! Add one to sync users.
              </div>
          <?php endif; ?>

          <div class="row g-3">
              <?php foreach($routers as $r): ?>
              <div class="col-md-6">
                  <div class="card border-0 shadow-sm rounded-4 h-100">
                      <div class="card-body p-3">
                          <div class="d-flex justify-content-between align-items-start mb-2">
                              <div>
                                  <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-microchip text-primary me-1"></i> <?= htmlspecialchars($r['shortname'] ?: 'Router') ?></h6>
                                  <div class="router-status" data-id="<?= $r['id'] ?>"><span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span></div>
                              </div>
                              <div class="dropdown">
                                  <button class="btn btn-light btn-sm rounded-circle shadow-xs" style="width:30px; height:30px; padding:0;" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                  <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                      <li><a class="dropdown-item fw-semibold text-primary edit-router-btn py-2" href="#" data-id="<?= $r['id'] ?>" data-ip="<?= htmlspecialchars($r['nasname']) ?>" data-short="<?= htmlspecialchars($r['shortname']) ?>" data-sec="<?= htmlspecialchars($r['secret']) ?>" data-usr="<?= htmlspecialchars($r['api_user']) ?>" data-bs-toggle="modal" data-bs-target="#editRouterModal"><i class="fa-solid fa-pen me-2"></i> Edit Config</a></li>
                                      <li><hr class="dropdown-divider my-1"></li>
                                      <li>
                                          <form method="POST" onsubmit="return confirm('WARNING: Deleting this router will break RADIUS authentication for users connected to it. Continue?');">
                                              <input type="hidden" name="action" value="delete_router">
                                              <input type="hidden" name="nas_id" value="<?= $r['id'] ?>">
                                              <button type="submit" class="dropdown-item fw-semibold text-danger py-2"><i class="fa-solid fa-trash me-2"></i> Remove Router</button>
                                          </form>
                                      </li>
                                  </ul>
                              </div>
                          </div>
                          
                          <div class="mb-1"><span class="badge bg-dark bg-opacity-10 text-dark border border-secondary px-2"><i class="fa-solid fa-network-wired me-1"></i> IP: <?= htmlspecialchars($r['nasname']) ?></span></div>
                          <div class="mb-3"><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-link me-1"></i> Linked</span></div>
                          
                          <?php
                          $r_secret = !empty($r['secret']) ? $r['secret'] : '123456';
                          $script_cmd = "/radius add address=" . $radius_server_ip . " secret=\"" . $r_secret . "\" service=ppp,hotspot authentication-port=1812 accounting-port=1813 timeout=3000ms\n"
                                      . "/radius incoming set accept=yes port=3799\n"
                                      . "/ppp aaa set use-radius=yes interim-update=1m\n"
                                      . "/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n";
                          ?>
                          <button type="button" class="btn btn-sm btn-outline-secondary w-100 rounded-pill script-btn fw-semibold" data-script="<?= htmlspecialchars($script_cmd, ENT_QUOTES) ?>">
                              <i class="fa-solid fa-terminal me-1"></i> Show Setup Script
                          </button>
                      </div>
                  </div>
              </div>
              <?php endforeach; ?>
          </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Router Modal -->
<div class="modal fade" id="addRouterModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header border-bottom p-3 px-4 bg-white">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-circle-plus text-success me-2"></i> Add New Router</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_router">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router Name / Location</label>
                <input type="text" name="shortname" class="form-control rounded-3" placeholder="e.g. Main Area, Tower 2" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router IP Address (NAS)</label>
                <input type="text" name="nasname" class="form-control rounded-3 font-monospace" placeholder="e.g. 119.156.28.215" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                <input type="text" name="secret" class="form-control rounded-3 font-monospace" value="123456" required>
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Username</label>
                    <input type="text" name="api_user" class="form-control rounded-3 font-monospace" placeholder="admin" required>
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Password</label>
                    <input type="password" name="api_password" class="form-control rounded-3 font-monospace" required>
                </div>
            </div>
        </div>
        <div class="modal-footer p-3 px-4 bg-light">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Router</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Router Modal -->
<div class="modal fade" id="editRouterModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header border-bottom p-3 px-4 bg-white">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Router</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update_router">
        <input type="hidden" name="nas_id" id="edit_nas_id">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router Name / Location</label>
                <input type="text" name="shortname" id="edit_shortname" class="form-control rounded-3" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router IP Address (NAS)</label>
                <input type="text" name="nasname" id="edit_nasname" class="form-control rounded-3 font-monospace" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                <input type="text" name="secret" id="edit_secret" class="form-control rounded-3 font-monospace" required>
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Username</label>
                    <input type="text" name="api_user" id="edit_apiuser" class="form-control rounded-3 font-monospace" required>
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Password</label>
                    <input type="password" name="api_password" class="form-control rounded-3 font-monospace" placeholder="Leave blank to keep">
                </div>
            </div>
        </div>
        <div class="modal-footer p-3 px-4 bg-light">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Update Router</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Quick Script Modal -->
<div class="modal fade" id="scriptModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header border-bottom p-3 px-4 bg-white">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-terminal text-primary me-2"></i> MikroTik Terminal Script</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 bg-light">
          <p class="small text-muted mb-3">Copy & paste these commands into your MikroTik <strong>New Terminal</strong>:</p>
          <div class="position-relative">
              <textarea id="mtScriptCode" class="form-control font-monospace bg-dark text-success p-3 rounded-3" rows="6" readonly style="font-size: 13px; resize: none;"></textarea>
              <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 shadow-sm fw-bold rounded-pill px-3" onclick="copyScript()">
                  <i class="fa-regular fa-copy me-1"></i> Copy
              </button>
          </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Handle Edit Router button clicks
    document.querySelectorAll(".edit-router-btn").forEach(btn => {
        btn.addEventListener("click", function() {
            document.getElementById("edit_nas_id").value = this.dataset.id;
            document.getElementById("edit_nasname").value = this.dataset.ip;
            document.getElementById("edit_shortname").value = this.dataset.short;
            document.getElementById("edit_secret").value = this.dataset.sec;
            document.getElementById("edit_apiuser").value = this.dataset.usr;
        });
    });

    // Handle Quick Script button clicks
    document.querySelectorAll(".script-btn").forEach(btn => {
        btn.addEventListener("click", function() {
            document.getElementById("mtScriptCode").value = this.dataset.script;
            var scriptModal = new bootstrap.Modal(document.getElementById("scriptModal"));
            
            // Hide main router modal if open
            var rModal = document.getElementById("routerModal");
            if (rModal && bootstrap.Modal.getInstance(rModal)) {
                bootstrap.Modal.getInstance(rModal).hide();
            }
            
            scriptModal.show();
        });
    });

    // Run router status check on page load
    checkRouterStatuses();
});

function checkRouterStatuses() {
    document.querySelectorAll(".router-status").forEach(el => {
        let nasId = el.dataset.id;
        el.innerHTML = '<span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span>';
        
        fetch("check_router_status.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "nas_id=" + nasId
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === "success") {
                el.innerHTML = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-50 px-2.5 py-1 rounded-pill"><span class="live-dot me-1"></span> Connected</span>';
            } else if (data.status === "warning") {
                el.innerHTML = '<span class="badge bg-warning bg-opacity-25 text-dark border border-warning px-2 py-1 rounded-pill" title="' + (data.tooltip || data.message) + '"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> ' + data.message + '</span>';
            } else {
                el.innerHTML = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-50 px-2 py-1 rounded-pill" title="'+data.message+'"><i class="fa-solid fa-circle-xmark me-1"></i> Offline ('+data.message+')</span>';
            }
        })
        .catch(err => {
            el.innerHTML = '<span class="badge bg-secondary bg-opacity-25 text-dark border border-secondary px-2 py-1 rounded-pill" title="RADIUS Active"><i class="fa-solid fa-signal text-secondary me-1"></i> RADIUS Ready</span>';
        });
    });
}

function copyScript() {
    var copyText = document.getElementById("mtScriptCode");
    copyText.select();
    document.execCommand("copy");
    alert("MikroTik script copied to clipboard!");
}
</script>
<?php endif; ?>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header border-bottom p-3 px-4 bg-white">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-key text-warning me-2"></i> Change Operator Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Current Password</label>
                <input type="password" name="current_password" class="form-control rounded-3" placeholder="Enter current password" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">New Password</label>
                <input type="password" name="new_password" class="form-control rounded-3" placeholder="Enter new strong password" required>
            </div>
        </div>
        <div class="modal-footer p-3 px-4 bg-light">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header border-bottom p-3 px-4 bg-white">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-regular fa-note-sticky text-success me-2"></i> Profile Notes & Remarks</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Notes / Remarks</label>
                <textarea name="notes" class="form-control rounded-3" rows="6" placeholder="Enter any important notes, network details, or reference remarks..."><?= htmlspecialchars($user['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="modal-footer p-3 px-4 bg-light">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark rounded-pill px-4 fw-bold">Save Note</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Photo Modal -->
<div class="modal fade" id="changePhotoModal" tabindex="-1" aria-labelledby="changePhotoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <div class="modal-header border-bottom p-3 px-4 bg-white">
        <h5 class="modal-title fw-bold text-dark" id="changePhotoModalLabel"><i class="fa-solid fa-camera me-2 text-primary"></i> Update Profile Photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="changePhotoForm">
        <input type="hidden" name="action" value="upload_photo">
        <div class="modal-body text-center py-4">
            <!-- Current / Preview Image Box -->
            <div class="mx-auto mb-3 position-relative" style="width: 130px; height: 130px; border-radius: 50%; overflow: hidden; border: 4px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.08); background: #f8fafc; display: flex; align-items: center; justify-content: center;">
                <img id="photoPreviewImg" 
                     src="<?= (!empty($user['photo']) && file_exists('../uploads/profiles/' . $user['photo'])) ? '../uploads/profiles/' . htmlspecialchars($user['photo']) . '?v=' . time() : '' ?>" 
                     alt="Photo Preview" 
                     style="width: 100%; height: 100%; object-fit: cover; <?= (empty($user['photo']) || !file_exists('../uploads/profiles/' . $user['photo'])) ? 'display: none;' : '' ?>">
                <div id="photoPreviewPlaceholder" style="<?= (!empty($user['photo']) && file_exists('../uploads/profiles/' . $user['photo'])) ? 'display: none;' : '' ?> font-size: 3.5rem; color: #cbd5e1;">
                    <i class="fa-solid fa-user"></i>
                </div>
            </div>

            <div class="mb-3 px-3">
                <label for="adminPhotoInput" class="form-label fw-semibold text-secondary small d-block mb-2">Select an image (JPG, PNG, WEBP, max 5MB)</label>
                <input type="file" class="form-control rounded-3" id="adminPhotoInput" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif" required onchange="previewAdminPhoto(this)">
            </div>
            <div id="photoFileSizeError" class="text-danger small mt-1" style="display: none;">
                <i class="fa-solid fa-circle-exclamation me-1"></i> File size exceeds 5MB limit. Please choose a smaller image.
            </div>
        </div>
        <div class="modal-footer border-top p-3 px-4 bg-light d-flex justify-content-between">
            <div>
                <?php if (!empty($user['photo']) && file_exists('../uploads/profiles/' . $user['photo'])): ?>
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="if(confirm('Are you sure you want to remove your profile photo?')) { document.getElementById('removePhotoForm').submit(); }">
                    <i class="fa-solid fa-trash-can me-1"></i> Remove
                </button>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold" id="btnSavePhoto">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> Save Photo
                </button>
            </div>
        </div>
      </form>
      <!-- Separate form for removing photo -->
      <form method="POST" id="removePhotoForm" style="display: none;">
          <input type="hidden" name="action" value="remove_photo">
      </form>
    </div>
  </div>
</div>

<script>
function previewAdminPhoto(input) {
    var previewImg = document.getElementById('photoPreviewImg');
    var placeholder = document.getElementById('photoPreviewPlaceholder');
    var errorDiv = document.getElementById('photoFileSizeError');
    var saveBtn = document.getElementById('btnSavePhoto');

    if (input.files && input.files[0]) {
        var file = input.files[0];
        if (file.size > 5 * 1024 * 1024) {
            errorDiv.style.display = 'block';
            if (saveBtn) saveBtn.disabled = true;
            return;
        } else {
            errorDiv.style.display = 'none';
            if (saveBtn) saveBtn.disabled = false;
        }

        var reader = new FileReader();
        reader.onload = function(e) {
            if (previewImg) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
            }
            if (placeholder) {
                placeholder.style.display = 'none';
            }
        };
        reader.readAsDataURL(file);
    }
}
</script>

<?php require_once 'footer.php'; ?>
