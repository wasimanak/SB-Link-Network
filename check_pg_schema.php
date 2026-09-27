<?php
require 'config/db.php';
print_r($pdo->query('DESCRIBE payment_gateways')->fetchAll(PDO::FETCH_ASSOC));
