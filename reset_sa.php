<?php
require 'config/db.php';

$stmt = $pdo->query("SELECT * FROM super_admins LIMIT 1");
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    // Reset password to admin123
    $new_pass = 'admin123';
    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
    
    $update = $pdo->prepare("UPDATE super_admins SET password = ? WHERE id = ?");
    $update->execute([$hashed, $admin['id']]);
    
    echo "<h1>Super Admin Recovery</h1>";
    echo "<p><strong>Name:</strong> " . htmlspecialchars($admin['name']) . "</p>";
    echo "<p><strong>Username:</strong> " . htmlspecialchars($admin['username']) . "</p>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($admin['email']) . "</p>";
    echo "<p><strong>New Password:</strong> admin123</p>";
    echo "<br><p>Please go to <a href='superadmin/login.php'>superadmin/login.php</a> to login using these credentials.</p>";
    echo "<p style='color:red;'><b>IMPORTANT:</b> Delete this file (reset_sa.php) after you log in, for security!</p>";
} else {
    echo "No Super Admin found in the database! Did you run the initial setup?";
}
?>
