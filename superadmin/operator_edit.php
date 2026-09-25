<?php
require_once 'header.php';

$id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $success = "Operator updated successfully!";
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $op = $stmt->fetch();
    if (!$op) {
        die("<div class='alert alert-danger'>Operator not found.</div>");
    }
} catch (PDOException $e) {
    die("Database error.");
}
?>

<div class="mb-3">
    <a href="operators.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div class="card shadow-sm w-75">
    <div class="card-header">
        <h5 class="mb-0">Edit Operator: <?= htmlspecialchars($op['company_name']) ?></h5>
    </div>
    <div class="card-body">
        <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Company Name</label>
                    <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($op['company_name']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($op['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Max Routers</label>
                    <input type="number" name="max_routers" class="form-control" value="<?= $op['max_routers'] ?>" min="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max Subscribers</label>
                    <input type="number" name="max_subscribers" class="form-control" value="<?= $op['max_subscribers'] ?>" min="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-control" value="<?= $op['expiry_date'] ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Update Operator</button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
