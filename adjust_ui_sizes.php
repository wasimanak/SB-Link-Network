<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Shrink Filter Cards
// padding
$c = str_replace('<div class="card-body text-center p-3">', '<div class="card-body text-center p-2 py-2">', $c);

// label size
$c = str_replace('fs-6 fw-bold mb-1', 'small fw-bold mb-0 text-uppercase', $c);

// number size
$c = str_replace('fs-3 fw-bold', 'fs-4 fw-bold mb-0', $c);

// Remove some top margin from the filter cards row
$c = str_replace('<div class="row g-3 mb-4 mt-2">', '<div class="row g-2 mb-3 mt-1">', $c);


// 2. Shrink User Cards (Search Results & Filtered Results)
// padding of list-group-item
$c = str_replace('list-group-item p-3 border', 'list-group-item p-2 border', $c);
$c = str_replace('mb-2 shadow-sm', 'mb-2 shadow-sm', $c); // Already fine, maybe no change needed

// Font sizes inside cards
// Changing full_name size from h6 to a slightly smaller size
$c = str_replace('<h6 class="fw-bold mb-1 text-dark">', '<div class="fw-bold mb-1 text-dark" style="font-size:0.9rem;">', $c);
$c = str_replace('</h6>', '</div>', $c); // This might be risky if there are other h6s. Let's be specific:
// Actually, it's safer to just str_replace the exact line:
$c = str_replace('<h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($u[\'full_name\']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u[\'username\']) ?></span></h6>', '<div class="fw-bold text-dark lh-sm mb-1" style="font-size:0.95rem;"><?= htmlspecialchars($u[\'full_name\']) ?> <span class="badge bg-primary ms-1 fw-normal" style="font-size:0.75rem;"><?= htmlspecialchars($u[\'username\']) ?></span></div>', $c);

// Adjust the balance text size
$c = str_replace('<strong class="fs-6 ', '<strong style="font-size:0.9rem;" class="', $c);

// Shrink the Renew button
$c = str_replace('btn btn-danger btn-sm px-4 fw-bold shadow-sm', 'btn btn-danger btn-sm px-3 py-1 fw-bold shadow-sm', $c);


// Optional: Top Stats (Today's Collection & Pending Cash)
$c = str_replace('<div class="col-6 mb-3">', '<div class="col-6 mb-2">', $c);
$c = str_replace('<div class="card card-custom bg-danger text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">', '<div class="card card-custom bg-danger text-white h-100 shadow-sm mb-0">
                <div class="card-body p-2 text-center">', $c);
$c = str_replace('<div class="card card-custom bg-success text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">', '<div class="card card-custom bg-success text-white h-100 shadow-sm mb-0">
                <div class="card-body p-2 text-center">', $c);
$c = str_replace('<h4 class="mb-0 fw-bold">', '<h5 class="mb-0 fw-bold">', $c);


file_put_contents($f, $c);
echo "UI adjusted for smaller, more professional sizes.\n";
?>
