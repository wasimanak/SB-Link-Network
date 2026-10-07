<?php
$f = 'operator/api_bandwidth.php';
$c = file_get_contents($f);

$oldCheck = '
        // 1. Check PPPoE Interface (Most Reliable for Bandwidth)
        $api->write(\'/interface/print\', false);
        $api->write(\'?name=<pppoe-\' . $username . \'>\', true);
        $iface = $api->read();
        
        if (!empty($iface) && isset($iface[0][\'rx-byte\'])) {
';

$newCheck = '
        // 1. Check PPPoE Interface (Most Reliable for Bandwidth)
        $api->write(\'/interface/print\', false);
        $api->write(\'?name=<pppoe-\' . $username . \'>\', true);
        $iface = $api->read();
        
        // Fallback for some routers where interface name lacks brackets or uses different format
        if (empty($iface)) {
            $api->write(\'/interface/print\', false);
            $api->write(\'?name=pppoe-\' . $username, true);
            $iface = $api->read();
        }
        
        // Fallback to fetch by active PPP connection address if name is dynamic/random
        if (empty($iface)) {
            $api->write(\'/ppp/active/print\', false);
            $api->write(\'?name=\' . $username, true);
            $ppp = $api->read();
            if (!empty($ppp) && isset($ppp[0][\'address\'])) {
                $userIp = $ppp[0][\'address\'];
                $api->write(\'/interface/print\', false);
                $api->write(\'?mac-address=\' . $ppp[0][\'caller-id\'], true); // Try by MAC
                $iface = $api->read();
            }
        }
        
        if (!empty($iface) && isset($iface[0][\'rx-byte\'])) {
';

$c = str_replace($oldCheck, $newCheck, $c);
file_put_contents($f, $c);
echo "api_bandwidth.php improved to find PPPoE interfaces better.\n";
?>
