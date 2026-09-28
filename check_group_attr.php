<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
$groups = $pdo->query("SELECT groupname FROM radusergroup WHERE username IN ('rrr', 'qqq')")->fetchAll(PDO::FETCH_COLUMN);
if ($groups) {
    $in = str_repeat('?,', count($groups) - 1) . '?';
    $chk = $pdo->prepare("SELECT * FROM radgroupcheck WHERE groupname IN ($in)");
    $chk->execute($groups);
    echo "GroupCheck:\n";
    print_r($chk->fetchAll(PDO::FETCH_ASSOC));
    
    $rep = $pdo->prepare("SELECT * FROM radgroupreply WHERE groupname IN ($in)");
    $rep->execute($groups);
    echo "GroupReply:\n";
    print_r($rep->fetchAll(PDO::FETCH_ASSOC));
}
?>
