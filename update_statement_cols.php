<?php
$f = 'operator/statement.php';
$c = file_get_contents($f);

// 1. Change Table Headers
$oldHeaders = '<th>Total Credit (Received)</th>
                        <th>Total Debit (Deducted)</th>
                        <th>Current Balance</th>';
$newHeaders = '<th>Total Recharged (Advance)</th>';
$c = str_replace($oldHeaders, $newHeaders, $c);

// 2. Change Table Rows
$oldRow = '<td class="text-success fw-bold">Rs. <?= number_format($cr, 2) ?></td>
                        <td class="text-danger fw-bold">Rs. <?= number_format($dr, 2) ?></td>
                        <td class="fw-bold <?= $bal < 0 ? \'text-danger\' : \'text-success\' ?>">Rs. <?= number_format($bal, 2) ?></td>';
$newRow = '<td class="text-success fw-bold fs-6">Rs. <?= number_format($cr, 2) ?></td>';
$c = str_replace($oldRow, $newRow, $c);

// 3. Change Footer
$oldFooter = '<td colspan="3" class="text-end">GRAND TOTAL:</td>
                        <td class="text-success fs-5">Rs. <?= number_format($total_all_credit, 2) ?></td>
                        <td class="text-danger fs-5">Rs. <?= number_format($total_all_debit, 2) ?></td>
                        <td class="fs-5 <?= $total_balance < 0 ? \'text-danger\' : \'text-success\' ?>">Rs. <?= number_format($total_balance, 2) ?></td>';
$newFooter = '<td colspan="3" class="text-end text-dark">LIFETIME RECHARGE TOTAL:</td>
                        <td class="text-success fs-4 fw-bold">Rs. <?= number_format($total_all_credit, 2) ?></td>';
$c = str_replace($oldFooter, $newFooter, $c);

// 4. Change page title
$c = str_replace('Complete Operator Statement', 'Operator Recharge Statement', $c);
$c = str_replace('Detailed financial summary of all users (including dealers\' users).', 'Lifetime advance / recharge history of all users (unaffected by package renewals).', $c);

file_put_contents($f, $c);
echo "operator/statement.php updated to show ONLY recharged balance.\n";
?>
