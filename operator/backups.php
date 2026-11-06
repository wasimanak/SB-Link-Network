<?php
// =========================================================================
// Official Financial Backups & Disaster Recovery Center
// Multi-Sheet SpreadsheetML Workbook (.xls), CSV, JSON & 1-Click Restore
// Strictly retains the last 30 daily snapshots per client
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/financial_backup_helper.php';

// Auth Guard: Only Admin / Operator can access Backups
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: ../login.php");
    exit;
}
if (!empty($_SESSION['is_manager'])) {
    echo "<script>alert('Permission Denied: Managers cannot access Financial Backups.'); window.location='dashboard.php';</script>";
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
$storage_dir = get_financial_backup_storage_dir($client_id);

// -------------------------------------------------------------------------
// 1. Handle File Download (xls, csv, json) - MUST RUN BEFORE header.php!
// -------------------------------------------------------------------------
if (isset($_GET['download']) && !empty($_GET['download'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $baseName = basename($_GET['download']);
    $format   = strtolower($_GET['format'] ?? 'xls');
    
    // Security check: Must belong to current client
    if (strpos($baseName, "fin_backup_client{$client_id}_") !== 0) {
        die("Security Error: Unauthorized access to backup file.");
    }
    
    $cleanBase = preg_replace('/[^a-zA-Z0-9_-]/', '', $baseName);
    $ext = in_array($format, ['xls', 'csv', 'json']) ? $format : 'xls';
    $filePath = $storage_dir . '/' . $cleanBase . '.' . $ext;

    if (!file_exists($filePath)) {
        die("Error: The requested backup file does not exist on storage.");
    }

    if ($ext === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } elseif ($ext === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
    } elseif ($ext === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    
    flush();
    readfile($filePath);
    exit;
}

// -------------------------------------------------------------------------
// Include Page Header & Navbar (Only for HTML views)
// -------------------------------------------------------------------------
require_once 'header.php';

// Auto-trigger daily backup if none generated in the last 24 hours
try {
    auto_trigger_daily_backup_if_needed($pdo, $client_id);
} catch (Exception $e) {}

$flash_msg = '';
$flash_type = 'success';

// -------------------------------------------------------------------------
// 2. Handle POST Actions
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Action A: Create Instant Financial Backup
    if ($action === 'create_instant_backup') {
        try {
            $res = create_client_financial_backup($pdo, $client_id, 'instant');
            $flash_msg = "Instant Financial Backup successfully created! Snapshot: <strong>{$res['basename']}.xls</strong> ({$res['size_fmt']}, {$res['records_count']} transactions).";
            $flash_type = "success";
        } catch (Exception $e) {
            $flash_msg = "Error creating financial backup: " . htmlspecialchars($e->getMessage());
            $flash_type = "danger";
        }
    }

    // Action B: 1-Click Restore Backup
    elseif ($action === 'restore_backup') {
        $backup_base = trim($_POST['backup_basename'] ?? '');
        if (empty($backup_base)) {
            $flash_msg = "Please select a valid backup snapshot to restore.";
            $flash_type = "danger";
        } else {
            try {
                $stats = restore_client_financial_backup($pdo, $client_id, $backup_base);
                $flash_msg = "<strong>Financial Backup Restored Successfully!</strong><br>" .
                    "&bull; <strong>{$stats['restored_user_ledgers']}</strong> missing subscriber payment ledgers restored.<br>" .
                    "&bull; <strong>{$stats['updated_sub_balances']}</strong> subscriber balances reconciled.<br>" .
                    "&bull; <strong>{$stats['restored_salaries']}</strong> staff salary & advance records restored.<br>" .
                    "&bull; <strong>{$stats['restored_expenses']}</strong> business expense records restored.<br>" .
                    "&bull; <strong>{$stats['reconciled_managers']}</strong> manager cash-in-hand accounts reconciled.<br>" .
                    "&bull; <strong>{$stats['reconciled_recoverymen']}</strong> operator cash-in-hand accounts reconciled.<br>" .
                    "<small class='text-muted'>A safety rollback snapshot was automatically created before applying this restore.</small>";
                $flash_type = "success";
            } catch (Exception $e) {
                $flash_msg = "Restore failed: " . htmlspecialchars($e->getMessage());
                $flash_type = "danger";
            }
        }
    }

    // Action C: Upload & Restore
    elseif ($action === 'upload_restore') {
        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['backup_file']['tmp_name'];
            $origName = $_FILES['backup_file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if ($ext === 'json') {
                $jsonContent = file_get_contents($tmpPath);
                $decoded = json_decode($jsonContent, true);
                if (!$decoded || !isset($decoded['client_id'])) {
                    $flash_msg = "Invalid JSON backup file: Missing client header metadata.";
                    $flash_type = "danger";
                } elseif ((int)$decoded['client_id'] !== $client_id) {
                    $flash_msg = "Security mismatch: This backup was created for Client ID #{$decoded['client_id']}, but your Client ID is #{$client_id}.";
                    $flash_type = "danger";
                } else {
                    $newBase = "fin_backup_client{$client_id}_" . date('Y-m-d_His') . "_uploaded";
                    $destJson = $storage_dir . '/' . $newBase . '.json';
                    move_uploaded_file($tmpPath, $destJson);

                    // Also generate corresponding .xls and .csv for it
                    try {
                        $fullData = fetch_client_financial_dataset($pdo, $client_id);
                        $xml = build_multisheet_excel_xml($fullData);
                        file_put_contents($storage_dir . '/' . $newBase . '.xls', $xml);
                        $csv = build_flat_csv($fullData);
                        file_put_contents($storage_dir . '/' . $newBase . '.csv', $csv);

                        // Execute restore
                        $stats = restore_client_financial_backup($pdo, $client_id, $newBase);
                        $flash_msg = "<strong>Uploaded Backup Restored Successfully!</strong> Restored {$stats['restored_user_ledgers']} ledgers and reconciled {$stats['updated_sub_balances']} subscribers.";
                        $flash_type = "success";
                    } catch (Exception $e) {
                        $flash_msg = "Restore failed: " . htmlspecialchars($e->getMessage());
                        $flash_type = "danger";
                    }
                }
            } else {
                $flash_msg = "Please upload a valid <strong>.json</strong> database backup snapshot file.";
                $flash_type = "danger";
            }
        } else {
            $flash_msg = "File upload error. Please select a valid file.";
            $flash_type = "danger";
        }
    }

    // Action D: Delete Specific Backup
    elseif ($action === 'delete_backup') {
        $backup_base = trim($_POST['backup_basename'] ?? '');
        if (!empty($backup_base) && strpos($backup_base, "fin_backup_client{$client_id}_") === 0) {
            $cleanBase = preg_replace('/[^a-zA-Z0-9_-]/', '', $backup_base);
            @unlink($storage_dir . '/' . $cleanBase . '.xls');
            @unlink($storage_dir . '/' . $cleanBase . '.csv');
            @unlink($storage_dir . '/' . $cleanBase . '.json');
            $flash_msg = "Backup snapshot <strong>{$cleanBase}</strong> was deleted from storage.";
            $flash_type = "warning";
        }
    }
}

// -------------------------------------------------------------------------
// 3. Load All Available Backups & Metrics
// -------------------------------------------------------------------------
$backups = list_client_financial_backups($client_id);
$total_snapshots = count($backups);
$total_storage_bytes = 0;
foreach ($backups as $b) {
    $total_storage_bytes += $b['size_bytes'];
}
$total_storage_mb = round($total_storage_bytes / (1024 * 1024), 2);
$latest_backup = $backups[0] ?? null;

?>

<style>
    .backup-stat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 20px 22px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        height: 100%;
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .backup-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.05);
    }
    .backup-stat-icon {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .backup-badge-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border-radius: 50px;
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.3px;
    }
    .badge-auto-daily {
        background-color: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    .badge-instant {
        background-color: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
    }
    .badge-pre-restore {
        background-color: #fffbeb;
        color: #d97706;
        border: 1px solid #fef3c7;
    }
    .badge-manual {
        background-color: #f5f3ff;
        color: #7c3aed;
        border: 1px solid #ddd6fe;
    }
    .table-backups {
        border-collapse: separate;
        border-spacing: 0;
        width: 100%;
    }
    .table-backups thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 12px 16px;
        border-bottom: 2px solid #e2e8f0;
    }
    .table-backups tbody td {
        padding: 14px 16px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        font-size: 0.88rem;
    }
    .table-backups tbody tr:hover {
        background-color: #f8fafc;
    }
    .btn-action-restore {
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
        font-weight: 700;
        font-size: 0.8rem;
        padding: 5px 14px;
        border-radius: 8px;
        transition: all 0.18s ease;
    }
    .btn-action-restore:hover {
        background: #059669;
        color: #ffffff;
        border-color: #059669;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(5, 150, 105, 0.25);
    }
    .btn-action-download {
        background: #f1f5f9;
        color: #1e293b;
        border: 1px solid #cbd5e1;
        font-weight: 600;
        font-size: 0.8rem;
        padding: 5px 12px;
        border-radius: 8px;
        transition: all 0.18s ease;
    }
    .btn-action-download:hover {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }
