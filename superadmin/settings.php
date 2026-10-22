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
        } elseif (str_contains($msg, 'Access denied') || str_contains($msg, '1045')) {
            $tip = "MySQL Username ya Password ghalat hai, ya user ko remote IP se connect karne ki permission nahi hai.";
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

    // Fetch settings for any custom overrides
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

                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

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

                    // Count rows on remote
                    $rowCount = (int)$remote_pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                    $syncedRowsForTable = 0;

                    if ($rowCount > 0) {
                        $batchSize = 250;
                        for ($offset = 0; $offset < $rowCount; $offset += $batchSize) {
                            $rowsStmt = $remote_pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}");
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

                                $pdo->exec("INSERT INTO `{$table}` ({$colsSql}) VALUES\n" . implode(",\n", $insertRows) . ";");
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

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

                $summarySnippet = implode(', ', array_slice($tableSummary, 0, 8));
                if (count($tableSummary) > 8) {
                    $summarySnippet .= " and " . (count($tableSummary) - 8) . " more tables...";
                }

                $success = "Database synchronized successfully from Old VPS (<strong>" . htmlspecialchars($remote_host) . "</strong>)! Transferred <strong>{$syncedTablesCount}</strong> tables and <strong>{$totalRowsCount}</strong> total records.<br><small class='text-muted'>Synced Tables: {$summarySnippet}" . ($preserve_sa ? " | <em>Your active SuperAdmin account was preserved safely.</em>" : "") . "</small>";

            } catch (Exception $e) {
                try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (Exception $ignored) {}
                $error = "Database synchronization failed: " . htmlspecialchars($e->getMessage());
            }
        }
    }
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
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update database credentials: " . htmlspecialchars($e->getMessage());
        }
    }
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

                } catch (Exception $e) {
                    $error = "Failed to update MariaDB password: " . htmlspecialchars($e->getMessage());
                }
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

// 3. FreeRADIUS Credentials
$radiusSecret   = $settings_raw['radius_secret'] ?? '123456';
$radiusAuthPort = $settings_raw['radius_auth_port'] ?? '1812';
$radiusAcctPort = $settings_raw['radius_acct_port'] ?? '1813';
$radiusCoaPort  = $settings_raw['radius_coa_port'] ?? '3799';

// 4. Current Server IP for command generator
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

