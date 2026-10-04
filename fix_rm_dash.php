<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Add SQL logic for the metrics and the filter list
$newMetricsPHP = "
// Metric Queries
\$now = date('Y-m-d H:i:s');
\$count_expired = \$pdo->prepare(\"SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND (expiry_date < NOW() OR status = 'expired')\"); \$count_expired->execute([\$client_id]); \$c_expired = \$count_expired->fetchColumn();
\$count_1d = \$pdo->prepare(\"SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)\"); \$count_1d->execute([\$client_id]); \$c_1d = \$count_1d->fetchColumn();
\$count_3d = \$pdo->prepare(\"SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)\"); \$count_3d->execute([\$client_id]); \$c_3d = \$count_3d->fetchColumn();
\$count_1w = \$pdo->prepare(\"SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)\"); \$count_1w->execute([\$client_id]); \$c_1w = \$count_1w->fetchColumn();
\$count_2w = \$pdo->prepare(\"SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)\"); \$count_2w->execute([\$client_id]); \$c_2w = \$count_2w->fetchColumn();

// Filter Logic for Main Table
\$filter = \$_GET['filter'] ?? 'expired';
\$filter_sql = \"AND (s.expiry_date < NOW() OR s.status = 'expired')\";
\$filter_title = \"Expired Users\";

if (\$filter === 'expiring_1d') {
    \$filter_sql = \"AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)\";
    \$filter_title = \"Expiring in 1 Day\";
} elseif (\$filter === 'expiring_3d') {
    \$filter_sql = \"AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)\";
    \$filter_title = \"Expiring in 3 Days\";
} elseif (\$filter === 'expiring_1w') {
    \$filter_sql = \"AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)\";
    \$filter_title = \"Expiring in 1 Week\";
} elseif (\$filter === 'expiring_2w') {
    \$filter_sql = \"AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)\";
    \$filter_title = \"Expiring in 2 Weeks\";
}

// Fetch Filtered Users
\$filteredStmt = \$pdo->prepare(\"
    SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name, p.price as package_price,
           (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
           (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.client_id = ? \$filter_sql
    ORDER BY s.expiry_date ASC
    LIMIT 100
\");
\$filteredStmt->execute([\$client_id]);
\$expired_users = \$filteredStmt->fetchAll(PDO::FETCH_ASSOC); // Overwrite the expired_users variable so the table renders it
";

// Inject the new PHP before "Fetch Today's Collection for this RM"
$c = preg_replace('/\/\/ Fetch Expired Users.*?ORDER BY s\.expiry_date DESC\s*LIMIT 100\s*"\);\s*\$expiredStmt->execute\(\[\$client_id\]\);\s*\$expired_users = \$expiredStmt->fetchAll\(PDO::FETCH_ASSOC\);/s', trim($newMetricsPHP), $c);


// 2. Add the Metric Cards HTML
$metricsHtml = '
<div class="row g-3 mb-4">
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

// Inject HTML right after <div class="container-fluid mt-4 pb-5">
$c = str_replace('<div class="container-fluid mt-4 pb-5">', '<div class="container-fluid mt-4 pb-5">' . "\n" . $metricsHtml, $c);

// Also change the title of the table from "Expired Users" to the dynamic title
$c = str_replace('<h5 class="fw-bold mb-0 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i> Expired Users</h5>', '<h5 class="fw-bold mb-0 <?= ($filter==\'expired\')?\'text-danger\':\'text-primary\' ?>"><i class="fa-solid <?= ($filter==\'expired\')?\'fa-triangle-exclamation\':\'fa-clock-rotate-left\' ?> me-2"></i> <?= htmlspecialchars($filter_title) ?></h5>', $c);

file_put_contents($f, $c);
echo "Recovery Man dashboard metrics added successfully!\n";
?>
