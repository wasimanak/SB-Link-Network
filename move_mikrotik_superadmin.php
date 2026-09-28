<?php
$root = 'C:/xampp/htdocs/SB Link Network';

// 1. Remove Mikrotik Menu from Operator Header entirely
$op_header = $root . '/operator/header.php';
$content_header = file_get_contents($op_header);

// The Mikrotik menu block is likely a whole div. We can use regex to remove it.
$pattern = '/<a href="#mikrotikMenu".*?<\/div>.*?<\/div>/s';
$content_header = preg_replace($pattern, '<!-- Mikrotik Menu Removed -->', $content_header);
file_put_contents($op_header, $content_header);

// 2. Modify superadmin/operator_add.php to include NAS and Sync logic
$op_add = $root . '/superadmin/operator_add.php';
$content_add = file_get_contents($op_add);

// Find the form and add the MikroTik fields
$old_form = <<<'HTML'
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-secondary fw-bold">Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end">
HTML;

$new_form = <<<'HTML'
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-secondary fw-bold">Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-router text-primary me-2"></i> Initial MikroTik Setup & Sync (Optional)</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-secondary fw-bold">Router IP Address</label>
                                <input type="text" name="nasname" class="form-control" placeholder="e.g. 10.133.13.69">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-secondary fw-bold">RADIUS Secret</label>
                                <input type="text" name="secret" class="form-control" placeholder="e.g. 123456">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-secondary fw-bold">API Username</label>
                                <input type="text" name="api_user" class="form-control" placeholder="e.g. admin">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-secondary fw-bold">API Password</label>
                                <input type="password" name="api_password" class="form-control">
                            </div>
                            <div class="col-12 mt-2">
                                <div class="form-check form-switch">
                                  <input class="form-check-input" type="checkbox" name="auto_sync" id="autoSync" value="1" checked>
                                  <label class="form-check-label fw-bold text-dark" for="autoSync">Automatically sync all Profiles and Users from this router after creation</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="text-end">
HTML;
$content_add = str_replace($old_form, $new_form, $content_add);

// Now update the POST PHP logic
$old_php = <<<'PHP'
            $stmt->execute([
                'company' => $company,
                'email' => $email,
                'pass' => $hashed,
                'phone' => $phone,
                'expiry' => $expiry,
                'mr' => $max_routers,
                'ms' => $max_subscribers
            ]);
            $success = "Operator account created successfully.";
        } catch (PDOException $e) {
PHP;

$new_php = <<<'PHP'
            $stmt->execute([
                'company' => $company,
                'email' => $email,
                'pass' => $hashed,
                'phone' => $phone,
                'expiry' => $expiry,
                'mr' => $max_routers,
                'ms' => $max_subscribers
            ]);
            $client_id = $pdo->lastInsertId();
            $success = "Operator account created successfully.";
            
            // Handle NAS and Sync
            $nasname = trim($_POST['nasname'] ?? '');
            if (!empty($nasname)) {
                $secret = trim($_POST['secret'] ?? '');
                $api_user = trim($_POST['api_user'] ?? '');
                $api_pass = trim($_POST['api_password'] ?? '');
                $auto_sync = isset($_POST['auto_sync']) ? true : false;
                
                // Insert NAS
                $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, 'Primary Router', ?, 8728, ?, ?)")
                    ->execute([$client_id, $nasname, $secret, $api_user, $api_pass]);
                
                if ($auto_sync) {
                    require_once '../config/routeros_api.class.php';
                    $api = new RouterosAPI();
                    if ($api->connect($nasname, $api_user, $api_pass, 8728)) {
                        // 1. Fetch & Sync Profiles
                        $api->write('/ppp/profile/print');
                        $ppp_profiles = $api->read();
                        $api->write('/ip/hotspot/user/profile/print');
                        $hs_profiles = $api->read();
                        
                        foreach (array_merge($ppp_profiles, $hs_profiles) as $p) {
                            $name = $p['name'] ?? '';
                            if (!$name || $name === 'default' || $name === 'default-encryption') continue;
                            $rate = $p['rate-limit'] ?? 'Unlimited';
                            
                            $chk = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND client_id = ?");
                            $chk->execute([$name, $client_id]);
                            if (!$chk->fetch()) {
                                $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, price) VALUES (?, ?, ?, 0)")->execute([$client_id, $name, $rate]);
                                if ($rate !== 'Unlimited') {
                                    $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$name, $rate]);
                                }
                                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', 'pool1')")->execute([$name]);
                            }
                        }
                        
                        // 2. Fetch & Sync Users
                        $api->write('/ppp/secret/print');
                        $ppp_users = $api->read();
                        $api->write('/ip/hotspot/user/print');
                        $hs_users = $api->read();
                        
                        $total_users = 0;
                        foreach (array_merge($ppp_users, $hs_users) as $u) {
                            $uname = $u['name'] ?? '';
                            $upass = $u['password'] ?? '';
                            $uprof = $u['profile'] ?? 'default';
                            if (!$uname || $uname === 'default') continue;
                            
                            $chk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
                            $chk->execute([$uname]);
                            if (!$chk->fetch()) {
                                $pkgStmt = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND client_id = ?");
                                $pkgStmt->execute([$uprof, $client_id]);
                                $pkg = $pkgStmt->fetch();
                                $pkg_id = $pkg ? $pkg['id'] : null;
                                
                                $pdo->prepare("INSERT INTO subscribers (client_id, username, password, full_name, package_id, status) VALUES (?, ?, ?, ?, ?, 'active')")
                                    ->execute([$client_id, $uname, $upass, $uname, $pkg_id]);
                                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$uname, $upass]);
                                
                                if ($uprof !== 'default') {
                                    $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$uname, $uprof]);
                                }
                                $total_users++;
                            }
                        }
                        $api->disconnect();
                        $success .= " MikroTik synced successfully ($total_users users imported).";
                    } else {
                        $error = "Operator created, but failed to connect to MikroTik API for sync.";
                    }
                } else {
                    $success .= " Router added.";
                }
            }
        } catch (Exception $e) {
PHP;
$content_add = str_replace($old_php, $new_php, $content_add);
// Fallback if Exception replaces PDOException
$content_add = str_replace('catch (PDOException $e)', 'catch (Exception $e)', $content_add);

file_put_contents($op_add, $content_add);

// Delete Operator Mikrotik files
$files_to_delete = [
    '/operator/mikrotik_sync.php',
    '/operator/api_mikrotik_sync.php',
    '/operator/mikrotik_active.php',
    '/operator/mikrotik_dhcp.php',
    '/operator/mikrotik_pools.php'
];
foreach ($files_to_delete as $f) {
    if (file_exists($root . $f)) {
        unlink($root . $f);
    }
}

echo "Moved MikroTik functionality to Super Admin and removed from Operator.\n";
?>
