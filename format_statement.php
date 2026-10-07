<?php
$f = 'operator/statement.php';
$c = file_get_contents($f);

// 1. Update the SQL Query
$oldQueryBlock = 'SELECT 
        s.id, s.username, s.full_name, s.balance, s.status,
        d.username as dealer_username,
        COALESCE(SUM(CASE WHEN l.type = \'credit\' THEN l.amount ELSE 0 END), 0) as total_credit,
        COALESCE(SUM(CASE WHEN l.type = \'debit\' THEN l.amount ELSE 0 END), 0) as total_debit
    FROM subscribers s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN user_ledger l ON s.username = l.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    WHERE s.client_id = ?
    GROUP BY s.id, s.username, s.full_name, s.balance, s.status, d.username
    ORDER BY s.username ASC';

$newQueryBlock = 'SELECT 
        s.id, s.username, s.full_name,
        d.username as dealer_username,
        COALESCE(SUM(CASE WHEN l.type = \'credit\' THEN l.amount ELSE 0 END), 0) as total_credit,
        MAX(CASE WHEN l.type = \'credit\' THEN l.created_at ELSE NULL END) as last_recharge_date,
        (SELECT amount FROM user_ledger ul WHERE ul.username = s.username COLLATE utf8mb4_general_ci AND ul.client_id = s.client_id AND ul.type = \'credit\' ORDER BY ul.id DESC LIMIT 1) as last_recharge_amount
    FROM subscribers s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN user_ledger l ON s.username = l.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    WHERE s.client_id = ?
    GROUP BY s.id, s.username, s.full_name, d.username
    HAVING total_credit > 0
    ORDER BY last_recharge_date DESC';

$c = str_replace($oldQueryBlock, $newQueryBlock, $c);

// 2. Extract and replace the HTML table structure
$start = strpos($c, '<table id="statementTable"');
$end = strpos($c, '</table>') + 8;
$oldTable = substr($c, $start, $end - $start);

$newTable = '<table id="statementTable" class="table table-hover table-custom-ui table-borderless w-100 align-middle">
                <thead>
                    <tr>
                        <th>User Details</th>
                        <th>Managed By</th>
                        <th>Last Recharge Date</th>
                        <th>Last R. Amount</th>
                        <th class="text-end pe-4">Lifetime Recharged</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $u): 
                        $cr = (float)$u[\'total_credit\'];
                        $total_all_credit += $cr;
                        
                        $lr_date = $u[\'last_recharge_date\'] ? date(\'d M Y\', strtotime($u[\'last_recharge_date\'])) : \'N/A\';
                        $lr_time = $u[\'last_recharge_date\'] ? date(\'h:i A\', strtotime($u[\'last_recharge_date\'])) : \'\';
                        $lr_amt = (float)$u[\'last_recharge_amount\'];
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-primary" style="font-size: 1.05rem;"><?= htmlspecialchars($u[\'username\']) ?></div>
                            <div class="text-secondary" style="font-size: 0.85rem;"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($u[\'full_name\']) ?></div>
                        </td>
                        <td>
                            <?php if($u[\'dealer_username\']): ?>
                                <span class="badge bg-secondary"><i class="fa-solid fa-store me-1"></i> <?= htmlspecialchars($u[\'dealer_username\']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary"><i class="fa-solid fa-user-shield me-1"></i> Operator</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><?= $lr_date ?></div>
                            <div class="small text-muted font-monospace"><?= $lr_time ?></div>
                        </td>
                        <td><span class="badge badge-soft-success px-3 py-2 fs-6">Rs. <?= number_format($lr_amt) ?></span></td>
                        <td class="text-success fw-bold fs-5 text-end pe-4">Rs. <?= number_format($cr) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="totals-row" style="border-top: 2px solid #e2e8f0;">
                        <td colspan="4" class="text-end text-dark fs-6 pt-3">GRAND TOTAL LIFETIME RECHARGES:</td>
                        <td class="text-success fs-3 fw-bold text-end pe-4 pt-3">Rs. <?= number_format($total_all_credit) ?></td>
                    </tr>
                </tfoot>
            </table>';

$c = str_replace($oldTable, $newTable, $c);

file_put_contents($f, $c);
echo "operator/statement.php format successfully updated.\n";
?>
