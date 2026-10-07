<?php
$f = 'operator/statement.php';
$c = file_get_contents($f);

// 1. Change Last R. Amount color to black
$oldBadge = '<td><span class="badge badge-soft-success px-3 py-2 fs-6">Rs. <?= number_format($lr_amt) ?></span></td>';
$newText = '<td><div class="fw-bold fs-6" style="color: #000 !important;">Rs. <?= number_format($lr_amt) ?></div></td>';
$c = str_replace($oldBadge, $newText, $c);

// 2. Update DataTables buttons for PDF A4 and CSV
$oldBtns = "{ extend: 'csv', className: 'btn btn-light border text-secondary shadow-sm' },
            { extend: 'excel', className: 'btn btn-light border text-success shadow-sm', text: '<i class=\"fa-solid fa-file-excel\"></i> Excel' },
            { extend: 'pdf', className: 'btn btn-danger shadow-sm text-white', text: '<i class=\"fa-solid fa-file-pdf\"></i> Download PDF', orientation: 'landscape', pageSize: 'A4' },";
            
$newBtns = "{ extend: 'csv', className: 'btn btn-light border text-secondary shadow-sm', text: '<i class=\"fa-solid fa-file-csv\"></i> Download CSV' },
            { extend: 'excel', className: 'btn btn-light border text-success shadow-sm', text: '<i class=\"fa-solid fa-file-excel\"></i> Excel' },
            { extend: 'pdfHtml5', className: 'btn btn-danger shadow-sm text-white', text: '<i class=\"fa-solid fa-file-pdf\"></i> Download PDF (A4)', orientation: 'portrait', pageSize: 'A4' },";
            
$c = str_replace($oldBtns, $newBtns, $c);

file_put_contents($f, $c);
echo "operator/statement.php updated with black text and button labels.\n";
?>
