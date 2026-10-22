<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/db.php';
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: ../login.php");
    exit;
}
$client_id = (int)$_SESSION['operator_id'];

// Logged-in actor identification
if (!empty($_SESSION['is_manager'])) {
    $actor_name = $_SESSION['manager_name'] ?? 'Manager';
    $actor_type = 'manager';
    $actor_id   = (int)($_SESSION['manager_id'] ?? 0);
} else {
    $actor_name = $_SESSION['operator_name'] ?? 'Operator';
    $actor_type = 'operator';
    $actor_id   = $client_id;
}

// -------------------------------------------------------------
// Self-Healing Table Schema for Expenses
// -------------------------------------------------------------
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expenses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT NOT NULL,
        `category` VARCHAR(100) NOT NULL DEFAULT 'General',
        `title` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
        `expense_date` DATE NOT NULL,
        `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
        `reference_no` VARCHAR(100) NULL,
        `payee` VARCHAR(150) NULL,
        `notes` TEXT NULL,
        `receipt_image` VARCHAR(255) NULL,
        `created_by` VARCHAR(150) NULL,
        `created_by_type` ENUM('operator', 'manager') DEFAULT 'operator',
        `created_by_id` INT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_client_date` (`client_id`, `expense_date`),
        INDEX `idx_client_category` (`client_id`, `category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // Graceful fallback
}

// Uploads directory for receipts
$uploadDir = '../uploads/expenses/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

// -------------------------------------------------------------
// AJAX Endpoint: Fetch Single Expense Details for Edit/View
// -------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_expense' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $expId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? AND client_id = ?");
    $stmt->execute([$expId, $client_id]);
    $exp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($exp) {
        echo json_encode(['success' => true, 'expense' => $exp]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Expense not found.']);
    }
    exit;
}

// -------------------------------------------------------------
// Helper for Bulletproof Redirection (Prevents White Screens)
// -------------------------------------------------------------
function safe_redirect_expenses() {
    $target = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'expenses';
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    } else {
        echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '"><script>window.location.href=' . json_encode($target) . ';</script></head><body>Redirecting...</body></html>';
        exit;
    }
}

// -------------------------------------------------------------
// Handle POST Actions (Add, Edit, Delete)
// -------------------------------------------------------------
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD EXPENSE
    if ($action === 'add_expense') {
        $title          = trim($_POST['title'] ?? '');
        $amount         = (float)($_POST['amount'] ?? 0);
        $expense_date   = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $category_raw   = trim($_POST['category'] ?? 'General');
        $custom_cat     = trim($_POST['custom_category'] ?? '');
        $category       = ($category_raw === '__custom__' && $custom_cat !== '') ? $custom_cat : $category_raw;
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $payee          = trim($_POST['payee'] ?? '');
        $reference_no   = trim($_POST['reference_no'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');

        if (empty($title) || $amount <= 0 || empty($expense_date)) {
            $_SESSION['expense_flash'] = ['type' => 'danger', 'msg' => 'Expense Title, Amount and Date are required.'];
        } else {
            // Handle optional receipt upload
            $receipt_filename = null;
            if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                if (in_array($ext, $allowed)) {
                    $receipt_filename = 'exp_' . $client_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($_FILES['receipt_file']['tmp_name'], $uploadDir . $receipt_filename);
                }
            }

            try {
                $ins = $pdo->prepare("INSERT INTO expenses 
                    (client_id, category, title, amount, expense_date, payment_method, reference_no, payee, notes, receipt_image, created_by, created_by_type, created_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $client_id, $category, $title, $amount, $expense_date, $payment_method, $reference_no, $payee, $notes,
                    $receipt_filename, $actor_name, $actor_type, $actor_id
                ]);

                // Activity log
                try {
                    $act = "Added expense: {$title} (Rs. " . number_format($amount, 0) . ") in category '{$category}'";
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Expense', 'Accounting', ?)")
                        ->execute([$client_id, $actor_name, $act]);
                } catch (Exception $e) {}

                $_SESSION['expense_flash'] = ['type' => 'success', 'msg' => 'Expense added successfully!'];
            } catch (Exception $e) {
                $_SESSION['expense_flash'] = ['type' => 'danger', 'msg' => 'Failed to save expense: ' . $e->getMessage()];
            }
        }
        header("Location: expenses" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }

    // 2. EDIT EXPENSE
    if ($action === 'edit_expense') {
        $exp_id         = (int)($_POST['expense_id'] ?? 0);
        $title          = trim($_POST['title'] ?? '');
        $amount         = (float)($_POST['amount'] ?? 0);
        $expense_date   = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $category_raw   = trim($_POST['category'] ?? 'General');
        $custom_cat     = trim($_POST['custom_category'] ?? '');
        $category       = ($category_raw === '__custom__' && $custom_cat !== '') ? $custom_cat : $category_raw;
        $payment_method = trim($_POST['payment_method'] ?? 'Cash');
        $payee          = trim($_POST['payee'] ?? '');
        $reference_no   = trim($_POST['reference_no'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $remove_receipt = !empty($_POST['remove_receipt']);

        // Check ownership
        $chk = $pdo->prepare("SELECT receipt_image FROM expenses WHERE id = ? AND client_id = ?");
        $chk->execute([$exp_id, $client_id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $_SESSION['expense_flash'] = ['type' => 'danger', 'msg' => 'Expense record not found.'];
        } elseif (empty($title) || $amount <= 0 || empty($expense_date)) {
            $_SESSION['expense_flash'] = ['type' => 'danger', 'msg' => 'Title, Amount and Date cannot be empty.'];
        } else {
            $receipt_filename = $existing['receipt_image'];

            if ($remove_receipt && $receipt_filename) {
                if (file_exists($uploadDir . $receipt_filename)) {
                    @unlink($uploadDir . $receipt_filename);
                }
                $receipt_filename = null;
            }

            // New upload if provided
            if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                if (in_array($ext, $allowed)) {
                    // Remove old
                    if ($receipt_filename && file_exists($uploadDir . $receipt_filename)) {
                        @unlink($uploadDir . $receipt_filename);
                    }
                    $receipt_filename = 'exp_' . $client_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    move_uploaded_file($_FILES['receipt_file']['tmp_name'], $uploadDir . $receipt_filename);
                }
            }

            try {
                $upd = $pdo->prepare("UPDATE expenses SET 
                    category = ?, title = ?, amount = ?, expense_date = ?, payment_method = ?, reference_no = ?, payee = ?, notes = ?, receipt_image = ?
                    WHERE id = ? AND client_id = ?");
                $upd->execute([
                    $category, $title, $amount, $expense_date, $payment_method, $reference_no, $payee, $notes, $receipt_filename,
                    $exp_id, $client_id
                ]);

                // Activity log
                try {
                    $act = "Updated expense #{$exp_id}: {$title} (Rs. " . number_format($amount, 0) . ")";
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Expense', 'Accounting', ?)")
                        ->execute([$client_id, $actor_name, $act]);
                } catch (Exception $e) {}

                $_SESSION['expense_flash'] = ['type' => 'success', 'msg' => 'Expense updated successfully!'];
            } catch (Exception $e) {
                $_SESSION['expense_flash'] = ['type' => 'danger', 'msg' => 'Failed to update expense: ' . $e->getMessage()];
            }
        }
        header("Location: expenses" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }

    // 3. DELETE EXPENSE
    if ($action === 'delete_expense') {
        $exp_id = (int)($_POST['expense_id'] ?? 0);
        $chk = $pdo->prepare("SELECT title, amount, receipt_image FROM expenses WHERE id = ? AND client_id = ?");
        $chk->execute([$exp_id, $client_id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (!empty($existing['receipt_image']) && file_exists($uploadDir . $existing['receipt_image'])) {
                @unlink($uploadDir . $existing['receipt_image']);
            }
            $pdo->prepare("DELETE FROM expenses WHERE id = ? AND client_id = ?")->execute([$exp_id, $client_id]);

            try {
                $act = "Deleted expense #{$exp_id}: {$existing['title']} (Rs. " . number_format((float)$existing['amount'], 0) . ")";
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Expense', 'Accounting', ?)")
                    ->execute([$client_id, $actor_name, $act]);
            } catch (Exception $e) {}

            $_SESSION['expense_flash'] = ['type' => 'success', 'msg' => 'Expense deleted successfully.'];
        } else {
            $_SESSION['expense_flash'] = ['type' => 'danger', 'msg' => 'Expense not found.'];
        }
        header("Location: expenses" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
}

// Flash message check
if (isset($_SESSION['expense_flash'])) {
    $flash = $_SESSION['expense_flash'];
    unset($_SESSION['expense_flash']);
}

// -------------------------------------------------------------
// Date Filters & Parameters ("Date ka to from wala filter")
// -------------------------------------------------------------
$range_param = $_GET['range'] ?? '';
$from_date   = $_GET['from_date'] ?? '';
$to_date     = $_GET['to_date'] ?? '';
$filter_cat  = $_GET['category'] ?? '';
$filter_pay  = $_GET['payment_method'] ?? '';

// Handle quick range shortcuts
$todayDate     = date('Y-m-d');
$yesterdayDate = date('Y-m-d', strtotime('-1 day'));
$weekStartDate = date('Y-m-d', strtotime('monday this week'));
$monthStart    = date('Y-m-01');
$monthEnd      = date('Y-m-t');
$lastMonthStart= date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd  = date('Y-m-t', strtotime('last day of last month'));

if ($range_param === 'today') {
    $from_date = $todayDate;
    $to_date   = $todayDate;
} elseif ($range_param === 'yesterday') {
    $from_date = $yesterdayDate;
    $to_date   = $yesterdayDate;
} elseif ($range_param === 'this_week') {
    $from_date = $weekStartDate;
    $to_date   = $todayDate;
} elseif ($range_param === 'this_month') {
    $from_date = $monthStart;
    $to_date   = $monthEnd;
} elseif ($range_param === 'last_month') {
    $from_date = $lastMonthStart;
    $to_date   = $lastMonthEnd;
} elseif ($range_param === 'all') {
    $from_date = '';
    $to_date   = '';
} else {
    // If no filter submitted at all, default to current month
    if (!isset($_GET['from_date']) && !isset($_GET['to_date']) && empty($range_param)) {
        $from_date = $monthStart;
        $to_date   = $todayDate;
        $range_param = 'this_month';
    }
}

// -------------------------------------------------------------
// Build Query with Filters
// -------------------------------------------------------------
$whereClauses = ["client_id = ?"];
$queryParams  = [$client_id];

if (!empty($from_date)) {
    $whereClauses[] = "expense_date >= ?";
    $queryParams[]  = $from_date;
}
if (!empty($to_date)) {
    $whereClauses[] = "expense_date <= ?";
    $queryParams[]  = $to_date;
}
if (!empty($filter_cat)) {
    $whereClauses[] = "category = ?";
    $queryParams[]  = $filter_cat;
}
if (!empty($filter_pay)) {
    $whereClauses[] = "payment_method = ?";
    $queryParams[]  = $filter_pay;
}

$whereSql = implode(" AND ", $whereClauses);
$sql = "SELECT * FROM expenses WHERE {$whereSql} ORDER BY expense_date DESC, id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($queryParams);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// Calculate Summary & KPI Stats
// -------------------------------------------------------------
$filtered_total = 0.0;
$filtered_count = count($expenses);
$category_breakdown = [];

foreach ($expenses as $row) {
    $amt = (float)$row['amount'];
    $filtered_total += $amt;
    $cat = !empty($row['category']) ? $row['category'] : 'General';
    if (!isset($category_breakdown[$cat])) {
        $category_breakdown[$cat] = 0.0;
    }
    $category_breakdown[$cat] += $amt;
}
arsort($category_breakdown);

// Global Stats for Context (Today, This Month, All Time)
$todayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt FROM expenses WHERE client_id = ? AND expense_date = ?");
$todayStmt->execute([$client_id, $todayDate]);
$todayStat = $todayStmt->fetch(PDO::FETCH_ASSOC);

$monthStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt FROM expenses WHERE client_id = ? AND expense_date >= ? AND expense_date <= ?");
$monthStmt->execute([$client_id, $monthStart, $monthEnd]);
$monthStat = $monthStmt->fetch(PDO::FETCH_ASSOC);

// Fetch Distinct Categories in DB for Filter Dropdown
$catStmt = $pdo->prepare("SELECT DISTINCT category FROM expenses WHERE client_id = ? AND category IS NOT NULL AND category != '' ORDER BY category ASC");
$catStmt->execute([$client_id]);
$existingCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Predefined Recommended Categories for ISPs
$defaultCategories = [
    'Bandwidth / Upstream Fiber',
    'Office Rent',
    'Electricity / WAPDA Bills',
    'Staff Salaries & Wages',
    'Lineman & Field Expenses',
    'Bike Fuel & Travel',
    'Tea, Food & Kitchen',
    'Hardware, Routers & ONUs',
    'Fiber Cable & Dropwire',
    'Generator & Fuel',
    'UPS, Inverter & Batteries',
    'Tools & Splicing Machine',
    'Marketing & Printing',
    'Software & Server Cloud',
    'Taxes & Regulatory',
    'General / Miscellaneous'
];
$allCategories = array_values(array_unique(array_merge($defaultCategories, $existingCategories)));

$paymentMethods = [
    'Cash',
    'Bank Transfer',
    'JazzCash',
    'EasyPaisa',
    'Nayapay',
    'Sadapay',
    'Cheque',
    'Other'
];

require_once 'header.php';
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    .card-ui { 
        background: #ffffff; 
        border-radius: 20px; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.03); 
        border: 1px solid #eef2f6; 
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 18px;
        padding: 18px 22px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.06);
    }
    .stat-pill-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1.15;
    }
    .stat-pill-label {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
        margin-bottom: 3px;
    }

    /* Filter Pills */
    .filter-pill-btn {
        border-radius: 50px;
        padding: 6px 16px;
        font-weight: 600;
        font-size: 0.82rem;
        border: 1px solid #e2e8f0;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ffffff;
        color: #64748b;
        cursor: pointer;
    }
    .filter-pill-btn:hover {
        background: #f1f5f9;
        color: #0f172a;
        border-color: #cbd5e1;
    }
    .filter-pill-btn.active {
        background: #dc2626 !important;
        color: #ffffff !important;
        border-color: #dc2626 !important;
        box-shadow: 0 4px 12px rgba(220,38,38,0.25);
    }

    /* Premium DataTables Styling */
    #expensesTable {
        border-collapse: separate !important;
        border-spacing: 0 8px !important;
    }
    #expensesTable thead th {
        background: #f8fafc !important;
        color: #64748b !important;
        font-size: 0.74rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.8px !important;
        padding: 12px 14px !important;
        border: none !important;
        white-space: nowrap !important;
    }
    #expensesTable thead th:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    #expensesTable thead th:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    #expensesTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02) !important;
        border-radius: 14px !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease !important;
    }
    #expensesTable tbody tr:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 18px rgba(0,0,0,0.06) !important;
    }
    #expensesTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 13px 14px !important;
        background: transparent !important;
        font-size: 0.88rem !important;
    }
    #expensesTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 14px !important;
        border-bottom-left-radius: 14px !important;
    }
    #expensesTable tbody td:last-child {
        border-right: 1px solid #f1f5f9 !important;
        border-top-right-radius: 14px !important;
        border-bottom-right-radius: 14px !important;
    }

    /* Badges */
    .badge-soft-danger { background-color: #fef2f2 !important; color: #dc2626 !important; border: 1px solid #fecaca !important; font-weight: 600; }
    .badge-soft-primary { background-color: #eff6ff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; font-weight: 600; }
    .badge-soft-info { background-color: #f0fdfa !important; color: #0d9488 !important; border: 1px solid #99f6e4 !important; font-weight: 600; }
    .badge-soft-secondary { background-color: #f8fafc !important; color: #64748b !important; border: 1px solid #e2e8f0 !important; font-weight: 600; }
    .badge-soft-purple { background-color: #faf5ff !important; color: #7e22ce !important; border: 1px solid #e9d5ff !important; font-weight: 600; }

    /* Category Tag Colors */
    .cat-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.76rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* DataTables Buttons */
    .dt-buttons .btn {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        color: #475569 !important;
        border-radius: 50px !important;
        padding: 5px 14px !important;
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        margin-right: 5px !important;
        transition: all 0.2s ease !important;
    }
    .dt-buttons .btn:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
    }

    /* Print Preview Styles */
    @media print {
        body * { visibility: hidden; }
        #printArea, #printArea * { visibility: visible; }
        #printArea { position: absolute; left: 0; top: 0; width: 100%; margin: 0; padding: 20px; background: #fff !important; }
        .no-print { display: none !important; }
    }
</style>

<div class="main-content">
    
    <!-- Top Alert Notification -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> fs-5"></i>
                <div class="fw-semibold"><?= htmlspecialchars($flash['msg']) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header & Action Button -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 46px; height: 46px; background: linear-gradient(135deg, #ef4444, #b91c1c);">
                    <i class="fa-solid fa-receipt fs-4"></i>
                </div>
                <div>
                    <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;">Daily Expenses</h3>
                    <p class="text-muted small mb-0">Record, track, and audit daily operational expenses with custom date filters.</p>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2" onclick="window.print()">
                <i class="fa-solid fa-print"></i>
                <span>Print Report</span>
            </button>
            <button type="button" class="btn btn-danger rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addExpenseModal" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <i class="fa-solid fa-plus"></i>
                <span>Add Expense</span>
            </button>
        </div>
    </div>

    <!-- 4 KPI Stat Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Filtered Expenses Total -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fef2f2; color: #dc2626;">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-label">Filtered Expenses</div>
                    <div class="stat-pill-val text-danger text-truncate">
                        Rs <?= number_format($filtered_total, 0) ?>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        <?= $filtered_count ?> transactions recorded
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Today's Expenses -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fff1f2; color: #e11d48;">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-label">Today's Expenses</div>
                    <div class="stat-pill-val text-dark text-truncate">
                        Rs <?= number_format((float)$todayStat['total'], 0) ?>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        <?= (int)$todayStat['cnt'] ?> entries today
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. This Month's Expenses -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-calendar-alt"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-label">This Month Total</div>
                    <div class="stat-pill-val text-primary text-truncate">
                        Rs <?= number_format((float)$monthStat['total'], 0) ?>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        <?= (int)$monthStat['cnt'] ?> monthly entries
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Top Expense Category -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f5f3ff; color: #7c3aed;">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <?php 
                    $topCat = !empty($category_breakdown) ? array_key_first($category_breakdown) : 'None';
                    $topCatAmt = !empty($category_breakdown) ? reset($category_breakdown) : 0;
                    ?>
                    <div class="stat-pill-label">Top Category</div>
                    <div class="stat-pill-val text-dark text-truncate" style="font-size: 1.15rem;" title="<?= htmlspecialchars($topCat) ?>">
                        <?= htmlspecialchars($topCat) ?>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        Rs <?= number_format($topCatAmt, 0) ?> spent
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Range & Category Filter Card ("to from wala filter") -->
    <div class="card-ui p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-sliders text-danger"></i>
                <h6 class="fw-bold mb-0 text-dark">Filter Expenses</h6>
            </div>
            
            <!-- Quick Preset Date Buttons -->
            <div class="d-flex flex-wrap align-items-center gap-1">
                <a href="expenses?range=today" class="filter-pill-btn <?= ($range_param === 'today' || ($from_date === $todayDate && $to_date === $todayDate)) ? 'active' : '' ?>">Today</a>
                <a href="expenses?range=yesterday" class="filter-pill-btn <?= ($range_param === 'yesterday' || ($from_date === $yesterdayDate && $to_date === $yesterdayDate)) ? 'active' : '' ?>">Yesterday</a>
                <a href="expenses?range=this_week" class="filter-pill-btn <?= ($range_param === 'this_week') ? 'active' : '' ?>">This Week</a>
                <a href="expenses?range=this_month" class="filter-pill-btn <?= ($range_param === 'this_month' || ($from_date === $monthStart && $to_date === $monthEnd)) ? 'active' : '' ?>">This Month</a>
                <a href="expenses?range=last_month" class="filter-pill-btn <?= ($range_param === 'last_month' || ($from_date === $lastMonthStart && $to_date === $lastMonthEnd)) ? 'active' : '' ?>">Last Month</a>
                <a href="expenses?range=all" class="filter-pill-btn <?= ($range_param === 'all' || (empty($from_date) && empty($to_date))) ? 'active' : '' ?>">All Time</a>
            </div>
        </div>

        <form method="GET" action="expenses" class="row g-3 align-items-end">
            <!-- From Date -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-bold mb-1">
                    <i class="fa-regular fa-calendar me-1"></i> From Date
                </label>
                <input type="date" name="from_date" class="form-control rounded-3" value="<?= htmlspecialchars($from_date) ?>">
            </div>

            <!-- To Date -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-bold mb-1">
                    <i class="fa-regular fa-calendar-check me-1"></i> To Date
                </label>
                <input type="date" name="to_date" class="form-control rounded-3" value="<?= htmlspecialchars($to_date) ?>">
            </div>

            <!-- Category Filter -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-bold mb-1">
                    <i class="fa-solid fa-tags me-1"></i> Category
                </label>
                <select name="category" class="form-select rounded-3">
                    <option value="">All Categories</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= ($filter_cat === $cat) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Payment Method Filter -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted small fw-bold mb-1">
                    <i class="fa-solid fa-credit-card me-1"></i> Payment Method
                </label>
                <select name="payment_method" class="form-select rounded-3">
                    <option value="">All Methods</option>
                    <?php foreach ($paymentMethods as $pm): ?>
                        <option value="<?= htmlspecialchars($pm) ?>" <?= ($filter_pay === $pm) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pm) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Filter Buttons -->
            <div class="col-12 d-flex justify-content-end gap-2 pt-2">
                <a href="expenses?range=all" class="btn btn-outline-secondary rounded-pill px-3 btn-sm fw-semibold">
                    <i class="fa-solid fa-rotate-left me-1"></i> Reset Filters
                </a>
                <button type="submit" class="btn btn-danger rounded-pill px-4 btn-sm fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="fa-solid fa-filter me-1"></i> Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Category Breakdown Bars (If multiple categories exist in filtered period) -->
    <?php if (!empty($category_breakdown) && count($category_breakdown) > 1): ?>
        <div class="card-ui p-4 mb-4">
            <h6 class="fw-bold text-dark mb-3">
                <i class="fa-solid fa-chart-column text-danger me-2"></i> Spending by Category (Filtered Period)
            </h6>
            <div class="row g-3">
                <?php 
                $shown = 0;
                $colors = ['#dc2626', '#ea580c', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6'];
                foreach ($category_breakdown as $cName => $cAmt): 
                    if ($shown++ >= 6) break;
                    $pct = ($filtered_total > 0) ? round(($cAmt / $filtered_total) * 100, 1) : 0;
                    $col = $colors[($shown - 1) % count($colors)];
                ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 rounded-3 border bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-semibold small text-dark text-truncate me-2" title="<?= htmlspecialchars($cName) ?>">
                                    <?= htmlspecialchars($cName) ?>
                                </span>
                                <span class="fw-bold small text-danger">Rs <?= number_format($cAmt, 0) ?></span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background-color: <?= $col ?>;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <div class="d-flex justify-content-between text-muted" style="font-size: 0.7rem; margin-top: 3px;">
                                <span><?= $pct ?>% of total</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Expenses Data Table -->
    <div class="card-ui p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold text-dark mb-0">Expense Transactions</h5>
                <span class="text-muted small">Showing <?= $filtered_count ?> records according to active date range</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="expensesTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Date</th>
                        <th>Title &amp; Notes</th>
                        <th>Category</th>
                        <th>Payee / Vendor</th>
                        <th>Payment Method</th>
                        <th>Amount (PKR)</th>
                        <th>Recorded By</th>
                        <th>Receipt</th>
                        <th class="text-end" style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expenses)): ?>
                        <!-- Handled cleanly by DataTables emptyTable -->
                    <?php else: ?>
                        <?php foreach ($expenses as $idx => $row): ?>
                            <tr>
                                <td class="text-muted fw-semibold">
                                    EXP-<?= str_pad($row['id'], 5, '0', STR_PAD_LEFT) ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= date('d M, Y', strtotime($row['expense_date'])) ?></div>
                                    <div class="text-muted small" style="font-size: 0.72rem;"><?= date('l', strtotime($row['expense_date'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['title']) ?></div>
                                    <?php if (!empty($row['notes'])): ?>
                                        <div class="text-muted small text-truncate" style="max-width: 240px;" title="<?= htmlspecialchars($row['notes']) ?>">
                                            <?= htmlspecialchars($row['notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-soft-danger cat-tag">
                                        <i class="fa-solid fa-tag"></i> <?= htmlspecialchars($row['category'] ?: 'General') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($row['payee'])): ?>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['payee']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-soft-secondary">
                                        <?= htmlspecialchars($row['payment_method']) ?>
                                    </span>
                                    <?php if (!empty($row['reference_no'])): ?>
                                        <div class="text-muted small mt-1" style="font-size: 0.72rem;">
                                            Ref: <?= htmlspecialchars($row['reference_no']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-danger fs-6">
                                        Rs <?= number_format((float)$row['amount'], 0) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-soft-primary">
                                        <i class="fa-regular fa-user me-1"></i><?= htmlspecialchars($row['created_by'] ?? 'Operator') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($row['receipt_image'])): 
                                        $ext = strtolower(pathinfo($row['receipt_image'], PATHINFO_EXTENSION));
                                        $fileUrl = '../uploads/expenses/' . htmlspecialchars($row['receipt_image']);
                                    ?>
                                        <?php if ($ext === 'pdf'): ?>
                                            <a href="<?= $fileUrl ?>" target="_blank" class="btn btn-sm btn-light border rounded-pill px-2 py-1 text-danger fw-semibold" title="View PDF Receipt">
                                                <i class="fa-solid fa-file-pdf"></i> PDF
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-light border rounded-circle p-0" style="width: 32px; height: 32px;" onclick="viewReceiptImage('<?= $fileUrl ?>', '<?= htmlspecialchars(addslashes($row['title'])) ?>')" title="View Receipt">
                                                <i class="fa-solid fa-image text-primary"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <!-- Voucher Print -->
                                        <button type="button" class="btn btn-sm btn-light border rounded-circle text-secondary" style="width: 32px; height: 32px;" onclick="printExpenseVoucher(<?= htmlspecialchars(json_encode($row)) ?>)" title="Print Expense Voucher">
                                            <i class="fa-solid fa-print"></i>
                                        </button>
                                        <!-- Edit -->
                                        <button type="button" class="btn btn-sm btn-light border rounded-circle text-primary" style="width: 32px; height: 32px;" onclick="openEditModal(<?= (int)$row['id'] ?>)" title="Edit Expense">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <!-- Delete -->
                                        <button type="button" class="btn btn-sm btn-light border rounded-circle text-danger" style="width: 32px; height: 32px;" onclick="confirmDelete(<?= (int)$row['id'] ?>, '<?= htmlspecialchars(addslashes($row['title'])) ?>')" title="Delete Expense">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ============================================================== -->
<!-- MODAL: ADD EXPENSE                                             -->
<!-- ============================================================== -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-labelledby="addExpenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #ef4444, #b91c1c);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-receipt fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="addExpenseModalLabel">Add Daily Expense</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_expense">
                
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Expense Date -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Expense Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="expense_date" class="form-control rounded-3" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Amount -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Amount (PKR) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="1" name="amount" class="form-control rounded-end-3" placeholder="0" required>
                            </div>
                        </div>

                        <!-- Expense Title -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Expense Title / Description <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="title" class="form-control rounded-3" placeholder="e.g. Generator Fuel 20L / Office Electricity Bill / Lineman Bike Fuel" required>
                        </div>

                        <!-- Category -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Category <span class="text-danger">*</span>
                            </label>
                            <select name="category" id="add_category_select" class="form-select rounded-3" onchange="toggleCustomCategoryInput(this, 'add_custom_cat_wrapper')">
                                <?php foreach ($allCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__">+ Custom Category...</option>
                            </select>
                            
                            <div id="add_custom_cat_wrapper" class="mt-2 d-none">
                                <input type="text" name="custom_category" class="form-control rounded-3" placeholder="Enter custom category name">
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select name="payment_method" class="form-select rounded-3">
                                <?php foreach ($paymentMethods as $pm): ?>
                                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Payee / Vendor -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Paid To / Payee (Vendor or Person)
                            </label>
                            <input type="text" name="payee" class="form-control rounded-3" placeholder="e.g. WAPDA, Shell Petrol Pump, Lineman Ali">
                        </div>

                        <!-- Reference / Bill # -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Reference # / Invoice # / Bill #
                            </label>
                            <input type="text" name="reference_no" class="form-control rounded-3" placeholder="e.g. INV-9872 / Slip # 45">
                        </div>

                        <!-- Receipt File Upload -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Attach Receipt / Voucher / Bill (Optional)
                            </label>
                            <input type="file" name="receipt_file" class="form-control rounded-3" accept=".jpg,.jpeg,.png,.webp,.pdf">
                            <div class="form-text text-muted" style="font-size: 0.72rem;">
                                Supported formats: JPG, PNG, WEBP, PDF (Max 5MB).
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Additional Notes / Remarks
                            </label>
                            <textarea name="notes" rows="2" class="form-control rounded-3" placeholder="Any additional details or purpose of this expense..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                        <i class="fa-solid fa-check me-1"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: EDIT EXPENSE                                            -->
<!-- ============================================================== -->
<div class="modal fade" id="editExpenseModal" tabindex="-1" aria-labelledby="editExpenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="editExpenseModalLabel">Edit Expense</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_expense">
                <input type="hidden" name="expense_id" id="edit_expense_id">
                
                <div class="modal-body p-4">
                    <div id="edit_loading_spinner" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="text-muted small mt-2">Loading expense details...</div>
                    </div>

                    <div id="edit_form_fields" class="row g-3 d-none">
                        <!-- Expense Date -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Expense Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="expense_date" id="edit_expense_date" class="form-control rounded-3" required>
                        </div>

                        <!-- Amount -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Amount (PKR) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="1" name="amount" id="edit_amount" class="form-control rounded-end-3" required>
                            </div>
                        </div>

                        <!-- Expense Title -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Expense Title / Description <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="title" id="edit_title" class="form-control rounded-3" required>
                        </div>

                        <!-- Category -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Category <span class="text-danger">*</span>
                            </label>
                            <select name="category" id="edit_category_select" class="form-select rounded-3" onchange="toggleCustomCategoryInput(this, 'edit_custom_cat_wrapper')">
                                <?php foreach ($allCategories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                                <option value="__custom__">+ Custom Category...</option>
                            </select>
                            
                            <div id="edit_custom_cat_wrapper" class="mt-2 d-none">
                                <input type="text" name="custom_category" id="edit_custom_category" class="form-control rounded-3" placeholder="Enter custom category name">
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select name="payment_method" id="edit_payment_method" class="form-select rounded-3">
                                <?php foreach ($paymentMethods as $pm): ?>
                                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Payee / Vendor -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Paid To / Payee
                            </label>
                            <input type="text" name="payee" id="edit_payee" class="form-control rounded-3">
                        </div>

                        <!-- Reference / Bill # -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Reference # / Invoice #
                            </label>
                            <input type="text" name="reference_no" id="edit_reference_no" class="form-control rounded-3">
                        </div>

                        <!-- Current Receipt Preview & Replace -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Receipt / Attachment
                            </label>
                            <div id="edit_current_receipt_box" class="mb-2 d-none">
                                <div class="p-2 border rounded-3 bg-light d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-paperclip text-primary"></i>
                                        <span id="edit_current_receipt_name" class="small fw-semibold text-truncate" style="max-width: 280px;"></span>
                                    </div>
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_receipt" id="remove_receipt_check" value="1">
                                        <label class="form-check-label small text-danger fw-semibold" for="remove_receipt_check">
                                            Remove Receipt
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <input type="file" name="receipt_file" class="form-control rounded-3" accept=".jpg,.jpeg,.png,.webp,.pdf">
                            <div class="form-text text-muted" style="font-size: 0.72rem;">
                                Uploading a new file will replace the current receipt.
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Additional Notes
                            </label>
                            <textarea name="notes" id="edit_notes" rows="2" class="form-control rounded-3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Update Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: DELETE CONFIRMATION                                     -->
<!-- ============================================================== -->
<div class="modal fade" id="deleteExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-4 text-center">
                <div class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-triangle-exclamation fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">Delete Expense?</h5>
                <p class="text-muted small mb-3">
                    Are you sure you want to delete <strong id="delete_expense_title" class="text-dark"></strong>? This action cannot be undone.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_expense">
                    <input type="hidden" name="expense_id" id="delete_expense_id">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill btn-sm px-4 fw-bold shadow-sm">Yes, Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: RECEIPT IMAGE PREVIEW                                   -->
<!-- ============================================================== -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-2 px-3">
                <h6 class="modal-title fw-bold mb-0 text-truncate" id="receiptModalTitle">Receipt Preview</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-2 text-center bg-light">
                <img id="receiptModalImg" src="" alt="Receipt" class="img-fluid rounded-3 shadow-sm" style="max-height: 80vh;">
            </div>
            <div class="modal-footer bg-light py-2 px-3 d-flex justify-content-between">
                <a id="receiptDownloadLink" href="" target="_blank" download class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                    <i class="fa-solid fa-download me-1"></i> Download
                </a>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- HIDDEN PRINT AREA FOR EXPENSE VOUCHER                          -->
<!-- ============================================================== -->
<div id="printArea" style="display: none;">
    <div style="font-family: Arial, sans-serif; max-width: 680px; margin: 0 auto; border: 2px solid #222; padding: 25px; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #222; padding-bottom: 12px; margin-bottom: 20px;">
            <div>
                <h2 style="margin: 0; color: #111; font-size: 24px; text-transform: uppercase;"><?= htmlspecialchars($tenant['company_name']) ?></h2>
                <div style="font-size: 13px; color: #666; margin-top: 3px;">Operational Expense Voucher</div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 16px; font-weight: bold; color: #dc2626;" id="pv_voucher_no">VOUCHER #</div>
                <div style="font-size: 13px; color: #444;" id="pv_date">Date: </div>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;">
            <tr>
                <td style="padding: 8px 0; width: 140px; color: #666;"><strong>Paid To (Payee):</strong></td>
                <td style="padding: 8px 0; font-weight: bold;" id="pv_payee">—</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #666;"><strong>Expense Category:</strong></td>
                <td style="padding: 8px 0;" id="pv_category">—</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #666;"><strong>Payment Method:</strong></td>
                <td style="padding: 8px 0;" id="pv_method">—</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #666;"><strong>Reference / Bill #:</strong></td>
                <td style="padding: 8px 0;" id="pv_ref">—</td>
            </tr>
        </table>

        <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 15px; margin-bottom: 25px;">
            <div style="font-size: 12px; color: #777; text-transform: uppercase; font-weight: bold; margin-bottom: 5px;">Description / Purpose:</div>
            <div style="font-size: 16px; font-weight: bold; color: #111;" id="pv_title"></div>
            <div style="font-size: 13px; color: #555; margin-top: 6px;" id="pv_notes"></div>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-bottom: 40px;">
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 15px 25px; text-align: right;">
                <div style="font-size: 13px; color: #dc2626; font-weight: bold;">TOTAL AMOUNT PAID</div>
                <div style="font-size: 26px; font-weight: 800; color: #dc2626;" id="pv_amount">Rs 0</div>
            </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 60px; padding-top: 20px; border-top: 1px dashed #aaa;">
            <div style="text-align: center; width: 200px;">
                <div style="border-top: 1px solid #333; margin-top: 30px; padding-top: 5px; font-size: 13px;" id="pv_recorded_by">Prepared By</div>
            </div>
            <div style="text-align: center; width: 200px;">
                <div style="border-top: 1px solid #333; margin-top: 30px; padding-top: 5px; font-size: 13px;">Authorized Signature</div>
            </div>
        </div>
    </div>
</div>

<!-- jQuery & DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- DataTables Buttons JS -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }

var expensesDT;

$(document).ready(function() {
    expensesDT = $('#expensesTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }, text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }, text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'excel', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }, text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }, text: '<i class="fa-solid fa-file-csv"></i> CSV' },
            { extend: 'pdf', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }, text: '<i class="fa-solid fa-file-pdf"></i> PDF' }
        ],
        pageLength: 25,
        order: [[1, 'desc'], [0, 'desc']],
        language: {
            search: "Search:",
            searchPlaceholder: "Type to search...",
            emptyTable: '<div class="text-center py-4"><i class="fa-solid fa-receipt fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No expenses recorded for this date range.</div><div class="text-muted small">Click "+ Add Expense" to record daily expenses.</div></div>',
            zeroRecords: '<div class="text-center py-4"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching expenses found.</div></div>'
        }
    });
});

// Toggle custom category text input
function toggleCustomCategoryInput(selectEl, wrapperId) {
    const wrap = document.getElementById(wrapperId);
    if (!wrap) return;
    if (selectEl.value === '__custom__') {
        wrap.classList.remove('d-none');
        const input = wrap.querySelector('input');
        if (input) input.focus();
    } else {
        wrap.classList.add('d-none');
    }
}

// Receipt Image Preview
function viewReceiptImage(imgUrl, title) {
    document.getElementById('receiptModalImg').src = imgUrl;
    document.getElementById('receiptModalTitle').innerText = title || 'Receipt Preview';
    document.getElementById('receiptDownloadLink').href = imgUrl;
    const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
    modal.show();
}

// Open Edit Modal via AJAX
function openEditModal(expId) {
    const modalEl = document.getElementById('editExpenseModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    document.getElementById('edit_loading_spinner').classList.remove('d-none');
    document.getElementById('edit_form_fields').classList.add('d-none');

    fetch('expenses?ajax=get_expense&id=' + expId)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.expense) {
                const e = res.expense;
                document.getElementById('edit_expense_id').value = e.id;
                document.getElementById('edit_expense_date').value = e.expense_date;
                document.getElementById('edit_amount').value = Math.round(parseFloat(e.amount));
                document.getElementById('edit_title').value = e.title;
                document.getElementById('edit_payee').value = e.payee || '';
                document.getElementById('edit_reference_no').value = e.reference_no || '';
                document.getElementById('edit_notes').value = e.notes || '';
                
                // Payment Method
                const pmSelect = document.getElementById('edit_payment_method');
                if (pmSelect) pmSelect.value = e.payment_method || 'Cash';

                // Category
                const catSelect = document.getElementById('edit_category_select');
                const catWrap = document.getElementById('edit_custom_cat_wrapper');
                let foundCat = false;
                for (let i = 0; i < catSelect.options.length; i++) {
                    if (catSelect.options[i].value === e.category) {
                        catSelect.selectedIndex = i;
                        foundCat = true;
                        break;
                    }
                }
                if (!foundCat) {
                    catSelect.value = '__custom__';
                    catWrap.classList.remove('d-none');
                    document.getElementById('edit_custom_category').value = e.category || '';
                } else {
                    catWrap.classList.add('d-none');
                }

                // Receipt Box
                const recBox = document.getElementById('edit_current_receipt_box');
                const chk = document.getElementById('remove_receipt_check');
                if (chk) chk.checked = false;
                if (e.receipt_image) {
                    recBox.classList.remove('d-none');
                    document.getElementById('edit_current_receipt_name').innerText = e.receipt_image;
                } else {
                    recBox.classList.add('d-none');
                }

                document.getElementById('edit_loading_spinner').classList.add('d-none');
                document.getElementById('edit_form_fields').classList.remove('d-none');
            } else {
                alert(res.message || 'Error loading expense.');
                modal.hide();
            }
        })
        .catch(err => {
            alert('Request failed: ' + err.message);
            modal.hide();
        });
}

// Delete Confirmation Modal
function confirmDelete(id, title) {
    document.getElementById('delete_expense_id').value = id;
    document.getElementById('delete_expense_title').innerText = title || ('EXP-' + id);
    const modal = new bootstrap.Modal(document.getElementById('deleteExpenseModal'));
    modal.show();
}

// Print Single Expense Voucher
function printExpenseVoucher(exp) {
    document.getElementById('pv_voucher_no').innerText = 'EXP-' + String(exp.id).padStart(5, '0');
    
    let dt = new Date(exp.expense_date);
    document.getElementById('pv_date').innerText = 'Date: ' + exp.expense_date;
    
    document.getElementById('pv_payee').innerText = exp.payee || 'Self / Cash';
    document.getElementById('pv_category').innerText = exp.category || 'General';
    document.getElementById('pv_method').innerText = exp.payment_method || 'Cash';
    document.getElementById('pv_ref').innerText = exp.reference_no || '—';
    
    document.getElementById('pv_title').innerText = exp.title;
    document.getElementById('pv_notes').innerText = exp.notes || '';
    document.getElementById('pv_amount').innerText = 'Rs ' + Math.round(parseFloat(exp.amount)).toLocaleString('en-US');
    document.getElementById('pv_recorded_by').innerText = 'Recorded By: ' + (exp.created_by || 'Operator');

    // Create hidden iframe to trigger clean print dialog
    let printContent = document.getElementById('printArea').innerHTML;
    let printFrame = document.createElement('iframe');
    printFrame.name = "expense_print_frame";
    printFrame.style.position = "absolute";
    printFrame.style.top = "-1000000px";
    document.body.appendChild(printFrame);

    let frameDoc = printFrame.contentWindow ? printFrame.contentWindow : printFrame.contentDocument.document ? printFrame.contentDocument.document : printFrame.contentDocument;
    frameDoc.document.open();
    frameDoc.document.write('<html><head><title>Expense Voucher - EXP-' + exp.id + '</title>');
    frameDoc.document.write('<style>body { font-family: Arial, sans-serif; margin: 20px; }</style>');
    frameDoc.document.write('</head><body>');
    frameDoc.document.write(printContent);
    frameDoc.document.write('</body></html>');
    frameDoc.document.close();

    setTimeout(function() {
        window.frames["expense_print_frame"].focus();
        window.frames["expense_print_frame"].print();
        document.body.removeChild(printFrame);
    }, 500);
}
</script>

<?php require_once 'footer.php'; ?>
