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
} else {
    $actor_name = $_SESSION['operator_name'] ?? 'Operator';
}

// -------------------------------------------------------------
// Self-Healing Tables: items & operator_investments
// -------------------------------------------------------------
try {
    // 1. Ensure SuperAdmin items catalog table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `unit` VARCHAR(50) NOT NULL DEFAULT 'Nos.',
        `category` VARCHAR(100) NULL DEFAULT 'General',
        `model_code` VARCHAR(100) NULL,
        `description` TEXT NULL,
        `status` ENUM('active', 'inactive') DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_item_name` (`name`),
        INDEX `idx_item_unit` (`unit`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    

    // 2. Ensure operator_investments table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `operator_investments` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `item_name` VARCHAR(255) NOT NULL,
        `unit` VARCHAR(50) NOT NULL DEFAULT 'Nos.',
        `category` VARCHAR(100) NULL DEFAULT 'General',
        `quantity` DECIMAL(12, 2) NOT NULL DEFAULT 1.00,
        `unit_price` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
        `total_amount` DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
        `investment_date` DATE NOT NULL,
        `vendor` VARCHAR(150) NULL,
        `notes` TEXT NULL,
        `created_by` VARCHAR(150) NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_client_inv` (`client_id`, `investment_date`),
        INDEX `idx_client_item` (`client_id`, `item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {
    // Graceful fallback
}

// -------------------------------------------------------------
// Fetch Available SuperAdmin Items for Dropdowns (No Manual Entry)
// -------------------------------------------------------------
$superAdminItemsStmt = $pdo->query("SELECT id, name, unit, category, model_code FROM items WHERE status = 'active' ORDER BY name ASC");
$superAdminItems = $superAdminItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Map items by ID for fast lookup
$itemsMap = [];
foreach ($superAdminItems as $si) {
    $itemsMap[$si['id']] = $si;
}

// -------------------------------------------------------------
// AJAX Endpoint: Fetch Single Investment Details for Edit
// -------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_investment' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $invId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM operator_investments WHERE id = ? AND client_id = ?");
    $stmt->execute([$invId, $client_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($inv) {
        echo json_encode(['success' => true, 'investment' => $inv]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Investment record not found.']);
    }
    exit;
}

// -------------------------------------------------------------
// Helper for Bulletproof Redirection (Prevents White Screens)
// -------------------------------------------------------------
function safe_redirect_net_worth() {
    $target = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'net_worth';
    if (!headers_sent()) {
        header('Location: ' . $target);
        exit;
    } else {
        echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '"><script>window.location.href=' . json_encode($target) . ';</script></head><body>Redirecting...</body></html>';
        exit;
    }
}

// -------------------------------------------------------------
// Handle POST Actions (Add Multiple Investments, Edit, Delete)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD MULTIPLE INVESTMENTS
    if ($action === 'add_investments') {
        $investment_date = trim($_POST['investment_date'] ?? date('Y-m-d'));
        $vendor          = trim($_POST['vendor'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        $item_ids   = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $prices     = $_POST['unit_price'] ?? [];

        $addedCount = 0;
        $totalBatchAmount = 0.0;

        if (!is_array($item_ids) || empty($item_ids)) {
            $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'Please select at least one item from the catalog.'];
        } else {
            $insStmt = $pdo->prepare("INSERT INTO operator_investments 
                (client_id, item_id, item_name, unit, category, quantity, unit_price, total_amount, investment_date, vendor, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            for ($i = 0; $i < count($item_ids); $i++) {
                $iId  = (int)($item_ids[$i] ?? 0);
                $qty  = (float)($quantities[$i] ?? 0);
                $prc  = (float)($prices[$i] ?? 0);

                if ($iId > 0 && isset($itemsMap[$iId]) && $qty > 0 && $prc >= 0) {
                    $itemObj = $itemsMap[$iId];
                    $iName   = $itemObj['name'];
                    $iUnit   = $itemObj['unit'];
                    $iCat    = $itemObj['category'] ?: 'General';
                    $lineTot = round($qty * $prc, 2);

                    $insStmt->execute([
                        $client_id, $iId, $iName, $iUnit, $iCat, $qty, $prc, $lineTot,
                        $investment_date, $vendor, $notes, $actor_name
                    ]);

                    $addedCount++;
                    $totalBatchAmount += $lineTot;
                }
            }

            if ($addedCount > 0) {
                // Log activity
                try {
                    $act = "Added {$addedCount} material items to Net Worth investment (Total: Rs. " . number_format($totalBatchAmount, 0) . ")";
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Net Worth', 'Inventory', ?)")
                        ->execute([$client_id, $actor_name, $act]);
                } catch (Exception $e) {}

                $_SESSION['networth_flash'] = [
                    'type' => 'success',
                    'msg' => "Successfully recorded {$addedCount} items! Total investment added: Rs. " . number_format($totalBatchAmount, 0)
                ];
            } else {
                $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'No valid item rows were submitted. Please check quantities and prices.'];
            }
        }
        safe_redirect_net_worth();
    }

    // 2. EDIT SINGLE INVESTMENT
    if ($action === 'edit_investment') {
        $inv_id          = (int)($_POST['investment_id'] ?? 0);
        $item_id         = (int)($_POST['item_id'] ?? 0);
        $quantity        = (float)($_POST['quantity'] ?? 0);
        $unit_price      = (float)($_POST['unit_price'] ?? 0);
        $investment_date = trim($_POST['investment_date'] ?? date('Y-m-d'));
        $vendor          = trim($_POST['vendor'] ?? '');
        $notes           = trim($_POST['notes'] ?? '');

        // Verify ownership
        $chk = $pdo->prepare("SELECT id FROM operator_investments WHERE id = ? AND client_id = ?");
        $chk->execute([$inv_id, $client_id]);
        if (!$chk->fetch()) {
            $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'Investment record not found.'];
        } elseif ($item_id <= 0 || !isset($itemsMap[$item_id]) || $quantity <= 0 || $unit_price < 0) {
            $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'Invalid product, quantity, or unit price.'];
        } else {
            $itemObj     = $itemsMap[$item_id];
            $item_name   = $itemObj['name'];
            $unit        = $itemObj['unit'];
            $category    = $itemObj['category'] ?: 'General';
            $total_amount = round($quantity * $unit_price, 2);

            try {
                $upd = $pdo->prepare("UPDATE operator_investments SET 
                    item_id = ?, item_name = ?, unit = ?, category = ?, quantity = ?, unit_price = ?, total_amount = ?, investment_date = ?, vendor = ?, notes = ?
                    WHERE id = ? AND client_id = ?");
                $upd->execute([
                    $item_id, $item_name, $unit, $category, $quantity, $unit_price, $total_amount,
                    $investment_date, $vendor, $notes, $inv_id, $client_id
                ]);

                $_SESSION['networth_flash'] = ['type' => 'success', 'msg' => "Investment for '{$item_name}' updated successfully!"];
            } catch (Exception $e) {
                $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'Error updating investment: ' . $e->getMessage()];
            }
        }
        safe_redirect_net_worth();
    }

    // 3. DELETE INVESTMENT
    if ($action === 'delete_investment') {
        $inv_id = (int)($_POST['investment_id'] ?? 0);
        try {
            $chk = $pdo->prepare("SELECT item_name, total_amount FROM operator_investments WHERE id = ? AND client_id = ?");
            $chk->execute([$inv_id, $client_id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->prepare("DELETE FROM operator_investments WHERE id = ? AND client_id = ?")->execute([$inv_id, $client_id]);
                
                try {
                    $act = "Deleted Net Worth investment item: {$existing['item_name']} (Rs. " . number_format((float)$existing['total_amount'], 0) . ")";
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Net Worth', 'Inventory', ?)")
                        ->execute([$client_id, $actor_name, $act]);
                } catch (Exception $e) {}

                $_SESSION['networth_flash'] = ['type' => 'success', 'msg' => "Investment item '{$existing['item_name']}' deleted successfully."];
            } else {
                $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'Investment record not found.'];
            }
        } catch (Exception $e) {
            $_SESSION['networth_flash'] = ['type' => 'danger', 'msg' => 'Error deleting investment: ' . $e->getMessage()];
        }
        safe_redirect_net_worth();
    }
}

// Flash Alert
$flash = null;
if (isset($_SESSION['networth_flash'])) {
    $flash = $_SESSION['networth_flash'];
    unset($_SESSION['networth_flash']);
}

// -------------------------------------------------------------
// Fetch All Investments for this Operator
// -------------------------------------------------------------
$investmentsStmt = $pdo->prepare("SELECT * FROM operator_investments WHERE client_id = ? ORDER BY id DESC");
$investmentsStmt->execute([$client_id]);
$investments = $investmentsStmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// Calculate Business Net Worth & Statistics
// -------------------------------------------------------------
$total_net_worth = 0.0;
$total_hardware_nos = 0.0;
$total_cables_km = 0.0;
$total_cables_mtr = 0.0;
$category_totals = [];

foreach ($investments as $inv) {
    $amt = (float)$inv['total_amount'];
    $qty = (float)$inv['quantity'];
    $total_net_worth += $amt;

    $u = strtolower(trim($inv['unit']));
    if (in_array($u, ['nos.', 'nos', 'pcs.', 'pcs', 'piece', 'unit'])) {
        $total_hardware_nos += $qty;
    } elseif (in_array($u, ['km', 'kilometer'])) {
        $total_cables_km += $qty;
    } elseif (in_array($u, ['mtr', 'meter', 'm'])) {
        $total_cables_mtr += $qty;
    }

    $c = !empty($inv['category']) ? $inv['category'] : 'General';
    if (!isset($category_totals[$c])) $category_totals[$c] = 0.0;
    $category_totals[$c] += $amt;
}
arsort($category_totals);

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
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
        margin-bottom: 3px;
    }

    /* Dynamic Item Row in Modal */
    .item-entry-row {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px;
        margin-bottom: 12px;
        transition: all 0.2s ease;
    }
    .item-entry-row:hover {
        border-color: #cbd5e1;
        background: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.03);
    }

    /* Unit Badges */
    .badge-unit-nos { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700; }
    .badge-unit-km { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 700; }
    .badge-unit-mtr { background: #faf5ff; color: #6b21a8; border: 1px solid #e9d5ff; font-weight: 700; }
    .badge-unit-roll { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; font-weight: 700; }
    .badge-unit-other { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; font-weight: 700; }

    /* Premium DataTables Styling */
    #netWorthTable {
        border-collapse: separate !important;
        border-spacing: 0 8px !important;
    }
    #netWorthTable thead th {
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
    #netWorthTable thead th:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    #netWorthTable thead th:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    #netWorthTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02) !important;
        border-radius: 14px !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease !important;
    }
    #netWorthTable tbody tr:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06) !important;
    }
    #netWorthTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 13px 14px !important;
        background: transparent !important;
        font-size: 0.88rem !important;
    }
    #netWorthTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 14px !important;
        border-bottom-left-radius: 14px !important;
    }
    #netWorthTable tbody td:last-child {
        border-right: 1px solid #f1f5f9 !important;
        border-top-right-radius: 14px !important;
        border-bottom-right-radius: 14px !important;
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
</style>

<!-- Flash Alert -->
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
                <div class="rounded-3 text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; background: linear-gradient(135deg, #10b981, #047857);">
                    <i class="fa-solid fa-chart-line fs-4"></i>
                </div>
                <div>
                    <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;">Business Net Worth &amp; Material Investment</h3>
                    <p class="text-muted small mb-0">Track all physical network hardware, fiber cables, ONUs and calculate total business asset value.</p>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold shadow-sm d-inline-flex align-items-center gap-2" onclick="window.print()">
                <i class="fa-solid fa-print"></i>
                <span>Print Report</span>
            </button>
            <button type="button" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addInvestmentModal" style="background: linear-gradient(135deg, #10b981, #059669); border: none;">
                <i class="fa-solid fa-plus"></i>
                <span>Add Investment</span>
            </button>
        </div>
    </div>

    <!-- 4 Summary KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Net Worth -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #ecfdf5; color: #059669;">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-label">Total Net Worth / Assets</div>
                    <div class="stat-pill-val text-success text-truncate">
                        Rs <?= number_format($total_net_worth, 0) ?>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        <?= count($investments) ?> total material entries
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Hardware / Routers / ONUs (Nos.) -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-label">Hardware &amp; Devices</div>
                    <div class="stat-pill-val text-primary text-truncate">
                        <?= number_format($total_hardware_nos, 0) ?> <span style="font-size: 0.95rem;">Nos.</span>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        MikroTik, ONUs, Enclosures
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Fiber Infrastructure (Km) -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #faf5ff; color: #7e22ce;">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-label">Fiber Infrastructure</div>
                    <div class="stat-pill-val text-purple text-truncate">
                        <?= number_format($total_cables_km, 0) ?> <span style="font-size: 0.95rem;">Km</span>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        Plus <?= number_format($total_cables_mtr, 0) ?> Mtr Drop wire
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Top Value Category -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fff7ed; color: #ea580c;">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <?php 
                    $topCat = !empty($category_totals) ? array_key_first($category_totals) : 'None';
                    $topCatAmt = !empty($category_totals) ? reset($category_totals) : 0;
                    ?>
                    <div class="stat-pill-label">Top Asset Category</div>
                    <div class="stat-pill-val text-dark text-truncate" style="font-size: 1.15rem;" title="<?= htmlspecialchars($topCat) ?>">
                        <?= htmlspecialchars($topCat) ?>
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">
                        Rs <?= number_format($topCatAmt, 0) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Breakdown Bars (If multiple categories exist) -->
    <?php if (!empty($category_totals) && count($category_totals) > 1): ?>
        <div class="card-ui p-4 mb-4">
            <h6 class="fw-bold text-dark mb-3">
                <i class="fa-solid fa-layer-group text-success me-2"></i> Investment Breakdown by Category
            </h6>
            <div class="row g-3">
                <?php 
                $shown = 0;
                $colors = ['#10b981', '#0284c7', '#8b5cf6', '#f59e0b', '#ec4899', '#64748b'];
                foreach ($category_totals as $cName => $cAmt): 
                    if ($shown++ >= 6) break;
                    $pct = ($total_net_worth > 0) ? round(($cAmt / $total_net_worth) * 100, 1) : 0;
                    $col = $colors[($shown - 1) % count($colors)];
                ?>
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 rounded-3 border bg-light-subtle">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-semibold small text-dark text-truncate me-2" title="<?= htmlspecialchars($cName) ?>">
                                    <?= htmlspecialchars($cName) ?>
                                </span>
                                <span class="fw-bold small text-success">Rs <?= number_format($cAmt, 0) ?></span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar" role="progressbar" style="width: <?= $pct ?>%; background-color: <?= $col ?>;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <div class="d-flex justify-content-between text-muted" style="font-size: 0.7rem; margin-top: 3px;">
                                <span><?= $pct ?>% of total net worth</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Net Worth Materials Table Card -->
    <div class="card-ui p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold text-dark mb-0">All Material Investments</h5>
                <span class="text-muted small">Showing all hardware, cables and networking items purchased and added into your net worth</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="netWorthTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Material / Item Name</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Unit Price (PKR)</th>
                        <th>Total Value (PKR)</th>
                        <th>Date</th>
                        <th>Supplier / Vendor</th>
                        <th>Notes / Specs</th>
                        <th class="text-end" style="width: 90px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($investments as $inv): 
                        $u = strtolower(trim($inv['unit']));
                        $badgeClass = 'badge-unit-other';
                        if (in_array($u, ['nos.', 'nos', 'pcs.', 'pcs'])) $badgeClass = 'badge-unit-nos';
                        elseif (in_array($u, ['km', 'kilometer'])) $badgeClass = 'badge-unit-km';
                        elseif (in_array($u, ['mtr', 'meter', 'm'])) $badgeClass = 'badge-unit-mtr';
                        elseif (in_array($u, ['roll', 'box'])) $badgeClass = 'badge-unit-roll';
                    ?>
                        <tr>
                            <td class="text-muted fw-semibold">
                                MAT-<?= str_pad($inv['id'], 4, '0', STR_PAD_LEFT) ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($inv['item_name']) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border rounded-pill px-2 py-1 small">
                                    <i class="fa-solid fa-tag me-1 text-muted"></i><?= htmlspecialchars($inv['category'] ?: 'General') ?>
                                </span>
                            </td>
                            <td>
                                <span class="fw-bold text-dark me-1"><?= number_format((float)$inv['quantity'], 0) ?></span>
                                <span class="badge rounded-pill px-2 py-1 <?= $badgeClass ?>"><?= htmlspecialchars($inv['unit']) ?></span>
                            </td>
                            <td>
                                <span class="fw-semibold text-muted">Rs <?= number_format((float)$inv['unit_price'], 0) ?></span>
                                <span class="text-muted small" style="font-size: 0.7rem;">/ <?= htmlspecialchars($inv['unit']) ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-success fs-6">
                                    Rs <?= number_format((float)$inv['total_amount'], 0) ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= date('d M, Y', strtotime($inv['investment_date'])) ?></div>
                            </td>
                            <td>
                                <?php if (!empty($inv['vendor'])): ?>
                                    <span class="fw-semibold text-dark"><?= htmlspecialchars($inv['vendor']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($inv['notes'])): ?>
                                    <div class="text-muted small text-truncate" style="max-width: 220px;" title="<?= htmlspecialchars($inv['notes']) ?>">
                                        <?= htmlspecialchars($inv['notes']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <!-- Edit -->
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle text-primary" style="width: 32px; height: 32px;" onclick="openEditInvestmentModal(<?= (int)$inv['id'] ?>)" title="Edit Investment">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <!-- Delete -->
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle text-danger" style="width: 32px; height: 32px;" onclick="confirmDeleteInvestment(<?= (int)$inv['id'] ?>, '<?= htmlspecialchars(addslashes($inv['item_name'])) ?>')" title="Delete Investment">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- ============================================================== -->
<!-- DIALOG BOX: ADD MULTIPLE INVESTMENTS MODAL                     -->
<!-- ============================================================== -->
<div class="modal fade" id="addInvestmentModal" tabindex="-1" aria-labelledby="addInvestmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #10b981, #059669);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-chart-line fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="addInvestmentModalLabel">Add Material Investment (Net Worth)</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST" id="formAddInvestments">
                <input type="hidden" name="action" value="add_investments">
                
                <div class="modal-body p-4">
                    
                    <!-- Batch Information Header -->
                    <div class="row g-3 p-3 bg-light rounded-3 border mb-4">
                        <div class="col-md-4">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Investment / Purchase Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="investment_date" class="form-control rounded-3" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Supplier / Vendor (Optional)
                            </label>
                            <input type="text" name="vendor" class="form-control rounded-3" placeholder="e.g. Al-Rehman Electronics / Direct Import">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-dark fw-bold small mb-1">
                                General Remarks / Notes (Optional)
                            </label>
                            <input type="text" name="notes" class="form-control rounded-3" placeholder="e.g. For Phase 2 Network Expansion">
                        </div>
                    </div>

                    <?php if (empty($superAdminItems)): ?>
                    <div class="alert alert-warning rounded-3 small py-2 px-3 mb-3 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-warning fs-5"></i>
                        <div>Catalog empty: SuperAdmin has not defined any hardware items yet. Please add items in <strong>SuperAdmin &gt; Items</strong> catalog first.</div>
                    </div>
                    <?php endif; ?>

                    <!-- Items Container -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h6 class="fw-bold text-dark mb-0">Select Materials / Products</h6>
                            <span class="text-muted small">Items are loaded from SuperAdmin catalog. Manual product typing is disabled.</span>
                        </div>
                        <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold" onclick="addMaterialRow()">
                            <i class="fa-solid fa-plus me-1"></i> Add Another Item Row
                        </button>
                    </div>

                    <div id="materialRowsContainer">
                        <!-- Dynamic rows will be rendered here -->
                    </div>

                    <!-- Live Grand Total Calculation Footer Bar -->
                    <div class="p-3 rounded-3 bg-success-subtle border border-success-subtle d-flex justify-content-between align-items-center mt-3">
                        <div class="fw-semibold text-success-emphasis">
                            <i class="fa-solid fa-calculator me-1"></i> Live Batch Total:
                        </div>
                        <div class="fs-5 fw-bold text-success" id="batchGrandTotal">
                            Rs 0.00
                        </div>
                    </div>

                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1" style="background: linear-gradient(135deg, #10b981, #059669); border: none;">
                        <i class="fa-solid fa-check me-1"></i> Save Material Investment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DIALOG BOX: EDIT SINGLE INVESTMENT MODAL                       -->
<!-- ============================================================== -->
<div class="modal fade" id="editInvestmentModal" tabindex="-1" aria-labelledby="editInvestmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="editInvestmentModalLabel">Edit Investment Item</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="edit_investment">
                <input type="hidden" name="investment_id" id="edit_inv_id">
                
                <div class="modal-body p-4">
                    <div id="edit_loading_spinner" class="text-center py-4">
                        <div class="spinner-border text-success" role="status"></div>
                        <div class="text-muted small mt-2">Loading investment record...</div>
                    </div>

                    <div id="edit_inv_fields" class="row g-3 d-none">
                        <!-- Select Product from SuperAdmin items -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Product / Material (From SuperAdmin Items) <span class="text-danger">*</span>
                            </label>
                            <select name="item_id" id="edit_item_id" class="form-select rounded-3" required onchange="handleEditItemChange(this)">
                                <option value="">-- Choose Product --</option>
                                <?php foreach ($superAdminItems as $si): ?>
                                    <option value="<?= $si['id'] ?>" data-unit="<?= htmlspecialchars($si['unit']) ?>" data-cat="<?= htmlspecialchars($si['category'] ?: 'General') ?>">
                                        <?= htmlspecialchars($si['name']) ?> (Unit: <?= htmlspecialchars($si['unit']) ?><?= !empty($si['category']) ? ' | ' . htmlspecialchars($si['category']) : '' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Quantity <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <input type="number" step="1" min="1" name="quantity" id="edit_quantity" class="form-control" required oninput="calcEditSubtotal()">
                                <span class="input-group-text bg-light fw-bold text-primary" id="edit_unit_display">Nos.</span>
                            </div>
                        </div>

                        <!-- Unit Price -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Unit Price (PKR) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="0" name="unit_price" id="edit_unit_price" class="form-control" required oninput="calcEditSubtotal()">
                            </div>
                        </div>

                        <!-- Total Calculated Subtotal -->
                        <div class="col-12">
                            <div class="p-2 px-3 rounded-3 bg-light border d-flex justify-content-between align-items-center">
                                <span class="text-muted small fw-bold">Calculated Investment Value:</span>
                                <span class="fw-bold text-success fs-6" id="edit_total_calc">Rs 0.00</span>
                            </div>
                        </div>

                        <!-- Date -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Purchase / Investment Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="investment_date" id="edit_investment_date" class="form-control rounded-3" required>
                        </div>

                        <!-- Vendor -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Supplier / Vendor
                            </label>
                            <input type="text" name="vendor" id="edit_vendor" class="form-control rounded-3">
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Notes &amp; Specifications
                            </label>
                            <textarea name="notes" id="edit_notes" rows="2" class="form-control rounded-3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Update Investment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DIALOG BOX: DELETE CONFIRMATION                                 -->
<!-- ============================================================== -->
<div class="modal fade" id="deleteInvestmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-4 text-center">
                <div class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-trash-can fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">Delete Material Entry?</h5>
                <p class="text-muted small mb-3">
                    Are you sure you want to remove <strong id="delete_inv_name_display" class="text-dark"></strong> from your business Net Worth?
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_investment">
                    <input type="hidden" name="investment_id" id="delete_inv_id_input">
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill btn-sm px-4 fw-bold shadow-sm">Yes, Delete</button>
                    </div>
                </form>
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

<!-- SuperAdmin Items Catalog JSON for Dynamic Rows -->
<script>
const superAdminItemsCatalog = <?= json_encode($superAdminItems) ?>;
if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }

var netWorthDT;

$(document).ready(function() {
    netWorthDT = $('#netWorthTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'excel', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, text: '<i class="fa-solid fa-file-excel"></i> Export Excel' },
            { extend: 'csv', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, text: '<i class="fa-solid fa-file-csv"></i> CSV' },
            { extend: 'print', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'pdf', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'copy', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, text: '<i class="fa-solid fa-copy"></i> Copy' }
        ],
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            searchPlaceholder: "Search material, category, vendor...",
            emptyTable: '<div class="text-center py-4"><i class="fa-solid fa-boxes-stacked fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No materials recorded in Net Worth yet.</div><div class="text-muted small">Click "+ Add Investment" to record networking devices, fiber cables &amp; calculate total business net worth.</div></div>',
            zeroRecords: '<div class="text-center py-4"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching materials found.</div></div>'
        }
    });

    // Populate initial row in modal
    addMaterialRow();
});

// -------------------------------------------------------------
// Dynamic Multi-Item Rows in Add Investment Modal
// -------------------------------------------------------------
var rowIndex = 0;

function addMaterialRow() {
    rowIndex++;
    const container = document.getElementById('materialRowsContainer');
    if (!container) return;

    let optionsHtml = '<option value="">-- Choose Item from SuperAdmin Catalog --</option>';
    superAdminItemsCatalog.forEach(item => {
        optionsHtml += `<option value="${item.id}" data-unit="${item.unit}" data-cat="${item.category || 'General'}">
            ${item.name} (Unit: ${item.unit}${item.category ? ' | ' + item.category : ''})
        </option>`;
    });

    const rowDiv = document.createElement('div');
    rowDiv.className = 'item-entry-row';
    rowDiv.id = `row_${rowIndex}`;
    rowDiv.innerHTML = `
        <div class="row g-2 align-items-center">
            <!-- Product Select -->
            <div class="col-md-5">
                <label class="form-label text-dark fw-bold small mb-1">
                    Product / Material <span class="text-danger">*</span>
                </label>
                <select name="item_id[]" class="form-select rounded-3 item-select" required onchange="handleRowItemChange(this, ${rowIndex})">
                    ${optionsHtml}
                </select>
            </div>

            <!-- Quantity -->
            <div class="col-md-2 col-sm-4">
                <label class="form-label text-dark fw-bold small mb-1">
                    Quantity <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <input type="number" step="1" min="1" name="quantity[]" class="form-control rounded-start-3 qty-input" placeholder="Qty" required oninput="calcRowTotal(${rowIndex})">
                    <span class="input-group-text bg-light text-primary fw-bold unit-badge" id="unit_badge_${rowIndex}" style="font-size: 0.75rem;">Nos.</span>
                </div>
            </div>

            <!-- Unit Price -->
            <div class="col-md-2 col-sm-4">
                <label class="form-label text-dark fw-bold small mb-1">
                    Unit Price <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted fw-bold" style="font-size: 0.75rem;">Rs</span>
                    <input type="number" step="1" min="0" name="unit_price[]" class="form-control rounded-end-3 price-input" placeholder="Price" required oninput="calcRowTotal(${rowIndex})">
                </div>
            </div>

            <!-- Line Total -->
            <div class="col-md-2 col-sm-3">
                <label class="form-label text-dark fw-bold small mb-1">Subtotal</label>
                <div class="form-control bg-light text-success fw-bold text-truncate border-0" id="subtotal_${rowIndex}" style="font-size: 0.88rem;">
                    Rs 0.00
                </div>
            </div>

            <!-- Remove Button -->
            <div class="col-md-1 col-sm-1 text-end pt-3">
                <button type="button" class="btn btn-outline-danger btn-sm rounded-circle" style="width: 32px; height: 32px;" onclick="removeMaterialRow(${rowIndex})" title="Remove row">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
    `;

    container.appendChild(rowDiv);
    updateGrandTotal();
}

function handleRowItemChange(selectEl, rId) {
    const selectedOpt = selectEl.options[selectEl.selectedIndex];
    const unitBadge = document.getElementById(`unit_badge_${rId}`);
    if (selectedOpt && unitBadge) {
        const u = selectedOpt.getAttribute('data-unit') || 'Nos.';
        unitBadge.innerText = u;
    }
    calcRowTotal(rId);
}

function calcRowTotal(rId) {
    const row = document.getElementById(`row_${rId}`);
    if (!row) return;

    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const prc = parseFloat(row.querySelector('.price-input').value) || 0;
    const sub = (qty * prc);

    const subDisplay = document.getElementById(`subtotal_${rId}`);
    if (subDisplay) {
        subDisplay.innerText = 'Rs ' + Math.round(sub).toLocaleString('en-US');
    }
    updateGrandTotal();
}

function removeMaterialRow(rId) {
    const rows = document.querySelectorAll('.item-entry-row');
    if (rows.length <= 1) {
        alert('You must keep at least one item row.');
        return;
    }
    const row = document.getElementById(`row_${rId}`);
    if (row) {
        row.remove();
        updateGrandTotal();
    }
}

function updateGrandTotal() {
    let grand = 0.0;
    document.querySelectorAll('.item-entry-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const prc = parseFloat(row.querySelector('.price-input').value) || 0;
        grand += (qty * prc);
    });

    const display = document.getElementById('batchGrandTotal');
    if (display) {
        display.innerText = 'Rs ' + Math.round(grand).toLocaleString('en-US');
    }
}

// -------------------------------------------------------------
// Edit Investment Modal Functions
// -------------------------------------------------------------
function openEditInvestmentModal(invId) {
    const modalEl = document.getElementById('editInvestmentModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    document.getElementById('edit_loading_spinner').classList.remove('d-none');
    document.getElementById('edit_inv_fields').classList.add('d-none');

    fetch('net_worth?ajax=get_investment&id=' + invId)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.investment) {
                const inv = res.investment;
                document.getElementById('edit_inv_id').value = inv.id;
                document.getElementById('edit_item_id').value = inv.item_id;
                document.getElementById('edit_quantity').value = Math.round(parseFloat(inv.quantity));
                document.getElementById('edit_unit_price').value = Math.round(parseFloat(inv.unit_price));
                document.getElementById('edit_unit_display').innerText = inv.unit || 'Nos.';
                document.getElementById('edit_investment_date').value = inv.investment_date;
                document.getElementById('edit_vendor').value = inv.vendor || '';
                document.getElementById('edit_notes').value = inv.notes || '';

                calcEditSubtotal();

                document.getElementById('edit_loading_spinner').classList.add('d-none');
                document.getElementById('edit_inv_fields').classList.remove('d-none');
            } else {
                alert(res.message || 'Error loading investment.');
                modal.hide();
            }
        })
        .catch(err => {
            alert('Request failed: ' + err.message);
            modal.hide();
        });
}

function handleEditItemChange(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const unitDisp = document.getElementById('edit_unit_display');
    if (opt && unitDisp) {
        unitDisp.innerText = opt.getAttribute('data-unit') || 'Nos.';
    }
    calcEditSubtotal();
}

function calcEditSubtotal() {
    const qty = parseFloat(document.getElementById('edit_quantity').value) || 0;
    const prc = parseFloat(document.getElementById('edit_unit_price').value) || 0;
    const sub = (qty * prc);
    document.getElementById('edit_total_calc').innerText = 'Rs ' + Math.round(sub).toLocaleString('en-US');
}

// -------------------------------------------------------------
// Delete Confirmation Modal
// -------------------------------------------------------------
function confirmDeleteInvestment(invId, itemName) {
    document.getElementById('delete_inv_id_input').value = invId;
    document.getElementById('delete_inv_name_display').innerText = itemName || ('MAT-' + invId);
    const modal = new bootstrap.Modal(document.getElementById('deleteInvestmentModal'));
    modal.show();
}
</script>

<?php require_once 'footer.php'; ?>
