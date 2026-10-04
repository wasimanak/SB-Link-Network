<?php
$f = 'login.php';
$c = file_get_contents($f);

$oldLogic = '$_SESSION[\'recovery_id\'] = $user[\'id\'];
                        $_SESSION[\'client_id\'] = $user[\'client_id\'];
                        $_SESSION[\'recovery_name\'] = $user[\'full_name\'];';
                        
$newLogic = '$_SESSION[\'rm_id\'] = $user[\'id\'];
                        $_SESSION[\'client_id\'] = $user[\'client_id\'];
                        $_SESSION[\'rm_name\'] = $user[\'full_name\'];';

if (strpos($c, 'recovery_id') !== false) {
    $c = str_replace($oldLogic, $newLogic, $c);
    file_put_contents($f, $c);
    echo "Fixed session variables in global login.php\n";
} else {
    echo "Already fixed.\n";
}

// Ensure the old recoveryman/login.php file is deleted or stubbed out
$rmLogin = 'recoveryman/login.php';
if (file_exists($rmLogin)) {
    file_put_contents($rmLogin, '<?php header("Location: ../login.php?role=recoveryman"); exit; ?>');
    echo "Disabled dedicated RM login page, redirecting to global login.\n";
}

?>
