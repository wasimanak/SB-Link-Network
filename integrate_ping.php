<?php
$f = 'lineman/dashboard.php';
$c = file_get_contents($f);

// 1. Add the UI Button and Results Container
$oldAlert = '<div class="alert alert-success fw-bold border-0 shadow-sm d-flex justify-content-between align-items-center">
                                <div><i class="fa-solid fa-circle-check me-2 fs-5 align-middle"></i> 🟢 <strong>Status OK:</strong> User is waqt theek tarah se connected hai. IP: <?= $search_result[\'live_ip\'] ?></div>
                            </div>';

$newAlert = '<div class="alert alert-success fw-bold border-0 shadow-sm d-flex justify-content-between align-items-center">
                                <div><i class="fa-solid fa-circle-check me-2 fs-5 align-middle"></i> 🟢 <strong>Status OK:</strong> User is waqt theek tarah se connected hai. IP: <?= $search_result[\'live_ip\'] ?></div>
                                <button type="button" class="btn btn-sm btn-dark px-3 fw-bold shadow-sm" onclick="testLineQuality(\'<?= $search_result[\'live_ip\'] ?>\')"><i class="fa-solid fa-microscope me-2"></i> Test Line / Fiber Quality</button>
                            </div>
                            <div id="ping_results" class="mt-3 mb-4 d-none">
                                <div class="card border-0 shadow-sm" style="background: #f8fafc;">
                                    <div class="card-body p-3">
                                        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-satellite-dish me-2 text-primary"></i> Live Optical/Fiber Diagnostics (MikroTik Core)</h6>
                                        <div class="row g-3 text-center" id="ping_stats">
                                            <div class="col-4">
                                                <div class="small text-secondary fw-bold text-uppercase mb-1">Packet Loss</div>
                                                <div class="fs-4 fw-bold text-dark" id="p_loss">--</div>
                                            </div>
                                            <div class="col-4 border-start border-end">
                                                <div class="small text-secondary fw-bold text-uppercase mb-1">Latency / Delay</div>
                                                <div class="fs-4 fw-bold text-dark" id="p_latency">--</div>
                                            </div>
                                            <div class="col-4">
                                                <div class="small text-secondary fw-bold text-uppercase mb-1">Network Health</div>
                                                <div class="fs-4 fw-bold" id="p_health">--</div>
                                            </div>
                                        </div>
                                        <div id="p_msg" class="alert alert-secondary mb-0 mt-3 small fw-bold border-0"><i class="fa-solid fa-spinner fa-spin me-2"></i> Analyzing optical line stability... please wait (takes ~5 seconds).</div>
                                    </div>
                                </div>
                            </div>';

$c = str_replace($oldAlert, $newAlert, $c);

// 2. Add JavaScript
$js = '
function testLineQuality(ip) {
    document.getElementById(\'ping_results\').classList.remove(\'d-none\');
    document.getElementById(\'ping_stats\').style.opacity = \'0.5\';
    document.getElementById(\'p_msg\').className = \'alert alert-secondary mb-0 mt-3 small fw-bold border-0\';
    document.getElementById(\'p_msg\').innerHTML = \'<i class="fa-solid fa-spinner fa-spin me-2"></i> Analyzing optical line stability... please wait (takes ~4 seconds).\';
    
    fetch(\'api_ping.php?ip=\' + ip)
    .then(res => res.json())
    .then(data => {
        document.getElementById(\'ping_stats\').style.opacity = \'1\';
        if (data.error) {
            document.getElementById(\'p_msg\').className = \'alert alert-danger mb-0 mt-3 small fw-bold border-0\';
            document.getElementById(\'p_msg\').innerHTML = \'<i class="fa-solid fa-triangle-exclamation me-2"></i> \' + data.error;
            return;
        }
        
        document.getElementById(\'p_loss\').innerHTML = data.loss + \'%\';
        document.getElementById(\'p_latency\').innerHTML = data.avg_rtt + \' ms\';
        
        if (data.status === \'ok\') {
            document.getElementById(\'p_health\').innerHTML = \'<span class="text-success"><i class="fa-solid fa-face-smile"></i> Excellent</span>\';
            document.getElementById(\'p_msg\').className = \'alert alert-success mb-0 mt-3 small fw-bold border-0\';
        } else if (data.status === \'warning\') {
            document.getElementById(\'p_health\').innerHTML = \'<span class="text-warning"><i class="fa-solid fa-face-frown"></i> Weak Signal</span>\';
            document.getElementById(\'p_msg\').className = \'alert alert-warning text-dark mb-0 mt-3 small fw-bold border-0\';
        } else {
            document.getElementById(\'p_health\').innerHTML = \'<span class="text-danger"><i class="fa-solid fa-face-dizzy"></i> Disconnected</span>\';
            document.getElementById(\'p_msg\').className = \'alert alert-danger mb-0 mt-3 small fw-bold border-0\';
        }
        
        document.getElementById(\'p_msg\').innerHTML = \'<i class="fa-solid fa-circle-info me-2"></i> <strong>Result:</strong> \' + data.msg;
    })
    .catch(err => {
        document.getElementById(\'ping_stats\').style.opacity = \'1\';
        document.getElementById(\'p_msg\').className = \'alert alert-danger mb-0 mt-3 small fw-bold border-0\';
        document.getElementById(\'p_msg\').innerHTML = \'Network error checking line quality.\';
    });
}
';

$c = str_replace('</body>', $js . "\n</body>", $c);

file_put_contents($f, $c);
echo "Integrated Line Quality Test into lineman/dashboard.php.\n";
?>
