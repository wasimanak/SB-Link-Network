<?php
require_once 'config/db.php';

echo "<h2>Fixing RADIUS NAS Configuration</h2>";

try {
    // 1. Remove ports from nasname (e.g. 223.123.43.82:8830 -> 223.123.43.82)
    $stmt = $pdo->query("SELECT id, nasname FROM nas WHERE nasname LIKE '%:%'");
    $invalidNas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($invalidNas as $nas) {
        $ipOnly = explode(':', $nas['nasname'])[0];
        $pdo->prepare("UPDATE nas SET nasname = ? WHERE id = ?")->execute([$ipOnly, $nas['id']]);
        echo "<span style='color:green;'>Fixed invalid IP (removed port): {$nas['nasname']} -> $ipOnly</span><br>";
    }

    // 2. Remove exact duplicates
    $pdo->exec("
        DELETE n1 FROM nas n1
        INNER JOIN nas n2 
        WHERE n1.id > n2.id AND n1.nasname = n2.nasname
    ");
    echo "<span style='color:green;'>Removed duplicate NAS entries successfully.</span><br>";
    
} catch (Exception $e) {
    echo "<span style='color:red;'>Error fixing NAS table: " . $e->getMessage() . "</span><br>";
}

echo "<h3>✅ Database Fix Complete!</h3>";
echo "<b>CRITICAL NEXT STEP:</b> You MUST restart your VPS Server from your Hosting Panel for these changes to take effect, or restart FreeRADIUS manually if you have SSH access.";
?>
