<?php
// 1. Revert operator/dealers.php to multi-select
$f1 = 'operator/dealers.php';
if (file_exists($f1)) {
    $c1 = file_get_contents($f1);
    
    // Pattern to match the whole checkbox div block
    $pattern1 = '/<div class="border rounded p-2 bg-light shadow-sm".*?<\/div>.*?<div class="form-text text-muted">.*?<\/div>/s';
    
    $replacement1 = '<select name="assigned_routers[]" class="form-select" multiple required style="height: 100px;">
                            <?php foreach($all_nas as $n): ?>
                                <option value="<?= $n[\'id\'] ?>"><?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers. The dealer will only be able to create users on these routers.</div>';
    
    if (preg_match($pattern1, $c1)) {
        $c1 = preg_replace($pattern1, $replacement1, $c1);
        file_put_contents($f1, $c1);
        echo "operator/dealers.php reverted to multi-select.\n";
    }
}

// 2. Revert operator/dealer_view.php to multi-select
$f2 = 'operator/dealer_view.php';
if (file_exists($f2)) {
    $c2 = file_get_contents($f2);
    
    $pattern2 = '/<div class="border rounded p-2 bg-light shadow-sm".*?<\/div>.*?<div class="form-text text-muted">.*?<\/div>/s';
    
    $replacement2 = '<select name="assigned_routers[]" class="form-select" multiple style="height: 100px;">
                          <?php foreach($all_nas as $n): ?>
                              <option value="<?= $n[\'id\'] ?>" <?= (in_array($n[\'id\'], $curr_routers)) ? \'selected\' : \'\' ?>>
                                  <?= htmlspecialchars($n[\'shortname\'] ?: \'Router\') ?> (<?= $n[\'nasname\'] ?>)
                              </option>
                          <?php endforeach; ?>
                      </select>
                      <div class="form-text text-muted">Hold CTRL (or CMD on Mac) to select multiple routers. The dealer will only be able to create users on these routers.</div>';
    
    if (preg_match($pattern2, $c2)) {
        $c2 = preg_replace($pattern2, $replacement2, $c2);
        file_put_contents($f2, $c2);
        echo "operator/dealer_view.php reverted to multi-select.\n";
    }
}
?>
