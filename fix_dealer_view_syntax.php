<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

// Fix the raw HTML injection issue
$badBlock = '<style>
.badge-soft-success { background-color: rgba(34,197,94,0.1); color: #22c55e; }
.badge-soft-danger { background-color: rgba(239,68,68,0.1); color: #ef4444; }
.badge-soft-warning { background-color: rgba(245,158,11,0.1); color: #f59e0b; }
.badge-soft-primary { background-color: rgba(59,130,246,0.1); color: #3b82f6; }
.badge-soft-secondary { background-color: rgba(100,116,139,0.1); color: #64748b; }
.avatar-circle { width: 35px; height: 35px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 14px; }
.table-custom-ui th { text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
.table-custom-ui td { vertical-align: middle; }
</style>';

$goodBlock = '?>' . $badBlock . '<?php';

$c = str_replace($badBlock, $goodBlock, $c);
file_put_contents($f, $c);
echo "Fixed syntax error in operator/dealer_view.php\n";
?>
