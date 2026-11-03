<?php
/**
 * SB Link Network - Centralized Database Backup & Retention Helper
 * 
 * Generates 100% full database SQL backups (compatible with mysqldump & PHP chunked dumper),
 * automatically maintains strict 30-day retention (last 30 backups), and provides safe
 * download & restore mechanisms for SuperAdmin.
 */

if (!function_exists('get_backup_storage_dir')) {
    function get_backup_storage_dir() {
        $base = dirname(__DIR__);
        $dir = $base . '/superadmin/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);

        // Protect backup directory against direct unauthorized web access
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "# Deny all direct web access to raw backup files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php header('Location: ../backups.php'); exit;\n");
        }
        $real = realpath($dir);
        return $real ? str_replace('\\', '/', $real) : str_replace('\\', '/', $dir);
    }
}

if (!function_exists('format_backup_bytes')) {
    function format_backup_bytes($bytes) {
        $bytes = (float)$bytes;
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes, 0) . ' B';
    }
}

if (!function_exists('prune_system_backups')) {
    /**
     * Retains strictly the newest $keep_count backups and automatically deletes older ones.
     */
    function prune_system_backups($target_dir = null, $keep_count = 30) {
        $dir = $target_dir ?: get_backup_storage_dir();
        if (!is_dir($dir)) return 0;

        $files = glob($dir . '/*.sql');
        if (!$files || count($files) <= $keep_count) {
            return 0;
        }

        // Sort files by modification time descending (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $deleted_count = 0;
        for ($i = $keep_count; $i < count($files); $i++) {
            if (is_file($files[$i])) {
                if (@unlink($files[$i])) {
                    $deleted_count++;
                }
            }
        }
        return $deleted_count;
    }
}

