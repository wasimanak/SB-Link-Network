<?php
$f = 'operator/recoveryman_profile.php';
$c = file_get_contents($f);

$oldRow = '<tr>
                                <td class="text-secondary small fw-bold"><?= date(\'d M Y, h:i A\', strtotime($sub[\'created_at\'])) ?></td>
                                <td><span class="badge bg-dark"><?= htmlspecialchars($sub[\'by_user\']) ?> (Operator)</span></td>
                                <td class="text-danger fw-bold"><?= htmlspecialchars($sub[\'activity\']) ?></td>
                            </tr>';
                            
$newRow = '<?php 
                                $amount_text = "N/A";
                                if (preg_match(\'/Rs\.\s*([\d,.]+)/\', $sub[\'activity\'], $m)) {
                                    $amount_text = $m[1];
                                }
                            ?>
                            <tr>
                                <td class="text-secondary small fw-bold"><?= date(\'d M Y, h:i A\', strtotime($sub[\'created_at\'])) ?></td>
                                <td><span class="badge bg-dark"><?= htmlspecialchars($sub[\'by_user\']) ?> (Operator)</span></td>
                                <td class="text-danger fw-bold fs-6">Rs. <?= $amount_text ?></td>
                            </tr>';

if (strpos($c, 'preg_match') === false) {
    $c = str_replace($oldRow, $newRow, $c);
    file_put_contents($f, $c);
    echo "Updated operator/recoveryman_profile.php layout.\n";
} else {
    echo "Already updated.\n";
}
?>
