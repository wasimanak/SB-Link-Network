<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// Update JavaScript to use direct rx_bps from API
$oldJS = '
                  if (prevBytesIn !== null && prevBytesOut !== null && lastTime !== null) {
                      let timeDiffSecs = (nowTime - lastTime) / 1000;
                      if (timeDiffSecs > 0) {
                          // Bytes to Bits = * 8. Bits to Megabits = / 1048576
                          // Hotspot bytes-in is user\'s upload (Rx to router). bytes-out is user\'s download (Tx from router).
                          let bytesInDiff = currentBytesIn - prevBytesIn;
                          let bytesOutDiff = currentBytesOut - prevBytesOut;
                          
                          if(bytesInDiff < 0) bytesInDiff = 0;
                          if(bytesOutDiff < 0) bytesOutDiff = 0;
                          
                          rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576;
                          tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576;
                      }
                  }
';

$newJS = '
                  // If API provides exact live bits per second (via monitor-traffic)
                  if (data.rx_bps !== undefined && data.tx_bps !== undefined) {
                      // Hotspot rx-bits (bytes_in) is User Upload. tx-bits (bytes_out) is User Download.
                      rx_mbps = data.rx_bps / 1048576; // User Upload
                      tx_mbps = data.tx_bps / 1048576; // User Download
                  } else {
                      // Fallback manual calculation if API doesn\'t provide live bps
                      if (prevBytesIn !== null && prevBytesOut !== null && lastTime !== null) {
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
                  }
';

$c = str_replace($oldJS, $newJS, $c);
file_put_contents($f, $c);
echo "subscriber_view.php patched to use direct rx_bps for Live Graph.\n";
?>
