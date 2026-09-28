<?php
$root = 'C:/xampp/htdocs/SB Link Network';

// 1. Update root unified login.php
$root_login = $root . '/login.php';
$content_login = file_get_contents($root_login);

$old_session = <<<'PHP'
                    $_SESSION['operator_id'] = $client['id'];
                    $_SESSION['operator_email'] = $client['email'];
                    $_SESSION['operator_name'] = $client['company_name'];
                    
                    // Also set client_id for generic compatibility if needed
                    $_SESSION['client_id'] = $client['id'];
PHP;

$new_session = <<<'PHP'
                    $_SESSION['operator_logged_in'] = true;
                    $_SESSION['operator_id'] = $client['id'];
                    $_SESSION['operator_email'] = $client['email'];
                    $_SESSION['operator_name'] = $client['company_name'];
                    $_SESSION['operator_company'] = $client['company_name'];
                    
                    // Also set client_id for generic compatibility if needed
                    $_SESSION['client_id'] = $client['id'];
PHP;

$content_login = str_replace($old_session, $new_session, $content_login);
file_put_contents($root_login, $content_login);

// 2. Update operator/header.php redirect
$op_header = $root . '/operator/header.php';
$content_header = file_get_contents($op_header);
$content_header = str_replace('header("Location: login.php");', 'header("Location: ../login.php");', $content_header);
file_put_contents($op_header, $content_header);

// 3. Rename operator/login.php to operator/_old_login.php
if (file_exists($root . '/operator/login.php')) {
    rename($root . '/operator/login.php', $root . '/operator/_old_login.php');
}

echo "Operator login loop fixed.\n";
?>
