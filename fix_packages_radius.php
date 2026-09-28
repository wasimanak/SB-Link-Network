<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($file);

$old_add = <<<'PHP'
            $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $name, $rate, $validity, $price, $data, $schedJson]);
PHP;

$new_add = <<<'PHP'
            $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $name, $rate, $validity, $price, $data, $schedJson]);
            
            // Add to FreeRADIUS
            if (strcasecmp($rate, 'unlimited') !== 0 && !empty($rate)) {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$name, $rate]);
            }
            $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', 'pool1')")->execute([$name]);
PHP;
$content = str_replace($old_add, $new_add, $content);

$old_edit = <<<'PHP'
            $stmt = $pdo->prepare("UPDATE packages SET name=?, rate_limit=?, validity_days=?, price=?, data_limit_gb=?, speed_scheduler=? WHERE id=? AND client_id=?");
            $stmt->execute([$name, $rate, $validity, $price, $data, $schedJson, $edit_id, $client_id]);
PHP;

$new_edit = <<<'PHP'
            $stmt = $pdo->prepare("UPDATE packages SET name=?, rate_limit=?, validity_days=?, price=?, data_limit_gb=?, speed_scheduler=? WHERE id=? AND client_id=?");
            $stmt->execute([$name, $rate, $validity, $price, $data, $schedJson, $edit_id, $client_id]);
            
            // Update FreeRADIUS
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")->execute([$name]);
            if (strcasecmp($rate, 'unlimited') !== 0 && !empty($rate)) {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$name, $rate]);
            }
            $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', 'pool1')")->execute([$name]);
PHP;
$content = str_replace($old_edit, $new_edit, $content);

file_put_contents($file, $content);
echo "packages.php updated for RADIUS compatibility.\n";
?>
