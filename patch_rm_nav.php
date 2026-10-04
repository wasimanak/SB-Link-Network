<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

$oldHtml = '<span class="me-3 small text-light"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($rm_name) ?></span>
        <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket"></i></a>';
        
$newHtml = '<span class="me-3 small text-light d-none d-md-inline"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($rm_name) ?></span>
        <a href="history.php" class="btn btn-sm btn-light text-dark fw-bold me-2"><i class="fa-solid fa-clock-rotate-left me-1"></i> History</a>
        <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket"></i></a>';

if (strpos($c, 'history.php') === false) {
    $c = str_replace($oldHtml, $newHtml, $c);
    file_put_contents($f, $c);
    echo "Added History button to RM dashboard.\n";
} else {
    echo "Already added.\n";
}
?>
