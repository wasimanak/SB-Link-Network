<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'header.php';

$client_id = $_SESSION['operator_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            s.id, s.username, s.full_name, s.balance, s.status,
            d.username as dealer_username,
            (SELECT SUM(amount) FROM user_ledger WHERE username = s.username AND client_id = s.client_id AND type = 'credit') as total_credit,
            (SELECT SUM(amount) FROM user_ledger WHERE username = s.username AND client_id = s.client_id AND type = 'debit') as total_debit
        FROM subscribers s
        LEFT JOIN dealers d ON s.dealer_id = d.id
        WHERE s.client_id = ?
        ORDER BY s.username ASC
    ");
    $stmt->execute([$client_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Query successful. Users found: " . count($users) . "<br>";
} catch (PDOException $e) {
    echo "SQL ERROR: " . $e->getMessage() . "<br>";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
}
?>
