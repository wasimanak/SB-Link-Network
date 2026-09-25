<?php
session_start();
require_once 'auth_check.php';
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id'])) {
    $client_id = (int)$_POST['client_id'];
    
    // Fetch client
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
    $stmt->execute(['id' => $client_id]);
    $client = $stmt->fetch();
    
    if ($client) {
        // Create an impersonation session
        $_SESSION['operator_logged_in'] = true;
        $_SESSION['operator_id'] = $client['id'];
        $_SESSION['operator_company'] = $client['company_name'];
        
        // Normally redirect to the operator portal index, here we just show a mock message
        die("<h3>Impersonating Operator: " . htmlspecialchars($client['company_name']) . "</h3>
             <p>Session started. Redirecting to Operator Portal...</p>
             <p><a href='operators.php'>Go Back to Super Admin</a></p>");
    }
}
header("Location: operators.php");
exit;
