<?php
// Create the AJAX handler
$ajax_code = '<?php
session_start();
require_once \'../config/db.php\';
require_once \'../config/routeros_api.class.php\';

header(\'Content-Type: application/json\');

if (!isset($_SESSION[\'operator_id\'])) {
    echo json_encode([\'status\' => \'error\', \'message\' => \'Unauthorized\']);
    exit;
}

$client_id = $_SESSION[\'operator_id\'];
$nas_id = (int)($_POST[\'nas_id\'] ?? 0);

$stmt = $pdo->prepare("SELECT nasname, api_user, api_password FROM nas WHERE id = ? AND client_id = ?");
$stmt->execute([$nas_id, $client_id]);
$nas = $stmt->fetch();

if (!$nas) {
    echo json_encode([\'status\' => \'error\', \'message\' => \'Router not found.\']);
    exit;
}

$API = new RouterosAPI();
$API->timeout_delay = 3; // Fast timeout
error_reporting(0);

if ($API->connect($nas[\'nasname\'], $nas[\'api_user\'], $nas[\'api_password\'])) {
    $API->disconnect();
    echo json_encode([\'status\' => \'success\', \'message\' => \'Connected\']);
} else {
    echo json_encode([\'status\' => \'error\', \'message\' => \'Timeout / Auth Failed\']);
}
?>';
file_put_contents('operator/check_router_status.php', $ajax_code);

// Update profile.php
$f = 'operator/profile.php';
$c = file_get_contents($f);

// 1. Add CSS for live-dot
$css = '<style>
    .live-dot {
        display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse-green 1.5s infinite;
    }
    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
    .view-card';

if (strpos($c, 'pulse-green') === false) {
    $c = str_replace('<style>
    .view-card', $css, $c);
}

// 2. Inject span into router title
$oldHtml = '<h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-microchip text-primary me-1"></i> <?= htmlspecialchars($r[\'shortname\'] ?: \'Router\') ?></h6>';
$newHtml = '<div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-microchip text-primary me-1"></i> <?= htmlspecialchars($r[\'shortname\'] ?: \'Router\') ?></h6>
                <div class="router-status" data-id="<?= $r[\'id\'] ?>"><span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span></div>
            </div>';

if (strpos($c, 'router-status') === false) {
    $c = str_replace($oldHtml, $newHtml, $c);
}

// 3. Inject JS function at the end
$js = 'function checkRouterStatuses() {
    document.querySelectorAll(".router-status").forEach(el => {
        let nasId = el.dataset.id;
        el.innerHTML = \'<span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span>\';
        
        fetch("check_router_status.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "nas_id=" + nasId
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === "success") {
                el.innerHTML = \'<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-50"><span class="live-dot me-1"></span> Connected</span>\';
            } else {
                el.innerHTML = \'<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-50" title="\'+data.message+\'"><i class="fa-solid fa-circle-xmark me-1"></i> Offline (\'+data.message+\')</span>\';
            }
        })
        .catch(err => {
            el.innerHTML = \'<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-50"><i class="fa-solid fa-triangle-exclamation me-1"></i> Error</span>\';
        });
    });
}

document.getElementById("routerModal").addEventListener("show.bs.modal", function () {
    checkRouterStatuses();
});';

if (strpos($c, 'checkRouterStatuses()') === false) {
    $c = str_replace('function copyScript() {', $js . "\n\nfunction copyScript() {", $c);
    file_put_contents($f, $c);
    echo "Added router live status check.\n";
} else {
    echo "Already added.\n";
}
?>
