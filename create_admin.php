<?php
require_once 'config/db.php';

$username = 'admin';
$password = 'admin123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id FROM super_admins WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        // Update password if exists
        $pdo->prepare("UPDATE super_admins SET password = ? WHERE username = ?")->execute([$hashed_password, $username]);
        echo "<h1>Superadmin password reset successfully!</h1>";
    } else {
        // Insert new admin
        $pdo->prepare("INSERT INTO super_admins (username, password, email) VALUES (?, ?, 'admin@sblink.com')")->execute([$username, $hashed_password]);
        echo "<h1>Superadmin created successfully!</h1>";
    }
    echo "<p><strong>Username:</strong> $username</p>";
    echo "<p><strong>Password:</strong> $password</p>";
    echo "<p><a href='superadmin/login.php'>Go to Login Page</a></p>";
    echo "<p style='color:red;'>Make sure to delete this file (create_admin.php) after logging in for security reasons!</p>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
