<?php
$date = '2026-09-28';
$time = '09:38:40';
$time_str = $date . ' ' . $time;
$timestamp = strtotime($time_str);
echo "Time String: $time_str\n";
echo "Timestamp: $timestamp\n";
echo "JSON: " . json_encode(['status' => 'success', 'timestamp' => $timestamp]) . "\n";
?>