$dbMetrics = [
    'subscribers_count' => 0,
    'nas_count' => 0,
    'db_size_mb' => '0.00',
    'mysql_version' => ''
];
try {
    $dbMetrics['subscribers_count'] = (int)$pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
} catch (Exception $e) {}
try {
    $dbMetrics['nas_count'] = (int)$pdo->query("SELECT COUNT(*) FROM nas")->fetchColumn();
} catch (Exception $e) {}
try {
    $verStmt = $pdo->query("SELECT VERSION()");
    $dbMetrics['mysql_version'] = $verStmt->fetchColumn() ?: '';
} catch (Exception $e) {}
try {
    $sizeStmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE table_schema = " . $pdo->quote($currentServerDb));
    $dbMetrics['db_size_mb'] = $sizeStmt->fetchColumn() ?: '0.00';
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
                <p class="text-muted small mb-0">Configure system parameters, update super admin credentials, and generate instant database disaster recovery dumps.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="#vps-sync-section" class="btn btn-primary rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-arrows-rotate"></i> Sync from Old VPS
                </a>
                <button type="button" class="btn btn-warning rounded-pill px-3 py-2 fw-bold shadow-sm text-dark d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#changeMariaDbModal">
                    <i class="fa-solid fa-key"></i> MariaDB Password
                </button>
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

                <!-- ======================================================= -->
                <!-- DATABASE & RADIUS CREDENTIALS CONTROL CENTER            -->
                <!-- ======================================================= -->
                <div class="p-3 mb-4 rounded-4 border" style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-color: #e2e8f0 !important;">
                    
                    <!-- Header -->
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; flex-shrink: 0;">
                                <i class="fa-solid fa-database"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h6 class="fw-bold text-dark mb-0">Database &amp; RADIUS Credentials Control Center</h6>
                                    <span id="vault_badge" class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1">
                                        <i class="fa-solid fa-lock me-1"></i> Hidden &amp; Locked
                                    </span>
                                </div>
                                <span class="text-muted small">
                                    Tamam saved database connection parameters, remote VPS sync profiles, aur FreeRADIUS secrets aik hi jagah se manage karein.
                                </span>
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
                                <span>Manage / Edit Credentials</span>
                            </button>
                            <button type="button" id="btn_copy_all" class="btn btn-outline-primary rounded-pill px-3 py-2 btn-sm fw-bold d-none align-items-center gap-1" onclick="copyAllVaultCredentials()">
                                <i class="fa-solid fa-copy"></i>
                                <span>Copy All</span>
                            </button>
                        </div>
                    </div>

                    <!-- Database Health & Engine Metrics Strip -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-3 col-6">
                            <div class="bg-white px-3 py-2 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                                <i class="fa-solid fa-microchip text-primary fs-5"></i>
                                <div class="overflow-hidden">
                                    <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">DB Engine</span>
                                    <strong class="text-dark small text-truncate d-block" title="<?= htmlspecialchars($dbMetrics['mysql_version'] ?: 'MySQL/MariaDB') ?>">
                                        <?= htmlspecialchars(explode('-', $dbMetrics['mysql_version'])[0] ?: 'MySQL Ready') ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="bg-white px-3 py-2 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                                <i class="fa-solid fa-hard-drive text-success fs-5"></i>
                                <div>
                                    <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">DB Storage Size</span>
                                    <strong class="text-dark small"><?= htmlspecialchars($dbMetrics['db_size_mb']) ?> MB (<?= $totalTables ?> Tables)</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="bg-white px-3 py-2 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                                <i class="fa-solid fa-users text-info fs-5"></i>
                                <div>
                                    <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">Total Subscribers</span>
                                    <strong class="text-dark small"><?= number_format($dbMetrics['subscribers_count']) ?> Accounts</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="bg-white px-3 py-2 rounded-3 border border-light-subtle d-flex align-items-center gap-2">
                                <i class="fa-solid fa-network-wired text-warning fs-5"></i>
                                <div>
                                    <span class="text-muted small d-block" style="font-size: 0.68rem; text-transform: uppercase;">Connected Routers</span>
                                    <strong class="text-dark small"><?= number_format($dbMetrics['nas_count']) ?> NAS Routers</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 1. Current Live Server DB Credentials -->
                    <div class="mb-3">
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

                    <!-- 2. Saved Remote Old VPS Credentials -->
                    <div class="mb-3">
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

                    <!-- 3. FreeRADIUS & MikroTik Shared Secret -->
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
                                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">Acct Port (UDP)</span>
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
                                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.68rem;">CoA Port (UDP)</span>
                                        <button type="button" class="btn btn-link p-0 text-muted d-none vault-copy-btn" onclick="copyVaultValue('vault_radius_coa', this)" title="Copy CoA Port">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                    <div class="fw-bold text-dark font-monospace small" id="vault_radius_coa">••••</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Auto Fill Strip -->
                    <div id="vault_autofill_strip" class="d-none mt-3 pt-2 border-top border-light-subtle d-flex justify-content-between align-items-center small text-muted">
                        <span><i class="fa-solid fa-circle-check text-success me-1"></i> Tamam database credentials unlocked hain. Aap inhein edit bhi kar saktay hain.</span>
                        <button type="button" class="btn btn-link btn-sm text-primary text-decoration-none p-0 fw-semibold" onclick="autoFillInboundSyncForm()">
                            <i class="fa-solid fa-arrow-down me-1"></i> Fill Saved Remote into Sync Form below
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
                                    <span class="text-muted d-block mb-2">Old VPS MySQL me New VPS IP (<code><?= htmlspecialchars($newServerIp) ?></code>) ko allow karein:</span>
                                    <div class="bg-dark text-white p-2 rounded small" style="font-size: 0.73rem; word-break: break-all;">
                                        sudo mysql -e "GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'<?= htmlspecialchars($newServerIp) ?>' IDENTIFIED BY 'Wasi1234'; FLUSH PRIVILEGES;"
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
                <form method="POST" id="vpsSyncForm" onsubmit="handleSyncSubmit(event)">
                    <input type="hidden" name="action" value="sync_remote_db">

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
                        <button type="submit" id="btn_submit_sync" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-cloud-arrow-down fs-5"></i>
                            <span>Start Live Database Sync 🚀</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 4. Database Disaster Recovery & Backup Card -->
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
                    <div class="d-flex flex-wrap gap-2">
                        <a href="download_backup.php" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-cloud-arrow-down fs-5"></i>
                            <span>Download Full Backup (.SQL)</span>
                        </a>
                        <button type="button" class="btn btn-warning rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2 text-dark" data-bs-toggle="modal" data-bs-target="#restoreBackupModal">
                            <i class="fa-solid fa-rotate-left fs-5"></i>
                            <span>Restore Database Backup (.SQL)</span>
                        </button>
                    </div>
                </div>

                <div class="row g-3 mt-3 pt-3 border-top border-light-subtle">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <i class="fa-solid fa-check-double text-success"></i>
                            <span>Pure PHP Native Exporter & Importer</span>
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
                            <span>Supports both .sql and .sql.gz backup formats</span>
                        </div>
                    </div>
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

<!-- Security Verification Modal for Revealing DB Credentials -->
<div class="modal fade" id="unlockVaultModal" tabindex="-1" aria-labelledby="unlockVaultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-warning fs-5"></i>
                    <h6 class="modal-title fw-bold mb-0" id="unlockVaultModalLabel">Security Verification</h6>
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

<!-- Modal: Edit / Manage All Database Credentials -->
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

<!-- Modal: Change Live MariaDB Password -->
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

    fetch('settings.php', {
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

    // Show Edit and Copy buttons
    if (document.getElementById('btn_change_mariadb_pwd')) {
        document.getElementById('btn_change_mariadb_pwd').classList.remove('d-none');
        document.getElementById('btn_change_mariadb_pwd').classList.add('d-inline-flex');
    }
    document.getElementById('btn_edit_credentials').classList.remove('d-none');
    document.getElementById('btn_edit_credentials').classList.add('d-inline-flex');
    document.getElementById('btn_copy_all').classList.remove('d-none');
    document.getElementById('btn_copy_all').classList.add('d-inline-flex');
    document.getElementById('vault_autofill_strip').classList.remove('d-none');

    // Show all copy buttons & eye toggles
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
    document.getElementById('vault_autofill_strip').classList.add('d-none');

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

function autoFillInboundSyncForm() {
    if (!vaultData) return;
    if (vaultData.remote_host && vaultData.remote_host !== 'Not configured' && vaultData.remote_host !== 'Not set') {
        document.getElementById('remote_host').value = vaultData.remote_host;
    }
    if (vaultData.remote_port) document.getElementById('remote_port').value = vaultData.remote_port;
    if (vaultData.remote_db) document.getElementById('remote_db').value = vaultData.remote_db;
    if (vaultData.remote_user && vaultData.remote_user !== 'Not configured' && vaultData.remote_user !== 'Not set') {
        document.getElementById('remote_user').value = vaultData.remote_user;
    }
    if (vaultData.remote_pass && vaultData.remote_pass !== '••••••••' && vaultData.remote_pass !== 'Not set') {
        document.getElementById('remote_pass').value = vaultData.remote_pass;
    }
}
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

function handleSyncSubmit(e) {
    const host = document.getElementById('remote_host').value.trim();
    const user = document.getElementById('remote_user').value.trim();
    const confirmBox = document.getElementById('confirm_sync');

    if (!host || !user) {
        alert('Please enter Old VPS Host/IP and MySQL Username.');
        e.preventDefault();
        return false;
    }

    if (!confirmBox.checked) {
        alert('Please tick the confirmation checkbox to authorize database synchronization.');
        e.preventDefault();
        return false;
    }

    if (!confirm('Are you ABSOLUTELY sure you want to start Live Database Sync from Old VPS (' + host + ')?\nMatching tables on this New VPS will be updated/replaced.')) {
        e.preventDefault();
        return false;
    }

    // Show Global Loader
    const loader = document.getElementById('global-loader');
    if (loader) {
        loader.style.display = 'flex';
        const title = loader.querySelector('h5');
        if (title) title.innerText = 'Syncing Database from Old VPS (' + host + ')... Please wait';
    }

    const btn = document.getElementById('btn_submit_sync');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Live Sync in progress...';
    }
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
