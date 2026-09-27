<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    
    // Add missing columns to dealers table
    $columns = [
        'national_id' => 'VARCHAR(50)',
        'email' => 'VARCHAR(100)',
        'phone' => 'VARCHAR(50)',
        'address' => 'TEXT',
        'city' => 'VARCHAR(50)'
    ];
    
    foreach ($columns as $col => $type) {
        $stmt = $pdo->query("SHOW COLUMNS FROM dealers LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE dealers ADD COLUMN $col $type");
        }
    }
    echo "Columns added successfully.";
} catch (Exception $e) {
    echo $e->getMessage();
}
