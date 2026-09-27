<?php
// Update Subscribers & Dashboard logic to include dealer_id in logs and log Add User.
$files = [
    'C:/xampp/htdocs/SB Link Network/operator/subscribers.php',
    'C:/xampp/htdocs/SB Link Network/operator/dashboard.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);

    // Renew User Log Update
    $oldRenewLog = <<<'PHP'
                            $note = "Package Upgrade/Renew for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Deducted: Rs. $net_deduction";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        } else {
                            // Downgrade or unused credit covers it. No deduction.
                            $note = "Package Downgrade/Change for user $u. Total Cost: Rs." . round($new_total_value, 2) . " covered by Old Credit Rs." . round($old_remaining_value, 2) . ". No balance deducted.";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        }
                    }

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")->execute([$client_id, $u]);
PHP;
    
    $newRenewLog = <<<'PHP'
                            $note = "Package Upgrade/Renew for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Deducted: Rs. $net_deduction";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        } else {
                            // Downgrade or unused credit covers it. No deduction.
                            $note = "Package Downgrade/Change for user $u. Total Cost: Rs." . round($new_total_value, 2) . " covered by Old Credit Rs." . round($old_remaining_value, 2) . ". No balance deducted.";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        }
                    }

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    
                    $act_dealer_id = ($sub_dealer_id > 0) ? $sub_dealer_id : null;
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")->execute([$client_id, $act_dealer_id, $u]);
PHP;
    $content = str_replace($oldRenewLog, $newRenewLog, $content);
    
    // Add User Log Update
    $oldAddUserLog = <<<'PHP'
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                            ->execute([$username, $pkg['rate_limit']]);
                    }
                    
                    $pdo->commit();
PHP;
    $newAddUserLog = <<<'PHP'
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                            ->execute([$username, $pkg['rate_limit']]);
                    }
                    
                    $act_dealer_id = ($dealer_id > 0) ? $dealer_id : null;
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Admin', ?, 'User', 'Created New User')")->execute([$client_id, $act_dealer_id, $username]);
                    
                    $pdo->commit();
PHP;
    $content = str_replace($oldAddUserLog, $newAddUserLog, $content);

    // Any other generic activity logs replace
    $content = preg_replace('/INSERT INTO activity_logs \(client_id, by_user, against_to, against_role, activity\) VALUES \(\?, \'Admin\', \?, \'User\', \?\)"\)->execute\(\[\$client_id, \$u, \$msg\]\);/', 
                            'INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, (SELECT dealer_id FROM subscribers WHERE username = ? LIMIT 1), \'Admin\', ?, \'User\', ?)")->execute([$client_id, $u, $u, $msg]);', 
                            $content);

    file_put_contents($file, $content);
}

// 2. Update dealer_view.php to fetch and display the logs
$dfile = 'C:/xampp/htdocs/SB Link Network/operator/dealer_view.php';
$dcontent = file_get_contents($dfile);

// Fetch Logic
$fetchLogic = <<<'PHP'
// Fetch Dealer Activity Logs
$alogsStmt = $pdo->prepare("SELECT activity, against_to, created_at FROM activity_logs WHERE dealer_id = ? ORDER BY id DESC LIMIT 500");
$alogsStmt->execute([$dealer_id]);
$dealer_activities = $alogsStmt->fetchAll(PDO::FETCH_ASSOC);
PHP;

if (strpos($dcontent, '$dealer_activities') === false) {
    $dcontent = preg_replace('/(function formatBytes)/', $fetchLogic . "\n\n$1", $dcontent);
}

// Replace HTML
$oldActHtml = <<<'HTML'
            <!-- Activity Log -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-chart-line"></i> Activity Log</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            </div>
HTML;
$newActHtml = <<<'HTML'
            <!-- Activity Log -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapseActivity" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-chart-line me-2" style="color: #fff;"></i> Activity Log</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                    </div>
                </div>
                <div id="collapseActivity" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered w-100" id="dealerActivityTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Target User</th>
                                        <th>Activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($dealer_activities as $act): ?>
                                    <tr>
                                        <td class="text-secondary"><?= date('d M Y, h:i A', strtotime($act['created_at'])) ?></td>
                                        <td class="fw-bold text-primary"><?= htmlspecialchars($act['against_to'] ?: 'N/A') ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($act['activity']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
HTML;
$dcontent = str_replace($oldActHtml, $newActHtml, $dcontent);

// Update JS for Activity Table
$dcontent = str_replace("$('#dealerUsersTable').DataTable(dtOpts);", "$('#dealerUsersTable').DataTable(dtOpts);\n    $('#dealerActivityTable').DataTable(Object.assign({}, dtOpts, { order: [[0, 'desc']] }));", $dcontent);

file_put_contents($dfile, $dcontent);

// 3. Add 30-day cleanup to header.php
$hfile = 'C:/xampp/htdocs/SB Link Network/operator/header.php';
$hcontent = file_get_contents($hfile);
if (strpos($hcontent, 'DELETE FROM activity_logs') === false) {
    // Add right after session start & db include
    $hcontent = preg_replace('/(require_once \'\.\.\/config\/db\.php\';)/', "$1\n\n// Prune old activity logs (30 days)\ntry { \$pdo->exec(\"DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 30 DAY\"); } catch (Exception \$e) {}\n", $hcontent);
    file_put_contents($hfile, $hcontent);
}

echo "Successfully updated activity logs logic and auto-cleanup.\n";
?>
