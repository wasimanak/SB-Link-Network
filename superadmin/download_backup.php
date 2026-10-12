<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// Prevent timeouts and memory bottlenecks during large dumps
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$database_name = $db ?? 'radius';
$filename = 'sblink_backup_' . $database_name . '_' . date('Y-m-d_H-i-s') . '.sql';

// Set download headers
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Output SQL Dump Header
echo "-- ========================================================\n";
echo "-- SB Link Network - Complete Database Backup\n";
echo "-- Database: `" . $database_name . "`\n";
echo "-- Generated At: " . date('Y-m-d H:i:s') . "\n";
echo "-- Server OS: " . php_uname('s') . "\n";
echo "-- ========================================================\n\n";

echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n";
echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

try {
    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "-- --------------------------------------------------------\n";
        echo "-- Table structure for table `{$table}`\n";
        echo "-- --------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";

        // Create table SQL
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        if (!empty($createStmt[1])) {
            echo $createStmt[1] . ";\n\n";
        }

        // Table Data Dumping in Chunks
        echo "-- Dumping data for table `{$table}`\n";

        $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $rowCount = (int)$countStmt->fetchColumn();

        if ($rowCount > 0) {
            $batchSize = 250;
            for ($offset = 0; $offset < $rowCount; $offset += $batchSize) {
                $rowsStmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}");
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

                    echo "INSERT INTO `{$table}` ({$colsSql}) VALUES\n" . implode(",\n", $insertRows) . ";\n";
                }
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "-- Backup completed successfully.\n";
    exit;

} catch (Exception $e) {
    echo "\n-- ERROR DURING BACKUP: " . $e->getMessage() . "\n";
    exit;
}
