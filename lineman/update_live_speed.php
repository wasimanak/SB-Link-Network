<?php
$file = 'C:/xampp/htdocs/SB Link Network/lineman/dashboard.php';
$content = file_get_contents($file);

// Replace the static total usage boxes with live speed boxes in the search result
$oldBoxes = <<<'HTML'
                            <div class="row mt-2 g-3">
                                <div class="col-6">
                                    <div class="stat-box border-success bg-opacity-10">
                                        <i class="fa-solid fa-arrow-down text-success fs-4 mb-2"></i>
                                        <div class="text-secondary small fw-bold">Download Usage</div>
                                        <div class="fs-5 fw-bold text-success"><?= formatBytes($search_result['download_bytes']) ?></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box border-primary bg-opacity-10">
                                        <i class="fa-solid fa-arrow-up text-primary fs-4 mb-2"></i>
                                        <div class="text-secondary small fw-bold">Upload Usage</div>
                                        <div class="fs-5 fw-bold text-primary"><?= formatBytes($search_result['upload_bytes']) ?></div>
                                    </div>
                                </div>
                            </div>
HTML;

$newBoxes = <<<'HTML'
                            <div class="row mt-2 g-3">
                                <div class="col-6">
                                    <div class="stat-box border-success bg-opacity-10">
                                        <i class="fa-solid fa-arrow-down text-success fs-4 mb-2"></i>
                                        <div class="text-secondary small fw-bold">Total Download Usage</div>
                                        <div class="fs-5 fw-bold text-success"><?= formatBytes($search_result['download_bytes']) ?></div>
                                        <div class="mt-2 border-top pt-2">
                                            <span class="badge bg-success"><i class="fa-solid fa-bolt"></i> Live: <span id="live_down">0.00</span> Mbps</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box border-primary bg-opacity-10">
                                        <i class="fa-solid fa-arrow-up text-primary fs-4 mb-2"></i>
                                        <div class="text-secondary small fw-bold">Total Upload Usage</div>
                                        <div class="fs-5 fw-bold text-primary"><?= formatBytes($search_result['upload_bytes']) ?></div>
                                        <div class="mt-2 border-top pt-2">
                                            <span class="badge bg-primary"><i class="fa-solid fa-bolt"></i> Live: <span id="live_up">0.00</span> Mbps</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
HTML;

$content = str_replace($oldBoxes, $newBoxes, $content);

// Add the JS polling logic at the bottom before </body>
$js = <<<'HTML'
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if(isset($_GET['search_username']) && $search_result): ?>
<script>
let lastBytesIn = null;
let lastBytesOut = null;
let lastTime = null;

function fetchLiveBandwidth() {
    fetch('api_bandwidth.php?username=<?= urlencode($search_result['username']) ?>')
        .then(response => response.json())
        .then(data => {
            if (data.error || data.msg) {
                document.getElementById('live_down').innerText = "0.00";
                document.getElementById('live_up').innerText = "0.00";
                return;
            }

            let currentBytesIn = data.bytes_in;
            let currentBytesOut = data.bytes_out;
            let currentTime = Date.now();

            if (lastBytesIn !== null && lastBytesOut !== null && lastTime !== null) {
                let timeDiffSecs = (currentTime - lastTime) / 1000;
                
                if (timeDiffSecs > 0) {
                    let bytesInDiff = currentBytesIn - lastBytesIn;
                    let bytesOutDiff = currentBytesOut - lastBytesOut;

                    // Sometimes queue bytes reset or router restarts
                    if (bytesInDiff < 0) bytesInDiff = 0;
                    if (bytesOutDiff < 0) bytesOutDiff = 0;

                    // Calculate Mbps (Megabits per second)
                    // Note: In Mikrotik Hotspot/Queue:
                    // bytes-in is Traffic FROM User to Router (Upload)
                    // bytes-out is Traffic FROM Router to User (Download)
                    let rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576; // Upload
                    let tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576; // Download

                    document.getElementById('live_up').innerText = rx_mbps.toFixed(2);
                    document.getElementById('live_down').innerText = tx_mbps.toFixed(2);
                }
            }

            lastBytesIn = currentBytesIn;
            lastBytesOut = currentBytesOut;
            lastTime = currentTime;
        })
        .catch(err => console.error("Error fetching bandwidth:", err));
}

// Fetch every 3 seconds
setInterval(fetchLiveBandwidth, 3000);
fetchLiveBandwidth(); // initial call
</script>
<?php endif; ?>
</body>
HTML;

$content = str_replace('<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>', $js, $content);

file_put_contents($file, $content);
echo "Added Live Speed logic to lineman dashboard.\n";
?>
