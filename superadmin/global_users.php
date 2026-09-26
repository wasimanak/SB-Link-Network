<?php
require_once 'header.php';

$search = $_GET['search'] ?? '';
$client_id = $_GET['client_id'] ?? '';

$where = "1=1";
$params = [];

if ($search) {
    $where .= " AND (s.username LIKE :search OR s.full_name LIKE :search)";
    $params['search'] = "%$search%";
}
if ($client_id) {
    $where .= " AND s.client_id = :client_id";
    $params['client_id'] = $client_id;
}

// Fetch all operators for filter
$operators = $pdo->query("SELECT id, company_name FROM clients ORDER BY company_name")->fetchAll();

// Fetch users
$sql = "SELECT s.*, c.company_name, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online 
        FROM subscribers s 
        JOIN clients c ON s.client_id = c.id 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE $where ORDER BY s.id DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold mb-1">Global Subscribers</h3>
        <p class="text-secondary">View and search all customers across all ISPs.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search by Username or Name..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-4">
                <select name="client_id" class="form-select">
                    <option value="">All Operators (ISPs)</option>
                    <?php foreach($operators as $op): ?>
                        <option value="<?= $op['id'] ?>" <?= $client_id == $op['id'] ? 'selected' : '' ?>><?= htmlspecialchars($op['company_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-search"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Username</th>
                        <th>ISP / Operator</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Live</th>
                        <th class="pe-4 text-end">Expiry</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($users)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No subscribers found.</td></tr>
                    <?php endif; ?>
                    <?php foreach($users as $u): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold"><?= htmlspecialchars($u['username']) ?></div>
                            <small class="text-secondary"><?= htmlspecialchars($u['full_name'] ?: 'N/A') ?></small>
                        </td>
                        <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?= htmlspecialchars($u['company_name']) ?></span></td>
                        <td><?= htmlspecialchars($u['package_name'] ?: 'N/A') ?></td>
                        <td>
                            <?php if($u['status'] === 'active'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger"><?= ucfirst($u['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($u['is_online']): ?>
                                <span class="badge bg-success rounded-pill"><i class="fa-solid fa-circle small"></i> Online</span>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-pill">Offline</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 text-end text-secondary">
                            <?= $u['expiry_date'] ? date('d M Y', strtotime($u['expiry_date'])) : 'N/A' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
