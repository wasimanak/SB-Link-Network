<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Add Member
    if ($action === 'add_member') {
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        
        try {
            $stmt = $pdo->prepare("INSERT INTO linemen (client_id, full_name, username, password, phone, address, city) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $full_name, $username, $password, $phone, $address, $city]);
            echo "<script>alert('Line Man created successfully!'); window.location='$_SERVER[PHP_SELF]';</script>";
            exit;
        } catch (PDOException $e) {
            echo "<script>alert('Error: Username might already exist.');</script>";
        }
    }
    
    // Delete Member
    if ($action === 'delete_member' && isset($_POST['id'])) {
        $del = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM linemen WHERE id = ? AND client_id = ?")->execute([$del, $client_id]);
        echo "<script>alert('Line Man deleted successfully!'); window.location='$_SERVER[PHP_SELF]';</script>";
        exit;
    }
}

// Fetch Members
$stmt = $pdo->prepare("SELECT * FROM linemen WHERE client_id = ? ORDER BY id DESC");
$stmt->execute([$client_id]);
$members = $stmt->fetchAll();
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .table-hover tbody tr:hover { background-color: #f8fafc; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="fa-solid fa-hard-hat text-primary me-2"></i> Manage Line Mans</h4>
        <small class="text-muted">View and manage your Line Man team</small>
    </div>
    <button class="btn btn-primary shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addMemberModal">
        <i class="fa-solid fa-plus me-1"></i> Add New Line Man
    </button>
</div>

<div class="card card-custom mb-4">
    <div class="card-body p-4">
        <div class="table-responsive">
            <table id="membersTable" class="table table-hover align-middle w-100">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Password</th>
                        <th>City</th>
                        <th>Contact</th>
                        <th>Created On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $m): ?>
                    <tr>
                        <td class="fw-bold text-dark"><?= htmlspecialchars($m['full_name']) ?></td>
                        <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($m['username']) ?></span></td>
                        <td class="font-monospace text-muted"><?= htmlspecialchars($m['password']) ?></td>
                        <td><?= htmlspecialchars($m['city'] ?: 'N/A') ?></td>
                        <td>
                            <div><i class="fa-solid fa-phone text-secondary small me-1"></i> <?= htmlspecialchars($m['phone'] ?: 'N/A') ?></div>
                            <div class="small text-muted"><i class="fa-solid fa-map-location-dot text-secondary small me-1"></i> <?= htmlspecialchars($m['address'] ?: 'N/A') ?></div>
                        </td>
                        <td class="text-secondary small"><?= date('d M Y', strtotime($m['created_at'])) ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this Line Man?');" class="m-0">
                                <input type="hidden" name="action" value="delete_member">
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-hard-hat text-primary me-2"></i> Create Line Man</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_member">
        <div class="modal-body p-4">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold text-secondary">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required placeholder="Enter full name">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold text-secondary">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required placeholder="Login username">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold text-secondary">Password <span class="text-danger">*</span></label>
                    <input type="text" name="password" class="form-control" required placeholder="Login password">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold text-secondary">Phone / Mobile</label>
                    <input type="text" name="phone" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label small fw-bold text-secondary">City</label>
                    <input type="text" name="city" class="form-control" placeholder="e.g. Lahore">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label small fw-bold text-secondary">Complete Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Optional">
                </div>
            </div>
        </div>
        <div class="modal-footer px-4 pb-4 border-top-0">
          <button type="button" class="btn btn-light px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-5 fw-bold">Create Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#membersTable').DataTable({
        language: { lengthMenu: "Show _MENU_ entries" },
        order: [[0, 'desc']]
    });
});

// Form Validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms).forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>

<?php require_once 'footer.php'; ?>