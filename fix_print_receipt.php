<?php
$f = 'recoveryman/print_receipt.php';
$c = file_get_contents($f);

$find = <<<'PHP'
require_once '../config/db.php';
require_once 'session.php'; // assuming RM session exists

if (!isset($_SESSION['rm_id']) || !isset($_SESSION['operator_id'])) {
    die("Unauthorized");
}

$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['operator_id'];
PHP;

$replace = <<<'PHP'
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['rm_id']) || !isset($_SESSION['client_id'])) {
    die("Unauthorized");
}

$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['client_id'];
PHP;

$c = str_replace($find, $replace, $c);
file_put_contents($f, $c);
echo "Fixed print receipt.\n";
?>
