<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/packages.php';
$content = file_get_contents($file);

$old_js = <<<'JS'
        } else {
            document.getElementById('sched_start').value = '';
            document.getElementById('sched_end').value = '';
            document.getElementById('sched_up').value = '';
            document.getElementById('sched_down').value = '';
        
      
      document.getElementById('modalBtn').innerText = 'Update Package';
JS;

$new_js = <<<'JS'
        } else {
            document.getElementById('sched_start').value = '';
            document.getElementById('sched_end').value = '';
            document.getElementById('sched_up').value = '';
            document.getElementById('sched_down').value = '';
        }
        
      document.getElementById('modalBtn').innerText = 'Update Package';
JS;

$content = str_replace($old_js, $new_js, $content);
file_put_contents($file, $content);
echo "Added missing closing brace to openEditModal.\n";
?>
