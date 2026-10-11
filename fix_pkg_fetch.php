<?php
$f = 'superadmin/packages_master.php';
$c = file_get_contents($f);

$find_fetch = <<<'PHP'
// Fetch Master Packages
try {
    $stmt = $pdo->query("SELECT * FROM packages WHERE is_master = 1 ORDER BY id DESC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
PHP;

$replace_fetch = <<<'PHP'
// Fetch Master & Operator Packages
try {
    $stmt = $pdo->query("SELECT p.*, c.username as owner_name FROM packages p LEFT JOIN clients c ON p.client_id = c.id WHERE p.is_master = 1 OR p.master_id IS NULL ORDER BY p.id DESC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
PHP;

$c = str_replace($find_fetch, $replace_fetch, $c);

// Fix the HTML display
$find_html = <<<'HTML'
                        <?php foreach($packages as $p): 
                            $is_global = empty($assignments[$p['id']]); // If no children, assume global
                            // Check if it's truly global (if the user explicitly saved it as global, there are no children)
                            // But maybe it's just not assigned to anyone? We treat no children as Global for display purposes or unassigned.
                            // To be safe, if we want Global vs Unassigned, we can just say Global.
                        ?>
                        <tr>
                            <td class="ps-4 fw-bold text-secondary">#<?= $p['id'] ?></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($p['name']) ?></td>
                            <td>
                                <?php if($is_global): ?>
                                    <span class="badge bg-success">Global (All Operators)</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                    <?php foreach($assignments[$p['id']] as $asg): ?>
                                        <span class="badge bg-primary"><i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($asg['username']) ?></span>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
HTML;

$replace_html = <<<'HTML'
                        <?php foreach($packages as $p): 
                            $is_global = empty($assignments[$p['id']]) && $p['client_id'] == 0; 
                            $is_operator_specific = ($p['client_id'] > 0 && $p['is_master'] == 0);
                        ?>
                        <tr>
                            <td class="ps-4 fw-bold text-secondary">#<?= $p['id'] ?></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($p['name']) ?></td>
                            <td>
                                <?php if($is_operator_specific): ?>
                                    <span class="badge bg-info text-dark"><i class="fa-solid fa-user-gear me-1"></i> <?= htmlspecialchars($p['owner_name'] ?? 'Operator') ?> (Owner)</span>
                                <?php elseif($is_global): ?>
                                    <span class="badge bg-success">Global (All Operators)</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                    <?php if(!empty($assignments[$p['id']])): ?>
                                        <?php foreach($assignments[$p['id']] as $asg): ?>
                                            <span class="badge bg-primary"><i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($asg['username']) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Not Assigned</span>
                                    <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
HTML;

$c = str_replace($find_html, $replace_html, $c);

// Fix the Edit modal logic for pre-selecting the owner if it's an operator-created package
$find_select = <<<'HTML'
                                                    <option value="0" <?= $is_global ? 'selected' : '' ?>>--- Global (All Operators) ---</option>
                                                    <?php foreach($operators as $op): 
                                                        $selected = '';
                                                        if (!$is_global && !empty($assignments[$p['id']])) {
                                                            foreach($assignments[$p['id']] as $asg) {
                                                                if($asg['id'] == $op['id']) $selected = 'selected';
                                                            }
                                                        }
                                                    ?>
                                                        <option value="<?= $op['id'] ?>" <?= $selected ?>><?= htmlspecialchars($op['username']) ?></option>
                                                    <?php endforeach; ?>
HTML;

$replace_select = <<<'HTML'
                                                    <option value="0" <?= $is_global ? 'selected' : '' ?>>--- Global (All Operators) ---</option>
                                                    <?php foreach($operators as $op): 
                                                        $selected = '';
                                                        // Pre-select if this operator is a child assignment
                                                        if (!empty($assignments[$p['id']])) {
                                                            foreach($assignments[$p['id']] as $asg) {
                                                                if($asg['id'] == $op['id']) $selected = 'selected';
                                                            }
                                                        }
                                                        // Pre-select if this operator is the OWNER of the package
                                                        if ($p['client_id'] == $op['id']) {
                                                            $selected = 'selected';
                                                        }
                                                    ?>
                                                        <option value="<?= $op['id'] ?>" <?= $selected ?>><?= htmlspecialchars($op['username']) ?></option>
                                                    <?php endforeach; ?>
HTML;
$c = str_replace($find_select, $replace_select, $c);

// Also fix the Edit backend to force is_master=1 if it was an operator package that got edited by superadmin
$find_update = <<<'PHP'
                // Update Master Package
                $stmt = $pdo->prepare("UPDATE packages SET name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit WHERE id = :id");
PHP;
$replace_update = <<<'PHP'
                // Update Master Package (Force is_master = 1 so it functions correctly for assignments)
                $stmt = $pdo->prepare("UPDATE packages SET is_master = 1, name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit WHERE id = :id");
PHP;
$c = str_replace($find_update, $replace_update, $c);

file_put_contents($f, $c);
echo "Fixed package fetching logic.\n";
?>
