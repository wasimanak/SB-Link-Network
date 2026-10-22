<?php
require_once 'config/db.php';
try {
    $stmt = $pdo->query("SELECT s.id, s.username, s.client_id, s.service_type, n.nasname, n.api_port, n.api_user, n.api_password 
        FROM subscribers s 
        JOIN nas n ON s.client_id = n.client_id 
        WHERE s.expiry_date < NOW() AND s.status = 'active'");
    echo "Query successful!\n";
} catch(Exception $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
}
?>
