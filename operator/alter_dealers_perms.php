<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    
    $cols = [
        'perm_create_user' => 'TINYINT(1) DEFAULT 0',
        'perm_delete_user' => 'TINYINT(1) DEFAULT 0',
        'perm_custom_expiry' => 'TINYINT(1) DEFAULT 0'
    ];
    
    foreach($cols as $col => $def) {
        $stmt = $pdo->query("SHOW COLUMNS FROM dealers LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE dealers ADD COLUMN $col $def");
        }
    }
    
    echo "Permissions columns added successfully.";
} catch (Exception $e) {
    echo $e->getMessage();
}
