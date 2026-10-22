<?php
echo "<h2>FreeRADIUS Server Log Viewer</h2>";

$log_paths = [
    '/var/log/freeradius/radius.log',
    '/var/log/radius/radius.log',
    '/var/log/freeradius/radius.log.1'
];

$found = false;
foreach ($log_paths as $path) {
    if (file_exists($path) && is_readable($path)) {
        echo "<h4>Reading: $path</h4>";
        $lines = array_slice(file($path), -40);
        echo "<pre style='background:#1e1e1e; color:#00ff00; padding:15px; border-radius:8px; overflow-x:auto;'>";
        echo htmlspecialchars(implode("", $lines));
        echo "</pre>";
        $found = true;
        break;
    }
}

if (!$found) {
    echo "<p>Direct log file read failed (permissions). Trying system commands...</p>";
    $cmd_output = shell_exec('tail -n 40 /var/log/freeradius/radius.log 2>&1');
    if (!$cmd_output || strpos($cmd_output, 'Permission denied') !== false) {
        $cmd_output = shell_exec('sudo tail -n 40 /var/log/freeradius/radius.log 2>&1');
    }
    if ($cmd_output) {
        echo "<pre style='background:#1e1e1e; color:#00ff00; padding:15px; border-radius:8px; overflow-x:auto;'>";
        echo htmlspecialchars($cmd_output);
        echo "</pre>";
    } else {
        echo "<h3 style='color:red;'>Could not read log directly. Please follow the Winbox Logging instructions below.</h3>";
    }
}
?>
