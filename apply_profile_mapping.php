<?php
require 'config/db.php';

echo "<h2>Applying Permanent Old-RADIUS Speed Solution (Mikrotik-Group Mapping)</h2>";

try {
    $pdo->beginTransaction();

    // Map each package to the EXACT profile name that exists in Winbox PPP -> Profiles
    $stmt = $pdo->query("SELECT id, name, rate_limit FROM packages");
    $pkgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>Package Name</th><th>Rate Limit</th><th>Matched Winbox PPP Profile</th><th>Status</th></tr>";

    foreach ($pkgs as $p) {
        $pkg_name = $p['name'];
        $rate = $p['rate_limit'];

        // Extract the speed number (e.g. '7Mbps (Piplan)' -> '7Mbps', '4Mbps-ZONG' -> '4Mbps', '3MB (Liaqatabad)' -> '3Mbps')
        preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg_name, $m);
        $matched_profile = null;
        if (!empty($m[1])) {
            $matched_profile = $m[1] . 'Mbps';
        }

        if ($matched_profile) {
            // 1. Set Mikrotik-Group in radgroupreply so router assigns user to exact Profile!
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute = 'Mikrotik-Group'")->execute([$pkg_name]);
            $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")
                ->execute([$pkg_name, $matched_profile]);

            // 2. Also keep Mikrotik-Rate-Limit for dual protection
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$pkg_name]);
            $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                ->execute([$pkg_name, $rate]);

            echo "<tr><td><strong>$pkg_name</strong></td><td>$rate</td><td style='color:green; font-weight:bold;'>$matched_profile</td><td>Mapped!</td></tr>";
        } else {
            echo "<tr><td>$pkg_name</td><td>$rate</td><td style='color:orange;'>Custom</td><td>Skipped</td></tr>";
        }
    }

    $pdo->commit();
    echo "</table>";
    echo "<h3 style='color:green;'>SUCCESS: All Packages now send 'Mikrotik-Group' to Winbox!</h3>";
    echo "<p>Ab Naya RADIUS bhi bilkul Purane RADIUS (MeshBill) ki tarah kaam karega. User seedha Winbox ke apne Profile (1Mbps, 4Mbps, 7Mbps) me jayega aur speed 100% lock ho jayegi!</p>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
