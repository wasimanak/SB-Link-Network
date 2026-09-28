<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. Delete all invalid 'Unlimited' rate limits
$pdo->exec("DELETE FROM radreply WHERE attribute='Mikrotik-Rate-Limit' AND value='Unlimited'");
$pdo->exec("DELETE FROM radgroupreply WHERE attribute='Mikrotik-Rate-Limit' AND value='Unlimited'");

// 2. Fix radusergroup for existing subscribers (if missing)
$subs = $pdo->query("SELECT s.username, p.name as pkg_name FROM subscribers s JOIN packages p ON s.package_id = p.id")->fetchAll();
foreach ($subs as $sub) {
    // Check if exists
    $chk = $pdo->prepare("SELECT username FROM radusergroup WHERE username = ?");
    $chk->execute([$sub['username']]);
    if (!$chk->fetch()) {
        $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")
            ->execute([$sub['username'], $sub['pkg_name']]);
    }
}

echo "Cleaned up 'Unlimited' limits and synced radusergroups!\n";
?>
