<?php
require 'config/db.php';
try {
    $pdo->exec("ALTER TABLE payment_gateways ADD COLUMN account_name VARCHAR(100) NULL AFTER gateway_name;");
    echo 'Column added';
} catch (Exception $e) {
    echo $e->getMessage();
}
