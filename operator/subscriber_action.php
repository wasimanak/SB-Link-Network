<?php
require_once 'auth_check.php';
require_once __DIR__ . '/../config/radius_kick_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $client_id = $_SESSION['operator_id'];

    if ($action === 'kick') {
        $username = trim($_POST['username'] ?? '');
        
        // Execute Dual-Engine Kick (MikroTik RouterOS API + RADIUS PoD)
        $kickRes = kick_subscriber_session($pdo, $username, $client_id);
        
        // If user is expired or disabled, ensure Auth-Type := Reject is in radcheck so they cannot reconnect
        $chkStmt = $pdo->prepare("SELECT status, expiry_date FROM subscribers WHERE username = ? AND client_id = ?");
        $chkStmt->execute([$username, $client_id]);
        $subRow = $chkStmt->fetch();
        if ($subRow) {
            $is_exp = (!empty($subRow['expiry_date']) && strtotime($subRow['expiry_date']) <= time());
            if ($subRow['status'] === 'disabled' || $subRow['status'] === 'expired' || $is_exp) {
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$username]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$username]);
                if ($is_exp && $subRow['status'] === 'active') {
                    $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE username = ?")->execute([$username]);
                }
            }
        }

        $_SESSION['msg'] = "User kicked successfully! " . $kickRes['message'];
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'subscribers.php';
        header("Location: " . $redirect);
        exit;
    }

    if ($action === 'kick_all_expired') {
        $res = sync_expired_subscribers($pdo, $client_id, true);
        $_SESSION['msg'] = "Kicked {$res['kicked']} active expired sessions and updated {$res['blocked']} in RADIUS.";
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'live_sessions.php';
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
                // Hard block in FreeRADIUS
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$sub['username']]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$sub['username']]);
                
                // Kick active session immediately from MikroTik
                kick_subscriber_session($pdo, $sub['username'], $client_id);
                $_SESSION['msg'] = "User disabled and disconnected from network.";
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
