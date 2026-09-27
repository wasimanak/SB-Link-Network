<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'] ?? 0;

// Handle Add Dealer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_dealer') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $national_id = trim($_POST['national_id'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    
    try {
        $stmt = $pdo->prepare("INSERT INTO dealers (client_id, full_name, username, password, national_id, email, phone, address, city) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$client_id, $full_name, $username, $password, $national_id, $email, $phone, $address, $city]);
        echo "<script>alert('Dealer added successfully!'); window.location.href='dealers.php';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Fetch Dealers
$stmt = $pdo->prepare("
    SELECT d.*, 
    (SELECT COUNT(*) FROM subscribers s WHERE s.dealer_id = d.id) as user_count 
    FROM dealers d 
    WHERE client_id = ?
    ORDER BY d.id DESC
");
$stmt->execute([$client_id]);
$dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
.dealer-header {
    background: #fff;
    padding: 15px 20px;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}
.dealer-header h4 { margin: 0; font-weight: 600; color: #334155; font-size: 1.25rem; }
.btn-dark-custom { background-color: #1e293b; color: #fff; border-radius: 6px; font-weight: 500; }
.btn-dark-custom:hover { background-color: #0f172a; color: #fff; }

.table-ui { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.table-ui thead th { background: #f8fafc; border-bottom: 1px solid #f1f5f9; color: #475569; font-size: 0.80rem; font-weight: 700; text-transform: capitalize; padding: 12px 15px; }
.table-ui tbody td { vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; color: #334155; padding: 12px 15px; }
.dt-buttons .btn { background: #475569; color: #fff; border: none; border-radius: 20px; padding: 4px 14px; font-size: 0.8rem; margin-right: 4px; }
.dt-buttons .btn:hover { background: #334155; }
.dataTables_filter input { border-radius: 4px; border: 1px solid #cbd5e1; padding: 4px 8px; font-size: 0.85rem; }
.dataTables_length select { border-radius: 4px; border: 1px solid #cbd5e1; padding: 4px 20px 4px 8px; font-size: 0.85rem; }

.avatar { width: 45px; height: 45px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #94a3b8; overflow: hidden; }
.avatar.has-img { background: transparent; }
.avatar img { width: 100%; height: 100%; object-fit: cover; }
.status-badge { background: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 12px; font-size: 0.70rem; font-weight: 600; vertical-align: middle; }
</style>

<div class="dealer-header mt-2">
    <h4><i class="fa-solid fa-users me-2 text-primary" style="opacity: 0.8;"></i> Dealers</h4>
    <button class="btn btn-dark-custom btn-sm px-3 py-2" data-bs-toggle="modal" data-bs-target="#addDealerModal">
        <i class="fa-solid fa-user-plus me-1"></i> Add New Dealer
    </button>
</div>

<div class="table-ui p-3">
    <table class="table table-hover table-borderless mb-0 w-100" id="dealerTable">
        <thead>
            <tr>
                <th>#ID</th>
                <th>Photo</th>
                <th>Name</th>
                <th>Admin</th>
                <th>Franchise</th>
                <th>Last Login</th>
                <th>Area</th>
                <th>Balance</th>
                <th>Users</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($dealers as $d): ?>
            <tr>
                <td class="text-muted fw-bold"><?= $d['id'] ?></td>
                <td>
                    <div class="avatar <?= $d['photo'] ? 'has-img' : '' ?>">
                        <?php if($d['photo']): ?>
                            <img src="<?= htmlspecialchars($d['photo']) ?>" alt="">
                        <?php else: ?>
                            <i class="fa-solid fa-user"></i>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <a href="dealer_view.php?id=<?= $d['id'] ?>" class="text-decoration-none">
                        <span class="fw-bold text-dark me-1" style="font-size:0.9rem;"><?= htmlspecialchars($d['full_name']) ?></span>
                        <span class="status-badge"><?= htmlspecialchars($d['username']) ?></span>
                    </a>
                </td>
                <td><?= htmlspecialchars($d['admin_name']) ?></td>
                <td><?= htmlspecialchars($d['franchise'] ?? 'N/A') ?></td>
                <td><?= $d['last_login'] ?: 'Never' ?></td>
                <td><?= htmlspecialchars($d['area'] ?? 'N/A') ?></td>
                <td class="fw-bold text-dark"><?= number_format($d['balance'], 2) ?></td>
                <td class="fw-bold text-dark"><?= $d['user_count'] ?></td>
                <td>
                    <button class="btn btn-sm text-primary fw-bold px-3 py-1 me-2" style="background-color: #e0e7ff; border-radius: 20px; font-size: 0.75rem;"><i class="fa-brands fa-paypal me-1"></i> Payment</button>
                    <i class="fa-solid fa-circle-check text-success fs-5 align-middle"></i>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.dealer-modal-title { color: #64748b; font-size: 1.15rem; }
.dealer-modal-label { color: #475569; font-weight: 700; font-size: 0.9rem; padding-top: 6px; }
</style>

<!-- Add Dealer Modal -->
<div class="modal fade" id="addDealerModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="max-width: 600px;">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-white border-bottom">
        <h5 class="modal-title fw-bold dealer-modal-title"><i class="fa-solid fa-user-plus me-2"></i> Add New Dealer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-horizontal">
        <input type="hidden" name="action" value="add_dealer">
        <div class="modal-body bg-white px-5 py-4">
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Name <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="text" name="full_name" class="form-control" placeholder="Enter Name" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Username <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Password <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="password" name="password" class="form-control" placeholder="Enter Password" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">National ID <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="text" name="national_id" class="form-control" placeholder="Enter National Identity Card" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Email <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="email" name="email" class="form-control" placeholder="Enter Email Address" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Phone <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="text" name="phone" class="form-control" placeholder="Enter Phone With Country Code" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">Address <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="text" name="address" class="form-control" placeholder="Enter Address" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="dealer-modal-label mb-0">City <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="text" name="city" class="form-control" placeholder="Select City" required>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn bg-white border text-dark px-4" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn text-white fw-bold px-4" style="background-color: #1e293b;">Submit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    $('#dealerTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-sm-12 col-md-3"l><"col-sm-12 col-md-6 text-center"B><"col-sm-12 col-md-3"f>>rt<"row align-items-center mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'print', className: 'btn', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', className: 'btn', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', className: 'btn', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', className: 'btn', text: '<i class="fa-solid fa-file-excel"></i> Excle' },
            { extend: 'csv', className: 'btn', text: '<i class="fa-solid fa-file-csv"></i> CSV' },
            { text: '<i class="fa-solid fa-eye"></i> View', className: 'btn' }
        ],
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "All"]],
        language: {
            lengthMenu: "Show _MENU_ entries"
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
