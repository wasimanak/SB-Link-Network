<?php
$f = 'operator/api_bandwidth.php';
$c = file_get_contents($f);

// Inject a debug logger
$debug_code = '
                $api->write(\'/interface/print\', false);
                $api->write(\'?name=\' . $iname, true);
                $iface = $api->read();
                
                // DEBUG LOGGING
                file_put_contents("../scratch/mikrotik_debug.txt", print_r($iface, true));
';

$c = preg_replace('/\$api->write\(\'\/interface\/print\', false\);\s*\$api->write\(\'\?name=\' \. \$iname, true\);\s*\$iface = \$api->read\(\);/s', $debug_code, $c);

file_put_contents($f, $c);
echo "Debug logging injected into api_bandwidth.php\n";
?>
