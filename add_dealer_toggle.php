<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

// 1. Add toggle PHP logic
$toggleLogic = '
    if ($action === \'toggle_user\') {
        $id = (int)$_POST[\'id\'];
        
        $subStmt = $pdo->prepare("SELECT username, status, client_id FROM subscribers WHERE id = ? AND dealer_id = ?");
        $subStmt->execute([$id, $dealer_id]);
        $sub = $subStmt->fetch();

        if ($sub) {
            $newStatus = $sub[\'status\'] === \'active\' ? \'disabled\' : \'active\';
            
            $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            
            if ($newStatus === \'disabled\') {
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, \'Auth-Type\', \':=\', \'Reject\') ON DUPLICATE KEY UPDATE value=\'Reject\'")->execute([$sub[\'username\']]);
                
                // Disconnect active session
                $nasStmt = $pdo->prepare("SELECT n.nasname, n.coa_port, n.secret FROM nas n 
                                        JOIN radacct r ON n.nasname = r.nasipaddress 
                                        WHERE r.username = ? AND r.acctstoptime IS NULL LIMIT 1");
                $nasStmt->execute([$sub[\'username\']]);
                $nas = $nasStmt->fetch();
                if (!$nas) {
                    $nasStmt = $pdo->prepare("SELECT nasname, coa_port, secret FROM nas WHERE client_id = ? LIMIT 1");
                    $nasStmt->execute([$sub[\'client_id\']]);
                    $nas = $nasStmt->fetch();
                }
                if ($nas) {
                    $ip = escapeshellarg($nas[\'nasname\'] . ":" . $nas[\'coa_port\']);
                    $secret = escapeshellarg($nas[\'secret\']);
                    $user = escapeshellarg("User-Name=" . $sub[\'username\']);
                    $cmd = "echo $user | radclient -x $ip disconnect $secret 2>&1";
                    exec($cmd);
                    $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = \'Admin-Reset\' WHERE username = ? AND acctstoptime IS NULL")->execute([$sub[\'username\']]);
                }
                echo "<script>alert(\'User disabled and disconnected!\'); window.location=\'users.php\';</script>";
            } else {
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = \'Auth-Type\'")->execute([$sub[\'username\']]);
                echo "<script>alert(\'User enabled!\'); window.location=\'users.php\';</script>";
            }
            exit;
        }
    }
';

if (strpos($c, '$action === \'toggle_user\'') === false) {
    // Insert after // ADD USER
    $c = str_replace('// ADD USER', $toggleLogic . "\n    // ADD USER", $c);
}

// 2. Add Status column to Table Header
$oldTh = '<th>Status</th>
                        <th>Expiry</th>
                        <th>Actions</th>';
$newTh = '<th>Access</th>
                        <th>Status</th>
                        <th>Expiry</th>
                        <th>Actions</th>';
if (strpos($c, '<th>Access</th>') === false) {
    $c = str_replace($oldTh, $newTh, $c);
}

// 3. Add Status column to Table Body and Toggle Button
$oldTd = '<td>
                            <?php if($s[\'is_online\'] > 0): ?>';
$newTd = '<td>
                            <?php if(isset($s[\'status\']) && $s[\'status\'] === \'active\'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s[\'is_online\'] > 0): ?>';
if (strpos($c, 'badge bg-success bg-opacity-10 text-success') === false) {
    $c = str_replace($oldTd, $newTd, $c);
}

$oldActions = '<div class="d-flex gap-2">
                                <button class="btn btn-sm btn-success" onclick="openRenewModal(<?= $s[\'id\'] ?>, \'<?= addslashes($s[\'username\']) ?>\')"><i class="fa-solid fa-rotate"></i> Renew</button>';
$newActions = '<div class="d-flex gap-2">
                                <form method="POST" onsubmit="return confirm(\'Are you sure you want to change this users access status?\');" class="m-0">
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="id" value="<?= $s[\'id\'] ?>">
                                    <?php if($s[\'status\'] === \'active\'): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Disable User"><i class="fa-solid fa-ban"></i></button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Enable User"><i class="fa-solid fa-check"></i></button>
                                    <?php endif; ?>
                                </form>
                                <button class="btn btn-sm btn-success" onclick="openRenewModal(<?= $s[\'id\'] ?>, \'<?= addslashes($s[\'username\']) ?>\')"><i class="fa-solid fa-rotate"></i> Renew</button>';
if (strpos($c, 'toggle_user') === false || strpos($c, '<input type="hidden" name="action" value="toggle_user">') === false) {
    $c = str_replace($oldActions, $newActions, $c);
}

file_put_contents($f, $c);
echo "Toggle feature added to dealer/users.php.\n";
?>
