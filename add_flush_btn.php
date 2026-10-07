<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// 1. Add Flush All AJAX Handler
$flushLogic = '
// Handle AJAX Flush Request
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'action\']) && $_POST[\'action\'] === \'flush_router\') {
    header(\'Content-Type: application/json\');
    $nas_id = (int)$_POST[\'nas_id\'];
    
    try {
        $nStmt = $pdo->prepare("SELECT * FROM nas WHERE id = ?");
        $nStmt->execute([$nas_id]);
        $nas = $nStmt->fetch();
        if (!$nas) throw new Exception("Router not found.");
        
        // Force all active sessions for this router to offline in DB
        $updateStmt = $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = \'Admin-Flush\' WHERE nasipaddress = ? AND acctstoptime IS NULL");
        $updateStmt->execute([$nas[\'nasname\']]);
        $flushed = $updateStmt->rowCount();
        
        echo json_encode([\'success\' => true, \'msg\' => "Database flushed! $flushed sessions forcefully marked as offline."]);
    } catch (Exception $e) {
        echo json_encode([\'error\' => $e->getMessage()]);
    }
    exit;
}
';

$c = preg_replace('/(\/\/ Handle AJAX Sync Request)/', $flushLogic . "\n$1", $c);

// 2. Add Flush Button to UI
$uiTarget = '<button type="button" class="btn btn-sm btn-outline-primary me-3 sync-btn" data-nas="<?= $router[\'id\'] ?>" title="Sync Router (Clear Ghost Sessions)" onclick="syncRouter(event, this)">
                            <i class="fa-solid fa-rotate"></i> Sync
                        </button>';
$uiReplacement = $uiTarget . '
                        <button type="button" class="btn btn-sm btn-outline-danger me-3 flush-btn" data-nas="<?= $router[\'id\'] ?>" title="Force Clear All Database Sessions for this Router" onclick="flushRouter(event, this)">
                            <i class="fa-solid fa-broom"></i> Flush DB
                        </button>';
                        
$c = str_replace($uiTarget, $uiReplacement, $c);

// 3. Add JS for Flush
$jsTarget = '</script>';
$jsReplacement = '
function flushRouter(e, btn) {
    e.stopPropagation();
    if (confirm("WARNING: This will forcefully clear ALL online users for this router from the dashboard database. Use this ONLY if your RADIUS is disabled in MikroTik but users are still stuck as online. Proceed?")) {
        const nas_id = btn.getAttribute("data-nas");
        const originalHtml = btn.innerHTML;
        btn.innerHTML = \'<i class="fa-solid fa-spinner fa-spin"></i>\';
        btn.disabled = true;
        
        const formData = new FormData();
        formData.append("action", "flush_router");
        formData.append("nas_id", nas_id);
        
        fetch("live_routers.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert(data.msg);
                location.reload();
            } else {
                alert("Error: " + data.error);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert("Network error while flushing router.");
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
}
</script>
';

$c = str_replace($jsTarget, $jsReplacement, $c);

file_put_contents($f, $c);
echo "Added Flush DB feature to superadmin/live_routers.php\n";
?>
