<?php
$dir = 'C:\xampp\htdocs\SB Link Network\operator';
$files = glob($dir . '/*.php');

$replacements = [
    // Table Custom UI CSS (subscribers.php & activity_logs.php)
    'background-color: #1e293b;' => 'background-color: #ffffff;',
    'background-color: #0f172a;' => 'background-color: #f8f9fa;',
    'color: #94a3b8;' => 'color: #4b5563;',
    'border-bottom: 2px solid #334155;' => 'border-bottom: 2px solid #e5e7eb;',
    'border-bottom: 1px solid #334155;' => 'border-bottom: 1px solid #e5e7eb;',
    'color: #cbd5e1;' => 'color: #1f2937;',
    
    // DataTables Input CSS
    '.dataTables_filter input { background-color: #334155; border: 1px solid #475569; color: white;' => '.dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; color: #333;',
    '.dataTables_length select { background-color: #334155; border: 1px solid #475569; color: white;' => '.dataTables_length select { background-color: #ffffff; border: 1px solid #ced4da; color: #333;',
    
    // Generic elements
    'table-dark' => 'table-hover',
    'bg-dark text-light' => 'bg-white text-dark',
    'btn-outline-light' => 'btn-outline-dark',
    'border-secondary' => 'border-light',
    
    // For Dashboard Cards
    'bg-secondary' => 'bg-light text-dark',
    'table-hover mb-0' => 'table-hover table-bordered mb-0'
];

foreach ($files as $file) {
    if (basename($file) === 'header.php') continue; // Handled separately
    
    $content = file_get_contents($file);
    
    foreach ($replacements as $search => $replace) {
        $content = str_replace($search, $replace, $content);
    }
    
    file_put_contents($file, $content);
}
echo "Theme updated across files.\n";
