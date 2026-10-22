<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

$search_str = '<form method="GET" class="d-flex gap-2">';

$new_button_html = <<<'HTML'
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold m-0"><i class="fa-solid fa-search me-2 text-primary"></i> Find Subscriber</h5>
    <button type="button" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
</div>
<form method="GET" class="d-flex gap-2">
HTML;

$c = str_replace($search_str, $new_button_html, $c);

file_put_contents($f, $c);
echo "Injected Button Successfully!\n";
?>
