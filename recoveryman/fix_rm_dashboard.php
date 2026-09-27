<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

// 1. Fix the SQL Query
$old_query = "SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name\n        FROM subscribers s\n        LEFT JOIN packages p ON s.package_id = p.id\n        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ?) AND s.client_id = ?\n        LIMIT 20";

$new_query = "SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name,
               (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
               (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
               (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ?) AND s.client_id = ?
        LIMIT 20";

$content = str_replace($old_query, $new_query, $content);

// 2. Add formatBytes() if it doesn't exist.
if (strpos($content, 'function formatBytes') === false) {
    $function_def = "
function formatBytes(\$bytes) {
    if (\$bytes <= 0) return \"0 MB\";
    \$bytes = \$bytes / (1024 * 1024);
    if (\$bytes > 1024) return round(\$bytes/1024, 2) . ' GB';
    return round(\$bytes, 2) . ' MB';
}
?>";
    // Replace the LAST `?>` before `<!DOCTYPE html>`
    $content = preg_replace('/\?>\s*<!DOCTYPE html>/s', $function_def . "\n<!DOCTYPE html>", $content);
}

file_put_contents($file, $content);
echo "Fixed query and formatBytes function.\n";
?>
