<?php
$user = trim(shell_exec('whoami'));
echo "<h2>How to Fix Permission Denied (Sudo Error)</h2>";
echo "<p>Your web server is running as the user: <b>" . htmlspecialchars($user) . "</b></p>";
echo "<p>To allow this user to restart FreeRADIUS without a password, you need to run ONE command in your Hostinger VPS Terminal.</p>";
echo "<h3>Step 1: Open Hostinger Terminal</h3>";
echo "<p>Go to your Hostinger VPS Panel and click on <b>Browser Terminal</b> (or SSH).</p>";
echo "<h3>Step 2: Copy and Paste this exact command:</h3>";
$cmd = "echo '$user ALL=(ALL) NOPASSWD: /bin/systemctl restart freeradius, /usr/bin/systemctl restart freeradius, /bin/systemctl restart radiusd, /usr/bin/systemctl restart radiusd' | sudo tee /etc/sudoers.d/freeradius_restart";
echo "<textarea style='width: 100%; height: 80px; font-family: monospace; font-size: 16px; padding: 10px;'>" . htmlspecialchars($cmd) . "</textarea>";
echo "<br><br><p>Once you paste this and press Enter, your Restart button on the dashboard will start working permanently!</p>";
?>
