<?php
$f = 'lineman/dashboard.php';
$c = file_get_contents($f);

$schemaUpgrade = "
// Auto-upgrade subscribers table to support linemen
try {
    \$pdo->exec(\"ALTER TABLE `subscribers` ADD COLUMN `lineman_id` int(11) DEFAULT 0\");
} catch (PDOException \$e) {
    // Silently ignore if already exists
}
";

// Insert right after require_once '../config/db.php';
$insertPoint = "require_once '../config/db.php';";

if (strpos($c, "ALTER TABLE `subscribers` ADD COLUMN `lineman_id`") === false) {
    $c = str_replace($insertPoint, $insertPoint . "\n" . $schemaUpgrade, $c);
    file_put_contents($f, $c);
    echo "Added lineman_id column upgrade to $f\n";
} else {
    echo "Already present in $f\n";
}
?>
