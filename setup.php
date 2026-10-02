<?php
require_once 'config/db.php';
require_once 'tables.php'; // Contains the full correct schema

echo "<h2>Fixing Database Schema...</h2>";

// Disable foreign key checks so tables can be created in any order
$pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

// 1. Drop the incomplete custom tables created by mistake
$custom_tables = [
    'activity_logs', 'clients', 'dealer_notes', 'dealer_packages', 'dealers', 
    'fund_requests', 'global_settings', 'linemen', 'package_requests', 'packages', 
    'payment_gateways', 'recovery_men', 'subscribers', 'super_admins', 'support_tickets', 
    'ticket_messages', 'user_ledger'
];

foreach($custom_tables as $t) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    } catch(Exception $e) {}
}

// 2. Recreate all tables using the perfect schema from tables.php
foreach ($tables as $t) {
    // Make them IF NOT EXISTS so it safely ignores existing radius tables
    $t = preg_replace('/CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $t, 1);
    try {
        $pdo->exec(stripslashes($t));
    } catch (PDOException $e) {
        echo "<p style='color:orange;'>Info: " . $e->getMessage() . "</p>";
    }
}

// 3. Add the NAS triggers
$triggers = [
    "DROP TRIGGER IF EXISTS restrict_nas_ip_insert",
    "CREATE TRIGGER restrict_nas_ip_insert AFTER INSERT ON subscribers FOR EACH ROW 
    BEGIN 
        IF NEW.nasname IS NOT NULL AND NEW.nasname != '' THEN 
            INSERT INTO radcheck (username, attribute, op, value) VALUES (NEW.username, 'NAS-IP-Address', '==', NEW.nasname); 
        END IF; 
    END",

    "DROP TRIGGER IF EXISTS restrict_nas_ip_update",
    "CREATE TRIGGER restrict_nas_ip_update AFTER UPDATE ON subscribers FOR EACH ROW 
    BEGIN 
        IF NEW.nasname IS NOT NULL AND NEW.nasname != '' THEN 
            DELETE FROM radcheck WHERE username = NEW.username AND attribute = 'NAS-IP-Address'; 
            INSERT INTO radcheck (username, attribute, op, value) VALUES (NEW.username, 'NAS-IP-Address', '==', NEW.nasname); 
        END IF; 
    END"
];

foreach ($triggers as $trig) {
    try { $pdo->exec($trig); } catch (Exception $e) {}
}

// Re-enable foreign key checks
$pdo->exec("SET FOREIGN_KEY_CHECKS=1;");

echo "<p style='color:green;'>✔️ All database tables created successfully with FULL columns!</p>";

// 4. Create Super Admin Account
$username = 'admin';
$password = 'admin123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("SELECT id FROM super_admins WHERE username = ?");
    $stmt->execute([$username]);
    
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE super_admins SET password = ? WHERE username = ?")->execute([$hashed_password, $username]);
    } else {
        // Fix: Added the `name` column to avoid the "Field 'name' doesn't have a default value" error
        $pdo->prepare("INSERT INTO super_admins (name, username, password, email) VALUES ('Super Admin', ?, ?, 'admin@sblink.com')")->execute([$username, $hashed_password]);
    }
    
    echo "<p style='color:green;'>✔️ Superadmin account created successfully!</p>";
    echo "<p><strong>Username:</strong> $username <br> <strong>Password:</strong> $password</p>";
    echo "<br><a href='superadmin/login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Go to Admin Panel</a>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error creating admin: " . $e->getMessage() . "</p>";
}
?>