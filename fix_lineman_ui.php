<?php
$f = 'operator/line_man.php';
$c = file_get_contents($f);

// 1. Fix Headers
$c = str_replace('<th>Created On</th>
                        <th>Actions</th>', '<th>Created On</th>
                        <th>Access</th>
                        <th>Actions</th>', $c);

// 2. Fix Body Rows
$oldRowEnd = '<td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm(\'Are you sure you want to delete this Line Man?\');" class="m-0">
                                <input type="hidden" name="action" value="delete_member">
                                <input type="hidden" name="id" value="<?= $m[\'id\'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>';

$newRowEnd = '<td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>
                        <td>
                            <div class="mb-1">
                            <?php if(isset($m[\'status\']) && $m[\'status\'] === \'active\'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">Disabled</span>
                            <?php endif; ?>
                            </div>
                            <div>
                            <?php if(isset($m[\'can_create_users\']) && $m[\'can_create_users\']): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><i class="fa-solid fa-user-plus"></i> Allow</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25"><i class="fa-solid fa-ban"></i> Deny</span>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary me-1 btn-edit" 
                                data-id="<?= $m[\'id\'] ?>"
                                data-fullname="<?= htmlspecialchars($m[\'full_name\']) ?>"
                                data-username="<?= htmlspecialchars($m[\'username\']) ?>"
                                data-password="<?= htmlspecialchars($m[\'password\']) ?>"
                                data-phone="<?= htmlspecialchars($m[\'phone\']) ?>"
                                data-city="<?= htmlspecialchars($m[\'city\']) ?>"
                                data-address="<?= htmlspecialchars($m[\'address\']) ?>"
                                data-status="<?= $m[\'status\'] ?? \'active\' ?>"
                                data-create="<?= $m[\'can_create_users\'] ?? 1 ?>">
                                <i class="fa-solid fa-pen"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm(\'Are you sure you want to delete this Line Man?\');" class="d-inline">
                                <input type="hidden" name="action" value="delete_member">
                                <input type="hidden" name="id" value="<?= $m[\'id\'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>';

$c = str_replace($oldRowEnd, $newRowEnd, $c);

// We need to double check if the Modal was inserted correctly previously.
if (strpos($c, 'id="editMemberModal"') === false) {
    echo "Wait, the modal is also missing! I will re-inject it.\n";
}

file_put_contents($f, $c);
echo "operator/line_man.php table rows updated!\n";
?>
