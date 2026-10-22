<?php
$output = "<h2>FreeRADIUS Log Output</h2>";

// Paths where FreeRADIUS logs usually live on Linux
$logPaths = [
    '/var/log/freeradius/radius.log',
    '/var/log/radius/radius.log',
    '/var/log/radiusd/radius.log'
];

$found = false;
foreach ($logPaths as $path) {
    if (file_exists($path) && is_readable($path)) {
        $found = true;
        $output .= "<h3>Log File Found: $path</h3>";
        // Get the last 100 lines of the log
        $lines = file($path);
        $lastLines = array_slice($lines, -100);
        $output .= "<pre style='background: #111; color: #0f0; padding: 10px; overflow-x: auto;'>";
        foreach ($lastLines as $line) {
            $output .= htmlspecialchars($line);
        }
        $output .= "</pre>";
        break;
    }
}

if (!$found) {
    $output .= "<p>Could not read FreeRADIUS logs directly. They might be protected or in a different location.</p>";
    $output .= "<h3>Attempting systemctl status...</h3>";
    $status = shell_exec('systemctl status freeradius 2>&1');
    if (!$status) {
        $status = shell_exec('systemctl status radiusd 2>&1');
    }
    $output .= "<pre style='background: #111; color: #0f0; padding: 10px; overflow-x: auto;'>" . htmlspecialchars($status) . "</pre>";
}

echo $output;
?>
