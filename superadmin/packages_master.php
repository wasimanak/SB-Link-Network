<?php
require_once 'header.php';

// Handle Add / Edit / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            if ($action === 'add') {
                $name = trim($_POST['name']);
                $rate_limit = trim($_POST['rate_limit']);
                $validity_days = (int)$_POST['validity_days'];
                $price = (float)$_POST['price'];
                $data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];

                $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb) VALUES (0, :name, :rate_limit, :validity, :price, :data_limit)");
                $stmt->execute([
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                    'validity' => $validity_days,
                    'price' => $price,
                    'data_limit' => $data_limit_gb
                ]);
                echo "<div class='alert alert-success mt-3'>Master package created successfully.</div>";
                
            } elseif ($action === 'edit') {
                $id = (int)$_POST['package_id'];
                $name = trim($_POST['name']);
                $rate_limit = trim($_POST['rate_limit']);
                $validity_days = (int)$_POST['validity_days'];
                $price = (float)$_POST['price'];
                $data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];

                $stmt = $pdo->prepare("UPDATE packages SET name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit WHERE id = :id AND client_id = 0");
                $stmt->execute([
                    'name' => $name,
                    'rate_limit' => $rate_limit,
                    'validity' => $validity_days,
                    'price' => $price,
                    'data_limit' => $data_limit_gb,
                    'id' => $id
                ]);
                echo "<div class='alert alert-success mt-3'>Master package updated successfully.</div>";
                
            } elseif ($action === 'delete') {
                $id = (int)$_POST['delete_id'];
                $stmt = $pdo->prepare("DELETE FROM packages WHERE id = :id AND client_id = 0");
                $stmt->execute(['id' => $id]);
                echo "<div class='alert alert-success mt-3'>Master package deleted successfully.</div>";
            }
        } catch (PDOException $e) {
            echo "<div class='alert alert-danger mt-3'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM packages WHERE client_id = 0 ORDER BY id DESC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Error loading packages: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 mt-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-box-open me-2 text-primary"></i> Master Packages</h4>
    <button class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#addPackageModal">
        <i class="fa-solid fa-plus me-1"></i> Add Master Package
    </button>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Package Name</th>
                        <th>Rate Limit</th>
                        <th>Validity</th>
                        <th>Price</th>
                        <th>Data Limit</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($packages)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No master packages found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach($packages as $p): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-secondary">#<?= $p['id'] ?></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($p['name']) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25"><?= htmlspecialchars($p['rate_limit']) ?></span></td>
                            <td><?= $p['validity_days'] ?> Days</td>
                            <td class="text-success fw-bold">Rs. <?= number_format($p['price'], 2) ?></td>
                            <td><?= $p['data_limit_gb'] > 0 ? $p['data_limit_gb'].' GB' : '<span class="text-muted">Unlimited</span>' ?></td>
                            <td class="text-end pe-4">
                                <!-- Edit Button -->
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPackageModal<?= $p['id'] ?>">
                                    <i class="fa-solid fa-edit"></i> Edit
                                </button>
                                
                                <!-- Delete Form -->
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this master package?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </form>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editPackageModal<?= $p['id'] ?>" tabindex="-1">
                                  <div class="modal-dialog">
                                    <div class="modal-content">
                                      <div class="modal-header">
                                        <h5 class="modal-title fw-bold">Edit Master Package</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <form method="POST">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                        <div class="modal-body text-start">
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Package Name</label>
                                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($p['name']) ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Rate Limit (e.g. 10M/10M)</label>
                                                <input type="text" name="rate_limit" class="form-control" value="<?= htmlspecialchars($p['rate_limit']) ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Validity (Days)</label>
                                                <input type="number" name="validity_days" class="form-control" value="<?= $p['validity_days'] ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Price</label>
                                                <input type="number" step="0.01" name="price" class="form-control" value="<?= $p['price'] ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Data Limit (GB) <small class="text-muted fw-normal">- Leave 0 for unlimited</small></label>
                                                <input type="number" name="data_limit_gb" class="form-control" value="<?= $p['data_limit_gb'] ?>">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                                          <button type="submit" class="btn btn-primary px-4">Update Package</button>
                                        </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                                <!-- End Edit Modal -->
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Package Modal -->
<div class="modal fade" id="addPackageModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Add Master Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="modal-body">
            <div class="alert alert-info py-2 small">
                Master packages are available as templates/options for all Operators.
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Package Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. 10 Mbps Standard" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Rate Limit (e.g. 10M/10M)</label>
                <input type="text" name="rate_limit" class="form-control" placeholder="10M/10M" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Validity (Days)</label>
                <input type="number" name="validity_days" class="form-control" value="30" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Price</label>
                <input type="number" step="0.01" name="price" class="form-control" placeholder="1500" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Data Limit (GB) <small class="text-muted fw-normal">- Leave 0 for unlimited</small></label>
                <input type="number" name="data_limit_gb" class="form-control" value="0">
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-check me-1"></i> Save Package</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
