<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

// 1. Update the SQL Query
$oldQuery = 'SELECT s.id, s.full_name, s.username, s.expiry_date, s.status,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip,
           (SELECT SUM(acctinputoctets + acctoutputoctets) FROM radacct r WHERE r.username = s.username) as total_usage
    FROM subscribers s
    WHERE s.dealer_id = ?
    ORDER BY s.id DESC';

$newQuery = 'SELECT s.id, s.full_name, s.username, s.expiry_date, s.status, s.mobile, s.phone, s.balance, s.service_type, p.name as package_name,
           (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.dealer_id = ?
    ORDER BY s.id DESC';

$c = str_replace($oldQuery, $newQuery, $c);

// 2. Update the HTML Table
$oldHTMLPattern = '/<table class="table table-hover table-bordered w-100" id="dealerUsersTable">.*?<\/table>/is';
$newHTML = '
<table class="table table-hover table-bordered table-custom-ui w-100" id="dealerUsersTable">
    <thead class="table-light">
        <tr>
            <th>#ID</th>
            <th>Photo</th>
            <th>Username</th>
            <th>Phone</th>
            <th>Package</th>
            <th>Balance</th>
            <th>Service</th>
            <th>On/Off</th>
            <th>Expiry</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($dealer_users as $u): ?>
        <tr>
            <td><?= $u[\'id\'] ?></td>
            <td><div class="avatar-circle"><i class="fa-solid fa-user"></i></div></td>
            <td>
                <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= htmlspecialchars($u[\'username\']) ?></span>
            </td>
            <td><?= htmlspecialchars($u[\'mobile\'] ?: ($u[\'phone\'] ?: \'N/A\')) ?></td>
            <td><?= htmlspecialchars($u[\'package_name\'] ?? \'N/A\') ?></td>
            <td><span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($u[\'balance\']) ?></span></td>
            <td><span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold"><?= strtoupper(htmlspecialchars($u[\'service_type\'])) ?></span></td>
            <td>
                <?php if($u[\'is_online\'] > 0): ?>
                    <div class="d-flex flex-column align-items-center gap-1">
                        <span class="badge rounded-pill badge-soft-success px-3 py-1">Online</span>
                        <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= htmlspecialchars($u[\'live_ip\']) ?></small>
                    </div>
                <?php else: ?>
                    <span class="badge rounded-pill badge-soft-secondary px-3 py-1">Offline</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if($u[\'expiry_date\']): ?>
                    <?php 
                        $is_expired = strtotime($u[\'expiry_date\']) < time(); 
                        $badge_class = $is_expired ? \'badge-soft-danger\' : \'badge-soft-success\';
                    ?>
                    <span class="badge <?= $badge_class ?> px-3 py-2"><?= date(\'d M Y, h:i A\', strtotime($u[\'expiry_date\'])) ?></span>
                <?php else: ?>
                    <span class="badge badge-soft-secondary px-3 py-2">N/A</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
';

if (preg_match($oldHTMLPattern, $c)) {
    $c = preg_replace($oldHTMLPattern, $newHTML, $c);
}

// 3. Inject missing CSS if any
$css = '
<style>
.badge-soft-success { background-color: rgba(34,197,94,0.1); color: #22c55e; }
.badge-soft-danger { background-color: rgba(239,68,68,0.1); color: #ef4444; }
.badge-soft-warning { background-color: rgba(245,158,11,0.1); color: #f59e0b; }
.badge-soft-primary { background-color: rgba(59,130,246,0.1); color: #3b82f6; }
.badge-soft-secondary { background-color: rgba(100,116,139,0.1); color: #64748b; }
.avatar-circle { width: 35px; height: 35px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 14px; }
.table-custom-ui th { text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
.table-custom-ui td { vertical-align: middle; }
</style>
';

if (strpos($c, '.avatar-circle') === false) {
    $c = preg_replace('/(require_once \'header.php\';)/i', "$1\n$css", $c);
}

file_put_contents($f, $c);
echo "operator/dealer_view.php patched to show requested user details.\n";
?>
