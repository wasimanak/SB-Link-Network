<?php
require_once 'auth_check.php';
// Stock and inventory items have been migrated to Operator/Admin portal
header("Location: dashboard.php");
exit;
