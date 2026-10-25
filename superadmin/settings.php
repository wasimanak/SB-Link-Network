<?php
// Handle AJAX endpoints before outputting HTML
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'test_remote_db') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    header('Content-Type: application/json; charset=utf-8');

    $host = trim($_POST['remote_host'] ?? '');
    $port = intval($_POST['remote_port'] ?? 3306) ?: 3306;
    $db_name = trim($_POST['remote_db'] ?? 'radius');
    $user = trim($_POST['remote_user'] ?? '');
    $pass = $_POST['remote_pass'] ?? '';

    if (empty($host) || empty($user)) {
        echo json_encode([
            'success' => false,
            'message' => 'Remote VPS Host/IP and MySQL Username are required.'
        ]);
        exit;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
        $remote_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 6, // 6 seconds timeout
        ]);

        $tables = $remote_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $tableCount = count($tables);

        // Fetch counts for common tables
        $previewCounts = [];
        foreach (['subscribers', 'radcheck', 'clients', 'nas', 'packages'] as $t) {
            if (in_array($t, $tables)) {
                try {
                    $cnt = (int)$remote_pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                    $previewCounts[$t] = $cnt;
                } catch (Exception $e) {}
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Successfully connected to Old VPS ({$host}:{$port})!",
            'table_count' => $tableCount,
            'tables' => $tables,
            'preview_counts' => $previewCounts
        ]);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $tip = "";

        if (str_contains($msg, 'Connection refused') || str_contains($msg, 'timed out') || str_contains($msg, '2002')) {
            $tip = "Old VPS par MySQL port {$port} block hai ya MySQL sirf 127.0.0.1 par bind hai. Setup Guide me diye gaye 3 commands Old VPS terminal me run karein.";
        } elseif (str_contains($msg, 'Access denied') || str_contains($msg, '1045') || str_contains($msg, '1698')) {
            $safeUser = htmlspecialchars($user, ENT_QUOTES);
            $safePass = htmlspecialchars($pass, ENT_QUOTES);
            $safeDb = htmlspecialchars($db_name, ENT_QUOTES);
            $tip = "Old VPS par user '{$safeUser}' ko remote access ki permission nahi hai. Old VPS terminal me ye command run karein:<br><code class='d-block bg-dark text-white p-2 rounded mt-1 text-break' style='font-size:0.75rem;'>sudo mysql -e \"CREATE USER IF NOT EXISTS '{$safeUser}'@'%' IDENTIFIED BY '{$safePass}'; ALTER USER '{$safeUser}'@'%' IDENTIFIED BY '{$safePass}'; GRANT ALL PRIVILEGES ON {$safeDb}.* TO '{$safeUser}'@'%'; FLUSH PRIVILEGES;\"</code>";
        } elseif (str_contains($msg, 'Unknown database') || str_contains($msg, '1049')) {
            $tip = "Database '{$db_name}' Old VPS par mojood nahi hai. Sahi database naam check karein (e.g. radius).";
        }

        echo json_encode([
            'success' => false,
            'message' => $msg,
            'tip' => $tip
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// -------------------------------------------------------------
// AJAX: Live Step-by-Step Sync with Realtime Progress & Percent
// -------------------------------------------------------------

// Action 1: sync_init (Validates, gathers table list and row counts)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'sync_init') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    header('Content-Type: application/json; charset=utf-8');

    $host = trim($_POST['remote_host'] ?? '');
    $port = intval($_POST['remote_port'] ?? 3306) ?: 3306;
    $db_name = trim($_POST['remote_db'] ?? 'radius');
    $user = trim($_POST['remote_user'] ?? '');
    $pass = $_POST['remote_pass'] ?? '';
    $sync_scope = $_POST['sync_scope'] ?? 'all';
    $preserve_sa = isset($_POST['preserve_superadmin']) && ($_POST['preserve_superadmin'] === '1' || $_POST['preserve_superadmin'] === 'true');

    if (empty($host) || empty($user)) {
        echo json_encode(['success' => false, 'message' => 'Remote VPS Host and MySQL Username are required.']);
        exit;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
        $remote_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
        ]);

        // Save remote host & user for convenience in global_settings
        $saveSettings = [
            'sync_remote_host' => $host,
            'sync_remote_port' => (string)$port,
            'sync_remote_db'   => $db_name,
            'sync_remote_user' => $user,
            'sync_remote_pass' => $pass,
        ];
        foreach ($saveSettings as $sk => $sv) {
            $upStmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $upStmt->execute([$sk, $sv, $sv]);
        }

        // Fetch tables
        $allRemoteTables = $remote_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($allRemoteTables)) {
            echo json_encode(['success' => false, 'message' => "No tables found in remote database '{$db_name}'."]);
            exit;
        }

        $tablesToSync = [];
        if ($sync_scope === 'subscribers_only') {
            $allowed = ['subscribers', 'radcheck', 'radreply', 'radacct', 'clients', 'packages', 'package_requests'];
            $tablesToSync = array_values(array_intersect($allRemoteTables, $allowed));
        } elseif ($sync_scope === 'radius_only') {
            $allowed = ['radcheck', 'radreply', 'radacct', 'radgroupcheck', 'radgroupreply', 'radusergroup', 'nas'];
            $tablesToSync = array_values(array_intersect($allRemoteTables, $allowed));
        } else {
            $tablesToSync = $allRemoteTables;
        }

        if (empty($tablesToSync)) {
            echo json_encode(['success' => false, 'message' => 'No matching tables found for the selected sync scope.']);
            exit;
        }

        // Preserve current SuperAdmin accounts
        $_SESSION['saved_superadmins'] = [];
        if ($preserve_sa) {
            try {
                $saStmt = $pdo->query("SELECT * FROM super_admins");
                $_SESSION['saved_superadmins'] = $saStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        // Get row estimates for each table
        $tablesList = [];
        $totalEstRows = 0;
        foreach ($tablesToSync as $tbl) {
            $cnt = 0;
            try {
                $cnt = (int)$remote_pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            } catch (Exception $e) {}
            $tablesList[] = [
                'name' => $tbl,
                'rows' => $cnt
            ];
            $totalEstRows += $cnt;
        }

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'total_tables' => count($tablesList),
            'tables' => $tablesList,
            'total_estimated_rows' => $totalEstRows
        ]);
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()]);
    }
    exit;
}

// Action 2: sync_table (Syncs one table in chunks with INSERT IGNORE INTO)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'sync_table') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    header('Content-Type: application/json; charset=utf-8');

    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $host = trim($_POST['remote_host'] ?? '');
    $port = intval($_POST['remote_port'] ?? 3306) ?: 3306;
    $db_name = trim($_POST['remote_db'] ?? 'radius');
    $user = trim($_POST['remote_user'] ?? '');
    $pass = $_POST['remote_pass'] ?? '';
    $table = trim($_POST['table'] ?? '');
    $offset = intval($_POST['offset'] ?? 0);
    $batchSize = 2500; // Fast 2,500-row chunks

    if (empty($table)) {
        echo json_encode(['success' => false, 'message' => 'Table name is missing.']);
        exit;
    }

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$db_name};charset=utf8mb4";
        $remote_pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 20,
        ]);

        $pdo->exec("SET NAMES utf8mb4;");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("SET UNIQUE_CHECKS = 0;");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");

        // If starting table (offset == 0), drop and recreate table schema identically from remote
        if ($offset === 0) {
            $createRow = $remote_pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $createSql = $createRow[1] ?? '';
            if (!empty($createSql)) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`;");
                $pdo->exec($createSql . ";");
            } else {
                $pdo->exec("TRUNCATE TABLE `{$table}`;");
            }
        }

        // Determine primary key for deterministic sorting
        $pkCol = null;
        try {
            $pkStmt = $remote_pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            $pkRow = $pkStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($pkRow['Column_name'])) {
                $pkCol = "`" . str_replace("`", "``", $pkRow['Column_name']) . "`";
            }
        } catch (Exception $e) {}
        $orderClause = $pkCol ? "ORDER BY {$pkCol} ASC" : "ORDER BY 1 ASC";

        // Fetch chunk
        $rowsStmt = $remote_pdo->query("SELECT * FROM `{$table}` {$orderClause} LIMIT {$batchSize} OFFSET {$offset}");
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        $syncedRows = count($rows);

        if (!empty($rows)) {
            $columnNames = array_keys($rows[0]);
            $escapedColumns = array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, $columnNames);
            $colsSql = implode(", ", $escapedColumns);

            // Insert in safe sub-batches of 500 to stay well within MySQL max_allowed_packet
            foreach (array_chunk($rows, 500) as $subChunk) {
                $insertRows = [];
                foreach ($subChunk as $row) {
                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $pdo->quote($val);
                        }
                    }
                    $insertRows[] = "(" . implode(", ", $values) . ")";
                }

                // INSERT IGNORE INTO guarantees 100% zero duplicate key errors!
                $pdo->exec("INSERT IGNORE INTO `{$table}` ({$colsSql}) VALUES\n" . implode(",\n", $insertRows) . ";");
            }
        }

        $isDone = ($syncedRows < $batchSize);
        $nextOffset = $offset + $syncedRows;

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'table' => $table,
            'synced_in_chunk' => $syncedRows,
            'current_offset' => $nextOffset,
            'is_done' => $isDone
        ]);
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => "Error syncing table '{$table}': " . $e->getMessage()]);
    }
    exit;
}

