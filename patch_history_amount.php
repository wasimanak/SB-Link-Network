<?php
$f = 'recoveryman/history.php';
$c = file_get_contents($f);

$oldLoop = '<?php foreach($submissions as $sub): ?>
                            <div class="list-group-item p-3 border-bottom border-light">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="fw-bold text-danger"><?= htmlspecialchars($sub[\'activity\']) ?></div>
                                </div>
                                <div class="text-secondary small fw-bold"><i class="fa-solid fa-user-shield me-1"></i> Received by <?= htmlspecialchars($sub[\'by_user\']) ?></div>
                                <div class="text-secondary small fw-bold mt-1"><i class="fa-solid fa-calendar-day me-1"></i> <?= date(\'d M Y, h:i A\', strtotime($sub[\'created_at\'])) ?></div>
                            </div>
                        <?php endforeach; ?>';

$newLoop = '<?php foreach($submissions as $sub): 
                            $amount_text = "N/A";
                            if (preg_match(\'/Rs\.\s*([\d,.]+)/\', $sub[\'activity\'], $m)) {
                                $amount_text = $m[1];
                            }
                        ?>
                            <div class="list-group-item p-3 border-bottom border-light">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div class="fw-bold text-dark"><i class="fa-solid fa-building-columns text-muted me-1"></i> Cash Submitted</div>
                                    <div class="fw-bold text-danger fs-6">Rs. <?= $amount_text ?></div>
                                </div>
                                <div class="text-secondary small fw-bold mb-2"><i class="fa-solid fa-user-shield me-1"></i> Received by <?= htmlspecialchars($sub[\'by_user\']) ?></div>
                                <div class="text-secondary small fw-bold"><i class="fa-solid fa-calendar-day me-1"></i> <?= date(\'d M Y, h:i A\', strtotime($sub[\'created_at\'])) ?></div>
                            </div>
                        <?php endforeach; ?>';

if (strpos($c, 'preg_match') === false) {
    $c = str_replace($oldLoop, $newLoop, $c);
    file_put_contents($f, $c);
    echo "Updated Cash to Operator layout.\n";
} else {
    echo "Already updated.\n";
}
?>
