<?php
require_once 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $client_id = $_SESSION['operator_id'];

    if ($action === 'kick') {
        $username = trim($_POST['username'] ?? '');
        
        // Fetch exact NAS info for PoD based on the active session
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
        }

        if ($nas && $username) {
            // PoD command formulation for radclient
            // Example: echo "User-Name=test" | radclient -x 192.168.88.1:3799 disconnect testing123
            $ip = escapeshellarg($nas['nasname'] . ":" . $nas['coa_port']);
            $secret = escapeshellarg($nas['secret']);
            $user = escapeshellarg("User-Name=" . $username);

            // Execute locally (requires radclient binary installed on VPS)
            // On Windows XAMPP, this will fail gracefully or output error
            $cmd = "echo $user | radclient -x $ip disconnect $secret 2>&1";
            exec($cmd, $output, $return_var);
            
            // CRITICAL FIX: Manually clear the ghost/stuck session from radacct
            $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL")->execute([$username]);
            
            $_SESSION['msg'] = "User kicked and session cleared successfully.";
        } else {
            $_SESSION['error'] = "NAS not configured for PoD.";
        }
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'subscribers.php';
        header("Location: " . $redirect);
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)$_POST['id'];
        
        $subStmt = $pdo->prepare("SELECT username, status FROM subscribers WHERE id = ? AND client_id = ?");
        $subStmt->execute([$id, $client_id]);
        $sub = $subStmt->fetch();

        if ($sub) {
            $newStatus = $sub['status'] === 'active' ? 'disabled' : 'active';
            
            $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            
            if ($newStatus === 'disabled') {
                // Reject future auth
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject') ON DUPLICATE KEY UPDATE value='Reject'")->execute([$sub['username']]);
                // Kick active session
                $_SESSION['msg'] = "User disabled. (Needs PoD kick to drop active session).";
            } else {
                // Remove Reject constraint
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$sub['username']]);
                $_SESSION['msg'] = "User enabled.";
            }
        }
        header("Location: subscribers.php");
        exit;
    }
}
header("Location: dashboard.php");
