<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// 1. Inject PHP upload action logic right after 'edit_profile' or 'add_balance' action
$action_upload = "
        elseif (\$action === 'upload_photo') {
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

if (strpos($c, "'upload_photo'") === false) {
    // find `elseif ($action === 'add_balance') {` and insert before it
    $c = str_replace("elseif (\$action === 'add_balance')", $action_upload . "\n        elseif (\$action === 'add_balance')", $c);
}

// 2. Replace avatar HTML
$avatar_html = "
                <?php if (!empty(\$user['photo']) && file_exists(\"../uploads/profiles/\" . \$user['photo'])): ?>
                    <div style=\"width: 60px; height: 60px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background-color: #e2e8f0; flex-shrink: 0;\">
                        <img src=\"../uploads/profiles/<?= htmlspecialchars(\$user['photo']) ?>\" alt=\"Profile\" style=\"width: 100%; height: 100%; object-fit: cover;\">
                    </div>
                <?php else: ?>
                    <div class=\"avatar-large\"><i class=\"fa-solid fa-user\"></i></div>
                <?php endif; ?>
";
$c = str_replace('<div class="avatar-large"><i class="fa-solid fa-user"></i></div>', trim($avatar_html), $c);

// 3. Replace Change Photo button
$btn_html = "
                <form method=\"POST\" enctype=\"multipart/form-data\" id=\"photoForm\" style=\"display:none;\">
                    <input type=\"hidden\" name=\"action\" value=\"upload_photo\">
                    <input type=\"file\" name=\"profile_photo\" id=\"photoInput\" accept=\"image/*\" onchange=\"document.getElementById('photoForm').submit();\">
                </form>
                <button type=\"button\" class=\"btn-pill\" onclick=\"document.getElementById('photoInput').click();\"><i class=\"fa-regular fa-image\"></i> Change Photo</button>
";
$c = str_replace('<button type="button" class="btn-pill"><i class="fa-regular fa-image"></i> Change Photo</button>', trim($btn_html), $c);

file_put_contents($f, $c);
echo "Photo upload integrated successfully.\n";
?>