// Action 3: sync_finish (Restores SuperAdmins and reenables constraints)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'sync_finish') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!empty($_SESSION['saved_superadmins'])) {
            foreach ($_SESSION['saved_superadmins'] as $adm) {
                $cols = array_keys($adm);
                $escapedCols = array_map(function($c) { return "`$c`"; }, $cols);
                $vals = [];
                foreach ($adm as $val) {
                    $vals[] = ($val === null) ? 'NULL' : $pdo->quote($val);
                }
                $sql = "REPLACE INTO `super_admins` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $vals) . ")";
                $pdo->exec($sql);
            }
            unset($_SESSION['saved_superadmins']);
        }

        $pdo->exec("SET UNIQUE_CHECKS = 1;");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => true, 'message' => 'Synchronization completed successfully!']);
    } catch (Exception $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Action 4: run_db_maintenance (Instant Database Optimization & Auto-Prune)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'run_db_maintenance') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    require_once '../config/db_maintenance_helper.php';
    header('Content-Type: application/json; charset=utf-8');

    $auth_days  = intval($_POST['auth_days'] ?? 7) ?: 7;
    $acct_days  = intval($_POST['acct_days'] ?? 30) ?: 30;
    $ghost_days = intval($_POST['ghost_days'] ?? 2) ?: 2;

    $res = run_database_maintenance($pdo, $auth_days, $acct_days, $ghost_days);

    // Fetch updated live counts
    $newTotalPostAuth = 0;
    $newTotalAcct = 0;
    $newActiveAcct = 0;
    $newGhostAcct = 0;
    try {
        $newTotalPostAuth = (int)$pdo->query("SELECT COUNT(*) FROM radpostauth")->fetchColumn();
        $newTotalAcct = (int)$pdo->query("SELECT COUNT(*) FROM radacct")->fetchColumn();
        $newActiveAcct = (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
        $stmtG = $pdo->prepare("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL AND acctstarttime < NOW() - INTERVAL ? DAY");
        $stmtG->execute([$ghost_days]);
        $newGhostAcct = (int)$stmtG->fetchColumn();
    } catch (Exception $e) {}

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Database optimized successfully! Purged " . number_format($res['auth_deleted']) . " auth logs (> {$auth_days}d), " . number_format($res['acct_deleted']) . " closed sessions (> {$acct_days}d), closed " . number_format($res['ghost_closed']) . " ghost sessions, and optimized " . count($res['optimized_tables']) . " tables in {$res['duration_seconds']}s.",
        'data' => $res,
        'counts' => [
            'total_postauth' => $newTotalPostAuth,
            'total_acct' => $newTotalAcct,
            'active_acct' => $newActiveAcct,
            'ghost_acct' => $newGhostAcct,
            'last_run' => $res['timestamp']
        ]
    ]);
    exit;
}

