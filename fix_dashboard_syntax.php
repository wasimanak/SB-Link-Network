<?php
$f = 'superadmin/dashboard.php';
$c = file_get_contents($f);

// Remove the accidental nested PHP tag
$c = str_replace("require_once 'header.php';\n<?php", "require_once 'header.php';\n", $c);
// Also remove the closing tag from the injected handler to keep the script in PHP mode
$c = str_replace("}\n?>\n\ntry {", "}\n\ntry {", $c);

file_put_contents($f, $c);
echo "Fixed nested PHP tags.\n";
?>