if (!function_exists('create_system_database_backup')) {
    /**
     * Generates a complete database .sql dump and saves to the backups folder.
     */
    function create_system_database_backup($target_dir = null, $type_label = 'automated') {
        global $pdo, $db, $host, $user, $pass;
        
        @set_time_limit(1800);
        @ini_set('memory_limit', '1024M');

        $dir = $target_dir ?: get_backup_storage_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);

        // Check if directory is writable
        if (!is_writable($dir)) {
            return [
                'success' => false,
                'error' => "Storage folder is not writable by web server.<br>Please run in VPS terminal:<br><code style='user-select:all; display:block; padding:8px; background:#1e293b; color:#38bdf8; border-radius:6px; margin-top:6px; font-weight:bold;'>chown -R www-data:www-data " . htmlspecialchars($dir) . " && chmod -R 777 " . htmlspecialchars($dir) . "</code>"
            ];
        }

        $db_name = $db ?? 'radius';
        $now_str = date('Y-m-d_H-i-s');
        $filename = "db_backup_{$db_name}_{$now_str}.sql";
        $filepath = $dir . '/' . $filename;

        $dump_success = false;

        // 1. Try mysqldump on Linux/Unix systems if exec is available
        $is_windows = (DIRECTORY_SEPARATOR === '\\');
        if (!$is_windows && function_exists('exec')) {
            $db_host = $host ?? '127.0.0.1';
            $db_user = $user ?? 'radius';
            $db_pass = $pass ?? 'Wasi1234';
            $pass_arg = (!empty($db_pass)) ? " -p" . escapeshellarg($db_pass) : "";

            $cmd = "mysqldump -h " . escapeshellarg($db_host) . 
                   " -u " . escapeshellarg($db_user) . 
                   $pass_arg . 
                   " " . escapeshellarg($db_name) . 
                   " --single-transaction --routines --triggers --quick > " . escapeshellarg($filepath) . " 2>&1";
            
            $output = [];
            $ret_code = 0;
            @exec($cmd, $output, $ret_code);

            if ($ret_code === 0 && file_exists($filepath) && filesize($filepath) > 1000) {
                $dump_success = true;
                @chmod($filepath, 0666);
            } else {
                // If mysqldump failed, delete any error log file created at $filepath
                if (file_exists($filepath)) {
                    @unlink($filepath);
                }
            }
        }

        // 2. High-performance PHP Fallback Dumper (Chunked & Transaction Safe)
        if (!$dump_success) {
            if (!$pdo) {
                if (file_exists(__DIR__ . '/db.php')) {
                    require_once __DIR__ . '/db.php';
                }
            }

            if (!$pdo) {
                return ['success' => false, 'error' => 'Database connection unavailable for backup generation.'];
            }

            $fp = @fopen($filepath, 'w');
            if (!$fp) {
                return [
                    'success' => false,
                    'error' => "Cannot write backup file to storage directory ({$filepath}).<br>Please run in VPS terminal:<br><code style='user-select:all; display:block; padding:8px; background:#1e293b; color:#38bdf8; border-radius:6px; margin-top:6px; font-weight:bold;'>chown -R www-data:www-data " . htmlspecialchars($dir) . " && chmod -R 777 " . htmlspecialchars($dir) . "</code>"
                ];
            }

            try {
                fwrite($fp, "-- ========================================================\n");
                fwrite($fp, "-- SB Link Network / NetBee - Full Database Backup\n");
                fwrite($fp, "-- Database: `" . $db_name . "`\n");
                fwrite($fp, "-- Backup Type: " . ucfirst($type_label) . "\n");
                fwrite($fp, "-- Generated At: " . date('Y-m-d H:i:s') . "\n");
                fwrite($fp, "-- Server Host: " . php_uname('n') . " (" . php_uname('s') . ")\n");
                fwrite($fp, "-- ========================================================\n\n");

                fwrite($fp, "SET NAMES utf8mb4;\n");
                fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n");
                fwrite($fp, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

                // Get all base tables
                $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                $tables = $tablesStmt->fetchAll(PDO::FETCH_NUM);

                foreach ($tables as $tRow) {
                    $table = $tRow[0];
                    fwrite($fp, "-- --------------------------------------------------------\n");
                    fwrite($fp, "-- Table structure for table `{$table}`\n");
                    fwrite($fp, "-- --------------------------------------------------------\n");
                    fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");

                    $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                    if (!empty($createStmt[1])) {
                        fwrite($fp, $createStmt[1] . ";\n\n");
                    }

                    // Dump rows in 300-row batches
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                    if ($cnt > 0) {
                        fwrite($fp, "-- Dumping data for table `{$table}` ({$cnt} records)\n");
                        $batchSize = 300;
                        for ($offset = 0; $offset < $cnt; $offset += $batchSize) {
                            $rows = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($rows)) {
                                $colNames = array_keys($rows[0]);
                                $escapedCols = implode(", ", array_map(function($c) { return "`" . str_replace("`", "``", $c) . "`"; }, $colNames));

                                $valList = [];
                                foreach ($rows as $r) {
                                    $rowVals = [];
                                    foreach ($r as $val) {
                                        $rowVals[] = ($val === null) ? 'NULL' : $pdo->quote($val);
                                    }
                                    $valList[] = "(" . implode(", ", $rowVals) . ")";
                                }
                                fwrite($fp, "INSERT INTO `{$table}` ({$escapedCols}) VALUES\n" . implode(",\n", $valList) . ";\n");
                            }
                        }
                        fwrite($fp, "\n");
                    }
                }

                fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
                fwrite($fp, "-- Backup completed successfully.\n");
                fclose($fp);
                $dump_success = true;
            } catch (Throwable $ePhpDump) {
                fclose($fp);
                @unlink($filepath);
                return ['success' => false, 'error' => "PHP SQL Dumper failed: " . $ePhpDump->getMessage()];
            }
        }

        if ($dump_success && file_exists($filepath)) {
            @chmod($filepath, 0666);
            $fsize = filesize($filepath);
            // Prune to strictly keep only the 30 newest backups
            $pruned = prune_system_backups($dir, 30);

            // Log activity if activity_logs exists
            try {
                if ($pdo) {
                    $logText = "Database backup created: {$filename} (" . format_backup_bytes($fsize) . "). Retained latest 30 backups.";
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) 
                        VALUES (0, 'System/Cron', 'SuperAdmin', 'DATABASE', 'System', ?)")->execute([$logText]);
                }
            } catch (Throwable $eLog) {}

            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'filesize' => $fsize,
                'size_str' => format_backup_bytes($fsize),
                'pruned_count' => $pruned
            ];
        }

        return ['success' => false, 'error' => 'Unknown error while generating backup file.'];
    }
}

if (!function_exists('get_system_backups_list')) {
    /**
     * Scans and returns all stored backups ordered newest first (max 30).
     */
    function get_system_backups_list($target_dir = null) {
        $dir = $target_dir ?: get_backup_storage_dir();
        if (!is_dir($dir)) return [];

        $files = glob($dir . '/*.sql');
        if (!$files) return [];

        // Sort descending by file modification time
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Limit to 30
        $files = array_slice($files, 0, 30);

        $list = [];
        foreach ($files as $f) {
            $filename = basename($f);
            $mtime = filemtime($f);
            $size = filesize($f);

            // Try to extract date from filename (e.g. db_backup_radius_2026-11-04_00-00-00.sql)
            $date_str = date('Y-m-d H:i:s', $mtime);
            if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})/', $filename, $m)) {
                $date_str = "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
            }

            $list[] = [
                'filename' => $filename,
                'filepath' => $f,
                'filesize' => $size,
                'size_str' => format_backup_bytes($size),
                'created_at' => $date_str,
                'mtime' => $mtime
            ];
        }

        return $list;
    }
}
