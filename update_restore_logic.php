<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/dashboard.php';
$content = file_get_contents($file);

// Find the CSV Restore Block
$startPos = strpos($content, '// --- Handle CSV Restore ---');
$endPos = strpos($content, '// --- ADD USER LOGIC ---');

if ($startPos !== false && $endPos !== false) {
    $oldRestoreLogic = substr($content, $startPos, $endPos - $startPos);

    $newRestoreLogic = <<<'PHP'
// --- Handle CSV Restore ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_backup') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['backup_file']['tmp_name'];
        if (($file = fopen($tmpName, 'r')) !== FALSE) {
            $headers = fgetcsv($file); // Read header row
            
            // Map header names to their index
            $colMap = [];
            foreach ($headers as $index => $name) {
                $colMap[trim($name)] = $index;
            }
            
            // Determine if this is the OLD basic CSV or NEW full CSV
            $is_new_format = isset($colMap['Package Name']);
            
            $pdo->beginTransaction();
            try {
                while (($data = fgetcsv($file)) !== FALSE) {
                    if (empty($data[0]) && empty($data[1])) continue; // Skip empty rows

                    if ($is_new_format) {
                        // NEW FULL FORMAT
                        $username = trim($data[$colMap['Username']] ?? '');
                        $password = trim($data[$colMap['Password']] ?? '');
                        $full_name = trim($data[$colMap['Full Name']] ?? '');
                        $service_type = trim($data[$colMap['Service Type']] ?? 'pppoe');
                        $package_name = trim($data[$colMap['Package Name']] ?? '');
                        $dealer_username = trim($data[$colMap['Dealer Username']] ?? '');
                        $mobile = trim($data[$colMap['Mobile']] ?? '');
                        $phone = trim($data[$colMap['Phone']] ?? '');
                        $national_id = trim($data[$colMap['National ID']] ?? '');
                        $city = trim($data[$colMap['City']] ?? '');
                        $subarea = trim($data[$colMap['Subarea']] ?? '');
                        $address = trim($data[$colMap['Address']] ?? '');
                        $gps_lat = trim($data[$colMap['GPS Lat']] ?? '');
                        $gps_lng = trim($data[$colMap['GPS Lng']] ?? '');
                        $notes = trim($data[$colMap['Notes']] ?? '');
                        $balance = (float)($data[$colMap['Balance']] ?? 0);
                        $status = trim($data[$colMap['Status']] ?? 'active');
                        $expiry = trim($data[$colMap['Expiry Date']] ?? '');
                        
                        // Resolve Package ID
                        $package_id = 0;
                        if (!empty($package_name)) {
                            $pkgStmt = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND (client_id = ? OR client_id = 0) LIMIT 1");
                            $pkgStmt->execute([$package_name, $client_id]);
                            $package_id = (int)$pkgStmt->fetchColumn();
                        }
                        
                        // Resolve Dealer ID
                        $dealer_id = null;
                        if (!empty($dealer_username)) {
                            $dlrStmt = $pdo->prepare("SELECT id FROM dealers WHERE username = ? AND client_id = ? LIMIT 1");
                            $dlrStmt->execute([$dealer_username, $client_id]);
                            $did = $dlrStmt->fetchColumn();
                            if ($did) $dealer_id = (int)$did;
                        }
                        
                        // Check if user exists
                        $chkStmt = $pdo->prepare("SELECT id FROM subscribers WHERE username = ? AND client_id = ?");
                        $chkStmt->execute([$username, $client_id]);
                        $existing_id = $chkStmt->fetchColumn();
                        
                        if ($existing_id) {
                            // UPDATE
                            $upd = $pdo->prepare("UPDATE subscribers SET 
                                password=?, full_name=?, service_type=?, package_id=?, dealer_id=?, 
                                mobile=?, phone=?, national_id=?, city=?, subarea=?, address=?, 
                                gps_lat=?, gps_lng=?, notes=?, balance=?, status=? 
                                WHERE id=?");
                            $upd->execute([
                                $password, $full_name, $service_type, $package_id, $dealer_id,
                                $mobile, $phone, $national_id, $city, $subarea, $address,
                                $gps_lat, $gps_lng, $notes, $balance, $status, $existing_id
                            ]);
                            
                            // Update Radcheck Password
                            $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$password, $username]);
                            
                        } else {
                            // INSERT NEW
                            $ins = $pdo->prepare("INSERT INTO subscribers (client_id, username, password, full_name, service_type, package_id, dealer_id, mobile, phone, national_id, city, subarea, address, gps_lat, gps_lng, notes, balance, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([
                                $client_id, $username, $password, $full_name, $service_type, $package_id, $dealer_id,
                                $mobile, $phone, $national_id, $city, $subarea, $address,
                                $gps_lat, $gps_lng, $notes, $balance, $status
                            ]);
                            
                            // RADIUS Basics
                            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
                            if ($package_name) {
                                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$username, $package_name]);
                            }
                            // NAS Restriction trigger will handle NAS-IP-Address
                        }
                        
                        // Process Expiry
                        if (!empty($expiry) && $expiry !== 'N/A') {
                            $parsed_time = strtotime($expiry);
                            if ($parsed_time !== false) {
                                $db_expiry = date('Y-m-d H:i:s', $parsed_time);
                                $formatted_expiry = date('d M Y H:i:s', $parsed_time);
                                
                                $pdo->prepare("UPDATE subscribers SET expiry_date = ? WHERE username = ? AND client_id = ?")->execute([$db_expiry, $username, $client_id]);
                                
                                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                            }
                        }

                    } else {
                        // OLD BASIC FORMAT
                        if (count($data) >= 6) {
                            $id = (int)$data[0];
                            $username = trim($data[1]);
                            $expiry = trim($data[5]);
                            
                            if (!empty($expiry) && $expiry !== 'N/A') {
                                $parsed_time = strtotime($expiry);
                                if ($parsed_time !== false) {
                                    $db_expiry = date('Y-m-d H:i:s', $parsed_time);
                                    $formatted_expiry = date('d M Y H:i:s', $parsed_time);
                                    
                                    $pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = 'active' WHERE id = ? AND client_id = ?")->execute([$db_expiry, $id, $client_id]);
                                    
                                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                                }
                            }
                        }
                    }
                }
                $pdo->commit();
                echo "<script>alert('Backup Restored Successfully!'); window.location='dashboard.php';</script>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error parsing CSV file: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
                exit;
            }
            fclose($file);
        }
    }
}

PHP;

    $content = str_replace($oldRestoreLogic, $newRestoreLogic, $content);
    
    // Also update the UI button text if needed
    $content = str_replace(
        '<i class="fa-solid fa-upload text-warning"></i><span>Restore Expiry</span>',
        '<i class="fa-solid fa-upload text-warning"></i><span>Restore CSV</span>',
        $content
    );

    file_put_contents($file, $content);
    echo "Updated dashboard.php restore logic successfully.\n";
} else {
    echo "Could not find the restore block in dashboard.php.\n";
}
?>
