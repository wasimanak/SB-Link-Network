<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// 1. Add the Sync AJAX Handler
$syncLogic = '
// Handle AJAX Sync Request
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && isset($_POST[\'action\']) && $_POST[\'action\'] === \'sync_router\') {
    header(\'Content-Type: application/json\');
    $nas_id = (int)$_POST[\'nas_id\'];
    
    try {
        $nStmt = $pdo->prepare("SELECT * FROM nas WHERE id = ?");
        $nStmt->execute([$nas_id]);
        $nas = $nStmt->fetch();
        
        if (!$nas) throw new Exception("Router not found.");
        
        $api = new RouterosAPI();
        $api->timeout = 3;
        if ($api->connect($nas[\'nasname\'], $nas[\'api_user\'], $nas[\'api_password\'], $nas[\'api_port\'])) {
            // Get active PPPoE
            $api->write(\'/ppp/active/print\');
            $ppp = $api->read();
            // Get active Hotspot
            $api->write(\'/ip/hotspot/active/print\');
            $hotspot = $api->read();
            
            $api->disconnect();
            
            $active_users = [];
            if (!empty($ppp)) {
                foreach ($ppp as $p) { if(isset($p[\'name\'])) $active_users[] = strtolower($p[\'name\']); }
            }
            if (!empty($hotspot)) {
                foreach ($hotspot as $h) { if(isset($h[\'user\'])) $active_users[] = strtolower($h[\'user\']); }
            }
            
            // Get database active users for this router
            $dbUsersStmt = $pdo->prepare("SELECT radacctid, username FROM radacct WHERE nasipaddress = ? AND acctstoptime IS NULL");
            $dbUsersStmt->execute([$nas[\'nasname\']]);
            $db_users = $dbUsersStmt->fetchAll();
            
            $ghosts_cleared = 0;
            $updateStmt = $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = \'Ghost-Cleared\' WHERE radacctid = ?");
            
            foreach ($db_users as $dbu) {
                if (!in_array(strtolower($dbu[\'username\']), $active_users)) {
                    $updateStmt->execute([$dbu[\'radacctid\']]);
                    $ghosts_cleared++;
                }
            }
            
            echo json_encode([\'success\' => true, \'msg\' => "Sync complete! Cleared $ghosts_cleared ghost sessions."]);
        } else {
            throw new Exception("Could not connect to MikroTik router via API to sync.");
        }
    } catch (Exception $e) {
        echo json_encode([\'error\' => $e->getMessage()]);
    }
    exit;
}
';

$c = preg_replace('/(\/\/ Handle AJAX Kick Request)/', $syncLogic . "\n$1", $c);

// 2. Add the Sync Button to the UI
$uiTarget = '<div class="d-flex justify-content-between align-items-center w-100 pe-3">';
$uiReplacement = '<div class="d-flex justify-content-between align-items-center w-100 pe-3">
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary me-3 sync-btn" data-nas="<?= $router[\'id\'] ?>" title="Sync Router (Clear Ghost Sessions)" onclick="syncRouter(event, this)">
                            <i class="fa-solid fa-rotate"></i> Sync
                        </button>
                    </div>';
                    
$c = str_replace($uiTarget, $uiReplacement, $c);

// 3. Add JS for the Sync Button
$jsTarget = '</script>';
$jsReplacement = '
function syncRouter(e, btn) {
    e.stopPropagation(); // Prevent accordion from toggling
    if (confirm("Are you sure you want to sync this router? This will scan MikroTik and automatically clear all ghost sessions from the database.")) {
        const nas_id = btn.getAttribute("data-nas");
        const originalHtml = btn.innerHTML;
        btn.innerHTML = \'<i class="fa-solid fa-spinner fa-spin"></i>\';
        btn.disabled = true;
        
        const formData = new FormData();
        formData.append("action", "sync_router");
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
            alert("Network error while syncing router.");
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        });
    }
}
</script>
';
$c = str_replace($jsTarget, $jsReplacement, $c);

file_put_contents($f, $c);
echo "Added Sync logic and button to superadmin/live_routers.php\n";
?>
