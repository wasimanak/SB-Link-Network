<?php
$f = 'login.php';
$c = file_get_contents($f);

// 1. Backend Login Logic modification for Dealer
$oldDealerLogin = 'if ($role === \'dealer\') {
                    $stmt = $pdo->prepare("SELECT d.*, c.status as op_status FROM dealers d JOIN clients c ON d.client_id = c.id WHERE d.username = ? AND d.client_id = ? AND d.status = \'active\' AND c.status = \'active\'");
                    $stmt->execute([$identifier, $client_id]);
                    $user = $stmt->fetch();
                    if ($user && $user[\'password\'] === $password) {
                        $_SESSION[\'dealer_id\'] = $user[\'id\'];
                        $_SESSION[\'client_id\'] = $user[\'client_id\'];
                        header("Location: dealer/dashboard.php");
                        exit;
                    } else {
                        $error = \'Invalid Dealer credentials, or Operator is suspended.\';
                    }';
$newDealerLogin = 'if ($role === \'dealer\') {
                    // Dealer logins do not require client_id to be selected
                    $stmt = $pdo->prepare("SELECT d.*, c.status as op_status FROM dealers d JOIN clients c ON d.client_id = c.id WHERE d.username = ? AND d.status = \'active\' AND c.status = \'active\'");
                    $stmt->execute([$identifier]);
                    $user = $stmt->fetch();
                    if ($user && $user[\'password\'] === $password) {
                        $_SESSION[\'dealer_id\'] = $user[\'id\'];
                        $_SESSION[\'client_id\'] = $user[\'client_id\'];
                        header("Location: dealer/dashboard.php");
                        exit;
                    } else {
                        $error = \'Invalid Dealer credentials, or Operator is suspended.\';
                    }';

if (strpos($c, 'Dealer logins do not require') === false) {
    $c = str_replace($oldDealerLogin, $newDealerLogin, $c);
}

// 2. Hide the dropdown for Dealer in the Frontend JS
$oldJs = 'if (role === \'superadmin\' || role === \'operator\') {
                opContainer.style.display = \'none\';
                opSelect.removeAttribute(\'required\');
                
                if(role === \'superadmin\') { btnSubmit.classList.add(\'btn-superadmin\'); subtitle = "SUPER ADMIN LOGIN"; }
                if(role === \'operator\') { btnSubmit.classList.add(\'btn-operator\'); subtitle = "OPERATOR LOGIN"; }
            } else {
                opContainer.style.display = \'block\';
                opSelect.setAttribute(\'required\', \'required\');
                
                if(role === \'dealer\') { btnSubmit.classList.add(\'btn-dealer\'); subtitle = "DEALER LOGIN"; }
                if(role === \'recoveryman\') { btnSubmit.classList.add(\'btn-recoveryman\'); subtitle = "RECOVERY LOGIN"; }
                if(role === \'lineman\') { btnSubmit.classList.add(\'btn-lineman\'); subtitle = "LINE MAN LOGIN"; }
            }';

$newJs = 'if (role === \'superadmin\' || role === \'operator\' || role === \'dealer\') {
                opContainer.style.display = \'none\';
                opSelect.removeAttribute(\'required\');
                
                if(role === \'superadmin\') { btnSubmit.classList.add(\'btn-superadmin\'); subtitle = "SUPER ADMIN LOGIN"; }
                if(role === \'operator\') { btnSubmit.classList.add(\'btn-operator\'); subtitle = "OPERATOR LOGIN"; }
                if(role === \'dealer\') { btnSubmit.classList.add(\'btn-dealer\'); subtitle = "DEALER LOGIN"; }
            } else {
                opContainer.style.display = \'block\';
                opSelect.setAttribute(\'required\', \'required\');
                
                if(role === \'recoveryman\') { btnSubmit.classList.add(\'btn-recoveryman\'); subtitle = "RECOVERY LOGIN"; }
                if(role === \'lineman\') { btnSubmit.classList.add(\'btn-lineman\'); subtitle = "LINE MAN LOGIN"; }
            }';

if (strpos($c, '|| role === \'dealer\'') === false) {
    $c = str_replace($oldJs, $newJs, $c);
    file_put_contents($f, $c);
    echo "login.php updated to remove City dropdown for Dealers.\n";
} else {
    echo "login.php already updated.\n";
}
?>
