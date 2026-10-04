<?php
require 'config/db.php';
try {
    $stmt = $pdo->query("DESCRIBE recovery_men");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {
    echo "recovery_men table error: " . $e->getMessage() . "\n";
}

try {
    $stmt = $pdo->query("DESCRIBE line_men");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {
    echo "line_men table error: " . $e->getMessage() . "\n";
}
?>
