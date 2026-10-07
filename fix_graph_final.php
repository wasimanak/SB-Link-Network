<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// Use Regex to replace the mbps calculation safely
$pattern = '/let tx_mbps = 0;\s*let rx_mbps = 0;\s*if \(prevBytesIn !== null.*?\s*\}\s*\}/s';

$replacement = '
                  let tx_mbps = 0;
                  let rx_mbps = 0;

                  if (data.rx_bps !== undefined && data.tx_bps !== undefined) {
                      // We got direct bps from monitor-traffic API
                      rx_mbps = data.rx_bps / 1048576;
                      tx_mbps = data.tx_bps / 1048576;
                  } else if (prevBytesIn !== null && prevBytesOut !== null && lastTime !== null) {
                      let timeDiffSecs = (nowTime - lastTime) / 1000;
                      if (timeDiffSecs > 0) {
                          let bytesInDiff = currentBytesIn - prevBytesIn;
                          let bytesOutDiff = currentBytesOut - prevBytesOut;
                          if(bytesInDiff < 0) bytesInDiff = 0;
                          if(bytesOutDiff < 0) bytesOutDiff = 0;
                          rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576;
                          tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576;
                      }
                  }
';

$c = preg_replace($pattern, $replacement, $c);
file_put_contents($f, $c);
echo "operator/subscriber_view.php JS Graph logic patched.\n";

// Also fix lineman/api_bandwidth.php by copying the new fast logic from operator/api_bandwidth.php
$op_api = file_get_contents('operator/api_bandwidth.php');
// Replace session variable from operator_id to lineman_id
$lm_api = str_replace("\$_SESSION['operator_id']", "\$_SESSION['client_id']", $op_api);
$lm_api = str_replace("!isset(\$_SESSION['client_id'])", "!isset(\$_SESSION['lineman_id'])", $lm_api);

file_put_contents('lineman/api_bandwidth.php', $lm_api);
echo "lineman/api_bandwidth.php synchronized with new monitor-traffic logic.\n";

?>
