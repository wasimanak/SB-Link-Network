<?php
require_once 'header.php';

$id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_operator';
    
    if ($action === 'update_operator') {
        $company = trim($_POST['company_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $max_routers = (int)($_POST['max_routers'] ?? 1);
        $max_subscribers = (int)($_POST['max_subscribers'] ?? 100);
        $expiry = $_POST['expiry_date'] ?: null;

        if (empty($company)) {
            $error = "Company Name is required.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE clients SET company_name=:comp, phone=:phone, expiry_date=:exp, max_routers=:mr, max_subscribers=:ms WHERE id=:id");
                $stmt->execute([
                    'comp' => $company,
                    'phone' => $phone,
                    'exp' => $expiry,
                    'mr' => $max_routers,
                    'ms' => $max_subscribers,
                    'id' => $id
                ]);
                $success = "Operator details updated successfully.";
            } catch (Exception $e) {
                $error = "Error updating operator: " . $e->getMessage();
            }
        }
    } 
    
}

// Fetch Operator
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
$stmt->execute(['id' => $id]);
$op = $stmt->fetch();
if (!$op) { die("Operator not found."); }

// Fetch linked NAS
$nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
$nasStmt->execute([$id]);
$nas = $nasStmt->fetch();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-tie text-primary me-2"></i> Operator Profile: <?= htmlspecialchars($op['company_name']) ?></h4>
    <a href="operators.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to List</a>
</div>

<?php if($error): ?><div class="alert alert-danger shadow-sm border-0"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $error ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success shadow-sm border-0"><i class="fa-solid fa-circle-check me-2"></i> <?= $success ?></div><?php endif; ?>

<div class="row">
    <!-- Operator Basic Details -->
    <div class="col-md-5 mb-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-address-card text-success me-2"></i> Basic Details</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <input type="hidden" name="action" value="update_operator">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($op['company_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($op['phone'] ?? '') ?>">
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold text-secondary small">Max Routers</label>
                            <input type="number" name="max_routers" class="form-control" value="<?= $op['max_routers'] ?>" min="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold text-secondary small">Max Subscribers</label>
                            <input type="number" name="max_subscribers" class="form-control" value="<?= $op['max_subscribers'] ?>" min="1">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small">Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= $op['expiry_date'] ? date('Y-m-d', strtotime($op['expiry_date'])) : '' ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm"><i class="fa-solid fa-save me-1"></i> Update Details</button>
                </form>
            </div>
        </div>
    </div>

    </div>
</div>

<?php require_once 'footer.php'; ?>