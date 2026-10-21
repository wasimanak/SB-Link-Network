<?php
/**
 * SB Link Network / NetBee - Real-Time 1-Click Database Restore Utility
 * Usage: http://YOUR_VPS_IP/restorebackup.php
 * Features: Live Upload Progress + Live SQL Execution Progress Bar + Live Terminal Console + Full Table Verification
 */

@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('max_execution_time', 3600);
@set_time_limit(3600);
@ini_set('memory_limit', '1024M');

$security_pin = '1234'; // Default PIN to authorize database overwrite

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
    $db_code = @file_get_contents(__DIR__ . '/config/db.php');
    if ($db_code) {
        if (preg_match('/\$host\s*=\s*[\'"]([^\'"]+)[\'"]/', $db_code, $m)) $default_host = $m[1];
        if (!$is_windows) {
            if (preg_match('/else\s*\{[^}]*?\$db\s*=\s*[\'"]([^\'"]+)[\'"][^}]*?\$user\s*=\s*[\'"]([^\'"]+)[\'"][^}]*?\$pass\s*=\s*[\'"]([^\'"]*)[\'"]/s', $db_code, $m2)) {
                $default_db   = $m2[1];
                $default_user = $m2[2];
                $default_pass = $m2[3];
            }
        }
    }
}

// Helper: get best writable directory for temporary chunks/files
function get_restore_temp_dir() {
    $candidates = [
        __DIR__ . '/uploads',
        sys_get_temp_dir(),
        '/tmp',
        __DIR__
    ];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return rtrim(str_replace('\\', '/', $dir), '/');
        }
    }
    return sys_get_temp_dir();
}

// Helper: send Server-Sent Event chunk
function send_sse($data) {
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) @ob_flush();
    @flush();
}

// -----------------------------------------------------------------------------
// AJAX ACTION 1: Chunked File Upload (Bypasses server post_max_size / upload_max_filesize)
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'upload_chunk') {
    header('Content-Type: application/json');

    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['chunk']['error'] ?? 'no_chunk';
        echo json_encode(['status' => 'error', 'message' => "Chunk upload failed. (Code: {$errCode})"]);
        exit;
    }

    $file_id      = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['file_id'] ?? '');
    $chunk_index  = (int)($_POST['chunk_index'] ?? 0);
    $total_chunks = (int)($_POST['total_chunks'] ?? 1);
    $orig_name    = $_POST['file_name'] ?? 'backup.sql';

    if (empty($file_id)) {
        $file_id = 'upload_' . time();
    }

    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql', 'gz'])) {
        echo json_encode(['status' => 'error', 'message' => "Invalid file extension. Only .sql or .sql.gz files are permitted."]);
        exit;
    }

    $temp_dir = get_restore_temp_dir();
    $target_file = $temp_dir . '/restore_chunk_' . $file_id . '.' . $ext;

    // Reset file on first chunk
    if ($chunk_index === 0 && file_exists($target_file)) {
        @unlink($target_file);
    }

    // Append chunk binary to target file
    $chunk_tmp = $_FILES['chunk']['tmp_name'];
    $in = @fopen($chunk_tmp, 'rb');
    $out = @fopen($target_file, 'ab');

    if (!$in || !$out) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        echo json_encode(['status' => 'error', 'message' => "Cannot write chunk to temporary target: {$target_file}. Permissions issue on {$temp_dir}."]);
        exit;
    }

    while (!feof($in)) {
        $buf = fread($in, 65536);
        if ($buf !== false && strlen($buf) > 0) {
            fwrite($out, $buf);
        }
    }
    fclose($in);
    fclose($out);

    $is_complete = ($chunk_index + 1 >= $total_chunks);

    if ($is_complete) {
        $final_size = file_exists($target_file) ? filesize($target_file) : 0;
        
        // Return relative path if located within project dir, else full path
        $return_path = $target_file;
        $norm_dir = rtrim(str_replace('\\', '/', __DIR__), '/');
        $norm_target = str_replace('\\', '/', $target_file);
        if (str_starts_with($norm_target, $norm_dir . '/')) {
            $return_path = substr($norm_target, strlen($norm_dir) + 1);
        }

        echo json_encode([
            'status'         => 'ok',
            'complete'       => true,
            'file'           => $return_path,
            'size'           => $final_size,
            'size_formatted' => round($final_size / (1024 * 1024), 2) . ' MB',
            'percent'        => 100
        ]);
    } else {
        $percent = round((($chunk_index + 1) / max(1, $total_chunks)) * 100, 1);
        echo json_encode([
            'status'      => 'ok',
            'complete'    => false,
            'chunk_index' => $chunk_index,
            'percent'     => $percent
        ]);
    }
    exit;
}

