<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// Find the JS function openCollectModal and add loadHistory() to it
$find_js = <<<'JS'
    // Switch to payment tab by default
    var triggerEl = document.querySelector('#payment-tab');
    bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl).show();

    var modal = new bootstrap.Modal(document.getElementById('collectModal'));
    modal.show();
}
JS;

$replace_js = <<<'JS'
    // Switch to payment tab by default
    var triggerEl = document.querySelector('#payment-tab');
    bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl).show();

    var modal = new bootstrap.Modal(document.getElementById('collectModal'));
    modal.show();
    
    // Load history immediately so the bottom section populates
    loadHistory();
}
JS;

$c = str_replace($find_js, $replace_js, $c);

// Also let's fix the broken HTML </div> on the header:
$c = str_replace('<h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-receipt me-2"></i> Last 5 Transactions</div>', '<h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-receipt me-2"></i> Last 5 Transactions</h6>', $c);

file_put_contents($f, $c);
echo "Fixed loadHistory trigger.\n";
?>
