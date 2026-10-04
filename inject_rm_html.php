<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

$metricsHtml = '
<!-- Upcoming Recovery Metrics -->
<div class="row g-3 mb-4 mt-2">
    <div class="col-md col-6">
        <a href="?filter=expired" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter==\'expired\')?\'bg-danger text-white\':\'bg-white\' ?> shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="fs-6 fw-bold mb-1 <?= ($filter==\'expired\')?\'\':\'text-danger\' ?>">Expired</div>
                    <div class="fs-3 fw-bold"><?= $c_expired ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md col-6">
        <a href="?filter=expiring_1d" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter==\'expiring_1d\')?\'bg-warning text-dark\':\'bg-white\' ?> shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="fs-6 fw-bold mb-1 <?= ($filter==\'expiring_1d\')?\'\':\'text-warning\' ?>">1 Day</div>
                    <div class="fs-3 fw-bold <?= ($filter==\'expiring_1d\')?\'\':\'text-dark\' ?>"><?= $c_1d ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md col-6">
        <a href="?filter=expiring_3d" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter==\'expiring_3d\')?\'bg-info text-white\':\'bg-white\' ?> shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="fs-6 fw-bold mb-1 <?= ($filter==\'expiring_3d\')?\'\':\'text-info\' ?>">3 Days</div>
                    <div class="fs-3 fw-bold <?= ($filter==\'expiring_3d\')?\'\':\'text-dark\' ?>"><?= $c_3d ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md col-6">
        <a href="?filter=expiring_1w" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter==\'expiring_1w\')?\'bg-primary text-white\':\'bg-white\' ?> shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="fs-6 fw-bold mb-1 <?= ($filter==\'expiring_1w\')?\'\':\'text-primary\' ?>">1 Week</div>
                    <div class="fs-3 fw-bold <?= ($filter==\'expiring_1w\')?\'\':\'text-dark\' ?>"><?= $c_1w ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md col-12">
        <a href="?filter=expiring_2w" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter==\'expiring_2w\')?\'bg-secondary text-white\':\'bg-white\' ?> shadow-sm">
                <div class="card-body text-center p-3">
                    <div class="fs-6 fw-bold mb-1 <?= ($filter==\'expiring_2w\')?\'\':\'text-secondary\' ?>">2 Weeks</div>
                    <div class="fs-3 fw-bold <?= ($filter==\'expiring_2w\')?\'\':\'text-dark\' ?>"><?= $c_2w ?></div>
                </div>
            </div>
        </a>
    </div>
</div>
';

// Locate where to inject: right before the <h5> heading for the user list
$searchHeader = '<h5 class="fw-bold mt-5 border-bottom pb-2 mb-3';
if (strpos($c, '<!-- Upcoming Recovery Metrics -->') === false) {
    $c = str_replace($searchHeader, $metricsHtml . "\n" . $searchHeader, $c);
    file_put_contents($f, $c);
    echo "Injected HTML metrics successfully!\n";
} else {
    echo "Already injected.\n";
}
?>
