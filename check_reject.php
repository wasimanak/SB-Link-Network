<?php
echo "<h2>Searching FreeRADIUS Log for zahidiqbalwawna19ml</h2>";

// Use grep to search the radius log for the user's rejection reason
$logPaths = [
    '/var/log/freeradius/radius.log',
    '/var/log/radius/radius.log'
];

$output = "";
foreach($logPaths as $path) {
    if(file_exists($path)) {
        $output .= "<h3>Found log at $path</h3>";
        // Search for the username in the last 1000 lines
        $cmd = "tail -n 1000 $path | grep -i 'zahidiqbalwawna19ml'";
        $res = shell_exec($cmd);
        if($res) {
            $output .= "<pre style='background: #111; color: #0f0; padding: 10px;'>" . htmlspecialchars($res) . "</pre>";
        } else {
            $output .= "<p>No entries found for this user in the recent log.</p>";
        }
        
        // Also check if there are any recent Access-Rejects for any user
        $cmd2 = "tail -n 500 $path | grep -i 'Reject'";
        $res2 = shell_exec($cmd2);
        if($res2) {
            $output .= "<h3>Recent Access-Rejects:</h3>";
            $output .= "<pre style='background: #111; color: #0f0; padding: 10px;'>" . htmlspecialchars($res2) . "</pre>";
        }
    }
}

echo $output ?: "Logs not found or not readable.";
?>
