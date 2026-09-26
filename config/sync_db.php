<?php
// Script to copy DB structure and data from Windows to Ubuntu
$local_dsn = 'mysql:host=127.0.0.1;dbname=radius_admin;charset=utf8mb4';
$ubuntu_dsn = 'mysql:host=192.168.137.169;dbname=radius_admin;charset=utf8mb4';
$user = 'syncuser';
$local_user = 'root';
$local_pass = '';
$ubuntu_pass = 'admin123';

try {
    $local = new PDO($local_dsn, $local_user, $local_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ubuntu = new PDO($ubuntu_dsn, $user, $ubuntu_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Get all tables
    $tables = $local->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        // Drop and Recreate in Ubuntu
        $ubuntu->exec("DROP TABLE IF EXISTS `$table`");
        
        $createStmt = $local->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createStmt['Create Table'];
        
        // Remove foreign key constraints temporarily for smooth insert
        $createSql = preg_replace('/CONSTRAINT `.*` FOREIGN KEY \(`.*`\) REFERENCES `.*` \(`.*`\)/', '', $createSql);
        $createSql = rtrim($createSql, ",\n ") . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        // We will just execute the raw Create Table
        $ubuntu->exec($createStmt['Create Table']);
        
        // Copy Data
        $data = $local->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if ($data) {
            $cols = array_keys($data[0]);
            $colNames = "`" . implode("`, `", $cols) . "`";
            $placeholders = rtrim(str_repeat('?, ', count($cols)), ', ');
            $insertSql = "INSERT INTO `$table` ($colNames) VALUES ($placeholders)";
            
            $stmt = $ubuntu->prepare($insertSql);
            foreach ($data as $row) {
                $stmt->execute(array_values($row));
            }
        }
        echo "Synced table: $table\n";
    }
    echo "SUCCESS: Database fully synced to Ubuntu!\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