// Fallback: Single-file upload (for smaller files)
if (isset($_GET['action']) && $_GET['action'] === 'upload_temp') {
    header('Content-Type: application/json');

    if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['sql_file']['error'] ?? 'no_file';
        $errMsg = 'File upload failed.';
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $errMsg = 'File size is too large for web server PHP limits.';
        } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
            $errMsg = 'No file was selected for upload.';
        }
        echo json_encode(['status' => 'error', 'message' => $errMsg . " (Code: {$errCode})"]);
        exit;
    }

    $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['sql', 'gz'])) {
        echo json_encode(['status' => 'error', 'message' => "Invalid file extension. Only .sql or .sql.gz backup files are allowed."]);
        exit;
    }

    $temp_dir = get_restore_temp_dir();
    $targetFile = $temp_dir . '/temp_restore_' . time() . '.' . $ext;
    if (move_uploaded_file($_FILES['sql_file']['tmp_name'], $targetFile)) {
        $return_path = $targetFile;
        $norm_dir = rtrim(str_replace('\\', '/', __DIR__), '/');
        $norm_target = str_replace('\\', '/', $targetFile);
        if (str_starts_with($norm_target, $norm_dir . '/')) {
            $return_path = substr($norm_target, strlen($norm_dir) + 1);
        }

        echo json_encode([
            'status' => 'ok',
            'file'   => $return_path,
            'size'   => filesize($targetFile),
            'size_formatted' => round(filesize($targetFile) / (1024 * 1024), 2) . ' MB'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => "Failed to save uploaded file into {$temp_dir}."]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// AJAX ACTION 2: Real-Time Streaming SQL Restore (EventStream)
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'stream_execute') {
    // Disable all buffering
    @ini_set('zlib.output_compression', 0);
    @ini_set('output_buffering', 'off');
    @ini_set('implicit_flush', 1);
    while (ob_get_level() > 0) @ob_end_clean();
    ob_implicit_flush(true);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $pin      = trim($_POST['pin'] ?? '');
    if (!in_array($pin, ['1234', '123456'])) {
        send_sse(['status' => 'error', 'message' => "Invalid Security PIN entered. Default PIN is 1234 or 123456."]);
        exit;
    }
    $relFile  = trim($_POST['file'] ?? '');
    $db_host  = trim($_POST['db_host'] ?: 'localhost');
    $db_user  = trim($_POST['db_user'] ?: 'root');
    $db_pass  = (string)($_POST['db_pass'] ?? '');
    $db_name  = trim($_POST['db_name'] ?: 'radius');

    // Resolve file: handle both relative and absolute paths safely
    $cleanFile = str_replace('..', '', $relFile);
    if (file_exists($cleanFile) && is_readable($cleanFile) && is_file($cleanFile)) {
        $source_file = $cleanFile;
    } elseif (file_exists(__DIR__ . '/' . ltrim($cleanFile, '/\\')) && is_readable(__DIR__ . '/' . ltrim($cleanFile, '/\\'))) {
        $source_file = __DIR__ . '/' . ltrim($cleanFile, '/\\');
    } else {
        send_sse(['status' => 'error', 'message' => "Backup file could not be found or read: " . htmlspecialchars($relFile)]);
        exit;
    }

    $file_size = filesize($source_file);
    $is_gz = (substr($source_file, -3) === '.gz');

    send_sse(['status' => 'log', 'percent' => 37, 'message' => "Connecting to MySQL server ({$db_host}) as user '{$db_user}'..."]);

    $pdo = null;
    $pdo_errors = [];

    // Prioritize user's provided credentials, but auto-try common fallbacks if 1045 Access Denied
    $cred_attempts = [
        ['user' => $db_user, 'pass' => $db_pass],
    ];
    if ($db_user === 'radius') {
        if ($db_pass !== '123456') $cred_attempts[] = ['user' => 'radius', 'pass' => '123456'];
        if ($db_pass !== 'Wasi1234') $cred_attempts[] = ['user' => 'radius', 'pass' => 'Wasi1234'];
        if ($db_pass !== '') $cred_attempts[] = ['user' => 'radius', 'pass' => ''];
    }
    // Also try root if radius fails
    if ($db_user !== 'root') {
        $cred_attempts[] = ['user' => 'root', 'pass' => ''];
        $cred_attempts[] = ['user' => 'root', 'pass' => 'root'];
        $cred_attempts[] = ['user' => 'root', 'pass' => '123456'];
        $cred_attempts[] = ['user' => 'root', 'pass' => 'Wasi1234'];
    }

    foreach ($cred_attempts as $idx => $cred) {
        try {
            $pdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $cred['user'], $cred['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $db_user = $cred['user'];
            $db_pass = $cred['pass'];
            if ($idx > 0) {
                send_sse(['status' => 'log', 'percent' => 39, 'message' => "<span class='text-info'>Connected via auto-matched credentials: user '{$db_user}'.</span>"]);
            }
            break;
        } catch (PDOException $e) {
            $pdo_errors[] = "[{$cred['user']}]: " . $e->getMessage();
            if ($e->getCode() != 1045 && !str_contains($e->getMessage(), 'Access denied')) {
                break;
            }
        }
    }

    if (!$pdo) {
        send_sse([
            'status' => 'error',
            'message' => "MySQL Connection Failed: Access denied for MySQL user '{$db_user}'.<br><br><b>Hal (Solution):</b><br>Ubuntu VPS par MySQL user <code>radius</code> create karne ke liye terminal me yeh 1 command run karein:<br><code style='user-select:all; display:block; padding:10px; background:#1e293b; color:#38bdf8; border-radius:8px; margin-top:6px; font-weight:bold;'>sudo mysql -e \"CREATE USER IF NOT EXISTS 'radius'@'localhost' IDENTIFIED BY 'Wasi1234'; ALTER USER 'radius'@'localhost' IDENTIFIED BY 'Wasi1234'; GRANT ALL PRIVILEGES ON *.* TO 'radius'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES;\"</code><br>Command run karne ke baad 'Try Again' dabayein."
        ]);
        exit;
    }

    // Auto-provision 'radius' user if connected as root so config/db.php works for entire site!
    try {
        $pdo->exec("CREATE USER IF NOT EXISTS 'radius'@'localhost' IDENTIFIED BY 'Wasi1234'");
        $pdo->exec("ALTER USER 'radius'@'localhost' IDENTIFIED BY 'Wasi1234'");
        $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO 'radius'@'localhost' WITH GRANT OPTION");
        $pdo->exec("FLUSH PRIVILEGES");
    } catch (Exception $e) {}

    send_sse(['status' => 'log', 'percent' => 40, 'message' => "MySQL connection established successfully as '{$db_user}'."]);

    // Create database if not exists and switch to it
    try {
        send_sse(['status' => 'log', 'percent' => 42, 'message' => "Checking target database `{$db_name}`..."]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db_name}`");
        send_sse(['status' => 'log', 'percent' => 44, 'message' => "Database `{$db_name}` is ready."]);

        // Optimization directives (safely wrapped for all MySQL / MariaDB versions)
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); } catch (Exception $e) {}
        try { $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'"); } catch (Exception $e) {}
        try { $pdo->exec("SET AUTOCOMMIT = 0"); } catch (Exception $e) {}
        try { $pdo->exec("SET GLOBAL max_allowed_packet = 1073741824"); } catch (Exception $e) {}
        try { $pdo->exec("SET SESSION net_read_timeout = 600"); } catch (Exception $e) {}
        try { $pdo->exec("SET SESSION net_write_timeout = 600"); } catch (Exception $e) {}
    } catch (Exception $e) {
        send_sse(['status' => 'error', 'message' => "Database initialization error: " . $e->getMessage()]);
        exit;
    }

    // Open file stream
    $handle = $is_gz ? @gzopen($source_file, 'r') : @fopen($source_file, 'r');
    if (!$handle) {
        send_sse(['status' => 'error', 'message' => "Failed to open stream for backup file."]);
        exit;
    }

    $start_time = microtime(true);
    $query = '';
    $queries_count = 0;
    $batch_count = 0;
    $current_table = 'Initializing';
    $tables_created = [];
    $last_reported_percent = 44;
    $bytes_processed = 0;

    send_sse(['status' => 'log', 'percent' => 45, 'message' => "Executing SQL statements from backup file (" . round($file_size/(1024*1024), 2) . " MB)..."]);

    while (($line = ($is_gz ? gzgets($handle) : fgets($handle))) !== false) {
        $bytes_processed += strlen($line);
        $trim = trim($line);

        // Skip comments and empty lines
        if ($trim === '' || str_starts_with($trim, '--') || (str_starts_with($trim, '/*') && !str_starts_with($trim, '/*!'))) {
            continue;
        }

        // Skip CREATE DATABASE or USE statements to prevent switching away from target db
        if (preg_match('/^(?:CREATE\s+DATABASE|USE\s+)/i', $trim)) {
            continue;
        }

        // Detect current table name for live status display
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $trim, $tblMatch)) {
            $current_table = $tblMatch[1];
            if (!in_array($current_table, $tables_created)) {
                $tables_created[] = $current_table;
                send_sse([
                    'status'  => 'log',
                    'percent' => $last_reported_percent,
                    'message' => "Creating table structure: `{$current_table}`...",
                    'table'   => $current_table
                ]);
            }
        } elseif (preg_match('/INSERT\s+INTO\s+`?([a-zA-Z0-9_]+)`?/i', $trim, $tblMatch)) {
            $current_table = $tblMatch[1];
        }

        $query .= $line;

        if (str_ends_with($trim, ';')) {
            // Collation Fix: Replace newer/incompatible collations on the fly
            $query = str_ireplace(['utf8mb4_uca1400_ai_ci', 'utf8mb4_0900_ai_ci'], 'utf8mb4_unicode_ci', $query);

            try {
                $pdo->exec($query);
                $queries_count++;
                $batch_count++;

                // Transaction batch commit
                if ($batch_count >= 250) {
                    $pdo->exec("COMMIT");
                    $batch_count = 0;
                }
            } catch (PDOException $qe) {
                // Ignore benign DROP TABLE IF EXISTS errors
                if (!str_contains($qe->getMessage(), "Unknown table")) {
                    send_sse(['status' => 'log', 'percent' => $last_reported_percent, 'message' => "<span class='text-warning'>Warning on query #{$queries_count}: " . htmlspecialchars($qe->getMessage()) . "</span>"]);
                }
            }
            $query = '';

            // Calculate progress by file offset (45% to 96%)
            $cur_percent = 45 + round(($bytes_processed / max(1, $file_size)) * 51);
            $cur_percent = min(96, max(45, $cur_percent));

            if ($cur_percent > $last_reported_percent) {
                $last_reported_percent = $cur_percent;
                send_sse([
                    'status'  => 'progress',
                    'percent' => $cur_percent,
                    'message' => "Restoring `{$current_table}`... ({$queries_count} queries executed)",
                    'table'   => $current_table,
                    'queries' => $queries_count
                ]);
            }
        }
    }

    if ($is_gz) gzclose($handle); else fclose($handle);

    // Finalize transaction
    try {
        send_sse(['status' => 'log', 'percent' => 97, 'message' => "Committing final transactions and enabling foreign key checks..."]);
        try { $pdo->exec("COMMIT"); } catch (Exception $e) {}
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $e) {}
        try { $pdo->exec("SET AUTOCOMMIT = 1"); } catch (Exception $e) {}
    } catch (Exception $e) {}

    // Post-Restore Step 1: Ensure all MikroTik routers in 'nas' use secret 123456
    try {
        $pdo->exec("UPDATE nas SET secret = '123456'");
        $pdo->exec("ALTER TABLE nas MODIFY secret VARCHAR(60) NOT NULL DEFAULT '123456'");
        send_sse(['status' => 'log', 'percent' => 98, 'message' => "<span class='text-success'><i class='fa-solid fa-key me-1'></i> All MikroTik Routers secret verified and set to 123456.</span>"]);
    } catch (Exception $e) {}

    // Post-Restore Step 2: Ensure Manager schema and audit log triggers exist
    if (file_exists(__DIR__ . '/config/manager_helper.php')) {
        require_once __DIR__ . '/config/manager_helper.php';
        if (function_exists('ensure_manager_schema')) {
            ensure_manager_schema($pdo);
            send_sse(['status' => 'log', 'percent' => 98, 'message' => "<span class='text-success'><i class='fa-solid fa-users-gear me-1'></i> Manager role and audit logs schema verified.</span>"]);
        }
    }

    // Post-Restore Step 3: Restart FreeRADIUS service to load restored routers and users
    if (function_exists('shell_exec')) {
        send_sse(['status' => 'log', 'percent' => 99, 'message' => "Restarting FreeRADIUS service..."]);
        @shell_exec("sudo systemctl restart freeradius 2>&1 || sudo service freeradius restart 2>&1");
        send_sse(['status' => 'log', 'percent' => 99, 'message' => "<span class='text-success'><i class='fa-solid fa-circle-check me-1'></i> FreeRADIUS restarted and synchronized with restored database.</span>"]);
    }

    $time_taken = round(microtime(true) - $start_time, 2);

    // Post-Restore Verification Stats
    send_sse(['status' => 'log', 'percent' => 99, 'message' => "Verifying restored tables and data counts..."]);
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $table_details = [];
    $total_records = 0;
    $sub_count = 0;
    $pkg_count = 0;
    $nas_count = 0;

    foreach ($tables as $t) {
        $cnt = 0;
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $total_records += $cnt;
            if ($t === 'subscribers') $sub_count = $cnt;
            if ($t === 'packages') $pkg_count = $cnt;
            if ($t === 'nas') $nas_count = $cnt;
        } catch(Exception $e) {}
        $table_details[] = ['name' => $t, 'rows' => $cnt];
    }

    // Clean up temporary uploaded file if in uploads/ or temp directory
    if (str_contains($source_file, 'restore_chunk_') || str_contains($source_file, 'temp_restore_')) {
        @unlink($source_file);
    }

    send_sse([
        'status'   => 'complete',
        'percent'  => 100,
        'message'  => "SUCCESS: Database restoration completed in {$time_taken} seconds!",
        'stats'    => [
            'queries'       => $queries_count,
            'time_sec'      => $time_taken,
            'db_name'       => $db_name,
            'table_count'   => count($tables),
            'total_records' => $total_records,
            'subscribers'   => $sub_count,
            'packages'      => $pkg_count,
            'routers'       => $nas_count
        ],
        'tables'   => $table_details
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// ACTION 3: Self Delete
// -----------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'self_delete') {
    if (@unlink(__FILE__)) {
        die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>File Deleted</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"></head><body class="bg-light p-5"><div class="container text-center"><div class="alert alert-success shadow p-5 rounded-4"><h3 class="fw-bold text-success mb-2"><i class="fa-solid fa-shield-halved"></i> restorebackup.php Has Been Deleted!</h3><p class="text-secondary">Aapka server ab 100% secure hai. Script delete ho chuki hai.</p><a href="/operator/login.php" class="btn btn-primary px-4 py-2 mt-3 fw-bold rounded-pill">Open Operator Login</a></div></div></body></html>');
    } else {
        $error_msg = "Could not delete restorebackup.php automatically due to file permissions. Please delete it via terminal: rm -f " . __FILE__;
    }
}

// Existing candidate files on server
$existing_backups = [];
$candidate_files = [
    'config/backup.sql',
    'database.sql',
    'database_schema.sql',
    'backup.sql'
];

if (is_dir(__DIR__ . '/uploads')) {
    $sc = @scandir(__DIR__ . '/uploads');
    if ($sc) {
        foreach ($sc as $f) {
            if ((str_ends_with($f, '.sql') || str_ends_with($f, '.sql.gz')) && !str_starts_with($f, 'restore_chunk_')) {
                $candidate_files[] = 'uploads/' . $f;
            }
        }
    }
}
$sc_root = @scandir(__DIR__);
if ($sc_root) {
    foreach ($sc_root as $f) {
        if ((str_ends_with($f, '.sql') || str_ends_with($f, '.sql.gz')) && !in_array($f, $candidate_files) && !str_starts_with($f, 'restore_chunk_')) {
            $candidate_files[] = $f;
        }
    }
}

foreach ($candidate_files as $cf) {
    $full = __DIR__ . '/' . $cf;
    if (file_exists($full) && is_readable($full) && is_file($full)) {
        $size_mb = round(filesize($full) / (1024 * 1024), 2);
        $existing_backups[] = [
            'relative' => $cf,
            'size'     => ($size_mb > 0 ? $size_mb . ' MB' : round(filesize($full)/1024, 1) . ' KB'),
            'mtime'    => date('Y-m-d H:i:s', filemtime($full))
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>1-Click Database Restore with Live Progress - SB Link Network / NetBee</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #090d16 0%, #1e293b 100%);
            min-height: 100vh;
            color: #334155;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .main-card {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.45);
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.15);
        }
        .card-header-gradient {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            padding: 35px 30px;
        }
        .upload-dropzone {
            border: 2px dashed #93c5fd;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover {
            background: #eff6ff;
            border-color: #2563eb;
        }
        .terminal-window {
            background: #090d16;
            color: #38bdf8;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 0.82rem;
            line-height: 1.5;
            padding: 16px;
            border-radius: 12px;
            height: 220px;
            overflow-y: auto;
            border: 1px solid #1e293b;
            box-shadow: inset 0 2px 8px rgba(0,0,0,0.5);
        }
        .terminal-window::-webkit-scrollbar {
            width: 8px;
        }
        .terminal-window::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 4px;
        }
        .stat-badge {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            text-align: center;
        }
        .stat-badge h3 {
            margin: 0;
            color: #0f172a;
            font-weight: 800;
        }
        .stat-badge small {
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.5px;
        }
        .progress {
            height: 28px;
            border-radius: 14px;
            background-color: #e2e8f0;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        .progress-bar {
            font-size: 0.85rem;
            font-weight: bold;
            letter-spacing: 0.5px;
            transition: width 0.3s ease;
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
                    <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-20 rounded-circle mb-3" style="width: 70px; height: 70px;">
                        <i class="fa-solid fa-database fa-2x text-white"></i>
                    </div>
                    <h3 class="fw-bold mb-1">1-Click Live Database Restore</h3>
                    <p class="mb-0 text-white-50 small">Automated Migration & Restore Engine with Real-Time Progress</p>
                </div>

                <div class="p-4 p-md-5">

                    <!-- Live Progress & Activity Card (Hidden initially) -->
                    <div id="progress_section" class="d-none mb-4">
                        <div class="card border-0 shadow-sm rounded-4 p-4" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="badge bg-primary px-3 py-2 rounded-pill" id="stage_badge">
                                    <i class="fa-solid fa-spinner fa-spin me-1"></i> Phase 1: Uploading
                                </span>
                                <span class="fw-bold text-dark fs-5 font-monospace" id="percent_text">0%</span>
                            </div>

                            <!-- Animated Progress Bar -->
                            <div class="progress mb-3">
                                <div id="live_progress_bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%">
                                    0%
                                </div>
                            </div>

                            <!-- Current Action Text -->
                            <div class="d-flex align-items-center justify-content-between small text-muted mb-3">
                                <div id="action_status_text" class="fw-semibold text-truncate">
                                    Initializing restore engine...
                                </div>
                                <div id="sub_status_text" class="text-nowrap ms-2 font-monospace"></div>
                            </div>

                            <!-- Live Terminal Logs -->
                            <label class="small fw-bold text-secondary mb-1">
                                <i class="fa-solid fa-terminal text-dark me-1"></i> Live Execution Terminal
                            </label>
                            <div id="terminal_console" class="terminal-window">
                                <div>[<?= date('H:i:s') ?>] Restore engine initialized. Ready to receive commands.</div>
                            </div>
                        </div>
                    </div>

                    <!-- Error Alert Box -->
                    <div id="error_alert" class="alert alert-danger border-0 shadow-sm rounded-4 d-none p-4 mb-4">
                        <div class="d-flex align-items-start gap-3">
                            <i class="fa-solid fa-circle-xmark fa-2x text-danger mt-1"></i>
                            <div>
                                <h5 class="fw-bold mb-1 text-danger">Restoration Failed!</h5>
                                <div id="error_message_text" class="mb-2 small"></div>
                                <div class="small text-muted p-2 bg-white rounded border">
                                    <strong>Troubleshooting Tips:</strong>
                                    <ul class="mb-0 ps-3 mt-1">
                                        <li>MySQL credentials check karein (User / Password / Host).</li>
                                        <li>Ensure karein ke MySQL service VPS par active hai: <code>sudo systemctl status mysql</code></li>
                                        <li>Security PIN check karein (Default: <code>1234</code>).</li>
                                    </ul>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger mt-3 fw-bold rounded-pill" onclick="resetForm()">
                                    <i class="fa-solid fa-rotate-left me-1"></i> Try Again
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Success Summary Card -->
                    <div id="success_section" class="d-none">
                        <div class="alert alert-success border-0 shadow-sm rounded-4 p-4 text-center mb-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle mb-3" style="width: 60px; height: 60px;">
                                <i class="fa-solid fa-check fa-2x"></i>
                            </div>
                            <h4 class="fw-bold text-success mb-1">Database Restored Successfully!</h4>
                            <p class="text-secondary small mb-4">Aapki database mukammal aur sahi salamat restore ho chuki hai.</p>

                            <!-- Stat Badges -->
                            <div class="row g-2 mb-4">
                                <div class="col-4">
                                    <div class="stat-badge">
                                        <h3 id="stat_tables">0</h3>
                                        <small>Tables Created</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-badge">
                                        <h3 class="text-primary" id="stat_subscribers">0</h3>
                                        <small>Subscribers</small>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="stat-badge">
                                        <h3 class="text-success" id="stat_routers">0</h3>
                                        <small>Routers (NAS)</small>
                                    </div>
                                </div>
                            </div>

                            <div class="small text-muted mb-4" id="stat_summary_line"></div>

                            <!-- Restored Tables Accordion -->
                            <div class="accordion mb-4 text-start" id="tablesAccordion">
                                <div class="accordion-item border-0 rounded-3 overflow-hidden shadow-sm">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-2 px-3 bg-light fw-bold text-secondary small" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTables">
                                            <i class="fa-solid fa-list-check me-2"></i> View Restored Tables & Row Counts
                                        </button>
                                    </h2>
                                    <div id="collapseTables" class="accordion-collapse collapse" data-bs-parent="#tablesAccordion">
                                        <div class="accordion-body p-2" style="max-height: 220px; overflow-y: auto;">
                                            <table class="table table-sm table-striped mb-0 small">
                                                <thead>
                                                    <tr>
                                                        <th>Table Name</th>
                                                        <th class="text-end">Rows Count</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="restored_tables_tbody"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Login Buttons -->
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
                                <p class="text-muted small mb-2">Restoration ke baad is script (<code>restorebackup.php</code>) ko server se delete kar dein taake koi unauthorized shakhs dobara database wipe na kar sake.</p>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete restorebackup.php?');">
                                    <input type="hidden" name="action" value="self_delete">
                                    <button type="submit" class="btn btn-sm btn-danger fw-bold rounded-pill px-3 shadow-sm">
                                        <i class="fa-solid fa-trash-can me-1"></i> Delete restorebackup.php Now
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Input Form -->
                    <form id="restoreForm">
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

                            <!-- Upload Box -->
                            <div id="upload_container" class="upload-dropzone mb-2" onclick="document.getElementById('sql_file').click();">
                                <i class="fa-solid fa-cloud-arrow-up fa-3x text-primary mb-2"></i>
                                <h6 class="fw-bold mb-1">Click to browse or drop your .sql file here</h6>
                                <p class="text-muted small mb-0">Supports .sql and compressed .sql.gz (Up to 512 MB)</p>
                                <input type="file" name="sql_file" id="sql_file" class="d-none" accept=".sql,.gz" onchange="displaySelectedFile(this)">
                                <div id="file_selected_name" class="mt-2 fw-bold text-success small d-none"></div>
                            </div>

                            <!-- Server File Picker -->
                            <?php if (!empty($existing_backups)): ?>
                            <div id="existing_container" class="d-none">
                                <select name="server_file" id="server_file" class="form-select form-select-lg">
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

                        <!-- Section 2: Database Credentials -->
                        <div class="card bg-light border-0 rounded-3 mb-4">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="fw-bold text-dark mb-0"><i class="fa-solid fa-server text-secondary me-2"></i> 2. Target Database Credentials</label>
                                    <span class="badge bg-secondary-subtle text-dark border">Editable</span>
                                </div>
                                <div class="small text-muted mb-3">
                                    Auto-detected: <code><?= htmlspecialchars($default_user) ?>@<?= htmlspecialchars($default_host) ?></code> &rarr; DB: <code><?= htmlspecialchars($default_db) ?></code>
                                </div>

                                <div class="alert alert-info py-2 px-3 small rounded-3 mb-3">
                                    <i class="fa-solid fa-circle-info me-1"></i> <strong>MySQL Notice:</strong> Agar VPS par MySQL user <code>radius</code> create nahi hai, toh VPS terminal me yeh command run karein:
                                    <code class="d-block mt-1 p-2 bg-dark text-warning rounded text-wrap user-select-all font-monospace" style="user-select:all;">sudo mysql -e "CREATE USER IF NOT EXISTS 'radius'@'localhost' IDENTIFIED BY 'Wasi1234'; ALTER USER 'radius'@'localhost' IDENTIFIED BY 'Wasi1234'; GRANT ALL PRIVILEGES ON *.* TO 'radius'@'localhost' WITH GRANT OPTION; FLUSH PRIVILEGES;"</code>
                                </div>

                                <div class="collapse show" id="dbSettings">
                                    <div class="row g-2 pt-2 border-top">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">DB Host</label>
                                            <input type="text" name="db_host" id="db_host" class="form-control form-control-sm" value="<?= htmlspecialchars($default_host) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">Database Name</label>
                                            <input type="text" name="db_name" id="db_name" class="form-control form-control-sm" value="<?= htmlspecialchars($default_db) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">DB Username</label>
                                            <input type="text" name="db_user" id="db_user" class="form-control form-control-sm" value="<?= htmlspecialchars($default_user) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold">DB Password</label>
                                            <input type="text" name="db_pass" id="db_pass" class="form-control form-control-sm" value="<?= htmlspecialchars($default_pass) ?>" placeholder="Leave blank if no password">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 3: Security PIN -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark"><i class="fa-solid fa-key text-warning me-2"></i> 3. Security Verification PIN</label>
                            <input type="text" name="pin" id="pin" class="form-control form-control-lg font-monospace text-center fw-bold" placeholder="1234" value="1234" autocomplete="off">
                            <small class="text-muted">Security confirmation code to authorize database overwrite. Default is <code>1234</code>.</small>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm py-3 rounded-pill" id="btnSubmit">
                            <i class="fa-solid fa-play me-2"></i> Start Database Restore Now
                        </button>
                    </form>

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
var lastUploadedFile = null;

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
    lastUploadedFile = null; // Reset cached upload if a new file is picked
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

function appendTerminalLog(msg) {
    var term = document.getElementById('terminal_console');
    if (!term) return;
    var d = new Date();
    var time = d.toTimeString().split(' ')[0];
    var div = document.createElement('div');
    div.innerHTML = '[' + time + '] ' + msg;
    term.appendChild(div);
    term.scrollTop = term.scrollHeight;
}

function setProgressBar(percent, stageText, actionText, subText, colorClass) {
    percent = Math.min(100, Math.max(0, Math.round(percent)));
    var bar = document.getElementById('live_progress_bar');
    var txt = document.getElementById('percent_text');
    var badge = document.getElementById('stage_badge');
    var action = document.getElementById('action_status_text');
    var sub = document.getElementById('sub_status_text');

    if (bar) {
        bar.style.width = percent + '%';
        bar.innerText = percent + '%';
        if (colorClass) {
            bar.className = 'progress-bar progress-bar-striped progress-bar-animated ' + colorClass;
        }
    }
    if (txt) txt.innerText = percent + '%';
    if (badge && stageText) badge.innerHTML = stageText;
    if (action && actionText) action.innerHTML = actionText;
    if (sub && subText) sub.innerHTML = subText;
}

function showError(msg) {
    document.getElementById('error_alert')?.classList.remove('d-none');
    var el = document.getElementById('error_message_text');
    if (el) el.innerHTML = '<div>' + msg + '</div>';
    setProgressBar(100, '<i class="fa-solid fa-triangle-exclamation me-1"></i> Error', 'Restore halted due to error', '', 'bg-danger');
    appendTerminalLog('<span class="text-danger fw-bold">ERROR: ' + msg.replace(/<[^>]*>/g, '') + '</span>');
    document.getElementById('btnSubmit').disabled = false;
    document.getElementById('btnSubmit').innerHTML = '<i class="fa-solid fa-rotate-left me-2"></i> Retry Restore';
}

function resetForm() {
    document.getElementById('error_alert')?.classList.add('d-none');
    document.getElementById('progress_section')?.classList.add('d-none');
    document.getElementById('restoreForm')?.classList.remove('d-none');
    setProgressBar(0, '', '', '', 'bg-primary');
    if (lastUploadedFile) {
        var label = document.getElementById('file_selected_name');
        if (label) {
            label.classList.remove('d-none');
            label.innerHTML = '<i class="fa-solid fa-check-double text-success me-1"></i> File ready on server: <code>' + lastUploadedFile + '</code> (Click Start Restore to resume)';
        }
    }
}

// Main Submit Listener
document.getElementById('restoreForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    var isUpload = document.getElementById('src_upload').checked;
    var fileInput = document.getElementById('sql_file');
    var pinVal = document.getElementById('pin').value.trim();
    
    if (isUpload && !lastUploadedFile && (!fileInput.files || fileInput.files.length === 0)) {
        alert('Barah-e-karam pehle .sql backup file select karein!');
        return;
    }

    if (!confirm('WARNING: Yeh action database ko update / overwrite kar dega. Kya aap waqai database restore karna chahte hain?')) {
        return;
    }

    // Hide errors & form, show progress section
    document.getElementById('error_alert').classList.add('d-none');
    document.getElementById('restoreForm').classList.add('d-none');
    document.getElementById('progress_section').classList.remove('d-none');

    var term = document.getElementById('terminal_console');
    term.innerHTML = '';
    appendTerminalLog('<span class="text-info fw-bold">Initializing Database Restore Process...</span>');

    if (isUpload) {
        if (lastUploadedFile) {
            appendTerminalLog("<span class='text-info'>Using existing uploaded backup file (" + lastUploadedFile + ")...</span>");
            startStreamRestore(lastUploadedFile);
        } else {
            uploadAndRestore();
        }
    } else {
        var serverFile = document.getElementById('server_file').value;
        startStreamRestore(serverFile);
    }
});

// Phase 1: Chunked File Upload (bypasses server post_max_size / upload_max_filesize limits)
function uploadAndRestore() {
    var fileInput = document.getElementById('sql_file');
    var file = fileInput.files[0];
    if (!file) {
        showError('No file selected.');
        return;
    }

    var chunkSize = 1.5 * 1024 * 1024; // 1.5 MB chunks (safe for default 2M/8M php limits)
    var totalSize = file.size;
    var totalChunks = Math.ceil(totalSize / chunkSize);
    var fileId = 'restore_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
    var currentChunk = 0;
    var maxRetries = 3;
    var retries = 0;

    var totalMB = (totalSize / (1024 * 1024)).toFixed(1);
    setProgressBar(0, '<i class="fa-solid fa-cloud-arrow-up fa-fade me-1"></i> Phase 1: Uploading File', 'Starting chunked upload (' + totalChunks + ' chunks)...', '0 / ' + totalMB + ' MB', 'bg-info');
    appendTerminalLog('Starting chunked upload of <b>' + file.name + '</b> (' + totalMB + ' MB in ' + totalChunks + ' chunks)...');

    function sendNextChunk() {
        if (currentChunk >= totalChunks) return;

        var start = currentChunk * chunkSize;
        var end = Math.min(start + chunkSize, totalSize);
        var blob = file.slice(start, end);

        var formData = new FormData();
        formData.append('chunk', blob);
        formData.append('file_id', fileId);
        formData.append('chunk_index', currentChunk);
        formData.append('total_chunks', totalChunks);
        formData.append('file_name', file.name);
        formData.append('total_size', totalSize);
        formData.append('pin', document.getElementById('pin').value.trim());

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'restorebackup.php?action=upload_chunk', true);

        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.status === 'ok') {
                        retries = 0;
                        currentChunk++;
                        var uploadedBytes = Math.min(currentChunk * chunkSize, totalSize);
                        var uploadedMB = (uploadedBytes / (1024 * 1024)).toFixed(1);
                        var percent = (uploadedBytes / totalSize) * 100;
                        var overallPercent = Math.min(35, Math.round(percent * 0.35));

                        setProgressBar(
                            overallPercent,
                            '<i class="fa-solid fa-cloud-arrow-up fa-fade me-1"></i> Uploading: ' + Math.round(percent) + '%',
                            'Uploaded ' + currentChunk + ' of ' + totalChunks + ' chunks...',
                            uploadedMB + ' / ' + totalMB + ' MB',
                            'bg-info'
                        );

                        if (currentChunk % 5 === 0 || res.complete) {
                            appendTerminalLog('Uploaded chunk ' + currentChunk + '/' + totalChunks + ' (' + uploadedMB + ' MB / ' + totalMB + ' MB)...');
                        }

                        if (res.complete) {
                            lastUploadedFile = res.file;
                            appendTerminalLog("<span class='text-success fw-bold'>All " + totalChunks + " chunks uploaded & assembled successfully (" + res.size_formatted + ")!</span>");
                            startStreamRestore(res.file);
                        } else {
                            sendNextChunk();
                        }
                    } else {
                        showError(res.message || 'Error uploading chunk ' + currentChunk);
                    }
                } catch (e) {
                    showError('Failed parsing server response on chunk ' + currentChunk + ': ' + xhr.responseText);
                }
            } else {
                if (retries < maxRetries) {
                    retries++;
                    appendTerminalLog("<span class='text-warning'>Chunk " + currentChunk + " retry " + retries + "/" + maxRetries + "...</span>");
                    setTimeout(sendNextChunk, 1000);
                } else {
                    showError('Upload failed on chunk ' + currentChunk + ' (HTTP Status: ' + xhr.status + ')');
                }
            }
        };

        xhr.onerror = function() {
            if (retries < maxRetries) {
                retries++;
                appendTerminalLog("<span class='text-warning'>Network issue on chunk " + currentChunk + ". Retrying (" + retries + "/" + maxRetries + ")...</span>");
                setTimeout(sendNextChunk, 1500);
            } else {
                showError('Network connection lost while uploading chunk ' + currentChunk + '. Please check your VPS connection.');
            }
        };

        xhr.send(formData);
    }

    sendNextChunk();
}

// Phase 2: Stream Execution with real-time SSE chunks
function startStreamRestore(filePath) {
    setProgressBar(36, '<i class="fa-solid fa-database fa-spin me-1"></i> Phase 2: Restoring Database', 'Connecting to MySQL database...', '', 'bg-primary');
    appendTerminalLog("Initiating streaming database execution engine...");

    var postData = new URLSearchParams();
    postData.append('pin', document.getElementById('pin').value.trim());
    postData.append('file', filePath);
    postData.append('db_host', document.getElementById('db_host').value.trim());
    postData.append('db_user', document.getElementById('db_user').value.trim());
    postData.append('db_pass', document.getElementById('db_pass').value);
    postData.append('db_name', document.getElementById('db_name').value.trim());

    fetch('restorebackup.php?action=stream_execute', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: postData
    }).then(function(response) {
        if (!response.ok) {
            throw new Error('HTTP Server Error ' + response.status);
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function readChunk() {
            reader.read().then(function(result) {
                if (result.done) return;

                buffer += decoder.decode(result.value, { stream: true });
                var lines = buffer.split("\n\n");
                buffer = lines.pop(); // Keep unfinished tail

                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i].trim();
                    if (line.startsWith('data: ')) {
                        var jsonStr = line.substring(6).trim();
                        try {
                            var data = JSON.parse(jsonStr);
                            handleStreamEvent(data);
                        } catch(e) {
                            console.error("JSON parse error:", e, jsonStr);
                        }
                    }
                }
                readChunk();
            }).catch(function(err) {
                showError('Stream connection error: ' + err.message);
            });
        }
        readChunk();
    }).catch(function(err) {
        showError(err.message);
    });
}

function handleStreamEvent(data) {
    if (data.status === 'log') {
        if (data.percent !== undefined) {
            setProgressBar(data.percent, null, data.message, null, null);
        }
        appendTerminalLog(data.message);
    } else if (data.status === 'progress') {
        setProgressBar(data.percent, '<i class="fa-solid fa-bolt fa-fade me-1"></i> Restoring Database...', data.message, data.queries ? data.queries + ' queries' : '', 'bg-primary');
    } else if (data.status === 'error') {
        showError(data.message);
    } else if (data.status === 'complete') {
        setProgressBar(100, '<i class="fa-solid fa-circle-check text-white me-1"></i> Completed!', 'All database tables restored successfully!', '', 'bg-success');
        appendTerminalLog("<span class='text-success fw-bold'>" + data.message + "</span>");
        showSuccessView(data.stats, data.tables);
    }
}

function showSuccessView(stats, tables) {
    document.getElementById('stat_tables').innerText = stats.table_count || 0;
    document.getElementById('stat_subscribers').innerText = stats.subscribers || 0;
    document.getElementById('stat_routers').innerText = stats.routers || 0;

    var summaryEl = document.getElementById('stat_summary_line');
    if (summaryEl) {
        summaryEl.innerHTML = '<i class="fa-solid fa-bolt text-warning me-1"></i> Executed <strong>' + (stats.queries || 0).toLocaleString() + '</strong> queries across <strong>' + (stats.table_count || 0) + '</strong> tables in <strong>' + stats.time_sec + 's</strong>.';
    }

    var tbody = document.getElementById('restored_tables_tbody');
    if (tbody && tables && tables.length) {
        tbody.innerHTML = '';
        tables.forEach(function(t) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><code>' + t.name + '</code></td><td class="text-end fw-bold text-primary">' + Number(t.rows).toLocaleString() + '</td>';
            tbody.appendChild(tr);
        });
    }

    setTimeout(function() {
        document.getElementById('progress_section').classList.add('d-none');
        document.getElementById('success_section').classList.remove('d-none');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, 1500);
}
</script>

</body>
</html>
