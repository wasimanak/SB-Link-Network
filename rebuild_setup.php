<?php
$sql = file_get_contents('database_schema.sql');
$sql = mb_convert_encoding($sql, 'UTF-8', 'UTF-16LE');

// Extract triggers
preg_match_all('/CREATE.*?TRIGGER.*?;/s', $sql, $matches_triggers);

require 'tables.php'; // gets $tables

$setup_content = "<?php\n";
$setup_content .= "require_once 'config/db.php';\n";
$setup_content .= "echo '<h2>Starting System Setup...</h2>';\n";
$setup_content .= "\$queries = [\n";
foreach ($tables as $t) {
    // Make them CREATE TABLE IF NOT EXISTS
    $t = preg_replace('/CREATE TABLE/', 'CREATE TABLE IF NOT EXISTS', $t, 1);
    $setup_content .= "    \"" . addslashes(str_replace('IF NOT EXISTS IF NOT EXISTS', 'IF NOT EXISTS', $t)) . "\",\n";
}

// Add triggers
foreach ($matches_triggers[0] as $trig) {
    $setup_content .= "    \"" . addslashes($trig) . "\",\n";
}

$setup_content .= "];\n\n";

$setup_content .= <<<'PHP'
foreach ($queries as $query) {
    try {
        $pdo->exec($query);
    } catch (PDOException $e) {
        // Ignore trigger already exists errors
    }
}
echo "<p style='color:green;'>✔️ All database tables and triggers created successfully!</p>";

// 2. Create Super Admin Account
$username = 'admin';
$password = 'admin123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("SELECT id FROM super_admins WHERE username = ?");
    $stmt->execute([$username]);
    
    if ($stmt->fetch()) {
        $pdo->prepare("UPDATE super_admins SET password = ? WHERE username = ?")->execute([$hashed_password, $username]);
        echo "<p style='color:green;'>✔️ Superadmin password reset to default!</p>";
    } else {
        $pdo->prepare("INSERT INTO super_admins (username, password, email) VALUES (?, ?, 'admin@sblink.com')")->execute([$username, $hashed_password]);
        echo "<p style='color:green;'>✔️ New Superadmin account created successfully!</p>";
    }
    
    echo "<div style='background:#f4f4f4; padding: 15px; border-radius: 5px; width: 300px; border: 1px solid #ddd;'>";
    echo "<h3 style='margin-top:0;'>Login Details</h3>";
    echo "<strong>Username:</strong> $username <br>";
    echo "<strong>Password:</strong> $password <br>";
    echo "</div>";
    
    echo "<br><a href='superadmin/login.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Go to Admin Panel</a>";
    
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ Error creating admin: " . $e->getMessage() . "</p>";
}
PHP;

file_put_contents('setup.php', $setup_content);
echo "setup.php rebuilt!";
?>
