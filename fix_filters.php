<?php
$files = ['operator/subscribers.php', 'dealer/subscribers.php'];

foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        
        $old_sql_block = '$sql = "SELECT s.*, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
        (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip,
        (SELECT MAX(acctstarttime) FROM radacct r WHERE r.username = s.username) as last_on_time,
        (SELECT MAX(acctstoptime) FROM radacct r WHERE r.username = s.username) as last_off_time,
        (SELECT SUM(acctinputoctets + acctoutputoctets) FROM radacct r WHERE r.username = s.username) as total_usage_bytes,
        (SELECT SUM(acctsessiontime) FROM radacct r WHERE r.username = s.username) as total_time_sec,
        (SELECT nasipaddress FROM radacct r WHERE r.username = s.username ORDER BY radacctid DESC LIMIT 1) as nas_ip
        FROM subscribers s 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.client_id = ? 
        ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);';

        // Some spacing might be different, so let's do it via regex
        $pattern = '/\$sql = "SELECT s\.\*, p\.name as package_name.*?WHERE s\.([a-z_]+) = \?.*?ORDER BY s\.id DESC";\s*\$stmt = \$pdo->prepare\(\$sql\);\s*\$stmt->execute\(\[\$([a-z_]+)\]\);/s';
        
        $new_sql_block = '
// Handle Filter
$filter = $_GET[\'filter\'] ?? \'\';
$filter_sql = "";
$params = [$client_id]; // Wait, for dealer it might be $dealer_id. Let\'s capture that dynamically from regex.
';

    }
}
?>
