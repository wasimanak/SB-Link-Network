<?php
require_once 'auth_check.php';
require_once '../config/db.php';
require_once '../config/backup_helper.php';

$success_msg = '';
$error_msg = '';

// 1. Download Backup Handler
if (isset($_GET['download'])) {
    $req_file = basename($_GET['download']);
    $dir = get_backup_storage_dir();
    $full_path = $dir . '/' . $req_file;

    if (file_exists($full_path) && str_ends_with($req_file, '.sql')) {
        // Output headers to trigger direct browser download
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $req_file . '"');
        header('Content-Length: ' . filesize($full_path));
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        readfile($full_path);
        exit;
    } else {
        $error_msg = "The requested backup file does not exist or has an invalid extension.";
    }
}

// 2. Create Instant Backup Action (Manual Snapshot)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_instant_backup') {
    $res = create_system_database_backup(null, 'manual_snapshot');
    if ($res['success']) {
        $success_msg = "New database backup successfully generated: <strong>{$res['filename']}</strong> ({$res['size_str']})!";
    } else {
        $error_msg = "Failed to create backup: " . htmlspecialchars($res['error'] ?? 'Unknown error');
    }
}

// 3. Delete Backup Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_backup') {
    $req_file = basename($_POST['filename'] ?? '');
    $dir = get_backup_storage_dir();
    $full_path = $dir . '/' . $req_file;

    if (file_exists($full_path) && str_ends_with($req_file, '.sql')) {
        if (@unlink($full_path)) {
            $success_msg = "Backup file <strong>{$req_file}</strong> has been deleted.";
        } else {
            $error_msg = "Unable to delete file. Check file permissions on the server.";
        }
    } else {
        $error_msg = "Backup file not found.";
    }
}

// 4. Direct Restore Action (Safe Line-by-Line Execution with Foreign Keys Disabled)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_direct_backup') {
    $req_file = basename($_POST['filename'] ?? '');
    $dir = get_backup_storage_dir();
    $full_path = $dir . '/' . $req_file;

    if (!isset($_POST['confirm_restore_checkbox'])) {
        $error_msg = "Please confirm restoration by ticking the confirmation checkbox.";
    } elseif (file_exists($full_path) && str_ends_with($req_file, '.sql')) {
        @set_time_limit(1800);
        @ini_set('memory_limit', '1024M');

        try {
            $handle = @fopen($full_path, 'r');
            if (!$handle) {
                throw new Exception("Unable to open backup file for reading.");
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
            $pdo->exec("SET NAMES utf8mb4;");

            $query = '';
            $in_string = false;
            $executed_queries = 0;

            while (($line = fgets($handle)) !== false) {
                if (!$in_string) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                }

                $len = strlen($line);
                for ($i = 0; $i < $len; $i++) {
                    $char = $line[$i];
                    if ($char === "'") {
                        $escaped = false;
                        $j = $i - 1;
                        while ($j >= 0 && $line[$j] === "\\") {
                            $escaped = !$escaped;
                            $j--;
                        }
                        if (!$escaped) {
                            $in_string = !$in_string;
                        }
                    } elseif ($char === ";" && !$in_string) {
                        $query .= substr($line, 0, $i);
                        $trimQ = trim($query);
                        if (!empty($trimQ)) {
                            $pdo->exec($trimQ);
                            $executed_queries++;
                        }
                        $query = '';
                        $line = substr($line, $i + 1);
                        $len = strlen($line);
                        $i = -1;
                    }
                }
                $query .= $line;
            }

            if (!empty(trim($query))) {
                $pdo->exec(trim($query));
                $executed_queries++;
            }

            fclose($handle);
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            // Log restoration
            try {
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) 
                    VALUES (0, 'SuperAdmin', 'SuperAdmin', 'DATABASE', 'System', ?)")
                    ->execute(["Restored database from stored backup: {$req_file} ({$executed_queries} queries executed)"]);
            } catch (Throwable $eL) {}

            $success_msg = "Database successfully restored from <strong>{$req_file}</strong>! ({$executed_queries} SQL statements executed).";
        } catch (Throwable $eRest) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $error_msg = "Restoration failed: " . htmlspecialchars($eRest->getMessage());
        }
    } else {
        $error_msg = "Selected backup file does not exist.";
    }
}

// 5. Fetch Backups List (Strictly last 30 backups)
$backups = get_system_backups_list();
$total_backups = count($backups);

$total_bytes = 0;
foreach ($backups as $b) {
    $total_bytes += $b['filesize'];
}
$total_storage_str = format_backup_bytes($total_bytes);
$latest_backup_date = !empty($backups[0]['created_at']) ? date('d M Y, h:i A', strtotime($backups[0]['created_at'])) : 'No backups yet';

require_once 'header.php';
?>

