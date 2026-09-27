<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

// Replace query
$old_query = "SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ?) AND s.client_id = ?
        LIMIT 20";

$new_query = "SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name,
               (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
               (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
               (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ?) AND s.client_id = ?
        LIMIT 20";

$content = str_replace($old_query, $new_query, $content);

// Ensure formatBytes exists
if (strpos($content, 'function formatBytes') === false) {
    $func = <<<PHP
function formatBytes(\$bytes) {
    if (\$bytes <= 0) return "0 MB";
    \$bytes = \$bytes / (1024 * 1024);
    if (\$bytes > 1024) return round(\$bytes/1024, 2) . " GB";
    return round(\$bytes, 2) . " MB";
}
?>
PHP;
    $content = preg_replace('/\?>\s*<!DOCTYPE html>/s', $func . "\n<!DOCTYPE html>", $content);
}

file_put_contents($file, $content);
echo "Dashboard fixed.\n";
?>
