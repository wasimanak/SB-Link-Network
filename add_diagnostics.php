<?php
$f = 'lineman/dashboard.php';
$c = file_get_contents($f);

// Find the online/offline badge block
$oldBlock = '<?php if($search_result[\'live_ip\']): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill"><i class="fa-solid fa-circle text-success small me-1"></i> Online (<?= $search_result[\'live_ip\'] ?>)</span>
                        <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-2 rounded-pill"><i class="fa-solid fa-circle text-secondary small me-1"></i> Offline</span>
                        <?php endif; ?>';

$newBlock = '<?php 
                        $is_expired = ($search_result[\'expiry_date\'] && strtotime($search_result[\'expiry_date\']) < time());
                        $is_disabled = ($search_result[\'status\'] === \'disabled\');
                        
                        if ($is_disabled): ?>
                            <span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-ban me-1"></i> Disabled (Admin Blocked)</span>
                        <?php elseif ($is_expired): ?>
                            <span class="badge bg-danger px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-triangle-exclamation me-1"></i> Expired (Needs Renewal)</span>
                        <?php elseif ($search_result[\'live_ip\']): ?>
                            <span class="badge bg-success px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-circle-check me-1"></i> Online (<?= $search_result[\'live_ip\'] ?>) - Status OK</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark px-3 py-2 rounded-pill shadow-sm"><i class="fa-solid fa-plug-circle-xmark me-1"></i> Offline (Check Power/Cable)</span>
                        <?php endif; ?>';

if (strpos($c, 'fa-plug-circle-xmark') === false) {
    $c = str_replace($oldBlock, $newBlock, $c);
    
    // Add a diagnostic row right below the header
    $diagBlock = '
                        <!-- Diagnostics Alert -->
                        <?php if($is_disabled): ?>
                            <div class="alert alert-danger fw-bold border-0 shadow-sm"><i class="fa-solid fa-ban me-2 fs-5 align-middle"></i> 🔴 <strong>Masla:</strong> Ye user Operator ki taraf se Banned/Disabled hai. Jab tak Operator isay dobara Enable nahi karega, iska internet nahi chalega.</div>
                        <?php elseif($is_expired): ?>
                            <div class="alert alert-danger fw-bold border-0 shadow-sm"><i class="fa-solid fa-clock-rotate-left me-2 fs-5 align-middle"></i> 🔴 <strong>Masla:</strong> Is user ka Package Expire ho chuka hai (<?= date(\'d M Y\', strtotime($search_result[\'expiry_date\'])) ?> ko). Recharge karwayen.</div>
                        <?php elseif(!$search_result[\'live_ip\']): ?>
                            <div class="alert alert-warning text-dark fw-bold border-0 shadow-sm"><i class="fa-solid fa-plug-circle-xmark me-2 fs-5 align-middle"></i> 🟡 <strong>Masla:</strong> User Offline hai. Piche se account theek hai (Active hai).<br><small class="ms-4">- Agar ghar pe router on hai tou taar (cable) kati hui ho sakti hai.<br><small class="ms-4">- Ya router reset ho gaya hai (configuration masla).</small></div>
                        <?php else: ?>
                            <div class="alert alert-success fw-bold border-0 shadow-sm d-flex justify-content-between align-items-center">
                                <div><i class="fa-solid fa-circle-check me-2 fs-5 align-middle"></i> 🟢 <strong>Status OK:</strong> User is waqt theek tarah se connected hai. IP: <?= $search_result[\'live_ip\'] ?></div>
                            </div>
                        <?php endif; ?>
    ';
    
    $c = preg_replace('/(User Details<\/h5>\s*<span class="badge.*?<\/span>\s*<\/div>)/is', "$1\n$diagBlock", $c);
    file_put_contents($f, $c);
    echo "Diagnostics added to lineman/dashboard.php\n";
} else {
    echo "Diagnostics already present.\n";
}
?>