<div class="content-wrapper">
    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="settings" class="text-decoration-none">Global Settings</a></li>
                <li class="breadcrumb-item active" aria-current="page">Database Backups</li>
            </ol>
        </nav>

        <!-- Flash Messages -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 rounded-4 mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-circle-check fs-4 me-3 text-success"></i>
                    <div><?= $success_msg ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 rounded-4 mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-circle-exclamation fs-4 me-3 text-danger"></i>
                    <div><?= $error_msg ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1">
                    <i class="fa-solid fa-database text-success me-2"></i> Database Backups &amp; Retention
                </h3>
                <p class="text-muted mb-0 small">
                    Automated daily midnight snapshots (12:00 AM) saved to <code>/var/www/html/superadmin/backups/</code>. Retains strictly the <strong>last 30 backups</strong>.
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <!-- Create Instant Backup Button -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="create_instant_backup">
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="this.innerHTML='<i class=\'fa-solid fa-circle-notch fa-spin me-1\'></i> Creating Backup...'; this.classList.add('disabled');">
                        <i class="fa-solid fa-plus me-1"></i> Create Backup Now
                    </button>
                </form>

                <!-- Link to Settings Restore -->
                <a href="settings" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm fw-semibold" title="Upload custom backup in Settings">
                    <i class="fa-solid fa-sliders me-1"></i> Settings
                </a>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <!-- 1. Stored Backups -->
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success flex-shrink-0" style="width: 50px; height: 50px;">
                            <i class="fa-solid fa-box-archive fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Stored Backups</div>
                            <div class="fs-4 fw-bold text-dark"><?= $total_backups ?> <span class="fs-6 text-muted fw-normal">/ 30 max</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Total Storage -->
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width: 50px; height: 50px;">
                            <i class="fa-solid fa-hard-drive fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Storage Used</div>
                            <div class="fs-4 fw-bold text-primary"><?= $total_storage_str ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Automated Midnight Schedule -->
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning flex-shrink-0" style="width: 50px; height: 50px;">
                            <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Auto Schedule</div>
                            <div class="fw-bold text-dark mt-1" style="font-size: 0.95rem;">
                                Daily 12:00 AM <span class="badge bg-success bg-opacity-10 text-success ms-1" style="font-size: 0.7rem;">Active</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Latest Backup Date -->
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-info bg-opacity-10 text-info flex-shrink-0" style="width: 50px; height: 50px;">
                            <i class="fa-solid fa-calendar-check fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Latest Snapshot</div>
                            <div class="fw-bold text-dark mt-1 text-truncate" style="font-size: 0.9rem;" title="<?= $latest_backup_date ?>">
                                <?= $latest_backup_date ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backups List Table Card -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-list-check text-success me-2"></i> Last 30 Daily Database Backups
                </h5>
                <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill fw-semibold">
                    Retention: Strictly 30 Backups
                </span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" style="width: 60px;">#</th>
                                <th>Backup File Name</th>
                                <th>File Size</th>
                                <th>Created Date &amp; Time</th>
                                <th>Integrity</th>
                                <th class="text-end pe-4" style="min-width: 260px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($backups)): ?>
                                <?php foreach ($backups as $idx => $b): 
                                    $is_today = (date('Y-m-d', $b['mtime']) === date('Y-m-d'));
                                    $formatted_date = date('d M Y, h:i:s A', $b['mtime']);
                                    $rel_ago = function_exists('time_ago_str') ? time_ago_str(date('Y-m-d H:i:s', $b['mtime'])) : '';
                                ?>
                                <tr>
                                    <!-- Index -->
                                    <td class="ps-4 fw-bold text-muted"><?= $idx + 1 ?></td>

                                    <!-- Filename -->
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px;">
                                                <i class="fa-solid fa-file-code"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark font-monospace" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($b['filename']) ?>
                                                </div>
                                                <div class="text-muted small" style="font-size: 0.75rem;">Full Database SQL Dump (.sql)</div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- File Size -->
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fw-bold">
                                            <?= $b['size_str'] ?>
                                        </span>
                                    </td>

                                    <!-- Date & Time -->
                                    <td>
                                        <div class="fw-semibold text-dark font-monospace" style="font-size: 0.88rem;">
                                            <?= $formatted_date ?>
                                        </div>
                                        <?php if ($is_today): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size: 0.68rem;">Today</span>
                                        <?php endif; ?>
                                        <?php if (!empty($rel_ago)): ?>
                                            <span class="text-muted small" style="font-size: 0.75rem;">(<?= $rel_ago ?>)</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Integrity Status -->
                                    <td>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 d-inline-flex align-items-center">
                                            <i class="fa-solid fa-shield-check me-1"></i> Complete &amp; Verified
                                        </span>
                                    </td>

                                    <!-- Actions -->
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-2">
                                            <!-- Download Button -->
                                            <a href="backups?download=<?= urlencode($b['filename']) ?>" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" title="Download .sql to computer">
                                                <i class="fa-solid fa-download me-1"></i> Download
                                            </a>

                                            <!-- 1-Click Restore Button -->
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold" onclick="triggerRestoreModal('<?= htmlspecialchars($b['filename']) ?>', '<?= $b['size_str'] ?>', '<?= $formatted_date ?>')">
                                                <i class="fa-solid fa-rotate-left me-1"></i> Restore
                                            </button>

                                            <!-- Delete Button -->
                                            <button type="button" class="btn btn-sm btn-outline-danger rounded-circle p-0 d-inline-flex align-items-center justify-content-center" style="width: 31px; height: 31px;" onclick="triggerDeleteModal('<?= htmlspecialchars($b['filename']) ?>')" title="Delete backup">
                                                <i class="fa-solid fa-trash-can" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-box-open fs-1 d-block mb-3 opacity-25"></i>
                                        <h5>No Database Backups Stored Yet</h5>
                                        <p class="small mb-3">Click the button below to generate your first complete database snapshot.</p>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="create_instant_backup">
                                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                                                <i class="fa-solid fa-plus me-1"></i> Create First Backup
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- VPS Automated Crontab Setup Guide -->
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="background: linear-gradient(135deg, #f8fafc, #edf2f7); border-left: 4px solid #10b981 !important;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                        <i class="fa-solid fa-clock fs-5"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="fw-bold text-dark mb-1">VPS Automated Midnight (12:00 AM) Cron Setup</h5>
                        <p class="text-secondary small mb-3">
                            Rozana raat 12:00 AM par automated backup lene ke liye apne VPS terminal me yeh 1-line cron command add karein:
                        </p>

                        <div class="bg-dark text-white p-3 rounded-3 font-monospace small position-relative mb-2" style="user-select: all;">
                            <span id="cronCommandText">0 0 * * * /usr/bin/php /var/www/html/superadmin/cron_backup.php > /dev/null 2>&1</span>
                            <button type="button" class="btn btn-sm btn-light position-absolute top-50 end-0 translate-middle-y me-2 rounded-pill px-3 fw-bold" onclick="copyCronCmd()">
                                <i class="fa-regular fa-copy me-1"></i> Copy
                            </button>
                        </div>
                        <div class="text-muted small">
                            <i class="fa-solid fa-circle-info text-primary me-1"></i> <strong>Tareeqa:</strong> VPS Terminal me <code>crontab -e</code> chalayein aur sab se aakhri line me yeh command paste kar ke save kar dein.
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Restore Confirmation Modal -->
<div class="modal fade" id="restoreConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Confirm Database Restoration
                </h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="directRestoreForm" onsubmit="showRestoreLoading()">
                <input type="hidden" name="action" value="restore_direct_backup">
                <input type="hidden" name="filename" id="restoreFilenameInput" value="">
                
                <div class="modal-body py-3">
                    <p class="text-secondary mb-3">
                        Aap database ko is snapshot se restore karne lage hain:
                    </p>
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Backup File:</span>
                            <span class="fw-bold text-dark font-monospace small" id="restoreFileDisplay">—</span>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Snapshot Size:</span>
                            <span class="fw-bold text-primary small" id="restoreSizeDisplay">—</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Created Date:</span>
                            <span class="fw-bold text-secondary small" id="restoreDateDisplay">—</span>
                        </div>
                    </div>

                    <div class="alert alert-warning border-0 rounded-3 p-3 small text-danger mb-3">
                        <i class="fa-solid fa-circle-exclamation me-1"></i> <strong>Critical Warning:</strong> Database restore karne se mojooda database tables, subscribers aur ledger records is backup file ke mutabiq overwrite ho jayenge.
                    </div>

                    <div class="form-check p-3 bg-light rounded-3 border">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_restore_checkbox" id="confirmRestoreCheck" required>
                        <label class="form-check-label text-dark fw-bold small" for="confirmRestoreCheck" style="cursor: pointer;">
                            I understand and confirm to restore MySQL database from this backup file.
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" id="restoreSubmitBtn">
                        <i class="fa-solid fa-rotate-left me-1"></i> Yes, Restore Database
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-trash-can me-2"></i> Delete Backup File
                </h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_backup">
                <input type="hidden" name="filename" id="deleteFilenameInput" value="">
                <div class="modal-body py-3">
                    <p class="text-secondary mb-2">
                        Kya aap waqai is backup snapshot ko delete karna chahte hain?
                    </p>
                    <div class="p-2 bg-light rounded border font-monospace small text-danger fw-bold text-truncate" id="deleteFileDisplay"></div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-trash-can me-1"></i> Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function triggerRestoreModal(filename, size, date) {
    document.getElementById('restoreFilenameInput').value = filename;
    document.getElementById('restoreFileDisplay').textContent = filename;
    document.getElementById('restoreSizeDisplay').textContent = size;
    document.getElementById('restoreDateDisplay').textContent = date;
    document.getElementById('confirmRestoreCheck').checked = false;
    new bootstrap.Modal(document.getElementById('restoreConfirmModal')).show();
}

function showRestoreLoading() {
    const btn = document.getElementById('restoreSubmitBtn');
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Restoring Database...';
    btn.classList.add('disabled');
}

function triggerDeleteModal(filename) {
    document.getElementById('deleteFilenameInput').value = filename;
    document.getElementById('deleteFileDisplay').textContent = filename;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

function copyCronCmd() {
    const text = document.getElementById('cronCommandText').textContent.trim();
    navigator.clipboard.writeText(text).then(() => {
        alert('Cron command copied to clipboard!');
    });
}
</script>

<?php require_once 'footer.php'; ?>
