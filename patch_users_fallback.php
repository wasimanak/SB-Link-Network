<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

$old = '$assigned_nas_ips = array_filter(explode(\',\', $current_dealer[\'assigned_routers\'] ?? \'\'));
$dealer_routers = [];
if (!empty($assigned_nas_ips)) {
    $in = str_repeat(\'?,\', count($assigned_nas_ips) - 1) . \'?\';
    $rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in)");
    $rStmt->execute($assigned_nas_ips);
    $dealer_routers = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}';

$new = '$raw_routers = explode(\',\', $current_dealer[\'assigned_routers\'] ?? \'\');
$assigned_nas_ips = [];
foreach($raw_routers as $rr) {
    $val = trim($rr);
    if($val !== \'\') $assigned_nas_ips[] = $val;
}

$dealer_routers = [];
if (!empty($assigned_nas_ips)) {
    $in = str_repeat(\'?,\', count($assigned_nas_ips) - 1) . \'?\';
    // If the value contains a dot (e.g. an IP address), we search by nasname for backwards compatibility,
    // otherwise we search by id. We\'ll just check both to be absolutely safe against cache issues.
    $rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in) OR nasname IN ($in)");
    $params = array_merge($assigned_nas_ips, $assigned_nas_ips);
    $rStmt->execute($params);
    $dealer_routers = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // De-duplicate routers by ID just in case
    $unique_routers = [];
    foreach($dealer_routers as $r) {
        $unique_routers[$r[\'id\']] = $r;
    }
    $dealer_routers = array_values($unique_routers);
}';

if (strpos($c, 'array_merge') === false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "dealer/users.php patched to support both ID and IP gracefully\n";
} else {
    echo "Already patched\n";
}
?>
