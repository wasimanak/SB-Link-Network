<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

// We need to inject the CSS just before the d-flex header
$css = '<style>
.table-custom { border-collapse: separate; border-spacing: 0 12px; margin-top: -12px; }
.table-custom thead th { border: none; color: #64748b; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; background: transparent; padding: 0 20px 5px 20px; }
.table-custom tbody tr { background-color: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.02); transition: all 0.2s ease; border-radius: 12px; }
.table-custom tbody tr:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
.table-custom tbody td { border: none; padding: 15px 20px; vertical-align: middle; }
.table-custom tbody td:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
.table-custom tbody td:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }

.user-avatar { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #eff6ff, #bfdbfe); color: #2563eb; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.2rem; }

.badge-soft-primary { background-color: rgba(37, 99, 235, 0.1); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.15); font-weight: 700; }
.badge-soft-success { background-color: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.15); font-weight: 700; }
.badge-soft-danger { background-color: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.15); font-weight: 700; }
.badge-soft-secondary { background-color: rgba(100, 116, 139, 0.1); color: #64748b; border: 1px solid rgba(100, 116, 139, 0.15); font-weight: 700; }

.action-btn { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; transition: all 0.2s; border: none; cursor: pointer; }
.action-btn:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.btn-edit-user { background-color: #f1f5f9; color: #475569; }
.btn-edit-user:hover { background-color: #2563eb; color: #fff; }
.btn-renew-user { background-color: rgba(16, 185, 129, 0.1); color: #10b981; }
.btn-renew-user:hover { background-color: #10b981; color: #fff; }
.btn-toggle-active { background-color: rgba(245, 158, 11, 0.1); color: #d97706; }
.btn-toggle-active:hover { background-color: #d97706; color: #fff; }
.btn-toggle-disabled { background-color: rgba(16, 185, 129, 0.1); color: #10b981; }
.btn-toggle-disabled:hover { background-color: #10b981; color: #fff; }
.btn-delete-user { background-color: rgba(239, 68, 68, 0.1); color: #ef4444; }
.btn-delete-user:hover { background-color: #ef4444; color: #fff; }

.dt-buttons .btn { border-radius: 8px; margin-bottom: 15px; }
.dataTables_wrapper .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #cbd5e1; outline: none; }
.dataTables_wrapper .dataTables_filter input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.2); }
</style>
<div class="d-flex justify-content-between align-items-center mb-4">';

if (strpos($c, 'table-custom') === false) {
    $c = str_replace('<div class="d-flex justify-content-between align-items-center mb-4">', $css, $c);
}

// Extract old table string and replace with new table
$tableStart = strpos($c, '<div class="table-responsive">');
$tableEnd = strpos($c, '</div>', strpos($c, '</table>', $tableStart));

if ($tableStart !== false && $tableEnd !== false) {
    $oldTableHtml = substr($c, $tableStart, $tableEnd - $tableStart + 6);
    
    $newTableHtml = '<div class="table-responsive pb-3">
            <table id="usersTable" class="table table-custom align-middle w-100">
                <thead>
                    <tr>
                        <th>User Identity</th>
                        <th>Package</th>
                        <th>Access</th>
                        <th>Connection</th>
                        <th>Expiry</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="border-0">
                    <?php foreach($subs as $s): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar shadow-sm"><i class="fa-solid fa-user-astronaut"></i></div>
                                <div>
                                    <a href="#" class="fw-bold text-dark fs-6 text-decoration-none edit-user-btn" data-id="<?= $s[\'id\'] ?>" data-username="<?= htmlspecialchars($s[\'username\']) ?>" data-fullname="<?= htmlspecialchars($s[\'full_name\']) ?>" data-password="<?= htmlspecialchars($s[\'password\']) ?>" data-expiry="<?= $s[\'expiry_date\'] ? date(\'Y-m-d\TH:i\', strtotime($s[\'expiry_date\'])) : \'\' ?>" data-old-ts="<?= $s[\'expiry_date\'] ? strtotime($s[\'expiry_date\']) : 0 ?>" data-pkg="<?= $s[\'package_id\'] ?>"><?= htmlspecialchars($s[\'username\']) ?></a>
                                    <div class="small text-muted fw-bold"><?= htmlspecialchars($s[\'full_name\']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge badge-soft-primary px-3 py-2 rounded-pill"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($s[\'package_name\']) ?></span></td>
                        <td>
                            <?php if(isset($s[\'status\']) && $s[\'status\'] === \'active\'): ?>
                                <span class="badge badge-soft-success px-3 py-2 rounded-pill"><i class="fa-solid fa-shield-check me-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-soft-danger px-3 py-2 rounded-pill"><i class="fa-solid fa-shield-halved me-1"></i> Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s[\'is_online\'] > 0): ?>
                                <span class="badge badge-soft-success px-3 py-2 rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                            <?php else: ?>
                                <span class="badge badge-soft-secondary px-3 py-2 rounded-pill"><i class="fa-solid fa-plug-circle-xmark me-1"></i> Offline</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s[\'expiry_date\']): ?>
                                <?php if(strtotime($s[\'expiry_date\']) < time()): ?>
                                    <span class="badge badge-soft-danger px-3 py-2 rounded-pill">Expired (<?= date(\'d M Y\', strtotime($s[\'expiry_date\'])) ?>)</span>
                                <?php else: ?>
                                    <span class="badge badge-soft-success px-3 py-2 rounded-pill">Valid (<?= date(\'d M Y\', strtotime($s[\'expiry_date\'])) ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-soft-secondary px-3 py-2 rounded-pill">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-2 justify-content-end position-relative" style="z-index: 2;">
                                <button type="button" class="action-btn btn-edit-user edit-user-btn" title="Edit Profile" data-id="<?= $s[\'id\'] ?>" data-username="<?= htmlspecialchars($s[\'username\']) ?>" data-fullname="<?= htmlspecialchars($s[\'full_name\']) ?>" data-password="<?= htmlspecialchars($s[\'password\']) ?>" data-expiry="<?= $s[\'expiry_date\'] ? date(\'Y-m-d\TH:i\', strtotime($s[\'expiry_date\'])) : \'\' ?>" data-old-ts="<?= $s[\'expiry_date\'] ? strtotime($s[\'expiry_date\']) : 0 ?>" data-pkg="<?= $s[\'package_id\'] ?>"><i class="fa-solid fa-pen"></i></button>
                                
                                <form method="POST" onsubmit="return confirm(\'Are you sure you want to change this users access status?\');" class="m-0">
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="id" value="<?= $s[\'id\'] ?>">
                                    <?php if(isset($s[\'status\']) && $s[\'status\'] === \'active\'): ?>
                                        <button type="submit" class="action-btn btn-toggle-active" title="Disable User"><i class="fa-solid fa-ban"></i></button>
                                    <?php else: ?>
                                        <button type="submit" class="action-btn btn-toggle-disabled" title="Enable User"><i class="fa-solid fa-check"></i></button>
                                    <?php endif; ?>
                                </form>

                                <button type="button" class="action-btn btn-renew-user" title="Renew / Upgrade" onclick="openRenewModal(<?= $s[\'id\'] ?>, \'<?= addslashes($s[\'username\']) ?>\')"><i class="fa-solid fa-rotate"></i></button>

                                <?php if($can_delete): ?>
                                <form method="POST" onsubmit="return confirm(\'Delete this user?\');" class="m-0">
                                    <input type="hidden" name="action" value="delete_id">
                                    <input type="hidden" name="id" value="<?= $s[\'id\'] ?>">
                                    <button type="submit" class="action-btn btn-delete-user" title="Delete User"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>';

    $c = str_replace($oldTableHtml, $newTableHtml, $c);
    
    // Also change the card wrapper background so the floating rows stand out better
    // Replace class="card border-0 shadow-sm rounded-4 mb-4" with transparent background
    $c = str_replace('<div class="card border-0 shadow-sm rounded-4 mb-4">', '<div class="card border-0 bg-transparent mb-4">', $c);
    $c = str_replace('<div class="card-body p-4">', '<div class="card-body p-0">', $c);
    
    file_put_contents($f, $c);
    echo "Successfully upgraded UI to premium design.\n";
} else {
    echo "Failed to locate table HTML.\n";
}
?>
