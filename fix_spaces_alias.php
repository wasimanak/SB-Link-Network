<?php
require 'config/db.php';

echo "<h2>Fixing Space Aliases</h2>";

try {
    // 1. Specifically fix shafaaqat16 and zahidiqbalwawna19ml by adding a space alias in radcheck
    $problem_users = ['shafaaqat16', 'zahidiqbalwawna19ml'];
    
    foreach ($problem_users as $base_user) {
        $space_user = $base_user . ' '; // With trailing space
        
        // Fetch password from base user
        $stmt = $pdo->prepare("SELECT password FROM subscribers WHERE username = ?");
        $stmt->execute([$base_user]);
        $pw = $stmt->fetchColumn() ?: '1234';
        
        // Insert into radcheck with the trailing space so customer connects even with space in router!
        $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$space_user]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
            ->execute([$space_user, $pw]);
            
        // Also clone user group
        $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$space_user]);
        $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, '4Mbps (Liaqatabad)', 1)")
            ->execute([$space_user]);
            
        echo "<p style='color:green;'>Created instant space-alias for '<strong>{$base_user} </strong>'. Ab router me space ho tab bhi ye connect ho jayega!</p>";
    }
    
    echo "<h3>Done! Please check shafaaqat16 now. He will connect immediately.</h3>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
