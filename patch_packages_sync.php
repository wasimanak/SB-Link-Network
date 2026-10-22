<?php
$f = 'operator/packages.php';
$c = file_get_contents($f);

// 1. Sync on Create
$old_create = '$stmt->execute([$client_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler]);';
$new_create = '$stmt->execute([$client_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler]);
            
            // Sync to FreeRADIUS radgroupreply
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute = \'Mikrotik-Rate-Limit\'")->execute([$name]);
            if (!empty($rate_limit) && $rate_limit !== \'Unlimited\') {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, \'Mikrotik-Rate-Limit\', \':=\', ?)")->execute([$name, $rate_limit]);
            }';

if (strpos($c, '// Sync to FreeRADIUS radgroupreply') === false) {
    $c = str_replace($old_create, $new_create, $c);
}

// 2. Sync on Update
$old_update = '$stmt->execute([$name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $id, $client_id]);';
$new_update = '$stmt->execute([$name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $id, $client_id]);
            
            // Sync to FreeRADIUS radgroupreply
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute = \'Mikrotik-Rate-Limit\'")->execute([$name]);
            if (!empty($rate_limit) && $rate_limit !== \'Unlimited\') {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, \'Mikrotik-Rate-Limit\', \':=\', ?)")->execute([$name, $rate_limit]);
            }';

if (strpos($c, '$new_update') === false) {
    $c = str_replace($old_update, $new_update, $c);
}

file_put_contents($f, $c);
echo "operator/packages.php patched to automatically sync radgroupreply on create and update.\n";
?>
