<?php
$f = 'operator/packages.php';
$c = file_get_contents($f);

// We need to strip out all the 'bg-dark', 'text-light', 'border-secondary' classes and replace them with light theme equivalents.

$replacements = [
    // Typography
    'text-light' => 'text-dark',
    'text-white' => 'text-dark',
    'text-secondary' => 'text-muted',
    'text-accent' => 'text-primary',
    
    // Backgrounds & Borders
    'bg-dark' => 'bg-white',
    'border-secondary border-opacity-50' => 'border-light',
    'border-secondary' => 'border-light',
    'style="background: rgba(255, 255, 255, 0.05); border-bottom: 1px solid rgba(255, 255, 255, 0.1);"' => 'style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;"',
    'style="border-bottom: 1px solid rgba(255,255,255,0.05);"' => 'style="border-bottom: 1px solid #f1f5f9;"',
    'style="background: rgba(255,255,255,0.02);"' => 'style="background: #f8fafc;"',
    'style="background: transparent;"' => 'style="background: white;"',
    
    // Elements
    'btn-close-white' => 'btn-close',
    'class="card-ui mb-4"' => 'class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;"',
    
    // Modal tweaks
    'bg-dark border border-secondary shadow-lg text-light' => 'bg-white border-0 shadow-lg text-dark',
    'modal-content bg-white border-0 shadow-lg text-dark' => 'modal-content bg-white border-0 shadow-lg text-dark',
];

foreach ($replacements as $old => $new) {
    $c = str_replace($old, $new, $c);
}

// A few specific manual fixes:
// Modal inputs
$c = str_replace('form-control bg-white border-light text-dark', 'form-control', $c);
$c = str_replace('input-group-text bg-white border-light', 'input-group-text bg-light border', $c);
$c = str_replace('border-light', 'border', $c); // Make borders slightly visible for inputs

file_put_contents($f, $c);
echo "Light theme applied!\n";
?>
