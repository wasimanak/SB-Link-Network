<?php
require 'config/db.php';

echo "<h2>Fixing FreeRADIUS Reply Operator (op = '=')</h2>";

try {
    $pdo->beginTransaction();

    // In FreeRADIUS, all reply attributes in radreply and radgroupreply MUST use '=' as the operator
    $aff1 = $pdo->exec("UPDATE radreply SET op = '=' WHERE attribute = 'Mikrotik-Rate-Limit'");
    echo "<p>Updated <strong>$aff1</strong> rows in radreply to op = '='.</p>";

    $aff2 = $pdo->exec("UPDATE radgroupreply SET op = '=' WHERE attribute = 'Mikrotik-Rate-Limit'");
    echo "<p>Updated <strong>$aff2</strong> rows in radgroupreply to op = '='.</p>";

    // Ensure aqeelshah has clean '=' in radreply
    $pdo->prepare("DELETE FROM radreply WHERE username = 'aqeelshah'")->execute();
    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES ('aqeelshah', 'Mikrotik-Rate-Limit', '=', '4M/4M')")->execute();
    echo "<p style='color:green;'>Saved 'aqeelshah' -> '4M/4M' with op = '=' in radreply.</p>";

    $pdo->commit();
    echo "<h3 style='color:green;'>SUCCESS: All Reply operators changed to '='!</h3>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
