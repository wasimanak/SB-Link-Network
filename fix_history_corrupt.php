<?php
$f = 'recoveryman/history.php';
$c = file_get_contents($f);

$bad_string = "// Fetch Users Created by this RM\r\n\$createdUsersStmt = \$pdo->prepare(\"\r\n    SELECT * FROM activity_logs \r\n    WHERE client_id = ? AND by_role = 'RecoveryMan' AND by_user = ? AND activity LIKE 'Created New User%'\r\n    ORDER BY created_at DESC \r\n    LIMIT 100\r\n\");\r\n\$createdUsersStmt->execute([\$client_id, \$rm_name]);\r\n\$created_users = \$createdUsersStmt->fetchAll(PDO::FETCH_ASSOC);\r\n\r\n?>";

$c = str_replace($bad_string, "?>", $c);

$bad_string2 = "// Fetch Users Created by this RM\n\$createdUsersStmt = \$pdo->prepare(\"\n    SELECT * FROM activity_logs \n    WHERE client_id = ? AND by_role = 'RecoveryMan' AND by_user = ? AND activity LIKE 'Created New User%'\n    ORDER BY created_at DESC \n    LIMIT 100\n\");\n\$createdUsersStmt->execute([\$client_id, \$rm_name]);\n\$created_users = \$createdUsersStmt->fetchAll(PDO::FETCH_ASSOC);\n\n?>";

$c = str_replace($bad_string2, "?>", $c);

$first_html_pos = strpos($c, '<!DOCTYPE html>');
if ($first_html_pos !== false) {
    $sub = substr($c, 0, $first_html_pos);
    $last_php_close = strrpos($sub, '?>');
    if ($last_php_close !== false) {
        $c = substr_replace($c, $bad_string2, $last_php_close, 2);
    }
}

file_put_contents($f, $c);
echo "Fixed!\n";
?>
