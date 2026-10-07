<?php
session_start();
require_once '../config/db.php';
require_once 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_SESSION['operator_id'];
    $user_id = (int)$_POST['user_id'];
    $package_id = (int)$_POST['package_id'];
    $expiry_type = $_POST['expiry_type']; // 'default' or 'custom'
    $custom_expiry = $_POST['custom_expiry'] ?? '';
    
    try {
        $userStmt = $pdo->prepare("SELECT username, expiry_date, balance FROM subscribers WHERE id = ? AND client_id = ?");
        $userStmt->execute([$user_id, $client_id]);
        $uData = $userStmt->fetch();
        
        if (!$uData) {
            die("<script>alert('User not found!'); window.location='dashboard.php';</script>");
        }
        
        $u = $uData['username'];
        $current_expiry = strtotime($uData['expiry_date'] ?? '1970-01-01');
        $now = time();
        
        $p = $pdo->prepare("SELECT name, validity_days, rate_limit, price FROM packages WHERE id = ? AND (client_id = ? OR client_id = 0)");
        $p->execute([$package_id, $client_id]);
        $pkg = $p->fetch();
        
        if (!$pkg) {
            die("<script>alert('Package not found!'); window.location='dashboard.php';</script>");
        }
        
        if ($expiry_type === 'custom') {
            $expiry_date = date('Y-m-d H:i:s', strtotime($custom_expiry));
        } else {
            $days = (int)$pkg['validity_days'];
            if ($current_expiry > $now) {
                // Active: Extend from current expiry
                $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $current_expiry));
            } else {
                // Expired: Extend from now
                $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $now));
            }
        }
        
        $current_bal = (float)$uData['balance'];
        $pkg_price = (float)$pkg['price'];
        $new_bal = $current_bal - $pkg_price;
        
        $pdo->beginTransaction();
        
        // Update DB
        $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active', balance = ? WHERE id = ?")
            ->execute([$package_id, $expiry_date, $new_bal, $user_id]);
            
        // Update Radius
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
        if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
        }
        
        $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
        
        // Logs
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")
            ->execute([$client_id, $u]);
            
        if ($pkg_price > 0) {
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $u, $pkg_price, $new_bal, "Package Renewed: " . $pkg['name']]);
        }
        
        $pdo->commit();
        echo "<script>alert('User renewed successfully!'); window.location='dashboard.php';</script>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        die("<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location='dashboard.php';</script>");
    }
} else {
    header("Location: dashboard.php");
    exit;
}
?>
