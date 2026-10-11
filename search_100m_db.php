<?php
require 'config/db.php';

echo "<h2>Global Database Search for '100M'</h2>";

$tables = ['packages', 'subscribers', 'radcheck', 'radreply', 'radgroupreply', 'global_settings'];

foreach ($tables as $t) {
    try {
        $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_COLUMN);
        $clauses = [];
        foreach ($cols as $c) {
            $clauses[] = "`$c` LIKE '%100M%'";
        }
        $sql = "SELECT * FROM `$t` WHERE " . implode(' OR ', $clauses);
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>Table '$t': Found " . count($rows) . " matches</h3>";
        if (count($rows) > 0) {
            echo "<pre>" . print_r(array_slice($rows, 0, 5), true) . "</pre>";
        }
    } catch (Exception $e) {
        echo "<p>Table '$t' error: " . $e->getMessage() . "</p>";
    }
}
?>
