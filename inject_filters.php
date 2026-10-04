<?php
function injectFilterLogic($filePath, $role) {
    if (!file_exists($filePath)) {
        echo "File $filePath does not exist.\n";
        return;
    }
    
    $c = file_get_contents($filePath);
    
    if (strpos($c, '$filter_sql = ""') !== false || strpos($c, '$filter_sql') !== false) {
        // Maybe it's already there but buggy? Or just skip and replace cleanly.
        echo "Filter variable might already be there in $filePath, replacing carefully...\n";
    }

    $client_var = ($role === 'operator') ? '$client_id' : '$dealer_id';
    
    // Find the SELECT query block.
    // In operator: WHERE s.client_id = ?
    // In dealer: WHERE s.dealer_id = ?
    
    $searchString = "WHERE s.{$role}_id = ?";
    if ($role === 'operator' && strpos($c, 'WHERE s.client_id = ?') !== false) {
        $searchString = "WHERE s.client_id = ?";
    }

    $pattern = '/\$sql = "SELECT s\.\*, p\.name as package_name.*?' . preg_quote($searchString, '/') . '.*?ORDER BY s\.id DESC";\s*\$stmt = \$pdo->prepare\(\$sql\);\s*\$stmt->execute\(\[' . preg_quote($client_var, '/') . '\]\);/s';

    $replacement = '
// Handle Filter Logic
$filter = $_GET[\'filter\'] ?? \'\';
$filter_sql = "";
$params = [' . $client_var . '];

if ($filter === \'active\') {
    $filter_sql = " AND s.status = \'active\'";
} elseif ($filter === \'expired\') {
    $filter_sql = " AND (s.status = \'expired\' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW()))";
} elseif ($filter === \'expiring_1w\') {
    $filter_sql = " AND (s.expiry_date IS NOT NULL AND s.expiry_date >= NOW() AND s.expiry_date <= DATE_ADD(NOW(), INTERVAL 7 DAY))";
} elseif ($filter === \'expiring_2w\') {
    $filter_sql = " AND (s.expiry_date IS NOT NULL AND s.expiry_date >= NOW() AND s.expiry_date <= DATE_ADD(NOW(), INTERVAL 14 DAY))";
} elseif ($filter === \'suspended\') {
    $filter_sql = " AND s.status = \'suspended\'";
} elseif ($filter === \'online\') {
    $filter_sql = " AND EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
} elseif ($filter === \'offline\') {
    $filter_sql = " AND NOT EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
}

$sql = "SELECT s.*, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
        (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip,
        (SELECT MAX(acctstarttime) FROM radacct r WHERE r.username = s.username) as last_on_time,
        (SELECT MAX(acctstoptime) FROM radacct r WHERE r.username = s.username) as last_off_time,
        (SELECT SUM(acctinputoctets + acctoutputoctets) FROM radacct r WHERE r.username = s.username) as total_usage_bytes,
        (SELECT SUM(acctsessiontime) FROM radacct r WHERE r.username = s.username) as total_time_sec,
        (SELECT nasipaddress FROM radacct r WHERE r.username = s.username ORDER BY radacctid DESC LIMIT 1) as nas_ip
        FROM subscribers s 
        LEFT JOIN packages p ON s.package_id = p.id 
        ' . $searchString . ' $filter_sql
        ORDER BY s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);';

    if (preg_match($pattern, $c)) {
        $c = preg_replace($pattern, trim($replacement), $c);
        file_put_contents($filePath, $c);
        echo "Injected filters successfully into $filePath\n";
    } else {
        echo "Could not match pattern in $filePath\n";
        // Let's dump the context to see why it didn't match.
    }
}

injectFilterLogic('operator/subscribers.php', 'operator');
injectFilterLogic('dealer/users.php', 'dealer');

?>