// Action 5: save_db_maintenance_settings
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_db_maintenance_settings') {
    require_once 'auth_check.php';
    require_once '../config/db.php';
    header('Content-Type: application/json; charset=utf-8');

    $auth_days  = max(1, intval($_POST['auth_days'] ?? 7));
    $acct_days  = max(1, intval($_POST['acct_days'] ?? 30));
    $ghost_days = max(1, intval($_POST['ghost_days'] ?? 2));

    $saveSettings = [
        'db_retention_auth_days' => (string)$auth_days,
        'db_retention_acct_days' => (string)$acct_days,
        'db_ghost_session_days'  => (string)$ghost_days,
    ];
    foreach ($saveSettings as $sk => $sv) {
        $upStmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $upStmt->execute([$sk, $sv, $sv]);
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => true, 'message' => 'Automatic retention rules updated successfully!']);
    exit;
}

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
    elseif (isset($_POST['action']) && $_POST['action'] === 'restore_backup') {
        if (!isset($_POST['confirm_restore'])) {
            $error = "Please confirm database restoration by ticking the confirmation checkbox.";
        } elseif (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_err = $_FILES['backup_file']['error'] ?? 0;
            if ($upload_err === UPLOAD_ERR_INI_SIZE || $upload_err === UPLOAD_ERR_FORM_SIZE) {
                $error = "Uploaded file is too large for the current PHP upload limit (upload_max_filesize). Please upload a compressed .sql.gz file or increase php.ini limits.";
            } else {
                $error = "Please select a valid .sql or .sql.gz backup file to restore.";
            }
        } else {
            $uploaded_name = $_FILES['backup_file']['name'];
            $tmp_path = $_FILES['backup_file']['tmp_name'];
            $ext = strtolower(pathinfo($uploaded_name, PATHINFO_EXTENSION));
            $is_gz = ($ext === 'gz' || str_ends_with(strtolower($uploaded_name), '.sql.gz'));

            if ($ext !== 'sql' && !$is_gz) {
                $error = "Invalid file type. Only .sql or .sql.gz files are supported.";
            } else {
                @set_time_limit(600);
                @ini_set('memory_limit', '512M');

                try {
                    $handle = $is_gz ? @gzopen($tmp_path, 'r') : @fopen($tmp_path, 'r');
                    if (!$handle) {
                        throw new Exception("Unable to open uploaded backup file for reading.");
                    }

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                    $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");
                    $pdo->exec("SET NAMES utf8mb4;");

                    $query = '';
                    $in_string = false;
                    $queryCount = 0;
                    $tableCount = 0;

                    while (($line = ($is_gz ? gzgets($handle) : fgets($handle))) !== false) {
                        if (!$in_string) {
                            $trimmedLine = trim($line);
                            if ($trimmedLine === '' || str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#')) {
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
                            }

                            if ($char === ';' && !$in_string) {
                                $query .= $char;
                                $stmtSql = trim($query);
                                if (!empty($stmtSql)) {
                                    try {
                                        $pdo->exec($stmtSql);
                                        $queryCount++;
                                        if (stripos($stmtSql, 'CREATE TABLE') !== false) {
                                            $tableCount++;
                                        }
                                    } catch (PDOException $ex) {
                                        // Ignore non-fatal minor errors
                                    }
                                }
                                $query = '';
                                continue;
                            }

                            $query .= $char;
                        }
                    }

                    if ($is_gz) {
                        gzclose($handle);
                    } else {
                        fclose($handle);
                    }

                    $pdo->exec("SET UNIQUE_CHECKS = 1;");
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                    // Post-Restore Automation: Ensure columns, indexes, and prune stale logs
                    try {
                        $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN IF NOT EXISTS `monthly_salary` INT NOT NULL DEFAULT 0");
                        $pdo->exec("ALTER TABLE `linemen` ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'disabled') DEFAULT 'active'");
                        $pdo->exec("ALTER TABLE `linemen` ADD COLUMN IF NOT EXISTS `can_create_users` TINYINT(1) DEFAULT 1");
                        $pdo->exec("ALTER TABLE `linemen` ADD COLUMN IF NOT EXISTS `monthly_salary` INT NOT NULL DEFAULT 0");
                        $pdo->exec("ALTER TABLE `managers` ADD COLUMN IF NOT EXISTS `monthly_salary` INT NOT NULL DEFAULT 0");
                        $pdo->exec("ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `photo` VARCHAR(255) DEFAULT NULL");
                    } catch (Exception $e) {}

                    try {
                        $pdo->exec("ALTER TABLE radpostauth ADD INDEX IF NOT EXISTS authdate_idx (authdate)");
                        $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acctstoptime_idx (acctstoptime)");
                        $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acctstarttime_idx (acctstarttime)");
                        $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acct_active_idx (acctstoptime, username)");
                    } catch (Exception $e) {}

                    if (file_exists(__DIR__ . '/../config/db_maintenance_helper.php')) {
                        require_once __DIR__ . '/../config/db_maintenance_helper.php';
                        if (function_exists('run_database_maintenance')) {
                            @run_database_maintenance($pdo, 7, 30, 2);
                        }
                    }

                    try {
                        $pdo->exec("INSERT INTO global_settings (setting_key, setting_value) VALUES ('sys_schema_ver', '5') ON DUPLICATE KEY UPDATE setting_value = '5'");
                    } catch (Exception $e) {}

                    @shell_exec("sudo systemctl restart freeradius 2>&1 || sudo service freeradius restart 2>&1");

                    $success = "Database restored successfully! Processed <strong>{$queryCount}</strong> SQL statements. <strong>{$tableCount}</strong> tables created/updated.";
                } catch (Exception $e) {
                    $error = "Database restoration failed: " . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
    elseif (isset($_POST['action']) && $_POST['action'] === 'sync_remote_db') {
        $remote_host = trim($_POST['remote_host'] ?? '');
        $remote_port = intval($_POST['remote_port'] ?? 3306) ?: 3306;
        $remote_db   = trim($_POST['remote_db'] ?? 'radius');
        $remote_user = trim($_POST['remote_user'] ?? '');
        $remote_pass = $_POST['remote_pass'] ?? '';
        $sync_scope  = $_POST['sync_scope'] ?? 'all';
        $sync_mode   = $_POST['sync_mode'] ?? 'mirror';
        $preserve_sa = isset($_POST['preserve_superadmin']);
        $confirmed   = isset($_POST['confirm_sync']);

        if (!$confirmed) {
            $error = "Please tick the confirmation checkbox to authorize database synchronization.";
        } elseif (empty($remote_host) || empty($remote_user)) {
            $error = "Old VPS IP/Host and MySQL Username are required.";
        } else {
            @set_time_limit(900); // 15 mins for large DB
            @ini_set('memory_limit', '512M');

            try {
                // 1. Connect to remote database
                $remote_dsn = "mysql:host={$remote_host};port={$remote_port};dbname={$remote_db};charset=utf8mb4";
                $remote_pdo = new PDO($remote_dsn, $remote_user, $remote_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 15,
                ]);

                // Save remote host & user for future convenience in global_settings
                $saveSettings = [
                    'sync_remote_host' => $remote_host,
                    'sync_remote_port' => (string)$remote_port,
                    'sync_remote_db'   => $remote_db,
                    'sync_remote_user' => $remote_user,
                    'sync_remote_pass' => $remote_pass,
                ];
                foreach ($saveSettings as $sk => $sv) {
                    $upStmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $upStmt->execute([$sk, $sv, $sv]);
                }

                // 2. Fetch all tables from remote database
                $allRemoteTables = $remote_pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                if (empty($allRemoteTables)) {
                    throw new Exception("No tables found in remote database '{$remote_db}'.");
                }

                // Filter tables according to sync_scope
                $tablesToSync = [];
                if ($sync_scope === 'subscribers_only') {
                    $allowed = ['subscribers', 'radcheck', 'radreply', 'radacct', 'clients', 'packages', 'package_requests'];
                    $tablesToSync = array_values(array_intersect($allRemoteTables, $allowed));
                } elseif ($sync_scope === 'radius_only') {
                    $allowed = ['radcheck', 'radreply', 'radacct', 'radgroupcheck', 'radgroupreply', 'radusergroup', 'nas'];
                    $tablesToSync = array_values(array_intersect($allRemoteTables, $allowed));
                } else {
                    $tablesToSync = $allRemoteTables;
                }

                if (empty($tablesToSync)) {
                    throw new Exception("No matching tables found on remote server for the selected sync scope.");
                }

                // 3. Preserve current Super Admin accounts if checked
                $savedSuperAdmins = [];
                if ($preserve_sa) {
                    try {
                        $saStmt = $pdo->query("SELECT * FROM super_admins");
                        $savedSuperAdmins = $saStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {}
                }

                // 4. Begin local synchronization
                $pdo->exec("SET NAMES utf8mb4;");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $pdo->exec("SET UNIQUE_CHECKS = 0;");
                $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';");

                $syncedTablesCount = 0;
                $totalRowsCount = 0;
                $tableSummary = [];

                foreach ($tablesToSync as $table) {
                    // Get CREATE TABLE statement from remote
                    $createRow = $remote_pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                    $createSql = $createRow[1] ?? '';

                    if ($sync_mode === 'mirror' && !empty($createSql)) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$table}`;");
                        $pdo->exec($createSql . ";");
                    } else {
                        // Check if exists locally
                        $localExists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
                        if (!$localExists && !empty($createSql)) {
                            $pdo->exec($createSql . ";");
                        } else {
                            $pdo->exec("TRUNCATE TABLE `{$table}`;");
                        }
                    }

                    // Detect primary key for deterministic chunking
                    $pkCol = null;
                    try {
                        $pkStmt = $remote_pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
                        $pkRow = $pkStmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($pkRow['Column_name'])) {
                            $pkCol = "`" . str_replace("`", "``", $pkRow['Column_name']) . "`";
                        }
                    } catch (Exception $e) {}
                    $orderClause = $pkCol ? "ORDER BY {$pkCol} ASC" : "";

                    // Count rows on remote
                    $rowCount = (int)$remote_pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                    $syncedRowsForTable = 0;

                    if ($rowCount > 0) {
                        $batchSize = 500;
                        for ($offset = 0; $offset < $rowCount; $offset += $batchSize) {
                            $rowsStmt = $remote_pdo->query("SELECT * FROM `{$table}` {$orderClause} LIMIT {$batchSize} OFFSET {$offset}");
                            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

                            if (!empty($rows)) {
                                $columnNames = array_keys($rows[0]);
                                $escapedColumns = array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, $columnNames);
                                $colsSql = implode(", ", $escapedColumns);

                                $insertRows = [];
                                foreach ($rows as $row) {
                                    $values = [];
                                    foreach ($row as $val) {
                                        if ($val === null) {
                                            $values[] = 'NULL';
                                        } else {
                                            $values[] = $pdo->quote($val);
                                        }
                                    }
                                    $insertRows[] = "(" . implode(", ", $values) . ")";
                                }

                                // Use INSERT IGNORE INTO to gracefully ignore duplicate keys or shifting offsets without breaking sync
                                $pdo->exec("INSERT IGNORE INTO `{$table}` ({$colsSql}) VALUES\n" . implode(",\n", $insertRows) . ";");
                                $syncedRowsForTable += count($rows);
                            }
                        }
                    }

                    $syncedTablesCount++;
                    $totalRowsCount += $syncedRowsForTable;
                    $tableSummary[] = "<strong>`{$table}`</strong> ({$syncedRowsForTable})";
                }

                // 5. Restore preserved SuperAdmin accounts
                if ($preserve_sa && !empty($savedSuperAdmins)) {
                    foreach ($savedSuperAdmins as $adm) {
                        $cols = array_keys($adm);
                        $escapedCols = array_map(function($c) { return "`$c`"; }, $cols);
                        $vals = [];
                        foreach ($adm as $val) {
                            $vals[] = ($val === null) ? 'NULL' : $pdo->quote($val);
                        }
                        $sql = "REPLACE INTO `super_admins` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $vals) . ")";
                        $pdo->exec($sql);
                    }
                }

                $pdo->exec("SET UNIQUE_CHECKS = 1;");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                $summarySnippet = implode(', ', array_slice($tableSummary, 0, 8));
                if (count($tableSummary) > 8) {
                    $summarySnippet .= " and " . (count($tableSummary) - 8) . " more tables...";
                }

                $success = "Database synchronized successfully from Old VPS (<strong>" . htmlspecialchars($remote_host) . "</strong>)! Transferred <strong>{$syncedTablesCount}</strong> tables and <strong>{$totalRowsCount}</strong> total records.<br><small class='text-muted'>Synced Tables: {$summarySnippet}" . ($preserve_sa ? " | <em>Your active SuperAdmin account was preserved safely.</em>" : "") . "</small>";

            } catch (Exception $e) {
                try { $pdo->exec("SET UNIQUE_CHECKS = 1;"); } catch (Exception $ignored) {}
                try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (Exception $ignored) {}
                $error = "Database synchronization failed: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Fetch settings
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings");
$settings_raw = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fallbacks
$appName = $settings_raw['app_name'] ?? BRAND_NAME;
$timezone = $settings_raw['timezone'] ?? ($settings_raw['system_timezone'] ?? 'Asia/Karachi');
$currency = $settings_raw['currency'] ?? 'PKR';

// 1. Current Live Server DB Credentials
$currentServerHost = $settings_raw['server_public_ip'] ?? ($_SERVER['SERVER_ADDR'] ?? '187.126.127.237');
if ($currentServerHost === '127.0.0.1' || $currentServerHost === '::1') {
    $currentServerHost = '187.126.127.237';
}
$currentServerPort = $settings_raw['server_db_port'] ?? '3306';
$currentServerDb   = $settings_raw['server_db_name'] ?? ($db ?? 'radius');
$currentServerUser = $settings_raw['server_db_user'] ?? ($user ?? 'radius');
$currentServerPass = $settings_raw['server_db_pass'] ?? ($pass ?? 'Wasi1234');

// 2. Saved Remote VPS Sync Settings
$savedSyncHost = $settings_raw['sync_remote_host'] ?? '';
$savedSyncPort = $settings_raw['sync_remote_port'] ?? '3306';
$savedSyncDb   = $settings_raw['sync_remote_db'] ?? 'radius';
$savedSyncUser = $settings_raw['sync_remote_user'] ?? 'radius';
$savedSyncPass = $settings_raw['sync_remote_pass'] ?? '';

// 3. Current Server IP for command generator
$newServerIp = $currentServerHost;

// Fetch current superadmin details
$adminStmt = $pdo->prepare("SELECT name, username, email FROM super_admins WHERE id = ?");
$adminStmt->execute([$admin_id]);
$currentAdmin = $adminStmt->fetch();

// Database metrics
$dbName = $currentServerDb;
try {
    $tblCountStmt = $pdo->query("SHOW TABLES");
    $allTables = $tblCountStmt->fetchAll(PDO::FETCH_COLUMN);
    $totalTables = count($allTables);
} catch (Exception $e) {
    $totalTables = 0;
}

// Database Health & Auto-Maintenance Metrics
$authRetentionDays = intval($settings_raw['db_retention_auth_days'] ?? 7) ?: 7;
$acctRetentionDays = intval($settings_raw['db_retention_acct_days'] ?? 30) ?: 30;
$ghostDays         = intval($settings_raw['db_ghost_session_days'] ?? 2) ?: 2;
$lastMaintRun      = $settings_raw['db_maintenance_last_run'] ?? '';
$lastMaintStats    = json_decode($settings_raw['db_maintenance_last_stats'] ?? '[]', true);

$totalPostAuth     = 0;
$prunablePostAuth  = 0;
try {
    $totalPostAuth = (int)$pdo->query("SELECT COUNT(*) FROM radpostauth")->fetchColumn();
    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM radpostauth WHERE authdate < NOW() - INTERVAL ? DAY");
    $stmtP->execute([$authRetentionDays]);
    $prunablePostAuth = (int)$stmtP->fetchColumn();
} catch (Exception $e) {}

$totalAcct    = 0;
$activeAcct   = 0;
$prunableAcct = 0;
$ghostAcct    = 0;
try {
    $totalAcct = (int)$pdo->query("SELECT COUNT(*) FROM radacct")->fetchColumn();
    $activeAcct = (int)$pdo->query("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
    $stmtA = $pdo->prepare("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NOT NULL AND acctstoptime < NOW() - INTERVAL ? DAY");
    $stmtA->execute([$acctRetentionDays]);
    $prunableAcct = (int)$stmtA->fetchColumn();

    $stmtG = $pdo->prepare("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL AND acctstarttime < NOW() - INTERVAL ? DAY");
    $stmtG->execute([$ghostDays]);
    $ghostAcct = (int)$stmtG->fetchColumn();
} catch (Exception $e) {}

$tableSizes = [];
$totalDbSizeMb = 0;
try {
    $sizeStmt = $pdo->query("
        SELECT 
            table_name, 
            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
            ROUND((data_free / 1024 / 1024), 2) AS free_mb
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
    ");
    while ($row = $sizeStmt->fetch(PDO::FETCH_ASSOC)) {
        $tableSizes[$row['table_name']] = [
            'size_mb' => floatval($row['size_mb']),
            'free_mb' => floatval($row['free_mb'])
        ];
        $totalDbSizeMb += floatval($row['size_mb']);
    }
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
                <p class="text-muted small mb-0">Configure system parameters, application branding, and generate instant database disaster recovery dumps.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="#db-maintenance-section" class="btn btn-outline-danger rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-heart-pulse text-danger"></i> DB Health &amp; Prune
                </a>
                <a href="#vps-sync-section" class="btn btn-primary rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-arrows-rotate"></i> Sync from Old VPS
                </a>
                <a href="download_backup.php" class="btn btn-success rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Download Backup (.sql)
                </a>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#restoreBackupModal">
                    <i class="fa-solid fa-rotate-left"></i> Restore Backup
                </button>
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
                        <div class="form-text text-muted" style="font-size: 0.74rem;">Brand name displayed across client and admin login screens.</div>
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

        <!-- 2. Database Backup & Disaster Recovery -->
        <div class="col-lg-6">
            <div class="card-ui p-4 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom border-light-subtle">
                    <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-database fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-dark mb-0 fs-6">Full Database Backup &amp; Recovery</h5>
                        <span class="text-muted small">Download instant .sql backup or restore from file</span>
                    </div>
                </div>

                <div class="flex-grow-1">
                    <p class="text-muted small mb-3">
                        Generates a complete native SQL dump of all FreeRADIUS tables (`radcheck`, `radreply`, `radacct`, `nas`), tenant profiles, subscribers, packages, ledgers, and configurations.
                    </p>

                    <div class="d-flex flex-column gap-2 mb-3">
                        <a href="download_backup.php" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                            <i class="fa-solid fa-cloud-arrow-down fs-5"></i>
                            <span>Download Full Backup (.SQL)</span>
                        </a>
                        <button type="button" class="btn btn-outline-warning rounded-pill px-4 py-2 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2 text-dark" data-bs-toggle="modal" data-bs-target="#restoreBackupModal">
                            <i class="fa-solid fa-rotate-left fs-5 text-warning"></i>
                            <span>Restore Database from File (.SQL / .GZ)</span>
                        </button>
                    </div>

                    <div class="bg-light p-3 rounded-3 border border-light-subtle">
                        <div class="d-flex align-items-center gap-2 text-muted small mb-1">
                            <i class="fa-solid fa-check text-success"></i>
                            <span>Includes complete table structures &amp; data records</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <i class="fa-solid fa-bolt text-warning"></i>
                            <span>Pure PHP Native Exporter &amp; Importer (zero mysqldump dependency)</span>
                        </div>
                    </div>
                </div>

                <div class="mt-auto pt-3 border-top border-light-subtle d-flex justify-content-between align-items-center">
                    <span class="text-muted small"><i class="fa-solid fa-shield-virus text-primary me-1"></i> Disaster Recovery Ready</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">Safe &amp; Encrypted</span>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- 3. LIVE REMOTE VPS DATABASE SYNC CARD -->
        <!-- ============================================================== -->
        <div class="col-12" id="vps-sync-section">
            <div class="card-ui p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3 pb-3 border-bottom border-light-subtle">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; flex-shrink: 0;">
                            <i class="fa-solid fa-arrows-rotate fs-4"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h5 class="fw-bold text-dark mb-0 fs-5">Live Remote VPS Database Sync</h5>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">Old VPS ➔ New VPS</span>
                            </div>
                            <p class="text-muted small mb-0">
                                Connect directly to your Old VPS MySQL database and sync all subscribers, Mikrotik NAS routers, FreeRADIUS credentials, packages, and billing records in real-time.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-info rounded-pill px-3 py-2 btn-sm fw-bold" data-bs-toggle="collapse" data-bs-target="#syncHelpGuide">
                            <i class="fa-solid fa-circle-question me-1"></i> Setup Guide (Urdu)
                        </button>
                    </div>
                </div>

                <!-- Setup Guide Accordion (Collapsed by default, opens if requested or error) -->
                <div class="collapse mb-4" id="syncHelpGuide">
                    <div class="alert alert-info border-0 rounded-4 p-4 shadow-sm" style="background: #f0f9ff; border: 1px solid #bae6fd !important;">
                        <div class="d-flex align-items-center gap-2 mb-2 text-primary fw-bold">
                            <i class="fa-solid fa-book-open"></i>
                            <span>Old VPS par Remote MySQL Connection Allow Karne ka Process (2 Minutes)</span>
                        </div>
                        <p class="small text-secondary mb-3">
                            Linux VPS par security ki waja se MySQL/MariaDB default tor par sirf <code>127.0.0.1</code> (localhost) par listen karta hai. Old VPS se direct sync karne ke liye Old VPS terminal me ye 3 steps run karein:
                        </p>
                        
                        <div class="row g-3 small">
                            <div class="col-md-4">
                                <div class="bg-white p-3 rounded-3 border border-info-subtle h-100">
                                    <strong class="text-dark d-block mb-1">Step 1: MySQL Bind Address Change</strong>
                                    <span class="text-muted d-block mb-2">Old VPS terminal me config file edit karein:</span>
                                    <code>nano /etc/mysql/mariadb.conf.d/50-server.cnf</code>
                                    <span class="text-muted d-block mt-2">Line <code>bind-address = 127.0.0.1</code> ko change kar ke ye karein:</span>
                                    <code class="text-success fw-bold">bind-address = 0.0.0.0</code>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="bg-white p-3 rounded-3 border border-info-subtle h-100">
                                    <strong class="text-dark d-block mb-1">Step 2: Remote User Permission</strong>
                                    <span class="text-muted d-block mb-2">Old VPS MySQL me Remote connection ko allow karein:</span>
                                    <div class="bg-dark text-white p-2 rounded small" style="font-size: 0.73rem; word-break: break-all;">
                                        sudo mysql -e "CREATE USER IF NOT EXISTS 'radius'@'%' IDENTIFIED BY 'Wasi1234'; ALTER USER 'radius'@'%' IDENTIFIED BY 'Wasi1234'; GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'%'; FLUSH PRIVILEGES;"
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="bg-white p-3 rounded-3 border border-info-subtle h-100">
                                    <strong class="text-dark d-block mb-1">Step 3: Firewall Port &amp; Restart</strong>
                                    <span class="text-muted d-block mb-2">Port 3306 allow karein aur MySQL restart karein:</span>
                                    <div class="bg-dark text-white p-2 rounded small" style="font-size: 0.73rem;">
                                        sudo ufw allow from <?= htmlspecialchars($newServerIp) ?> to any port 3306<br>
                                        sudo systemctl restart mariadb
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-top border-info-subtle small text-dark d-flex align-items-center gap-2">
                            <i class="fa-solid fa-lightbulb text-warning fs-5 flex-shrink-0"></i>
                            <div>
                                <strong>Alternative (Offline File Sync):</strong> Agar aap Old VPS par port open nahi karna chahte, to Old VPS SuperAdmin se <strong>"Download Backup (.sql)"</strong> karein aur neeche <strong>"Restore Database Backup"</strong> modal me select kar ke 1-click me restore kar dein!
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sync Form -->
                <form id="vpsSyncForm" onsubmit="event.preventDefault(); startLiveProgressSync(event); return false;">

                    <div class="row g-3 mb-4">
                        <!-- Old VPS Host/IP -->
                        <div class="col-md-3">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Old VPS IP or Hostname <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="remote_host" id="remote_host" class="form-control" placeholder="e.g. 123.45.67.89" value="<?= htmlspecialchars($savedSyncHost) ?>" required autocomplete="off">
                            <div class="form-text text-muted" style="font-size: 0.73rem;">IP address of your old server.</div>
                        </div>

                        <!-- Port -->
                        <div class="col-md-2">
                            <label class="form-label text-dark fw-bold small mb-1">
                                MySQL Port
                            </label>
                            <input type="number" name="remote_port" id="remote_port" class="form-control" value="<?= htmlspecialchars($savedSyncPort) ?>" placeholder="3306">
                            <div class="form-text text-muted" style="font-size: 0.73rem;">Default: 3306.</div>
                        </div>

                        <!-- Database Name -->
                        <div class="col-md-2">
                            <label class="form-label text-dark fw-bold small mb-1">
                                DB Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="remote_db" id="remote_db" class="form-control" value="<?= htmlspecialchars($savedSyncDb) ?>" placeholder="radius" required>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">Usually 'radius'.</div>
                        </div>

                        <!-- MySQL Username -->
                        <div class="col-md-2">
                            <label class="form-label text-dark fw-bold small mb-1">
                                DB Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="remote_user" id="remote_user" class="form-control" value="<?= htmlspecialchars($savedSyncUser) ?>" placeholder="radius" required autocomplete="off">
                            <div class="form-text text-muted" style="font-size: 0.73rem;">Old server MySQL user.</div>
                        </div>

                        <!-- MySQL Password -->
                        <div class="col-md-3">
                            <label class="form-label text-dark fw-bold small mb-1">
                                DB Password <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="password" name="remote_pass" id="remote_pass" class="form-control" placeholder="Enter DB password" required autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" onclick="const p = document.getElementById('remote_pass'); p.type = p.type === 'password' ? 'text' : 'password';">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">Old VPS MySQL password.</div>
                        </div>
                    </div>

                    <!-- Test Connection Strip -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 bg-light rounded-4 border border-light-subtle mb-4">
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" id="btn_test_conn" class="btn btn-outline-primary rounded-pill px-4 btn-sm fw-bold" onclick="testRemoteConnection()">
                                <i class="fa-solid fa-plug me-1"></i> Test Connection
                            </button>
                            <span class="text-muted small" style="font-size: 0.76rem;">Test connectivity to Old VPS before starting full sync.</span>
                        </div>
                        <div id="test_conn_result" class="flex-grow-1 text-md-end"></div>
                    </div>

                    <!-- Sync Options Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">Sync Scope (Tables)</label>
                            <select name="sync_scope" class="form-select">
                                <option value="all" selected>All Tables (Full Database Mirror - Recommended)</option>
                                <option value="subscribers_only">Subscribers, Routers &amp; Packages Only (subscribers, radcheck, radreply, clients, packages)</option>
                                <option value="radius_only">FreeRADIUS Core Tables Only (radcheck, radreply, radacct, nas)</option>
                            </select>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">Select which data tables should be imported from the Old VPS.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">Sync Mode</label>
                            <select name="sync_mode" class="form-select">
                                <option value="mirror" selected>Mirror &amp; Recreate Tables (Full structure + all data records)</option>
                                <option value="replace">Truncate &amp; Replace Records Only (Retains current table schemas)</option>
                            </select>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">Recreate tables ensures new columns or modified indexes from Old VPS are replicated.</div>
                        </div>
                    </div>

                    <!-- Safety & Confirmation Checkboxes -->
                    <div class="bg-light-subtle border border-light-subtle rounded-4 p-3 mb-4">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="preserve_superadmin" id="preserve_superadmin" checked>
                            <label class="form-check-label text-dark small fw-bold" for="preserve_superadmin">
                                <i class="fa-solid fa-shield-halved text-success me-1"></i> Preserve Current SuperAdmin Account Credentials (Recommended)
                            </label>
                            <div class="text-muted small ms-4" style="font-size: 0.74rem;">
                                Keeps your active Super Admin login details so you won't be locked out even if the old database had different credentials.
                            </div>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm_sync" id="confirm_sync" required>
                            <label class="form-check-label text-danger small fw-bold" for="confirm_sync">
                                I confirm database synchronization. I understand that matching tables on this New VPS will be updated/replaced with records from the Old VPS.
                            </label>
                        </div>
                    </div>

                    <!-- Action Button Strip -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pt-2">
                        <div class="text-muted small">
                            <i class="fa-solid fa-circle-info text-primary me-1"></i> Live transfer runs in memory chunks to prevent timeouts and packet drops.
                        </div>
                        <button type="button" id="btn_submit_sync" onclick="startLiveProgressSync(event)" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-cloud-arrow-down fs-5"></i>
                            <span>Start Live Database Sync 🚀</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <!-- 4. Database Health, Auto-Prune & Nightly Maintenance -->
    <div class="card-ui p-4 mb-4" id="db-maintenance-section">
        <!-- Section Header -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 border-bottom border-light-subtle mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; flex-shrink: 0;">
                    <i class="fa-solid fa-heart-pulse fs-4"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h5 class="fw-bold text-dark mb-0 fs-5">Database Health &amp; Auto-Maintenance</h5>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">
                            <i class="fa-solid fa-shield-halved me-1"></i> Auto-Prune Active
                        </span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                            <i class="fa-solid fa-moon me-1"></i> Nightly 03:00 AM Cron
                        </span>
                    </div>
                    <p class="text-muted small mb-0">
                        Keeps your FreeRADIUS and billing database ultra-lightweight and fast by auto-purging old auth logs, expiring stale sessions, and defragmenting tables.
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-danger rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2" id="btn_run_maintenance" onclick="runInstantMaintenance()">
                    <i class="fa-solid fa-broom fs-5"></i>
                    <span>Run Instant Clean 🧹</span>
                </button>
            </div>
        </div>

        <!-- Live Health Metric Cards -->
        <div class="row g-3 mb-4">
            <!-- 1. radpostauth Card -->
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-4 border border-light-subtle h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small fw-bold text-uppercase">Auth Logs (radpostauth)</span>
                        <span class="badge bg-primary rounded-pill small"><?= htmlspecialchars($authRetentionDays) ?>d Rule</span>
                    </div>
                    <div class="fs-4 fw-bold text-dark" id="stat_postauth_total"><?= number_format($totalPostAuth) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 small">
                        <span class="text-muted"><i class="fa-solid fa-hard-drive me-1"></i> Size: <strong><?= number_format($tableSizes['radpostauth']['size_mb'] ?? 0, 2) ?> MB</strong></span>
                        <?php if ($prunablePostAuth > 0): ?>
                            <span class="badge bg-danger-subtle text-danger" id="stat_postauth_prunable"><?= number_format($prunablePostAuth) ?> Prunable</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success">Clean &amp; Optimized</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 2. radacct Sessions Card -->
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-4 border border-light-subtle h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small fw-bold text-uppercase">Sessions (radacct)</span>
                        <span class="badge bg-primary rounded-pill small"><?= htmlspecialchars($acctRetentionDays) ?>d Rule</span>
                    </div>
                    <div class="fs-4 fw-bold text-dark" id="stat_acct_total"><?= number_format($totalAcct) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 small">
                        <span class="text-success fw-bold"><i class="fa-solid fa-circle-dot me-1"></i> Online: <?= number_format($activeAcct) ?></span>
                        <?php if ($prunableAcct > 0): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle" id="stat_acct_prunable"><?= number_format($prunableAcct) ?> Old Closed</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success">Optimal</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 3. Ghost / Zombie Sessions -->
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-4 border border-light-subtle h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small fw-bold text-uppercase">Ghost / Zombie Sessions</span>
                        <span class="badge bg-secondary rounded-pill small">&gt; <?= htmlspecialchars($ghostDays) ?>d Stuck</span>
                    </div>
                    <div class="fs-4 fw-bold <?= $ghostAcct > 0 ? 'text-warning' : 'text-success' ?>" id="stat_ghost_total"><?= number_format($ghostAcct) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-2 small">
                        <span class="text-muted">Unterminated Sessions</span>
                        <?php if ($ghostAcct > 0): ?>
                            <span class="badge bg-warning text-dark">Will Auto-Close</span>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success">Zero Ghosts</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 4. Total DB Footprint & Last Clean -->
            <div class="col-md-3">
                <div class="p-3 bg-light rounded-4 border border-light-subtle h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small fw-bold text-uppercase">Total Database Size</span>
                        <span class="badge bg-dark rounded-pill small"><?= $totalTables ?> Tables</span>
                    </div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($totalDbSizeMb, 1) ?> MB</div>
                    <div class="text-muted small mt-2 text-truncate" title="<?= htmlspecialchars($lastMaintRun) ?>" id="stat_last_maint">
                        <i class="fa-regular fa-clock me-1"></i> Last: <?= htmlspecialchars($lastMaintRun ?: 'Never') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Execution Alert Strip (dynamically populated on run) -->
        <div id="maint_result_alert" style="display: none;" class="alert alert-success rounded-4 p-3 mb-4"></div>

        <!-- Financial Records Retention Guarantee Banner -->
        <div class="alert alert-info border-0 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3 shadow-sm" style="background: #eff6ff; border-left: 4px solid #3b82f6 !important;">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0;">
                    <i class="fa-solid fa-file-invoice-dollar fs-5"></i>
                </div>
                <div>
                    <h6 class="fw-bold text-dark mb-1">Financial Records &amp; Ledgers Guarantee (2 Years Retention)</h6>
                    <span class="text-muted small">All user ledgers, operator recoveries, manager collections, staff salaries, expenses, and invoices are strictly preserved for a minimum of <strong>2 Years (730 Days)</strong>. Ephemeral FreeRADIUS session pruning will never delete financial audit records.</span>
                </div>
            </div>
            <span class="badge bg-primary text-white px-3 py-2 rounded-pill font-monospace" style="font-size: 0.8rem;">
                <i class="fa-solid fa-lock me-1"></i> 2-Year Retention Protected
            </span>
        </div>

        <!-- 2 Sub-Cards: Retention Rules Form & Nightly Cron Setup -->
        <div class="row g-4">
            <!-- Left: Retention Rules Settings Form -->
            <div class="col-lg-6">
                <div class="p-4 bg-light rounded-4 border border-light-subtle h-100 d-flex flex-column">
                    <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom border-light-subtle">
                        <i class="fa-solid fa-sliders text-primary fs-5"></i>
                        <h6 class="fw-bold text-dark mb-0">Automatic Retention Rules</h6>
                    </div>
                    
                    <form id="maintRulesForm" onsubmit="saveMaintenanceSettings(event)" class="flex-grow-1 d-flex flex-column">
                        <div class="mb-3">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Auth Logs Retention (`radpostauth`)
                            </label>
                            <select name="auth_days" id="maint_auth_days" class="form-select">
                                <option value="3" <?= $authRetentionDays == 3 ? 'selected' : '' ?>>Keep 3 Days (Ultra-Aggressive / Minimalist)</option>
                                <option value="7" <?= $authRetentionDays == 7 ? 'selected' : '' ?>>Keep 7 Days (Recommended Standard)</option>
                                <option value="15" <?= $authRetentionDays == 15 ? 'selected' : '' ?>>Keep 15 Days</option>
                                <option value="30" <?= $authRetentionDays == 30 ? 'selected' : '' ?>>Keep 30 Days (1 Month)</option>
                            </select>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">
                                FreeRADIUS never reads historical auth attempts. Older records are 100% safe to purge.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Closed Sessions Retention (`radacct`)
                            </label>
                            <select name="acct_days" id="maint_acct_days" class="form-select">
                                <option value="15" <?= $acctRetentionDays == 15 ? 'selected' : '' ?>>Keep 15 Days (High-frequency networks)</option>
                                <option value="30" <?= $acctRetentionDays == 30 ? 'selected' : '' ?>>Keep 30 Days (Recommended - 1 Full Month)</option>
                                <option value="60" <?= $acctRetentionDays == 60 ? 'selected' : '' ?>>Keep 60 Days (2 Months)</option>
                                <option value="90" <?= $acctRetentionDays == 90 ? 'selected' : '' ?>>Keep 90 Days (Quarterly Audit)</option>
                            </select>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">
                                Only closed sessions older than this will be deleted. <strong>Live online sessions are NEVER deleted.</strong>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Ghost / Zombie Sessions Auto-Close Threshold
                            </label>
                            <select name="ghost_days" id="maint_ghost_days" class="form-select">
                                <option value="1" <?= $ghostDays == 1 ? 'selected' : '' ?>>After 1 Day (24 Hours of inactivity)</option>
                                <option value="2" <?= $ghostDays == 2 ? 'selected' : '' ?>>After 2 Days (48 Hours - Recommended)</option>
                                <option value="3" <?= $ghostDays == 3 ? 'selected' : '' ?>>After 3 Days</option>
                            </select>
                            <div class="form-text text-muted" style="font-size: 0.73rem;">
                                Closes stuck sessions where router rebooted without sending an Acct-Stop packet.
                            </div>
                        </div>

                        <div class="mt-auto pt-2 text-end">
                            <button type="submit" id="btn_save_maint_rules" class="btn btn-outline-primary rounded-pill px-4 fw-bold btn-sm">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Retention Rules
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right: Nightly Automated Cron Setup -->
            <div class="col-lg-6">
                <div class="p-4 bg-light rounded-4 border border-light-subtle h-100 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-light-subtle">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-success fs-5"></i>
                            <h6 class="fw-bold text-dark mb-0">Nightly Automated Cron Job</h6>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">03:00 AM Daily</span>
                    </div>

                    <p class="text-muted small mb-3">
                        Your Linux VPS executes this maintenance every night at <strong>03:00 AM</strong> automatically. This permanently prevents database bloat without needing manual intervention.
                    </p>

                    <!-- Command Box -->
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">Linux Crontab Command</label>
                        <div class="input-group">
                            <input type="text" id="crontab_cmd_input" class="form-control font-monospace small bg-white" value="0 3 * * * php /var/www/html/config/cron_db_maintenance.php > /dev/null 2>&1" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyCrontabCmd()">
                                <i class="fa-solid fa-copy me-1"></i> Copy
                            </button>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.73rem;">
                            Add to VPS root crontab: Run <code>crontab -e</code> and paste this line.
                        </div>
                    </div>

                    <!-- Webhook URL Box -->
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">Web URL / External Cron Trigger</label>
                        <div class="input-group">
                            <input type="text" id="cron_url_input" class="form-control font-monospace small bg-white" value="http://<?= htmlspecialchars($currentServerHost) ?>/config/cron_db_maintenance.php?key=sb_cron_secret_786" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyCronUrl()">
                                <i class="fa-solid fa-copy me-1"></i> Copy
                            </button>
                        </div>
                        <div class="form-text text-muted" style="font-size: 0.73rem;">
                            Can also be triggered remotely via curl or automated health monitors.
                        </div>
                    </div>

                    <div class="mt-auto pt-2 bg-white p-3 rounded-3 border border-light-subtle d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-circle-check text-success fs-5"></i>
                            <span class="small text-dark fw-semibold">Zero-Downtime Hot Optimization</span>
                        </div>
                        <span class="text-muted small" style="font-size: 0.72rem;">Safe while subscribers are online</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Live Sync Progress Modal (Realtime Percentage & Table Tracker) -->
<div class="modal fade" id="syncProgressModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white p-4">
                <div class="d-flex align-items-center gap-3">
                    <i class="fa-solid fa-arrows-rotate fa-spin text-primary fs-3" id="syncModalIcon"></i>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0" id="syncModalTitle">Live Database Synchronization in Progress</h5>
                        <div class="text-white-50 small" id="syncModalSubtitle">Connecting to Old VPS and initializing transfer engine...</div>
                    </div>
                </div>
            </div>
            <div class="modal-body p-4">
                <!-- Overall Progress Bar & Percentage -->
                <div class="bg-light p-3 rounded-4 border mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold text-dark small text-uppercase"><i class="fa-solid fa-chart-pie text-primary me-1"></i> Overall Sync Progress</span>
                        <span class="badge bg-primary px-3 py-1 fs-6 fw-bold" id="syncPercentBadge">0%</span>
                    </div>
                    <div class="progress rounded-pill bg-white border" style="height: 22px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary fw-bold" id="syncProgressBar" role="progressbar" style="width: 0%; font-size: 0.82rem;">0%</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
                        <span id="syncTableCountLabel"><i class="fa-solid fa-table me-1"></i> Tables Processed: 0 / 0</span>
                        <span id="syncRowsCountLabel"><i class="fa-solid fa-database me-1"></i> Total Records Synced: 0</span>
                    </div>
                </div>

                <!-- Current Table Status Banner -->
                <div class="alert alert-primary d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3" id="syncCurrentTableAlert">
                    <div class="spinner-border text-primary spinner-border-sm flex-shrink-0" role="status" id="syncCurrentSpinner"></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold text-dark small" id="syncCurrentTableTitle">Preparing sync request...</div>
                        <div class="text-secondary small" id="syncCurrentTableDetail" style="font-size: 0.75rem;">Please keep this browser window open. Do not close or refresh.</div>
                    </div>
                </div>

                <!-- Live Table Checklist / Progress Window -->
                <div class="card border rounded-3 overflow-hidden shadow-sm">
                    <div class="card-header bg-white py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-muted text-uppercase"><i class="fa-solid fa-list-check me-1"></i> Table Sync Status Log</span>
                        <span class="badge bg-light text-dark border small font-monospace" id="syncSpeedLabel">Live Engine Active</span>
                    </div>
                    <div class="card-body p-0" style="max-height: 240px; overflow-y: auto;" id="syncTableLogContainer">
                        <ul class="list-group list-group-flush small" id="syncTableListUl">
                            <!-- Injected dynamically -->
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light p-3 border-top d-flex justify-content-between">
                <span class="text-muted small" id="syncFooterNote"><i class="fa-solid fa-shield-halved text-success me-1"></i> Zero Duplicate Key Protection (INSERT IGNORE) Active.</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" id="btnSyncErrorClose" style="display: none;" onclick="closeSyncModal()">
                        <i class="fa-solid fa-xmark me-1"></i> Close
                    </button>
                    <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" id="btnSyncCompletedDone" style="display: none;" onclick="location.reload()">
                        <i class="fa-solid fa-check-circle me-1"></i> Sync Complete - Refresh Settings
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Restore Database Backup Modal -->
<div class="modal fade" id="restoreBackupModal" tabindex="-1" aria-labelledby="restoreBackupModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning-subtle border-bottom border-warning-subtle py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-rotate-left fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0 fs-6" id="restoreBackupModalLabel">Restore Database Backup</h5>
                        <span class="text-muted small" style="font-size: 0.74rem;">MySQL Database Disaster Recovery</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="restoreBackupForm" onsubmit="handleRestoreSubmit(event)">
                <input type="hidden" name="action" value="restore_backup">
                
                <div class="modal-body p-4">
                    <!-- Warning Notice -->
                    <div class="alert alert-warning border-0 rounded-3 mb-4 p-3 d-flex gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-danger fs-5 mt-1 flex-shrink-0"></i>
                        <div class="small text-dark">
                            <strong>Crucial Caution:</strong> Restoring a backup will overwrite/update existing tables, packages, subscribers, ledgers, and configurations with the contents of the uploaded SQL file.
                        </div>
                    </div>

                    <!-- File Select -->
                    <div class="mb-3">
                        <label class="form-label text-dark fw-bold small mb-1">
                            Select Backup File <span class="text-danger">*</span>
                        </label>
                        <input type="file" name="backup_file" id="backup_file_input" class="form-control" accept=".sql,.gz" required>
                        <div class="form-text text-muted" style="font-size: 0.74rem;">
                            Accepted file formats: <strong>.sql</strong> or <strong>.sql.gz</strong> (e.g., created via "Download Backup").
                        </div>
                    </div>

                    <!-- Confirmation Checkbox -->
                    <div class="form-check bg-light p-3 rounded-3 border border-light-subtle mb-2">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_restore" id="confirm_restore" required>
                        <label class="form-check-label text-dark small fw-bold" for="confirm_restore">
                            I understand that current MySQL data will be overwritten with this backup.
                        </label>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" id="btn_submit_restore" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-2">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>Start Database Restore</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function testRemoteConnection() {
    const host = document.getElementById('remote_host').value.trim();
    const port = document.getElementById('remote_port').value.trim() || '3306';
    const db = document.getElementById('remote_db').value.trim() || 'radius';
    const user = document.getElementById('remote_user').value.trim();
    const pass = document.getElementById('remote_pass').value;
    const btn = document.getElementById('btn_test_conn');
    const resultDiv = document.getElementById('test_conn_result');

    if (!host || !user) {
        alert('Please enter Old VPS Host/IP and MySQL Username first.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing Connection...';
    resultDiv.innerHTML = '<div class="text-primary small"><i class="fa-solid fa-spinner fa-spin me-1"></i> Connecting to ' + host + ':' + port + '...</div>';

    const formData = new FormData();
    formData.append('ajax_action', 'test_remote_db');
    formData.append('remote_host', host);
    formData.append('remote_port', port);
    formData.append('remote_db', db);
    formData.append('remote_user', user);
    formData.append('remote_pass', pass);

    fetch('settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plug me-1"></i> Test Connection';
        if (data.success) {
            let details = '';
            if (data.preview_counts) {
                const parts = [];
                for (const [tbl, cnt] of Object.entries(data.preview_counts)) {
                    parts.push(`<code>${tbl}</code>: ${cnt}`);
                }
                if (parts.length) {
                    details = `<div class="mt-1 small text-muted">${parts.join(' | ')}</div>`;
                }
            }
            resultDiv.innerHTML = `
                <div class="alert alert-success py-2 px-3 mb-0 rounded-3 small text-start">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-circle-check fs-5 text-success"></i>
                        <div>
                            <strong>Connection Successful!</strong> Connected to <code>${host}:${port}</code>.<br>
                            Found <strong>${data.table_count}</strong> tables in database <code>${db}</code>.
                        </div>
                    </div>
                    ${details}
                </div>`;
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-danger py-2 px-3 mb-0 rounded-3 small text-start">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fa-solid fa-circle-xmark fs-5 text-danger mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Connection Failed:</strong> ${data.message}
                            ${data.tip ? `<div class="mt-1 text-dark fw-bold">💡 Tip: ${data.tip}</div>` : ''}
                        </div>
                    </div>
                </div>`;
            // Auto open help guide if connection failed
            const guide = document.getElementById('syncHelpGuide');
            if (guide && !guide.classList.contains('show')) {
                const bsCollapse = new bootstrap.Collapse(guide, { toggle: true });
            }
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plug me-1"></i> Test Connection';
        resultDiv.innerHTML = `<div class="alert alert-danger py-2 px-3 mb-0 rounded-3 small text-start">Request Error: ${err.message}</div>`;
    });
}

function closeSyncModal() {
    const syncModalEl = document.getElementById('syncProgressModal');
    if (syncModalEl) {
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const inst = bootstrap.Modal.getInstance(syncModalEl);
                if (inst) inst.hide();
            } else if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
                $('#syncProgressModal').modal('hide');
            }
        } catch (e) {}
        syncModalEl.classList.remove('show');
        syncModalEl.style.display = 'none';
        document.body.classList.remove('modal-open');
        const b = document.querySelector('.modal-backdrop');
        if (b) b.remove();
    }
}

function handleSyncSubmit(e) {
    return startLiveProgressSync(e);
}

function startLiveProgressSync(e) {
    if (e && e.preventDefault) e.preventDefault();

    const host = (document.getElementById('remote_host')?.value || '').trim();
    const port = (document.getElementById('remote_port')?.value || '3306').trim();
    const db = (document.getElementById('remote_db')?.value || 'radius').trim();
    const user = (document.getElementById('remote_user')?.value || '').trim();
    const pass = document.getElementById('remote_pass')?.value || '';
    const scope = document.querySelector('select[name="sync_scope"]')?.value || 'all';
    const preserveSa = document.getElementById('preserve_superadmin')?.checked ?? true;
    const confirmBox = document.getElementById('confirm_sync');

    if (!host || !user) {
        alert('Please enter Old VPS Host/IP and MySQL Username.');
        return false;
    }

    if (!confirmBox || !confirmBox.checked) {
        alert('Please tick the confirmation checkbox to authorize database synchronization.');
        if (confirmBox) confirmBox.focus();
        return false;
    }

    if (!confirm('Are you ABSOLUTELY sure you want to start Live Database Sync from Old VPS (' + host + ')?\nMatching tables on this New VPS will be updated/replaced.')) {
        return false;
    }

    // Launch Realtime Progress Modal safely
    const syncModalEl = document.getElementById('syncProgressModal');
    if (syncModalEl) {
        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getOrCreateInstance(syncModalEl, { backdrop: 'static', keyboard: false });
                modal.show();
            } else if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
                $('#syncProgressModal').modal({ backdrop: 'static', keyboard: false, show: true });
            } else {
                syncModalEl.classList.add('show');
                syncModalEl.style.display = 'block';
                syncModalEl.style.backgroundColor = 'rgba(0,0,0,0.6)';
                document.body.classList.add('modal-open');
            }
        } catch (modalErr) {
            console.error('Modal launch exception:', modalErr);
            syncModalEl.classList.add('show');
            syncModalEl.style.display = 'block';
            syncModalEl.style.backgroundColor = 'rgba(0,0,0,0.6)';
        }
    }

    // Reset UI state
    document.getElementById('syncPercentBadge').innerText = '0%';
    document.getElementById('syncPercentBadge').className = 'badge bg-primary px-3 py-1 fs-6 fw-bold';
    document.getElementById('syncProgressBar').style.width = '0%';
    document.getElementById('syncProgressBar').innerText = '0%';
    document.getElementById('syncProgressBar').className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary fw-bold';
    document.getElementById('syncTableCountLabel').innerHTML = '<i class="fa-solid fa-table me-1"></i> Initializing...';
    document.getElementById('syncRowsCountLabel').innerHTML = '<i class="fa-solid fa-database me-1"></i> Records Synced: 0';
    document.getElementById('syncCurrentTableAlert').className = 'alert alert-primary d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3';
    document.getElementById('syncCurrentSpinner').style.display = 'inline-block';
    document.getElementById('syncCurrentTableTitle').innerText = 'Connecting to Old VPS database...';
    document.getElementById('syncCurrentTableDetail').innerText = `Target: ${host}:${port}/${db}`;
    document.getElementById('syncTableListUl').innerHTML = '';
    document.getElementById('btnSyncCompletedDone').style.display = 'none';
    document.getElementById('btnSyncErrorClose').style.display = 'none';
    document.getElementById('syncModalIcon').className = 'fa-solid fa-arrows-rotate fa-spin text-primary fs-3';
    document.getElementById('syncModalTitle').innerText = 'Live Database Synchronization in Progress';
    document.getElementById('syncModalSubtitle').innerText = 'Connecting to Old VPS and initializing transfer engine...';

    let totalTables = 0;
    let tablesQueue = [];
    let completedTables = 0;
    let grandTotalSyncedRows = 0;

    // Step 1: Initialize Sync via AJAX
    const initData = new FormData();
    initData.append('ajax_action', 'sync_init');
    initData.append('remote_host', host);
    initData.append('remote_port', port);
    initData.append('remote_db', db);
    initData.append('remote_user', user);
    initData.append('remote_pass', pass);
    initData.append('sync_scope', scope);
    initData.append('preserve_superadmin', preserveSa ? '1' : '0');

    fetch('settings.php', { method: 'POST', body: initData })
    .then(r => r.text())
    .then(rawText => {
        let res;
        try {
            res = JSON.parse(rawText);
        } catch (e) {
            throw new Error('Invalid response from server: ' + rawText.substring(0, 250));
        }

        if (!res.success) {
            document.getElementById('syncCurrentTableAlert').className = 'alert alert-danger d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3';
            document.getElementById('syncCurrentTableTitle').innerText = 'Sync Initialization Failed';
            document.getElementById('syncCurrentTableDetail').innerText = res.message;
            document.getElementById('syncCurrentSpinner').style.display = 'none';
            document.getElementById('syncModalIcon').className = 'fa-solid fa-triangle-exclamation text-danger fs-3';
            document.getElementById('btnSyncErrorClose').style.display = 'inline-block';
            return;
        }

        tablesQueue = res.tables;
        totalTables = res.total_tables;
        document.getElementById('syncTableCountLabel').innerHTML = `<i class="fa-solid fa-table me-1"></i> Tables Processed: 0 / ${totalTables} (0%)`;
        document.getElementById('syncModalSubtitle').innerText = `Syncing ${totalTables} tables (~${(res.total_estimated_rows || 0).toLocaleString()} estimated records)...`;

        // Populate table list log
        const ul = document.getElementById('syncTableListUl');
        ul.innerHTML = tablesQueue.map((t, idx) => `
            <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3" id="log_row_${idx}">
                <div class="d-flex align-items-center gap-2">
                    <span id="log_icon_${idx}" class="text-secondary"><i class="fa-regular fa-circle"></i></span>
                    <strong class="font-monospace text-dark">${t.name}</strong>
                    <span class="text-muted" style="font-size: 0.72rem;">(~${t.rows} rows)</span>
                </div>
                <span class="badge bg-light text-secondary border font-monospace" id="log_badge_${idx}">Waiting</span>
            </li>
        `).join('');

        // Start processing tables sequentially
        processNextTable(0);
    })
    .catch(err => {
        document.getElementById('syncCurrentTableAlert').className = 'alert alert-danger d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3';
        document.getElementById('syncCurrentTableTitle').innerText = 'Network / Request Error';
        document.getElementById('syncCurrentTableDetail').innerText = err.message;
        document.getElementById('syncCurrentSpinner').style.display = 'none';
        document.getElementById('syncModalIcon').className = 'fa-solid fa-triangle-exclamation text-danger fs-3';
        document.getElementById('btnSyncErrorClose').style.display = 'inline-block';
    });

    // Helper: Process Table by Index
    function processNextTable(tableIdx) {
        if (tableIdx >= tablesQueue.length) {
            // All tables completed -> Finish sync
            finishSyncProcess();
            return;
        }

        const tableItem = tablesQueue[tableIdx];
        const tableName = tableItem.name;

        // Update UI for active table
        document.getElementById('syncCurrentTableTitle').innerText = `Syncing table: ${tableName} (${tableIdx + 1} of ${totalTables})`;
        document.getElementById('syncCurrentTableDetail').innerText = `Transferring records in chunks...`;
        
        const rowEl = document.getElementById(`log_row_${tableIdx}`);
        const iconEl = document.getElementById(`log_icon_${tableIdx}`);
        const badgeEl = document.getElementById(`log_badge_${tableIdx}`);

        if (rowEl) rowEl.className = 'list-group-item d-flex justify-content-between align-items-center py-2 px-3 bg-primary-subtle';
        if (iconEl) iconEl.innerHTML = '<i class="fa-solid fa-arrows-rotate fa-spin text-primary"></i>';
        if (badgeEl) {
            badgeEl.className = 'badge bg-primary text-white font-monospace';
            badgeEl.innerText = 'Syncing...';
        }

        if (rowEl) rowEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Chunked sync for this table
        syncTableChunks(tableName, tableIdx, 0, 0, function(tableTotalSynced) {
            completedTables++;
            grandTotalSyncedRows += tableTotalSynced;

            if (rowEl) rowEl.className = 'list-group-item d-flex justify-content-between align-items-center py-2 px-3';
            if (iconEl) iconEl.innerHTML = '<i class="fa-solid fa-circle-check text-success"></i>';
            if (badgeEl) {
                badgeEl.className = 'badge bg-success-subtle text-success border border-success-subtle font-monospace';
                badgeEl.innerText = `${tableTotalSynced.toLocaleString()} rows ✔`;
            }

            // Update Progress Bar
            const percent = Math.min(100, Math.round((completedTables / totalTables) * 100));
            document.getElementById('syncPercentBadge').innerText = `${percent}%`;
            document.getElementById('syncProgressBar').style.width = `${percent}%`;
            document.getElementById('syncProgressBar').innerText = `${percent}%`;
            document.getElementById('syncTableCountLabel').innerHTML = `<i class="fa-solid fa-table me-1"></i> Tables Processed: ${completedTables} / ${totalTables} (${percent}%)`;
            document.getElementById('syncRowsCountLabel').innerHTML = `<i class="fa-solid fa-database me-1"></i> Total Records Synced: ${grandTotalSyncedRows.toLocaleString()}`;

            // Process next table
            processNextTable(tableIdx + 1);
        });
    }

    // Helper: Sync chunks for a single table
    function syncTableChunks(tableName, tableIdx, offset, accumulatedRows, callback) {
        const chunkData = new FormData();
        chunkData.append('ajax_action', 'sync_table');
        chunkData.append('remote_host', host);
        chunkData.append('remote_port', port);
        chunkData.append('remote_db', db);
        chunkData.append('remote_user', user);
        chunkData.append('remote_pass', pass);
        chunkData.append('table', tableName);
        chunkData.append('offset', offset);

        fetch('settings.php', { method: 'POST', body: chunkData })
        .then(r => r.text())
        .then(rawText => {
            let res;
            try {
                res = JSON.parse(rawText);
            } catch (e) {
                throw new Error('Invalid JSON: ' + rawText.substring(0, 150));
            }

            if (!res.success) {
                console.warn(res.message);
                const badgeEl = document.getElementById(`log_badge_${tableIdx}`);
                if (badgeEl) {
                    badgeEl.className = 'badge bg-warning-subtle text-warning border border-warning-subtle font-monospace';
                    badgeEl.innerText = 'Warn / Skipped';
                }
                callback(accumulatedRows);
                return;
            }

            const currentTotal = accumulatedRows + res.synced_in_chunk;
            document.getElementById('syncCurrentTableDetail').innerText = `Transferred ${currentTotal.toLocaleString()} records...`;
            
            // Live row counter update during chunks
            const liveTotalRows = grandTotalSyncedRows + currentTotal;
            document.getElementById('syncRowsCountLabel').innerHTML = `<i class="fa-solid fa-database me-1"></i> Total Records Synced: ${liveTotalRows.toLocaleString()}`;

            const badgeEl = document.getElementById(`log_badge_${tableIdx}`);
            if (badgeEl) {
                badgeEl.innerText = `${currentTotal.toLocaleString()} rows...`;
            }

            if (res.is_done) {
                callback(currentTotal);
            } else {
                syncTableChunks(tableName, tableIdx, res.current_offset, currentTotal, callback);
            }
        })
        .catch(err => {
            console.error(err);
            callback(accumulatedRows);
        });
    }

    // Helper: Finish sync and restore credentials
    function finishSyncProcess() {
        document.getElementById('syncCurrentTableTitle').innerText = 'Finalizing Synchronization & Restoring Accounts...';
        document.getElementById('syncCurrentTableDetail').innerText = 'Enabling foreign keys and security credentials...';

        const finishData = new FormData();
        finishData.append('ajax_action', 'sync_finish');

        fetch('settings.php', { method: 'POST', body: finishData })
        .then(r => r.text())
        .then(rawText => {
            // 100% Complete UI
            document.getElementById('syncPercentBadge').innerText = '100%';
            document.getElementById('syncPercentBadge').className = 'badge bg-success px-3 py-1 fs-6 fw-bold';
            document.getElementById('syncProgressBar').style.width = '100%';
            document.getElementById('syncProgressBar').innerText = '100% Complete';
            document.getElementById('syncProgressBar').className = 'progress-bar bg-success fw-bold';

            document.getElementById('syncModalIcon').className = 'fa-solid fa-circle-check text-success fs-3';
            document.getElementById('syncModalTitle').innerText = '🎉 Synchronization Completed Successfully!';
            document.getElementById('syncModalSubtitle').innerText = `All ${totalTables} tables and ${grandTotalSyncedRows.toLocaleString()} records are synchronized.`;

            document.getElementById('syncCurrentTableAlert').className = 'alert alert-success d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3';
            document.getElementById('syncCurrentSpinner').style.display = 'none';
            document.getElementById('syncCurrentTableTitle').innerText = `100% Completed! Transferred ${totalTables} tables & ${grandTotalSyncedRows.toLocaleString()} records.`;
            document.getElementById('syncCurrentTableDetail').innerText = 'Your active SuperAdmin account and all tenant databases are fully updated.';

            document.getElementById('btnSyncCompletedDone').style.display = 'inline-block';
        })
        .catch(err => {
            document.getElementById('btnSyncCompletedDone').style.display = 'inline-block';
        });
    }

    return false;
}

function handleRestoreSubmit(e) {
    const fileInput = document.getElementById('backup_file_input');
    const confirmBox = document.getElementById('confirm_restore');
    
    if (!fileInput.files.length) {
        alert('Please select a .sql or .sql.gz backup file to restore.');
        e.preventDefault();
        return false;
    }

    if (!confirmBox.checked) {
        alert('Please tick the confirmation checkbox to proceed.');
        e.preventDefault();
        return false;
    }

    if (!confirm('Are you ABSOLUTELY sure you want to restore this database? Current records will be replaced.')) {
        e.preventDefault();
        return false;
    }

    // Show Global Loader
    const loader = document.getElementById('global-loader');
    if (loader) {
        loader.style.display = 'flex';
        const title = loader.querySelector('h5');
        if (title) title.innerText = 'Restoring MySQL Database... Please wait';
    }

    const btn = document.getElementById('btn_submit_restore');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Restoring Database...';
    }
}

function runInstantMaintenance() {
    const authDays = document.getElementById('maint_auth_days')?.value || 7;
    const acctDays = document.getElementById('maint_acct_days')?.value || 30;
    const ghostDays = document.getElementById('maint_ghost_days')?.value || 2;

    if (!confirm('Run Instant Database Optimization now?\n\n- Old auth logs (> ' + authDays + ' days) will be purged\n- Old closed sessions (> ' + acctDays + ' days) will be purged\n- Stale ghost sessions (> ' + ghostDays + ' days) will be closed\n- Tables will be defragmented\n\nActive online subscribers will NOT be affected.')) {
        return;
    }

    const btn = document.getElementById('btn_run_maintenance');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Optimizing Database...';
    }

    const alertEl = document.getElementById('maint_result_alert');
    if (alertEl) {
        alertEl.style.display = 'block';
        alertEl.className = 'alert alert-info py-2 px-3 rounded-4 mb-4 small';
        alertEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Database cleanup and table defragmentation in progress... Please wait.';
    }

    const formData = new FormData();
    formData.append('ajax_action', 'run_db_maintenance');
    formData.append('auth_days', authDays);
    formData.append('acct_days', acctDays);
    formData.append('ghost_days', ghostDays);

    fetch('settings.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }

        if (data.success) {
            if (alertEl) {
                alertEl.className = 'alert alert-success py-2 px-3 rounded-4 mb-4 small';
                alertEl.innerHTML = `<div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-circle-check fs-5 text-success"></i>
                    <div>
                        <strong>Database Cleaned &amp; Optimized!</strong> ${data.message}
                    </div>
                </div>`;
            }

            // Update live metrics on page
            if (data.counts) {
                const elAuth = document.getElementById('stat_postauth_total');
                if (elAuth) elAuth.innerText = Number(data.counts.total_postauth).toLocaleString();

                const elAuthPrune = document.getElementById('stat_postauth_prunable');
                if (elAuthPrune) elAuthPrune.innerText = '0 Prunable';

                const elAcct = document.getElementById('stat_acct_total');
                if (elAcct) elAcct.innerText = Number(data.counts.total_acct).toLocaleString();

                const elAcctPrune = document.getElementById('stat_acct_prunable');
                if (elAcctPrune) elAcctPrune.innerText = '0 Old Closed';

                const elGhost = document.getElementById('stat_ghost_total');
                if (elGhost) elGhost.innerText = Number(data.counts.ghost_acct).toLocaleString();

                const elLast = document.getElementById('stat_last_maint');
                if (elLast) elLast.innerHTML = `<i class="fa-regular fa-clock me-1"></i> Last: Just now (${data.counts.last_run})`;
            }
        } else {
            if (alertEl) {
                alertEl.className = 'alert alert-danger py-2 px-3 rounded-4 mb-4 small';
                alertEl.innerHTML = '<strong>Error during maintenance:</strong> ' + data.message;
            }
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
        if (alertEl) {
            alertEl.className = 'alert alert-danger py-2 px-3 rounded-4 mb-4 small';
            alertEl.innerHTML = 'Request error: ' + err.message;
        }
    });
}

function saveMaintenanceSettings(e) {
    e.preventDefault();
    const authDays = document.getElementById('maint_auth_days').value;
    const acctDays = document.getElementById('maint_acct_days').value;
    const ghostDays = document.getElementById('maint_ghost_days').value;
    const btn = document.getElementById('btn_save_maint_rules');

    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
    }

    const formData = new FormData();
    formData.append('ajax_action', 'save_db_maintenance_settings');
    formData.append('auth_days', authDays);
    formData.append('acct_days', acctDays);
    formData.append('ghost_days', ghostDays);

    fetch('settings.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        if (data.success) {
            alert('Automatic Retention Rules saved successfully!');
            location.reload();
        } else {
            alert('Failed to save rules: ' + data.message);
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
        alert('Network error: ' + err.message);
    });
}

function copyCrontabCmd() {
    const input = document.getElementById('crontab_cmd_input');
    if (input) {
        input.select();
        navigator.clipboard.writeText(input.value).then(() => {
            alert('Crontab command copied to clipboard!\n\nPaste this in VPS terminal using: crontab -e');
        });
    }
}

function copyCronUrl() {
    const input = document.getElementById('cron_url_input');
    if (input) {
        input.select();
        navigator.clipboard.writeText(input.value).then(() => {
            alert('Cron Webhook URL copied to clipboard!');
        });
    }
}
</script>

<?php require_once 'footer.php'; ?>
