<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// Fix the title
$oldTitle = '<h5 class="fw-bold text-danger mt-5 border-bottom pb-2 mb-3"><i class="fa-solid fa-clock text-danger me-2"></i> Expired Users</h5>';
$newTitle = '<h5 class="fw-bold mt-5 border-bottom pb-2 mb-3 <?= ($filter===\'expired\')?\'text-danger\':\'text-primary\' ?>"><i class="fa-solid <?= ($filter===\'expired\')?\'fa-triangle-exclamation\':\'fa-clock-rotate-left\' ?> me-2"></i> <?= htmlspecialchars($filter_title) ?></h5>';
$c = str_replace($oldTitle, $newTitle, $c);

// Fix the red border in the loop
$oldItem = '<div class="list-group-item p-3 border border-danger border-opacity-25 rounded-3 mb-2 shadow-sm bg-white">';
$newItem = '<div class="list-group-item p-3 border <?= ($filter===\'expired\')?\'border-danger\':\'border-primary\' ?> border-opacity-25 rounded-3 mb-2 shadow-sm bg-white">';
$c = str_replace($oldItem, $newItem, $c);

file_put_contents($f, $c);
echo "Visual fixes applied to recoveryman/dashboard.php\n";
?>
