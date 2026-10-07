<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);
$c = str_replace("</div>\r\n\r\n<!-- Renew Modal -->", "</div>\n<?php endif; ?>\n\n<!-- Renew Modal -->", $c);
$c = str_replace("</div>\n\n<!-- Renew Modal -->", "</div>\n<?php endif; ?>\n\n<!-- Renew Modal -->", $c);
file_put_contents($f, $c);
echo "Fixed missing endif in dealer/users.php.\n";
?>
