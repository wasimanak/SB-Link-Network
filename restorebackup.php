<?php
/**
 * SB Link Network / NetBee - 1-Click Database Restore Utility
 * Usage: http://YOUR_VPS_IP/restorebackup.php
 * For security, delete this file after completing database restoration!
 */

@ini_set('upload_max_filesize', '256M');
@ini_set('post_max_size', '256M');
@ini_set('max_execution_time', 1800);
@set_time_limit(1800);
@ini_set('memory_limit', '512M');

$security_pin = '1234'; // Default PIN to prevent unauthorized triggers

// Determine default DB credentials from config/db.php if available
$default_host = 'localhost';
$default_user = 'root';
$default_pass = '';
$default_db   = 'radius';

$is_windows = (DIRECTORY_SEPARATOR === '\\');
if (!$is_windows) {
    // Linux VPS defaults
    $default_user = 'radius';
    $default_pass = 'Wasi1234';
    $default_db   = 'radius';
} else {
    // Local XAMPP defaults
    $default_user = 'root';
    $default_pass = '';
    $default_db   = 'radius_admin';
}

if (file_exists(__DIR__ . '/config/db.php')) {
    $db_code = file_get_contents(__DIR__ . '/config/db.php');
    if (preg_match('/\$host\s*=\s*[\'"]([^\'"]+)[\'"]/', $db_code, $m)) $default_host = $m[1];
    if (!$is_windows) {
        if (preg_match('/\$user\s*=\s*[\'"]([^\'"]+)[\'"]\s*;\s*.*?\$pass\s*=\s*[\'"]([^\'"]*)[\'"]/s', $db_code, $m)) {
            // inside VPS else block
            if (preg_match('/else\s*\{[^}]*?\$db\s*=\s*[\'"]([^\'"]+)[\'"][^}]*?\$user\s*=\s*[\'"]([^\'"]+)[\'"][^}]*?\$pass\s*=\s*[\'"]([^\'"]*)[\'"]/s', $db_code, $m2)) {
                $default_db   = $m2[1];
                $default_user = $m2[2];
                $default_pass = $m2[3];
            }
        }
    }
}

// Find existing .sql files on server
$existing_backups = [];
$candidate_files = [
    'config/backup.sql',
    'database.sql',
    'database_schema.sql',
    'backup.sql'
];
foreach ($candidate_files as $cf) {
    $full = __DIR__ . '/' . $cf;
    if (file_exists($full) && is_readable($full)) {
        $size_mb = round(filesize($full) / (1024 * 1024), 2);
        $existing_backups[] = [
            'relative' => $cf,
            'full'     => $full,
            'size'     => ($size_mb > 0 ? $size_mb . ' MB' : round(filesize($full)/1024, 1) . ' KB'),
            'mtime'    => date('Y-m-d H:i:s', filemtime($full))
        ];
    }
}

// Self-delete handler
if (isset($_POST['action']) && $_POST['action'] === 'self_delete') {
    if (unlink(__FILE__)) {
        die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>File Deleted</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head><body class="bg-light p-5"><div class="container text-center"><div class="alert alert-success shadow p-4 rounded-4"><h3 class="fw-bold"><i class="fa-solid fa-shield-halved text-success"></i> restorebackup.php Has Been Successfully Deleted!</h3><p class="text-muted mt-2">Aapka server ab 100% secure hai. Restoration script delete ho chuki hai.</p><a href="/operator/login.php" class="btn btn-primary px-4 py-2 mt-3 fw-bold">Go To Operator Login</a></div></div></body></html>');
    } else {
        $error_msg = "Could not delete restorebackup.php automatically due to file permissions. Please delete it manually via SSH/FTP: rm -f " . __FILE__;
    }
}

