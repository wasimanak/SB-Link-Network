<?php
$f = 'operator/profile.php';
$c = file_get_contents($f);

$oldLogic = '$chk = $pdo->prepare("SELECT COUNT(*) FROM nas WHERE client_id = ?");
        $chk->execute([$client_id]);
        $current_routers = $chk->fetchColumn();
        
        if ($current_routers >= $user[\'max_routers\']) {';

$newLogic = '$chk = $pdo->prepare("SELECT COUNT(*) FROM nas WHERE client_id = ?");
        $chk->execute([$client_id]);
        $current_routers = $chk->fetchColumn();
        
        $uStmt = $pdo->prepare("SELECT max_routers FROM clients WHERE id = ?");
        $uStmt->execute([$client_id]);
        $max_r = $uStmt->fetchColumn() ?: 1;
        
        if ($current_routers >= $max_r) {';

if (strpos($c, 'SELECT max_routers FROM clients') === false) {
    $c = str_replace($oldLogic, $newLogic, $c);
    file_put_contents($f, $c);
    echo "Fixed fatal error caused by undefined user variable.\n";
} else {
    echo "Already fixed.\n";
}
?>
