<?php
$root = 'C:/xampp/htdocs/SB Link Network/operator/';

// 1. subscriber_add.php
$add_file = $root . 'subscriber_add.php';
$add_content = file_get_contents($add_file);

$old_add = <<<'PHP'
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                    ->execute([$username, $pkg['rate_limit']]);
PHP;

$new_add = <<<'PHP'
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
                    ->execute([$username, $pkg['name']]);
                
                // Only insert custom rate limit into radreply if it's strictly required, 
                // but usually the group handles it. If not unlimited, we could insert, but let's rely on group.
PHP;
$add_content = str_replace($old_add, $new_add, $add_content);
file_put_contents($add_file, $add_content);

// 2. subscriber_edit.php
$edit_file = $root . 'subscriber_edit.php';
$edit_content = file_get_contents($edit_file);

$old_edit = <<<'PHP'
                $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")
                    ->execute([$pkg['rate_limit'], $username]);
PHP;

$new_edit = <<<'PHP'
                // Update Group instead of radreply
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
                    ->execute([$username, $pkg['name']]);
                
                // Also remove any rogue rate limits that might have been saved in radreply
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$username]);
PHP;
$edit_content = str_replace($old_edit, $new_edit, $edit_content);
file_put_contents($edit_file, $edit_content);

echo "Subscriber Add/Edit fixed.\n";
?>
