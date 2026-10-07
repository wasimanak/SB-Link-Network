<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// Include BOTH closed and active sessions for Total Volume
$oldQuery = 'SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL';
$newQuery = 'SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ?';
$c = str_replace($oldQuery, $newQuery, $c);

// Also in Javascript, since past_bytes now INCLUDES current session, we SHOULD NOT add currentLiveBytes to it again,
// OTHERWISE we double-count the data! 
// Actually, if we fetch from API, currentLiveBytes is the CURRENT session's data.
// So if we include current session in past_bytes, and ALSO add currentLiveBytes from API, we will DOUBLE COUNT the current session.
// So we must SUBTRACT the current session's RADIUS data from past_bytes, OR we just trust the API for current session and keep past_bytes as closed sessions.
// Wait, if API is failing, we should use RADIUS data.

// Let's change the logic in PHP:
$phpFix = '
  $volStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL");
  $volStmt->execute([$user[\'username\']]);
  $vol = $volStmt->fetch();
  $past_bytes = ($vol[\'up\'] ?? 0) + ($vol[\'down\'] ?? 0);
  
  $liveStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ? AND acctstoptime IS NULL");
  $liveStmt->execute([$user[\'username\']]);
  $liveVol = $liveStmt->fetch();
  $radius_live_bytes = ($liveVol[\'up\'] ?? 0) + ($liveVol[\'down\'] ?? 0);
  
  $total_bytes = $past_bytes + $radius_live_bytes;
';

$oldVolBlock = '
  // Fetch metrics from past sessions (closed sessions)
  $volStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL");
  $volStmt->execute([$user[\'username\']]);
  $vol = $volStmt->fetch();
  
  $past_bytes = ($vol[\'up\'] ?? 0) + ($vol[\'down\'] ?? 0);
  $total_bytes = $past_bytes; // Initial total (will be updated by JS if online)
';

$c = str_replace($oldVolBlock, $phpFix, $c);

// Update Javascript to handle API failure smoothly
$oldJsBytes = '
                  // Update Used Volume LIVE
                  let currentLiveBytes = data.bytes_in + data.bytes_out;
                  let totalCurrentBytes = pastBytes + currentLiveBytes;
';

$newJsBytes = '
                  // Update Used Volume LIVE
                  let currentLiveBytes = data.bytes_in + data.bytes_out;
                  let totalCurrentBytes = 0;
                  
                  // If API returned 0 (failed or missing interface), fallback to RADIUS live bytes!
                  if (currentLiveBytes === 0) {
                      totalCurrentBytes = pastBytes + <?= $radius_live_bytes ?>;
                  } else {
                      totalCurrentBytes = pastBytes + currentLiveBytes;
                  }
';
$c = str_replace($oldJsBytes, $newJsBytes, $c);

file_put_contents($f, $c);
echo "subscriber_view.php patched to fallback to RADIUS Interim data.\n";
?>
