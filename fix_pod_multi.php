<?php
$f = 'operator/subscriber_action.php';
$c = file_get_contents($f);

// We need to fetch the nasipaddress from radacct for this user's live session
$oldLogic = '// Fetch NAS info for PoD
        $nasStmt = $pdo->prepare("SELECT nasname, coa_port, secret FROM nas WHERE client_id = ? LIMIT 1");
        $nasStmt->execute([$client_id]);
        $nas = $nasStmt->fetch();';
        
$newLogic = '// Fetch exact NAS info for PoD based on the active session
        $nasStmt = $pdo->prepare("SELECT n.nasname, n.coa_port, n.secret FROM nas n 
                                JOIN radacct r ON n.nasname = r.nasipaddress 
                                WHERE r.username = ? AND n.client_id = ? AND r.acctstoptime IS NULL LIMIT 1");
        $nasStmt->execute([$username, $client_id]);
        $nas = $nasStmt->fetch();
        
        // Fallback to first NAS if session not found
        if (!$nas) {
            $fallback = $pdo->prepare("SELECT nasname, coa_port, secret FROM nas WHERE client_id = ? LIMIT 1");
            $fallback->execute([$client_id]);
            $nas = $fallback->fetch();
        }';

if (strpos($c, 'n.nasname = r.nasipaddress') === false) {
    $c = str_replace($oldLogic, $newLogic, $c);
    file_put_contents($f, $c);
    echo "PoD logic updated for multi-router.\n";
} else {
    echo "Already updated.\n";
}
?>
