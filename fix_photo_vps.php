<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

$old_block = "        elseif (\$action === 'upload_photo') {
            if (isset(\$_FILES['profile_photo']) && \$_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                \$ext = strtolower(pathinfo(\$_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                \$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (in_array(\$ext, \$allowed)) {
                    \$filename = 'user_' . \$id . '_' . time() . '.' . \$ext;
                    \$dest = '../uploads/profiles/' . \$filename;
                    if (move_uploaded_file(\$_FILES['profile_photo']['tmp_name'], \$dest)) {
                        // Delete old photo if exists
                        \$oldStmt = \$pdo->prepare('SELECT photo FROM subscribers WHERE id=?');
                        \$oldStmt->execute([\$id]);
                        \$oldPhoto = \$oldStmt->fetchColumn();
                        if (\$oldPhoto && file_exists('../uploads/profiles/' . \$oldPhoto)) {
                            unlink('../uploads/profiles/' . \$oldPhoto);
                        }
                        
                        \$pdo->prepare('UPDATE subscribers SET photo = ? WHERE id=?')->execute([\$filename, \$id]);
                        \$pdo->prepare(\"INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Updated Profile Photo')\")->execute([\$client_id, \$u]);
                        echo \"<script>window.location='subscriber_view.php?id=\$id';</script>\";
                        exit;
                    }
                }
            }
            echo \"<script>alert('Failed to upload photo. Please ensure it is a valid image file (JPG, PNG, GIF, WEBP).'); window.location='subscriber_view.php?id=\$id';</script>\";
            exit;
        }";

$new_block = "        elseif (\$action === 'upload_photo') {
            if (!isset(\$_FILES['profile_photo'])) {
                echo \"<script>alert('No file uploaded.'); window.location='subscriber_view.php?id=\$id';</script>\";
                exit;
            }
            if (\$_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                \$errCode = \$_FILES['profile_photo']['error'];
                echo \"<script>alert('Upload error code: \$errCode. (1=Too large for PHP, 2=Too large for form, 3=Partial, 4=No file)'); window.location='subscriber_view.php?id=\$id';</script>\";
                exit;
            }
            
            \$ext = strtolower(pathinfo(\$_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            \$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array(\$ext, \$allowed)) {
                echo \"<script>alert('Invalid file format: \$ext. Allowed: JPG, PNG, GIF, WEBP'); window.location='subscriber_view.php?id=\$id';</script>\";
                exit;
            }

            // Ensure directory exists
            \$uploadDir = '../uploads/profiles/';
            if (!is_dir(\$uploadDir)) {
                mkdir(\$uploadDir, 0777, true);
            }

            \$filename = 'user_' . \$id . '_' . time() . '.' . \$ext;
            \$dest = \$uploadDir . \$filename;
            
            if (move_uploaded_file(\$_FILES['profile_photo']['tmp_name'], \$dest)) {
                
                // Safely add column if it doesn't exist
                try {
                    \$pdo->exec(\"ALTER TABLE subscribers ADD COLUMN photo VARCHAR(255) DEFAULT NULL\");
                } catch (PDOException \$e) {
                    // Ignore, column likely exists
                }

                try {
                    // Delete old photo if exists
                    \$oldStmt = \$pdo->prepare('SELECT photo FROM subscribers WHERE id=?');
                    \$oldStmt->execute([\$id]);
                    \$oldPhoto = \$oldStmt->fetchColumn();
                    if (\$oldPhoto && file_exists('../uploads/profiles/' . \$oldPhoto)) {
                        unlink('../uploads/profiles/' . \$oldPhoto);
                    }
                    
                    \$pdo->prepare('UPDATE subscribers SET photo = ? WHERE id=?')->execute([\$filename, \$id]);
                    \$pdo->prepare(\"INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Updated Profile Photo')\")->execute([\$client_id, \$u]);
                    
                    echo \"<script>window.location='subscriber_view.php?id=\$id';</script>\";
                    exit;
                } catch (PDOException \$e) {
                    \$dbErr = addslashes(\$e->getMessage());
                    echo \"<script>alert('Database error: \$dbErr'); window.location='subscriber_view.php?id=\$id';</script>\";
                    exit;
                }
            } else {
                echo \"<script>alert('Server error: Failed to save file. Check directory permissions for uploads/profiles/'); window.location='subscriber_view.php?id=\$id';</script>\";
                exit;
            }
        }";

if (strpos($c, "Upload error code:") === false) {
    $c = str_replace($old_block, $new_block, $c);
    file_put_contents($f, $c);
    echo "Replaced block successfully.";
} else {
    echo "Already updated.";
}
?>
