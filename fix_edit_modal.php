<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($file);

// Fix the missing brace by completely replacing the openEditModal function
$pattern = '/function openEditModal\(pkg\) \{.*?\n\}\n<\/script>/s';

$replacement = <<<'JS'
function openEditModal(pkg) {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen text-primary"></i> Edit Package';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalPackageId').value = pkg.id;
    
    document.getElementById('pkg_name').value = pkg.name;
    document.getElementById('pkg_price').value = pkg.price;
    document.getElementById('pkg_validity').value = pkg.validity_days;
    document.getElementById('pkg_data').value = pkg.data_limit_gb;
    
    let rate = pkg.rate_limit || 'Unlimited';
    if (rate.toLowerCase() === 'unlimited' || !rate.includes('/')) {
        document.getElementById('pkg_rate_up').value = 'Unlimited';
        document.getElementById('pkg_rate_down').value = 'Unlimited';
    } else {
        let parts = rate.split('/');
        document.getElementById('pkg_rate_up').value = parts[0];
        document.getElementById('pkg_rate_down').value = parts[1];
    }
    
    if (pkg.speed_scheduler) {
        let sched = JSON.parse(pkg.speed_scheduler);
        document.getElementById('sched_start').value = sched.start_time;
        document.getElementById('sched_end').value = sched.end_time;
        if (sched.speed && sched.speed.includes('/')) {
            let sparts = sched.speed.split('/');
            document.getElementById('sched_up').value = sparts[0];
            document.getElementById('sched_down').value = sparts[1];
        }
    } else {
        document.getElementById('sched_start').value = '';
        document.getElementById('sched_end').value = '';
        document.getElementById('sched_up').value = '';
        document.getElementById('sched_down').value = '';
    }
    
    document.getElementById('modalBtn').innerText = 'Update Package';
    var myModal = new bootstrap.Modal(document.getElementById('packageModal'));
    myModal.show();
}
</script>
JS;

$content = preg_replace($pattern, $replacement, $content);

// Also fix the PHP POST block for scheduler missing sched_speed checking
$php_pattern = '/if \(!empty\(\$_POST\[\'sched_start\'\]\) && !empty\(\$_POST\[\'sched_end\'\]\) && !empty\(\$_POST\[\'sched_speed\'\]\)\) \{/s';
$php_replacement = 'if (!empty($_POST[\'sched_start\']) && !empty($_POST[\'sched_end\']) && (!empty($_POST[\'sched_up\']) || !empty($_POST[\'sched_down\']))) {';
$content = preg_replace($php_pattern, $php_replacement, $content);

file_put_contents($file, $content);
echo "packages.php JS syntax totally fixed.\n";
?>