// Restoration Handler
$restore_success = false;
$restore_stats = [];
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
    $pin = trim($_POST['pin'] ?? '');
    if ($pin !== $security_pin) {
        $error_msg = "Galat Security PIN! Default PIN '1234' hai.";
    } else {
        $db_host = trim($_POST['db_host'] ?: 'localhost');
        $db_user = trim($_POST['db_user'] ?: 'root');
        $db_pass = (string)($_POST['db_pass'] ?? '');
        $db_name = trim($_POST['db_name'] ?: 'radius');
        $source_type = $_POST['source_type'] ?? 'upload';

        $source_file = '';
        $temp_uploaded = false;

        if ($source_type === 'existing' && !empty($_POST['server_file'])) {
            $chosen = __DIR__ . '/' . ltrim(str_replace(['..', '\\'], ['', '/'], $_POST['server_file']), '/');
            if (file_exists($chosen) && is_readable($chosen)) {
                $source_file = $chosen;
            } else {
                $error_msg = "Selected server file nahi mila ya read nahi ho saka: " . htmlspecialchars($_POST['server_file']);
            }
        } elseif ($source_type === 'upload' && isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['sql', 'gz'])) {
                $error_msg = "Sirf .sql ya .sql.gz backup files allow hain!";
            } else {
                $source_file = $_FILES['sql_file']['tmp_name'];
                $temp_uploaded = true;
            }
        } else {
            $error_msg = "Barah-e-karam backup file select karein ya upload karein!";
        }

        if (empty($error_msg) && !empty($source_file)) {
            $start_time = microtime(true);
            try {
                // Step 1: Connect to MySQL server (without selecting DB first)
                $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);

                // Step 2: Create DB if not exists and select it
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$db_name}`");

                // Step 3: Optimize MySQL session for lightning fast import
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
                $pdo->exec("SET AUTOCOMMIT = 0");

                // Step 4: Stream and execute SQL statements
                $is_gz = (substr($source_file, -3) === '.gz');
                $handle = $is_gz ? gzopen($source_file, 'r') : fopen($source_file, 'r');

                if (!$handle) {
                    throw new Exception("Backup file open nahi ho saki: " . $source_file);
                }

                $query = '';
                $queries_count = 0;
                $batch_count = 0;

                while (($line = ($is_gz ? gzgets($handle) : fgets($handle))) !== false) {
                    $trim = trim($line);
                    // Skip comments and empty lines
                    if ($trim === '' || str_starts_with($trim, '--') || (str_starts_with($trim, '/*') && !str_starts_with($trim, '/*!'))) {
                        continue;
                    }

                    $query .= $line;

                    if (str_ends_with($trim, ';')) {
                        try {
                            $pdo->exec($query);
                            $queries_count++;
                            $batch_count++;
                            if ($batch_count >= 300) {
                                $pdo->exec("COMMIT");
                                $batch_count = 0;
                            }
                        } catch (PDOException $qe) {
                            // Ignore minor drop errors if table doesn't exist
                            if (!str_contains($qe->getMessage(), "Unknown table")) {
                                // Silent continue or track
                            }
                        }
                        $query = '';
                    }
                }

                if ($is_gz) gzclose($handle); else fclose($handle);

                // Commit remaining and re-enable FK
                $pdo->exec("COMMIT");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $pdo->exec("SET AUTOCOMMIT = 1");

                $time_taken = round(microtime(true) - $start_time, 2);

                // Fetch database verification stats
                $table_count = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$db_name}'")->fetchColumn();
                $sub_count = 0;
                try {
                    $sub_count = (int)$pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
                } catch (Exception $e) {}

                $pkg_count = 0;
                try {
                    $pkg_count = (int)$pdo->query("SELECT COUNT(*) FROM packages")->fetchColumn();
                } catch (Exception $e) {}

                $nas_count = 0;
                try {
                    $nas_count = (int)$pdo->query("SELECT COUNT(*) FROM nas")->fetchColumn();
                } catch (Exception $e) {}

                $restore_success = true;
                $restore_stats = [
                    'queries'     => $queries_count,
                    'time_sec'    => $time_taken,
                    'db_name'     => $db_name,
                    'tables'      => $table_count,
                    'subscribers' => $sub_count,
                    'packages'    => $pkg_count,
                    'routers'     => $nas_count
                ];

            } catch (Exception $ex) {
                $error_msg = "Restore Error: " . $ex->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>1-Click Database Restore Utility - SB Link Network / NetBee</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            color: #334155;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .main-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.35);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .card-header-gradient {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 30px;
        }
        .upload-dropzone {
            border: 2px dashed #93c5fd;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover {
            background: #eff6ff;
            border-color: #3b82f6;
        }
        .stat-badge {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
        }
        .stat-badge h3 {
            margin: 0;
            color: #1e293b;
            font-weight: 700;
        }
        .stat-badge small {
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body class="py-5">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-md-10">

            <div class="main-card">
                <!-- Header -->
                <div class="card-header-gradient text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-20 rounded-circle mb-3" style="width: 65px; height: 65px;">
                        <i class="fa-solid fa-database fa-2x text-white"></i>
                    </div>
                    <h3 class="fw-bold mb-1">1-Click Database Restore</h3>
                    <p class="mb-0 text-white-50 small">New VPS & Local PC Database Setup & Restore Utility</p>
                </div>

                <div class="p-4 p-md-5">

                    <!-- Alert messages -->
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger border-0 shadow-sm rounded-3 d-flex align-items-center gap-3 mb-4">
                            <i class="fa-solid fa-triangle-exclamation fa-2x text-danger"></i>
                            <div>
                                <h6 class="fw-bold mb-1">Restoration Failed!</h6>
                                <p class="mb-0 small"><?= htmlspecialchars($error_msg) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($restore_success): ?>
                        <!-- Success Banner & Stats -->
                        <div class="alert alert-success border-0 shadow-sm rounded-4 p-4 mb-4 text-center">
                            <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle mb-3" style="width: 55px; height: 55px;">
                                <i class="fa-solid fa-check fa-2x"></i>
                            </div>
                            <h4 class="fw-bold text-success mb-1">Database Restored Successfully!</h4>
                            <p class="text-secondary small mb-4">Aapki database <strong><?= htmlspecialchars($restore_stats['db_name']) ?></strong> mukammal restore ho chuki hai.</p>

                            <div class="row g-2 mb-4">
                                <div class="col-4">
                                    <div class="stat-badge">
                                        <h3><?= number_format($restore_stats['tables']) ?></h3>
                                        <small>Tables Created</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-badge">
                                        <h3 class="text-primary"><?= number_format($restore_stats['subscribers']) ?></h3>
                                        <small>Subscribers</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-badge">
                                        <h3 class="text-success"><?= number_format($restore_stats['routers']) ?></h3>
                                        <small>Routers (NAS)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="small text-muted mb-4">
                                <i class="fa-solid fa-bolt text-warning me-1"></i> Executed <strong><?= number_format($restore_stats['queries']) ?></strong> SQL queries in <strong><?= $restore_stats['time_sec'] ?>s</strong>.
                            </div>

                            <!-- Navigation Links -->
                            <div class="d-flex flex-wrap gap-2 justify-content-center mb-4">
                                <a href="/operator/login.php" class="btn btn-primary fw-bold px-4 py-2 rounded-pill shadow-sm">
                                    <i class="fa-solid fa-user-tie me-1"></i> Operator Login
                                </a>
                                <a href="/superadmin/login.php" class="btn btn-dark fw-bold px-4 py-2 rounded-pill shadow-sm">
                                    <i class="fa-solid fa-user-shield me-1"></i> SuperAdmin Login
                                </a>
                                <a href="/customer/login.php" class="btn btn-outline-secondary fw-semibold px-4 py-2 rounded-pill">
                                    <i class="fa-solid fa-users me-1"></i> Customer Portal
                                </a>
                            </div>

                            <!-- Security Self-Delete Box -->
                            <div class="p-3 bg-danger bg-opacity-10 border border-danger-subtle rounded-3 text-start">
                                <h6 class="fw-bold text-danger mb-1"><i class="fa-solid fa-shield-halved me-1"></i> Security Recommendation:</h6>
                                <p class="text-muted small mb-2">Restoration ke baad is script (<code>restorebackup.php</code>) ko server se delete kar dein taake koi doosra shakhs database ko dubara overwrite na kar saky.</p>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete restorebackup.php?');">
                                    <input type="hidden" name="action" value="self_delete">
                                    <button type="submit" class="btn btn-sm btn-danger fw-bold rounded-pill px-3">
                                        <i class="fa-solid fa-trash-can me-1"></i> Delete restorebackup.php Now
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$restore_success): ?>
                        <form method="POST" enctype="multipart/form-data" id="restoreForm">
                            <input type="hidden" name="action" value="restore">

                            <!-- Section 1: Choose Backup File -->
                            <div class="mb-4">
                                <label class="form-label fw-bold text-dark"><i class="fa-solid fa-file-arrow-up text-primary me-2"></i> 1. Select Backup File (.sql / .sql.gz)</label>
                                
                                <div class="mb-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="source_type" id="src_upload" value="upload" checked onclick="toggleSource('upload')">
                                        <label class="form-check-label fw-semibold" for="src_upload">Upload File From PC / Mobile</label>
                                    </div>
                                    <?php if (!empty($existing_backups)): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="source_type" id="src_existing" value="existing" onclick="toggleSource('existing')">
                                        <label class="form-check-label fw-semibold" for="src_existing">Pick File Already On Server (<?= count($existing_backups) ?> found)</label>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Upload Input Container -->
                                <div id="upload_container" class="upload-dropzone mb-2" onclick="document.getElementById('sql_file').click();">
                                    <i class="fa-solid fa-cloud-arrow-up fa-3x text-primary mb-2"></i>
                                    <h6 class="fw-bold mb-1">Click to browse or drop your .sql file here</h6>
                                    <p class="text-muted small mb-0">Max allowed upload size: 256 MB</p>
                                    <input type="file" name="sql_file" id="sql_file" class="d-none" accept=".sql,.gz" onchange="displaySelectedFile(this)">
                                    <div id="file_selected_name" class="mt-2 fw-bold text-success small d-none"></div>
                                </div>

                                <!-- Existing Server Files Picker -->
                                <?php if (!empty($existing_backups)): ?>
                                <div id="existing_container" class="d-none">
                                    <select name="server_file" class="form-select form-select-lg">
                                        <?php foreach ($existing_backups as $eb): ?>
                                            <option value="<?= htmlspecialchars($eb['relative']) ?>">
                                                <?= htmlspecialchars($eb['relative']) ?> (<?= $eb['size'] ?> - <?= $eb['mtime'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Yeh files aapke server par pehle se mojood hain.</small>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Section 2: Database Credentials (Collapsible) -->
                            <div class="card bg-light border-0 rounded-3 mb-4">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="fw-bold text-dark mb-0"><i class="fa-solid fa-server text-secondary me-2"></i> 2. Target Database Credentials</label>
                                        <button class="btn btn-sm btn-link text-decoration-none p-0 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#dbSettings">
                                            Edit Credentials <i class="fa-solid fa-chevron-down ms-1"></i>
                                        </button>
                                    </div>
                                    <div class="small text-muted mb-2">
                                        Auto-detected: <code><?= htmlspecialchars($default_user) ?>@<?= htmlspecialchars($default_host) ?></code> &rarr; DB: <code><?= htmlspecialchars($default_db) ?></code>
                                    </div>

                                    <div class="collapse" id="dbSettings">
                                        <div class="row g-2 pt-2 border-top">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">DB Host</label>
                                                <input type="text" name="db_host" class="form-control form-control-sm" value="<?= htmlspecialchars($default_host) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Database Name</label>
                                                <input type="text" name="db_name" class="form-control form-control-sm" value="<?= htmlspecialchars($default_db) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">DB Username</label>
                                                <input type="text" name="db_user" class="form-control form-control-sm" value="<?= htmlspecialchars($default_user) ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">DB Password</label>
                                                <input type="text" name="db_pass" class="form-control form-control-sm" value="<?= htmlspecialchars($default_pass) ?>" placeholder="Leave blank if no password">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3: Security PIN -->
                            <div class="mb-4">
                                <label class="form-label fw-bold text-dark"><i class="fa-solid fa-key text-warning me-2"></i> 3. Security PIN</label>
                                <div class="input-group">
                                    <input type="password" name="pin" id="pin" class="form-control form-control-lg font-monospace" placeholder="Enter PIN (Default: 1234)" value="1234" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePinVisibility()"><i class="fa-solid fa-eye" id="eyeIcon"></i></button>
                                </div>
                                <small class="text-muted">Security verification code to authorize database overwrite. Default is <code>1234</code>.</small>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm py-3 rounded-pill" id="btnSubmit">
                                <i class="fa-solid fa-play me-2"></i> Start Database Restore Now
                            </button>
                        </form>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Footer note -->
            <div class="text-center text-white-50 small mt-4">
                &copy; <?= date('Y') ?> SB Link Network / NetBee &bull; RADIUS Management Engine
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSource(type) {
    var upCont = document.getElementById('upload_container');
    var exCont = document.getElementById('existing_container');
    if (type === 'upload') {
        if (upCont) upCont.classList.remove('d-none');
        if (exCont) exCont.classList.add('d-none');
    } else {
        if (upCont) upCont.classList.add('d-none');
        if (exCont) exCont.classList.remove('d-none');
    }
}

function displaySelectedFile(input) {
    var label = document.getElementById('file_selected_name');
    if (input.files && input.files[0]) {
        var f = input.files[0];
        label.classList.remove('d-none');
        label.innerHTML = '<i class="fa-solid fa-file-circle-check text-success me-1"></i> Selected: ' + f.name + ' (' + (f.size / (1024*1024)).toFixed(2) + ' MB)';
    }
}

function togglePinVisibility() {
    var p = document.getElementById('pin');
    var icon = document.getElementById('eyeIcon');
    if (p.type === 'password') {
        p.type = 'text';
        icon.className = 'fa-solid fa-eye-slash';
    } else {
        p.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}

document.getElementById('restoreForm')?.addEventListener('submit', function(e) {
    var isUpload = document.getElementById('src_upload')?.checked;
    var fileInp = document.getElementById('sql_file');
    
    if (isUpload && (!fileInp.files || fileInp.files.length === 0)) {
        e.preventDefault();
        alert('Barah-e-karam pehle .sql backup file select karein!');
        return false;
    }
    
    if (!confirm('WARNING: Yeh action database ko update / overwrite kar dega. Kya aap waqai database restore karna chahte hain?')) {
        e.preventDefault();
        return false;
    }
    
    var btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Restoring Database... Please wait (do not close this window)';
});
</script>

</body>
</html>
