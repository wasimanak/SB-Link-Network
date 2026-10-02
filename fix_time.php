<?php
require_once 'config/db.php';

echo "<h2>Fixing FreeRADIUS Accounting Time Format...</h2>";

try {
    // Modify the radacct table to accept integer timestamps from FreeRADIUS
    $query = "ALTER TABLE radacct 
              MODIFY acctstarttime BIGINT, 
              MODIFY acctupdatetime BIGINT, 
              MODIFY acctstoptime BIGINT";
              
    $pdo->exec($query);
    
    echo "<h1 style='color: green;'>✅ Database Time Columns Fixed Successfully!</h1>";
    echo "<p>The database will now accept the time format sent by MikroTik/FreeRADIUS.</p>";
    echo "<p><b>Next Step:</b> Please restart (reboot) your MikroTik router to clear the old jammed errors. New connections will now work perfectly.</p>";

} catch (\PDOException $e) {
    echo "<h1 style='color: red;'>❌ Error Fixing Database</h1>";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
}
?>
