<?php
require_once 'header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company = trim($_POST['company_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $max_routers = (int)($_POST['max_routers'] ?? 1);
    $max_subscribers = (int)($_POST['max_subscribers'] ?? 100);
    $expiry = $_POST['expiry_date'] ?: null;

    if (empty($company) || empty($email) || empty($password)) {
        $error = "Company, Email, and Password are required.";
    } else {
        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO clients (company_name, email, password, phone, expiry_date, max_routers, max_subscribers) VALUES (:company, :email, :pass, :phone, :expiry, :mr, :ms)");
            $stmt->execute([
                'company' => $company,
                'email' => $email,
                'pass' => $hashed,
                'phone' => $phone,
                'expiry' => $expiry,
                'mr' => $max_routers,
                'ms' => $max_subscribers
            ]);
            $success = "Operator added successfully!";
        } catch (Exception $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>

<div class="mb-3">
    <a href="operators.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div class="card shadow-sm w-75">
    <div class="card-header">
        <h5 class="mb-0">Add New Operator</h5>
    </div>
    <div class="card-body">
        <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Company Name</label>
                    <input type="text" name="company_name" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Max Routers</label>
                    <input type="number" name="max_routers" class="form-control" value="1" min="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max Subscribers</label>
                    <input type="number" name="max_subscribers" class="form-control" value="100" min="1">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Operator</button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