</style>

<!-- Main Container -->
<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Top Header & Actions -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary px-3 py-1 fw-bold" style="font-size: 0.78rem;">
                    <i class="fa-solid fa-shield-halved me-1"></i> Automated Disaster Recovery
                </span>
                <span class="badge rounded-pill bg-success bg-opacity-10 text-success px-3 py-1 fw-bold" style="font-size: 0.78rem;">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i> 30-Day Auto Retention
                </span>
            </div>
            <h3 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">Financial Backups &amp; Ledger Archival</h3>
            <p class="text-secondary small mb-0">Multi-Sheet Excel Workbooks (.xls), Master CSV &amp; 1-Click Database Restore</p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- Create Instant Backup Button -->
            <form method="POST" class="d-inline" onsubmit="showGlobalLoader()">
                <input type="hidden" name="action" value="create_instant_backup">
                <button type="submit" class="btn btn-primary fw-bold px-4 py-2 rounded-3 shadow-sm d-flex align-items-center gap-2" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none;">
                    <i class="fa-solid fa-cloud-arrow-down fa-bounce"></i>
                    <span>+ Create Backup Now</span>
                </button>
            </form>

            <!-- Upload & Restore Modal Trigger -->
            <button type="button" class="btn btn-outline-secondary fw-bold px-3 py-2 rounded-3 bg-white shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#uploadRestoreModal">
                <i class="fa-solid fa-cloud-arrow-up text-primary"></i>
                <span>Upload &amp; Restore</span>
            </button>

            <!-- Cron Command Information Modal Trigger -->
            <button type="button" class="btn btn-light border fw-bold px-3 py-2 rounded-3 shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#cronInfoModal" title="View Daily Automation Schedule">
                <i class="fa-solid fa-robot text-secondary"></i>
                <span class="d-none d-sm-inline">Daily Cron</span>
            </button>
        </div>
    </div>

    <!-- Flash Alert Message -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show rounded-4 border-0 shadow-sm p-3 mb-4" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="fa-solid <?= $flash_type === 'success' ? 'fa-circle-check text-success' : ($flash_type === 'warning' ? 'fa-triangle-exclamation text-warning' : 'fa-circle-exclamation text-danger') ?> fs-5 mt-1"></i>
                <div class="flex-grow-1">
                    <?= $flash_msg ?>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4 High-Level Metric Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Stored Snapshots / Retention -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="backup-stat-card">
                <div class="backup-stat-icon" style="background-color: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Stored Backups</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <h4 class="fw-bold text-dark mb-0"><?= $total_snapshots ?> <span class="fs-6 text-muted fw-normal">/ 30 Max</span></h4>
                    </div>
                    <div class="progress mt-2" style="height: 5px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= min(100, round(($total_snapshots / 30) * 100)) ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Storage Footprint -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="backup-stat-card">
                <div class="backup-stat-icon" style="background-color: #ecfdf5; color: #059669;">
                    <i class="fa-solid fa-hard-drive"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Disk Footprint</div>
                    <h4 class="fw-bold text-dark mb-0"><?= $total_storage_mb ?> <span class="fs-6 text-muted fw-normal">MB Total</span></h4>
                    <span class="text-success small fw-semibold"><i class="fa-solid fa-bolt me-1"></i> Ultra-Fast &amp; Optimized</span>
                </div>
            </div>
        </div>

        <!-- 3. Latest Snapshot -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="backup-stat-card">
                <div class="backup-stat-icon" style="background-color: #f5f3ff; color: #7c3aed;">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Latest Snapshot</div>
                    <h5 class="fw-bold text-dark mb-0" style="font-size: 1.05rem;">
                        <?= $latest_backup ? date('d M Y, h:i A', $latest_backup['timestamp']) : 'No backups yet' ?>
                    </h5>
                    <span class="text-muted small">
                        <?= $latest_backup ? $latest_backup['type'] : 'Click "+ Create Backup Now"' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- 4. Multi-Sheet Architecture -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="backup-stat-card">
                <div class="backup-stat-icon" style="background-color: #fffbeb; color: #d97706;">
                    <i class="fa-solid fa-file-excel"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Spreadsheet Structure</div>
                    <h5 class="fw-bold text-dark mb-0" style="font-size: 1.05rem;">Multi-Worksheet XLS</h5>
                    <span class="text-muted small">Dedicated sheet for each Manager</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Format Structure Feature Banner -->
    <div class="card border-0 rounded-4 p-3 mb-4 shadow-sm" style="background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border-left: 5px solid #2563eb !important;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-white rounded-3 p-2 shadow-sm border text-primary fs-4">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <h6 class="fw-bold text-dark mb-1">What is inside each financial backup snapshot?</h6>
                    <p class="text-secondary small mb-0">
                        Every snapshot includes a <strong>Multi-Sheet Excel Workbook (.xls)</strong> with individual tabs:
                        <span class="badge bg-white text-dark border me-1">1. All Transactions</span>
                        <span class="badge bg-white text-dark border me-1">2. User Collections</span>
                        <span class="badge bg-white text-dark border me-1">3. Mgr_&lt;ManagerName&gt; (Per Manager)</span>
                        <span class="badge bg-white text-dark border me-1">4. Staff RecoveryMen</span>
                        <span class="badge bg-white text-dark border me-1">5. Staff LineMen</span>
                        <span class="badge bg-white text-dark border me-1">6. Staff Salaries</span>
                        <span class="badge bg-white text-dark border">7. Daily Expenses</span>
                    </p>
                </div>
            </div>
            <div class="text-end">
                <span class="badge bg-primary text-white rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-rotate me-1"></i> Auto 30-Day Rotation
                </span>
            </div>
        </div>
    </div>

    <!-- Backups Table Card -->
    <div class="card border-0 rounded-4 shadow-sm bg-white overflow-hidden mb-4">
        <div class="card-header bg-white border-0 py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-0">Archived Snapshots (Last 30 Days)</h5>
                <p class="text-secondary small mb-0">Backups are strictly retained for 30 days. Older files are automatically pruned to save storage.</p>
            </div>
            <div>
                <input type="text" id="backupSearchInput" class="form-control form-control-sm rounded-pill px-3 shadow-none border" placeholder="Search snapshot date, type..." style="width: 250px;">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-backups align-middle mb-0" id="backupsTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Date &amp; Time</th>
                        <th>Snapshot Type</th>
                        <th>File Basename</th>
                        <th>Scope &amp; Scope Counts</th>
                        <th>File Size</th>
                        <th class="text-center">Download Formats</th>
                        <th class="text-end">Disaster Recovery</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($backups)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="fa-solid fa-box-open fs-1 mb-2 d-block opacity-50"></i>
                                    <h6 class="fw-bold">No Financial Backups Found</h6>
                                    <p class="small mb-3">Click the button below to generate your first financial snapshot.</p>
                                    <form method="POST" class="d-inline" onsubmit="showGlobalLoader()">
                                        <input type="hidden" name="action" value="create_instant_backup">
                                        <button type="submit" class="btn btn-primary btn-sm fw-bold px-4 rounded-pill">
                                            + Create First Backup
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($backups as $idx => $b): ?>
                            <?php
                                $badgeClass = 'badge-auto-daily';
                                if ($b['type'] === 'Instant Snapshot') $badgeClass = 'badge-instant';
                                elseif ($b['type'] === 'Pre-Restore Safety') $badgeClass = 'badge-pre-restore';
                                elseif ($b['type'] === 'Manual Snapshot') $badgeClass = 'badge-manual';
                                
                                $colCount = $b['summary']['total_user_collections'] ?? ($b['summary']['total_transactions'] ?? 'All');
                                $colAmt = !empty($b['summary']['total_collected_amount']) ? 'Rs. ' . number_format($b['summary']['total_collected_amount'], 0) : '';
                                $mgrCount = $b['summary']['total_managers'] ?? 0;
                            ?>
                            <tr>
                                <td class="text-muted fw-bold"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= date('d M Y, h:i A', $b['timestamp']) ?></div>
                                    <small class="text-muted"><?= date('l', $b['timestamp']) ?></small>
                                </td>
                                <td>
                                    <span class="backup-badge-pill <?= $badgeClass ?>">
                                        <i class="fa-solid fa-circle" style="font-size: 0.5rem;"></i>
                                        <?= htmlspecialchars($b['type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <code class="text-dark bg-light px-2 py-1 rounded small border" title="<?= htmlspecialchars($b['basename']) ?>">
                                        <?= htmlspecialchars(mb_strimwidth($b['basename'], 0, 32, '...')) ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fw-semibold">
                                        <i class="fa-solid fa-receipt me-1 text-success"></i> <?= $colCount ?> Collections <?= $colAmt ? "({$colAmt})" : '' ?>
                                    </span>
                                    <span class="badge bg-light text-dark border fw-semibold ms-1">
                                        <i class="fa-solid fa-user-shield me-1 text-info"></i> <?= $mgrCount ?> Managers
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($b['size_fmt']) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <!-- Multi-Sheet Excel -->
                                        <a href="backups?download=<?= urlencode($b['basename']) ?>&format=xls" download="<?= htmlspecialchars($b['basename']) ?>.xls" class="btn btn-action-download" title="Download Multi-Sheet Excel Workbook (.xls)">
                                            <i class="fa-solid fa-file-excel text-success me-1"></i> XLS
                                        </a>
                                        <!-- Master CSV -->
                                        <a href="backups?download=<?= urlencode($b['basename']) ?>&format=csv" download="<?= htmlspecialchars($b['basename']) ?>.csv" class="btn btn-action-download" title="Download Master CSV File (.csv)">
                                            <i class="fa-solid fa-file-csv text-primary me-1"></i> CSV
                                        </a>
                                        <!-- JSON DB Payload -->
                                        <a href="backups?download=<?= urlencode($b['basename']) ?>&format=json" download="<?= htmlspecialchars($b['basename']) ?>.json" class="btn btn-action-download" title="Download Full JSON Payload (.json)">
                                            <i class="fa-solid fa-code text-warning me-1"></i> JSON
                                        </a>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- Restore Button Trigger -->
                                        <button type="button" class="btn btn-action-restore" onclick="openRestoreModal('<?= htmlspecialchars($b['basename']) ?>', '<?= date('d M Y, h:i A', $b['timestamp']) ?>', '<?= htmlspecialchars($b['type']) ?>', '<?= $trxCount ?>')">
                                            <i class="fa-solid fa-rotate-left me-1"></i> Restore
                                        </button>

                                        <!-- Delete Button Trigger -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete backup snapshot: <?= htmlspecialchars($b['basename']) ?>?');">
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="backup_basename" value="<?= htmlspecialchars($b['basename']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0 rounded-circle" style="width: 32px; height: 32px;" title="Delete Snapshot">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ========================================================================= -->
<!-- 1. RESTORE CONFIRMATION MODAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="restoreConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
            <div class="modal-header bg-success text-white border-0 py-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0">Confirm Financial Restore</h5>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" onsubmit="showGlobalLoader()">
                <input type="hidden" name="action" value="restore_backup">
                <input type="hidden" name="backup_basename" id="restoreTargetBasename" value="">
                
                <div class="modal-body p-4">
                    <div class="alert alert-warning border-0 rounded-3 mb-3 d-flex gap-2">
                        <i class="fa-solid fa-shield-halved text-warning fs-5 mt-1"></i>
                        <div class="small">
                            <strong>Safety Guarantee:</strong> An automatic pre-restore safety snapshot will be taken of your live system before restoring.
                        </div>
                    </div>

                    <div class="bg-light rounded-3 p-3 mb-3 border">
                        <div class="row g-2 small">
                            <div class="col-5 text-muted">Snapshot File:</div>
                            <div class="col-7 fw-bold text-dark text-break" id="modalSnapshotName">-</div>
                            <div class="col-5 text-muted">Created On:</div>
                            <div class="col-7 fw-bold text-dark" id="modalSnapshotDate">-</div>
                            <div class="col-5 text-muted">Snapshot Type:</div>
                            <div class="col-7 fw-bold text-dark" id="modalSnapshotType">-</div>
                            <div class="col-5 text-muted">Total Scope:</div>
                            <div class="col-7 fw-bold text-dark" id="modalSnapshotTrx">-</div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-dark mb-2" style="font-size: 0.9rem;">What will be restored?</h6>
                    <ul class="text-secondary small ps-3 mb-3">
                        <li>Missing subscriber payment ledgers &amp; recharge receipts</li>
                        <li>Subscriber balance reconciliations</li>
                        <li>Staff salary accruals, cash advances, and payouts</li>
                        <li>Daily business expense records</li>
                        <li>Manager &amp; Operator cash-in-hand running accounts</li>
                    </ul>

                    <div class="form-check bg-light p-3 rounded-3 border">
                        <input class="form-check-input ms-0 me-2" type="checkbox" id="confirmCheckbox" required>
                        <label class="form-check-label small fw-semibold text-dark" for="confirmCheckbox">
                            I confirm that I want to restore this financial snapshot into the live database.
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer border-0 bg-light py-3 px-4">
                    <button type="button" class="btn btn-light border fw-semibold rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm" style="background: linear-gradient(135deg, #059669, #047857); border: none;">
                        <i class="fa-solid fa-rotate-left me-1"></i> Execute Restore
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. UPLOAD & RESTORE MODAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="uploadRestoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-cloud-arrow-up fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0">Upload &amp; Restore Backup</h5>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" onsubmit="showGlobalLoader()">
                <input type="hidden" name="action" value="upload_restore">
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-3">
                        Upload a previously downloaded <strong>.json</strong> database snapshot file from this client.
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark small">Select Backup File (.json):</label>
                        <input type="file" name="backup_file" class="form-control" accept=".json" required>
                        <div class="form-text small">Only .json backup payloads generated from your portal are supported for 100% loss-free atomic restoration.</div>
                    </div>

                    <div class="alert alert-info border-0 rounded-3 small mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        The system will verify client authenticity, create a safety rollback snapshot, and restore ledgers.
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light py-3 px-4">
                    <button type="button" class="btn btn-light border fw-semibold rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 shadow-sm">
                        <i class="fa-solid fa-upload me-1"></i> Upload &amp; Restore
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 3. CRON INFO MODAL -->
<!-- ========================================================================= -->
<div class="modal fade" id="cronInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-clock fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0">Daily Auto-Backup Schedule</h5>
                </div>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <h6 class="fw-bold text-dark mb-2">Automated Background Engine</h6>
                <p class="text-secondary small mb-3">
                    Your financial backups are automatically scheduled every 24 hours. The system retains strictly the newest <strong>30 snapshots</strong> and auto-prunes older files.
                </p>

                <h6 class="fw-bold text-dark mb-1 small text-uppercase">Server Crontab Command (Linux VPS):</h6>
                <div class="bg-light p-3 rounded-3 border mb-3">
                    <code class="text-dark small user-select-all">0 2 * * * php /var/www/html/operator/cron_financial_backup.php > /dev/null 2>&amp;1</code>
                </div>

                <h6 class="fw-bold text-dark mb-1 small text-uppercase">Self-Healing Web Trigger:</h6>
                <p class="text-secondary small mb-0">
                    Even if crontab is not configured, the dashboard automatically generates the daily snapshot whenever you or your staff log in!
                </p>
            </div>
            <div class="modal-footer border-0 bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm fw-semibold rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openRestoreModal(basename, dateStr, typeStr, trxStr) {
    document.getElementById('restoreTargetBasename').value = basename;
    document.getElementById('modalSnapshotName').textContent = basename + '.xls';
    document.getElementById('modalSnapshotDate').textContent = dateStr;
    document.getElementById('modalSnapshotType').textContent = typeStr;
    document.getElementById('modalSnapshotTrx').textContent = trxStr + ' Transactions';
    document.getElementById('confirmCheckbox').checked = false;
    
    var modal = new bootstrap.Modal(document.getElementById('restoreConfirmModal'));
    modal.show();
}

// Client-Side Search Filter for Backups Table
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('backupSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            var filter = this.value.toLowerCase();
            var rows = document.querySelectorAll('#backupsTable tbody tr');
            rows.forEach(function(row) {
                var text = row.innerText.toLowerCase();
                row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
            });
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
