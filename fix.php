<?php
$f = 'superadmin/operator_edit.php';
$c = file_get_contents($f);
$c = preg_replace('/elseif \(\$action === \'update_router\'\).*?catch \(Exception \$e\) \{.*?\}\s*\}\s*\}/s', '', $c);
$c = preg_replace('/<!-- MikroTik Settings -->.*?(<\?php require_once \'footer\.php\'; \?>)/s', "</div>\n</div>\n\n$1", $c);
file_put_contents($f, $c);
echo "Done.";
?>
