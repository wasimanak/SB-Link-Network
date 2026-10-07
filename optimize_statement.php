<?php
$f = 'operator/statement.php';
$c = file_get_contents($f);

$oldQuery = 'SELECT 
        s.id, s.username, s.full_name, s.balance, s.status,
        d.username as dealer_username,
        (SELECT SUM(amount) FROM user_ledger WHERE username = s.username AND client_id = s.client_id AND type = \'credit\') as total_credit,
        (SELECT SUM(amount) FROM user_ledger WHERE username = s.username AND client_id = s.client_id AND type = \'debit\') as total_debit
    FROM subscribers s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    WHERE s.client_id = ?
    ORDER BY s.username ASC';

$newQuery = 'SELECT 
        s.id, s.username, s.full_name, s.balance, s.status,
        d.username as dealer_username,
        COALESCE(SUM(CASE WHEN l.type = \'credit\' THEN l.amount ELSE 0 END), 0) as total_credit,
        COALESCE(SUM(CASE WHEN l.type = \'debit\' THEN l.amount ELSE 0 END), 0) as total_debit
    FROM subscribers s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN user_ledger l ON s.username = l.username AND s.client_id = l.client_id
    WHERE s.client_id = ?
    GROUP BY s.id, s.username, s.full_name, s.balance, s.status, d.username
    ORDER BY s.username ASC';

if (strpos($c, 'total_credit') !== false) {
    $c = str_replace($oldQuery, $newQuery, $c);
    
    // Also inject error reporting temporarily so if it STILL fails we see the error!
    if (strpos($c, 'ini_set') === false) {
        $c = str_replace('require_once \'header.php\';', "ini_set('display_errors', 1);\nerror_reporting(E_ALL);\nrequire_once 'header.php';", $c);
    }
    
    file_put_contents($f, $c);
    echo "Query optimized and error reporting enabled in operator/statement.php\n";
} else {
    echo "Old query not found.\n";
}
?>
