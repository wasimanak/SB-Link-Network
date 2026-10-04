<?php
$f = 'operator/recovery_man.php';
$c = file_get_contents($f);

$newActions = '<td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>
                          <td>
                              <button type="button" class="btn btn-sm btn-outline-primary me-1 btn-edit" 
                                  data-id="<?= $m[\'id\'] ?>"
                                  data-fullname="<?= htmlspecialchars($m[\'full_name\']) ?>"
                                  data-username="<?= htmlspecialchars($m[\'username\']) ?>"
                                  data-password="<?= htmlspecialchars($m[\'password\']) ?>"
                                  data-phone="<?= htmlspecialchars($m[\'phone\']) ?>"
                                  data-city="<?= htmlspecialchars($m[\'city\']) ?>"
                                  data-address="<?= htmlspecialchars($m[\'address\']) ?>"
                                  data-status="<?= $m[\'status\'] ?? \'active\' ?>">
                                  <i class="fa-solid fa-pen"></i>
                              </button>
                              <form method="POST" onsubmit="return confirm(\'Are you sure you want to delete this Recovery Man?\');" class="d-inline">';

// Force replace the exact form start
$c = preg_replace('/<td class="text-secondary small"><\?= date\(\'d M Y\', strtotime\(\$m\[\'created_at\'\]\)\) \?><\/td>\s*<td>\s*<form method="POST" onsubmit="return confirm\(\'Are you sure you want to delete this Recovery Man\?\'\);" class="m-0">/s', $newActions, $c);

file_put_contents($f, $c);
echo "Edit button forced.\n";
?>
