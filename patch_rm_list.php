<?php
$f = 'operator/recovery_man.php';
$c = file_get_contents($f);

// 1. Schema check to ensure cash_in_hand exists (avoids errors on first load)
$schemaUpgrade = "
// Auto-upgrade recovery_men table for cash_in_hand
try {
    \$pdo->exec(\"ALTER TABLE `recovery_men` ADD COLUMN `cash_in_hand` DECIMAL(10,2) DEFAULT 0.00\");
} catch (PDOException \$e) {}
";

if (strpos($c, 'ADD COLUMN `cash_in_hand`') === false) {
    $c = str_replace('// Auto-create table if not exists', $schemaUpgrade . "\n// Auto-create table if not exists", $c);
}

// 2. Add 'Cash in Hand' to headers
$oldHeaders = '<th>Contact</th>
                        <th>Created On</th>
                        <th>Actions</th>';
$newHeaders = '<th>Contact</th>
                        <th>Cash in Hand</th>
                        <th>Created On</th>
                        <th>Actions</th>';
$c = str_replace($oldHeaders, $newHeaders, $c);

// 3. Make Name clickable and add Cash in Hand column to rows
$oldName = '<td class="fw-bold text-dark"><?= htmlspecialchars($m[\'full_name\']) ?></td>';
$newName = '<td><a href="recoveryman_profile.php?id=<?= $m[\'id\'] ?>" class="fw-bold text-primary text-decoration-none"><i class="fa-solid fa-user-circle me-1"></i> <?= htmlspecialchars($m[\'full_name\']) ?></a></td>';
$c = str_replace($oldName, $newName, $c);

$oldRowEnd = '<td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>';
$newRowEnd = '<td>
                            <div class="fw-bold <?= ($m[\'cash_in_hand\']>0)?\'text-success\':\'text-muted\' ?>">
                                Rs. <?= number_format($m[\'cash_in_hand\'] ?? 0) ?>
                            </div>
                        </td>
                        <td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>';
$c = str_replace($oldRowEnd, $newRowEnd, $c);

file_put_contents($f, $c);
echo "Updated operator/recovery_man.php layout.\n";
?>
