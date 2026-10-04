<?php
$f = 'operator/subscriber_action.php';
$c = file_get_contents($f);

$oldCode = '            // Execute locally (requires radclient binary installed on VPS)
            // On Windows XAMPP, this will fail gracefully or output error
            $cmd = "echo $user | radclient -x $ip disconnect $secret 2>&1";
            exec($cmd, $output, $return_var);
            
            $_SESSION[\'msg\'] = "Live Kick command sent to router.";';

$newCode = '            // Execute locally (requires radclient binary installed on VPS)
            // On Windows XAMPP, this will fail gracefully or output error
            $cmd = "echo $user | radclient -x $ip disconnect $secret 2>&1";
            exec($cmd, $output, $return_var);
            
            // CRITICAL FIX: Manually clear the ghost/stuck session from radacct
            $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = \'Admin-Reset\' WHERE username = ? AND acctstoptime IS NULL")->execute([$username]);
            
            $_SESSION[\'msg\'] = "User kicked and session cleared successfully.";';

// Fix redirect to go back to where they came from (Live Sessions or Subscribers)
$oldRedirect = 'header("Location: subscribers.php");
        exit;
    }

    if ($action === \'toggle\') {';

$newRedirect = '$redirect = $_SERVER[\'HTTP_REFERER\'] ?? \'subscribers.php\';
        header("Location: " . $redirect);
        exit;
    }

    if ($action === \'toggle\') {';

if (strpos($c, 'acctterminatecause = \'Admin-Reset\'') === false) {
    $c = str_replace($oldCode, $newCode, $c);
    $c = str_replace($oldRedirect, $newRedirect, $c);
    file_put_contents($f, $c);
    echo "Fixed ghost sessions on kick.\n";
} else {
    echo "Already fixed.\n";
}
?>
