<?php
// Fallback redirect for /admin/settings to /admin/profile
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['is_manager'])) {
    $my_mgr_id = (int)($_SESSION['manager_id'] ?? 0);
    header("Location: manager_view.php?id=" . $my_mgr_id);
    exit;
}
header("Location: profile");
exit;