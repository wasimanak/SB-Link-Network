<?php
echo "<h2>Checking for Silent Packet Drops (NAS Issues)</h2>";

$logPaths = [
    '/var/log/freeradius/radius.log',
    '/var/log/radius/radius.log'
];

$output = "";
foreach($logPaths as $path) {
    if(file_exists($path)) {
        $output .= "<h3>Log: $path</h3>";
        
        $cmd = "tail -n 100 $path | grep -E 'Dropping|Ignoring'";
        $res = shell_exec($cmd);
        if($res) {
            $output .= "<p style='color: red;'><strong>WARNING: FreeRADIUS is STILL dropping packets! Did you restart the server?</strong></p>";
            $output .= "<pre style='background: #111; color: #f00; padding: 10px;'>" . htmlspecialchars($res) . "</pre>";
        } else {
            $output .= "<p style='color: green;'>No recent packet drops found. FreeRADIUS seems to be receiving packets correctly.</p>";
        }
        
        // Also let's check the FreeRADIUS start time to prove if it was restarted
        $cmd2 = "tail -n 500 $path | grep -i 'Ready to process requests'";
        $res2 = shell_exec($cmd2);
        if($res2) {
            $output .= "<h3>FreeRADIUS Last Restart Time:</h3>";
            $output .= "<pre style='background: #111; color: #0f0; padding: 10px;'>" . htmlspecialchars($res2) . "</pre>";
        }
    }
}

echo $output ?: "Logs not found.";
?>
