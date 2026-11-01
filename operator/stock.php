<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

// Auth Check
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: ../login.php");
    exit;
}

$client_id = (int)($_SESSION['operator_id'] ?? 1);
$current_user = $_SESSION['operator_username'] ?? (!empty($_SESSION['is_manager']) ? 'Manager' : 'Admin');

try {
    // Adopt any legacy superadmin items to current operator so they immediately appear
    $chkLegacy = $pdo->prepare("SELECT COUNT(*) FROM items WHERE client_id = ?");
    $chkLegacy->execute([$client_id]);
    if ($chkLegacy->fetchColumn() == 0) {
        $pdo->prepare("UPDATE items SET client_id = ? WHERE client_id = 0 OR client_id = 1")->execute([$client_id]);
        $pdo->prepare("UPDATE stock_transactions SET client_id = ? WHERE client_id = 0 OR client_id = 1")->execute([$client_id]);
    }
} catch (Exception $e) {}

// -------------------------------------------------------------
// Self-Healing Database Schema Migration
// -------------------------------------------------------------
try {
    // 1. Items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT NOT NULL DEFAULT 1,
        `name` VARCHAR(255) NOT NULL,
        `category` VARCHAR(100) NULL DEFAULT 'General',
        `unit` VARCHAR(50) NOT NULL DEFAULT 'Nos.',
        `model_code` VARCHAR(100) NULL,
        `current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `min_alert_qty` DECIMAL(12,2) NOT NULL DEFAULT 5.00,
        `description` TEXT NULL,
        `status` ENUM('active', 'inactive') DEFAULT 'active',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_items_client` (`client_id`),
        INDEX `idx_items_name` (`name`),
        INDEX `idx_items_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Auto-migrate if items table existed previously without stock columns
    $cols = $pdo->query("SHOW COLUMNS FROM items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('client_id', $cols)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN client_id INT NOT NULL DEFAULT 1 AFTER id, ADD INDEX (client_id)");
    }
    if (!in_array('current_stock', $cols)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER model_code");
    }
    if (!in_array('unit_price', $cols)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER current_stock");
    }
    if (!in_array('min_alert_qty', $cols)) {
        $pdo->exec("ALTER TABLE items ADD COLUMN min_alert_qty DECIMAL(12,2) NOT NULL DEFAULT 5.00 AFTER unit_price");
    }

    // 2. Stock Transactions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_transactions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT NOT NULL DEFAULT 1,
        `item_id` INT NOT NULL,
        `type` ENUM('IN', 'OUT') NOT NULL,
        `quantity` DECIMAL(12,2) NOT NULL,
        `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `balance_before` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `reference_no` VARCHAR(100) NULL,
        `party_name` VARCHAR(255) NULL,
        `issued_to_type` VARCHAR(50) DEFAULT 'general',
        `issued_to_id` INT NULL,
        `remarks` TEXT NULL,
        `created_by` VARCHAR(100) NOT NULL DEFAULT 'Admin',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_st_client` (`client_id`),
        INDEX `idx_st_item` (`item_id`),
        INDEX `idx_st_type` (`type`),
        INDEX `idx_st_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

// -------------------------------------------------------------
// AJAX Endpoint: Get Single Item Details
// -------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_item' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $itemId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ? AND (client_id = ? OR client_id = 1 OR client_id = 0)");
    $stmt->execute([$itemId, $client_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        echo json_encode(['success' => true, 'item' => $item]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
    }
    exit;
}

// -------------------------------------------------------------
// POST Actions Handler
// -------------------------------------------------------------
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD ITEM
    if ($action === 'add_item') {
        $name          = trim($_POST['name'] ?? '');
        $category      = trim($_POST['category'] ?? 'General');
        $unit          = trim($_POST['unit'] ?? 'Nos.');
        $model_code    = trim($_POST['model_code'] ?? '');
        $unit_price    = max(0, (float)($_POST['unit_price'] ?? 0));
        $initial_stock = max(0, (float)($_POST['initial_stock'] ?? 0));
        $min_alert_qty = max(0, (float)($_POST['min_alert_qty'] ?? 5));
        $description   = trim($_POST['description'] ?? '');
        $status        = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name) || empty($unit)) {
            $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Item Name and Unit are required.'];
        } else {
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO items (client_id, name, category, unit, model_code, current_stock, unit_price, min_alert_qty, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$client_id, $name, $category, $unit, $model_code, $initial_stock, $unit_price, $min_alert_qty, $description, $status]);
                $newItemId = $pdo->lastInsertId();

                if ($initial_stock > 0) {
                    $totPrice = $initial_stock * $unit_price;
                    $stIns = $pdo->prepare("INSERT INTO stock_transactions (client_id, item_id, type, quantity, unit_price, total_price, balance_before, balance_after, reference_no, party_name, remarks, created_by) VALUES (?, ?, 'IN', ?, ?, ?, 0.00, ?, 'INITIAL', 'Opening Balance', 'Initial stock entry', ?)");
                    $stIns->execute([$client_id, $newItemId, $initial_stock, $unit_price, $totPrice, $initial_stock, $current_user]);
                }

                $pdo->commit();
                $_SESSION['stock_flash'] = ['type' => 'success', 'msg' => "Item '{$name}' created successfully!"];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Error creating item: ' . $e->getMessage()];
            }
        }
        echo "<script>window.location='stock';</script>";
        exit;
    }

    // 2. EDIT ITEM
    if ($action === 'edit_item') {
        $itemId        = (int)($_POST['item_id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $category      = trim($_POST['category'] ?? 'General');
        $unit          = trim($_POST['unit'] ?? 'Nos.');
        $model_code    = trim($_POST['model_code'] ?? '');
        $unit_price    = max(0, (float)($_POST['unit_price'] ?? 0));
        $min_alert_qty = max(0, (float)($_POST['min_alert_qty'] ?? 5));
        $description   = trim($_POST['description'] ?? '');
        $status        = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($itemId) || empty($name) || empty($unit)) {
            $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Item ID, Name and Unit are required.'];
        } else {
            try {
                $upd = $pdo->prepare("UPDATE items SET name = ?, category = ?, unit = ?, model_code = ?, unit_price = ?, min_alert_qty = ?, description = ?, status = ? WHERE id = ? AND client_id = ?");
                $upd->execute([$name, $category, $unit, $model_code, $unit_price, $min_alert_qty, $description, $status, $itemId, $client_id]);
                $_SESSION['stock_flash'] = ['type' => 'success', 'msg' => "Item '{$name}' updated successfully!"];
            } catch (Exception $e) {
                $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Error updating item: ' . $e->getMessage()];
            }
        }
        echo "<script>window.location='stock';</script>";
        exit;
    }

    // 3. DELETE ITEM
    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        try {
            $chk = $pdo->prepare("SELECT name, current_stock FROM items WHERE id = ? AND client_id = ?");
            $chk->execute([$itemId, $client_id]);
            $itemRow = $chk->fetch(PDO::FETCH_ASSOC);

            if ($itemRow) {
                $pdo->prepare("DELETE FROM items WHERE id = ? AND client_id = ?")->execute([$itemId, $client_id]);
                $pdo->prepare("DELETE FROM stock_transactions WHERE item_id = ? AND client_id = ?")->execute([$itemId, $client_id]);
                $_SESSION['stock_flash'] = ['type' => 'success', 'msg' => "Item '{$itemRow['name']}' deleted successfully."];
            }
        } catch (Exception $e) {
            $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Error deleting item: ' . $e->getMessage()];
        }
        echo "<script>window.location='stock';</script>";
        exit;
    }

    // 4. STOCK IN (PURCHASE / RECEIVED)
    if ($action === 'stock_in') {
        $itemId       = (int)($_POST['item_id'] ?? 0);
        $quantity     = max(0.01, (float)($_POST['quantity'] ?? 0));
        $unit_price   = (float)($_POST['unit_price'] ?? 0);
        $supplier     = trim($_POST['supplier'] ?? 'Direct Purchase');
        $reference_no = trim($_POST['reference_no'] ?? '');
        $remarks      = trim($_POST['remarks'] ?? '');

        if ($itemId <= 0 || $quantity <= 0) {
            $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Please select a valid item and quantity.'];
        } else {
            try {
                $pdo->beginTransaction();
                $q = $pdo->prepare("SELECT name, current_stock, unit_price, unit FROM items WHERE id = ? AND client_id = ? FOR UPDATE");
                $q->execute([$itemId, $client_id]);
                $item = $q->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    throw new Exception("Item not found.");
                }

                if ($unit_price <= 0) {
                    $unit_price = (float)$item['unit_price'];
                }

                $bal_before = (float)$item['current_stock'];
                $bal_after  = $bal_before + $quantity;
                $tot_price  = $quantity * $unit_price;

                // Update item current stock and unit price
                $updItem = $pdo->prepare("UPDATE items SET current_stock = ?, unit_price = ? WHERE id = ? AND client_id = ?");
                $updItem->execute([$bal_after, $unit_price, $itemId, $client_id]);

                // Insert into stock_transactions
                $stIns = $pdo->prepare("INSERT INTO stock_transactions (client_id, item_id, type, quantity, unit_price, total_price, balance_before, balance_after, reference_no, party_name, issued_to_type, remarks, created_by) VALUES (?, ?, 'IN', ?, ?, ?, ?, ?, ?, ?, 'supplier', ?, ?)");
                $stIns->execute([$client_id, $itemId, $quantity, $unit_price, $tot_price, $bal_before, $bal_after, $reference_no, $supplier, $remarks, $current_user]);

                // Activity log
                try {
                    $logAct = $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, 'Stock', ?)");
                    $logAct->execute([$client_id, $current_user, $item['name'], "Stock IN: +{$quantity} {$item['unit']} (New Balance: {$bal_after})"]);
                } catch (Exception $e) {}

                $pdo->commit();
                $_SESSION['stock_flash'] = ['type' => 'success', 'msg' => "Stock IN recorded! +{$quantity} {$item['unit']} added to '{$item['name']}'. New Balance: {$bal_after}."];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Error recording Stock IN: ' . $e->getMessage()];
            }
        }
        echo "<script>window.location='stock';</script>";
        exit;
    }

    // 5. STOCK OUT (ISSUED / USAGE / DAMAGE)
    if ($action === 'stock_out') {
        $itemId         = (int)($_POST['item_id'] ?? 0);
        $quantity       = max(0.01, (float)($_POST['quantity'] ?? 0));
        $issued_to_type = trim($_POST['issued_to_type'] ?? 'general');
        $party_name     = trim($_POST['party_name'] ?? '');
        $reference_no   = trim($_POST['reference_no'] ?? '');
        $remarks        = trim($_POST['remarks'] ?? '');

        // Determine party name if lineman or dealer selected from dropdown
        if ($issued_to_type === 'lineman' && !empty($_POST['lineman_id'])) {
            $lmStmt = $pdo->prepare("SELECT full_name FROM linemen WHERE id = ? AND client_id = ?");
            $lmStmt->execute([(int)$_POST['lineman_id'], $client_id]);
            $lmName = $lmStmt->fetchColumn();
            if ($lmName) $party_name = "Lineman: " . $lmName;
        } elseif ($issued_to_type === 'dealer' && !empty($_POST['dealer_id'])) {
            $dlStmt = $pdo->prepare("SELECT full_name FROM dealers WHERE id = ? AND client_id = ?");
            $dlStmt->execute([(int)$_POST['dealer_id'], $client_id]);
            $dlName = $dlStmt->fetchColumn();
            if ($dlName) $party_name = "Dealer: " . $dlName;
        }

        if (empty($party_name)) {
            $party_name = ucfirst($issued_to_type) . " Usage";
        }

        if ($itemId <= 0 || $quantity <= 0) {
            $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => 'Please select a valid item and quantity.'];
        } else {
            try {
                $pdo->beginTransaction();
                $q = $pdo->prepare("SELECT name, current_stock, unit_price, unit FROM items WHERE id = ? AND client_id = ? FOR UPDATE");
                $q->execute([$itemId, $client_id]);
                $item = $q->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    throw new Exception("Item not found.");
                }

                $bal_before = (float)$item['current_stock'];

                if ($quantity > $bal_before) {
                    throw new Exception("Insufficient stock! Available balance is only {$bal_before} {$item['unit']}, cannot issue {$quantity} {$item['unit']}.");
                }

                $bal_after  = $bal_before - $quantity;
                $unit_price = (float)$item['unit_price'];
                $tot_price  = $quantity * $unit_price;

                // Update item current stock
                $updItem = $pdo->prepare("UPDATE items SET current_stock = ? WHERE id = ? AND client_id = ?");
                $updItem->execute([$bal_after, $itemId, $client_id]);

                // Insert into stock_transactions
                $stIns = $pdo->prepare("INSERT INTO stock_transactions (client_id, item_id, type, quantity, unit_price, total_price, balance_before, balance_after, reference_no, party_name, issued_to_type, remarks, created_by) VALUES (?, ?, 'OUT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stIns->execute([$client_id, $itemId, $quantity, $unit_price, $tot_price, $bal_before, $bal_after, $reference_no, $party_name, $issued_to_type, $remarks, $current_user]);

                // Activity log
                try {
                    $logAct = $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, 'Stock', ?)");
                    $logAct->execute([$client_id, $current_user, $item['name'], "Stock OUT: -{$quantity} {$item['unit']} to {$party_name} (New Balance: {$bal_after})"]);
                } catch (Exception $e) {}

                $pdo->commit();
                $_SESSION['stock_flash'] = ['type' => 'success', 'msg' => "Stock OUT recorded! -{$quantity} {$item['unit']} issued to {$party_name}. Remaining Balance: {$bal_after} {$item['unit']}."];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['stock_flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
            }
        }
        echo "<script>window.location='stock';</script>";
        exit;
    }
}

if (isset($_SESSION['stock_flash'])) {
    $flash = $_SESSION['stock_flash'];
    unset($_SESSION['stock_flash']);
}

require_once 'header.php';

// -------------------------------------------------------------
// Data Fetching: Linemen & Dealers for Stock OUT Select
// -------------------------------------------------------------
$linemenStmt = $pdo->prepare("SELECT id, full_name, phone FROM linemen WHERE client_id = ? ORDER BY full_name ASC");
$linemenStmt->execute([$client_id]);
$all_linemen = $linemenStmt->fetchAll(PDO::FETCH_ASSOC);

$dealersStmt = $pdo->prepare("SELECT id, full_name, username FROM dealers WHERE client_id = ? ORDER BY full_name ASC");
$dealersStmt->execute([$client_id]);
$all_dealers = $dealersStmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// Key KPI Metrics & Analytics Calculation
// -------------------------------------------------------------
$kpiStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_items,
        COALESCE(SUM(current_stock * unit_price), 0) as total_valuation,
        COALESCE(SUM(current_stock), 0) as total_units_in_stock,
        SUM(CASE WHEN current_stock <= min_alert_qty THEN 1 ELSE 0 END) as low_stock_alerts
    FROM items 
    WHERE client_id = ? AND status = 'active'
");
$kpiStmt->execute([$client_id]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

// Usage Stats: Today, Last 7 Days (Weekly), Last 30 Days (Monthly)
$usageStatsStmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN quantity ELSE 0 END), 0) as today_usage,
        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_price ELSE 0 END), 0) as today_usage_val,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN quantity ELSE 0 END), 0) as weekly_usage,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN total_price ELSE 0 END), 0) as weekly_usage_val,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN quantity ELSE 0 END), 0) as monthly_usage,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_price ELSE 0 END), 0) as monthly_usage_val,
        COALESCE(SUM(quantity), 0) as all_time_usage
    FROM stock_transactions
    WHERE client_id = ? AND type = 'OUT'
");
$usageStatsStmt->execute([$client_id]);
$usageStats = $usageStatsStmt->fetch(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// Items Query with Real-time Daily, Weekly & Monthly Usage
// -------------------------------------------------------------
$itemsQuery = "
    SELECT 
        i.*,
        COALESCE(st_today.qty_out, 0) as today_out,
        COALESCE(st_week.qty_out, 0) as week_out,
        COALESCE(st_month.qty_out, 0) as month_out,
        COALESCE(st_all.qty_out, 0) as total_out,
        COALESCE(st_all.qty_in, 0) as total_in
    FROM items i
    LEFT JOIN (
        SELECT item_id, SUM(quantity) as qty_out 
        FROM stock_transactions 
        WHERE client_id = ? AND type = 'OUT' AND DATE(created_at) = CURDATE() 
        GROUP BY item_id
    ) st_today ON st_today.item_id = i.id
    LEFT JOIN (
        SELECT item_id, SUM(quantity) as qty_out 
        FROM stock_transactions 
        WHERE client_id = ? AND type = 'OUT' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
        GROUP BY item_id
    ) st_week ON st_week.item_id = i.id
    LEFT JOIN (
        SELECT item_id, SUM(quantity) as qty_out 
        FROM stock_transactions 
        WHERE client_id = ? AND type = 'OUT' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
        GROUP BY item_id
    ) st_month ON st_month.item_id = i.id
    LEFT JOIN (
        SELECT item_id, 
               SUM(CASE WHEN type = 'OUT' THEN quantity ELSE 0 END) as qty_out,
               SUM(CASE WHEN type = 'IN' THEN quantity ELSE 0 END) as qty_in
        FROM stock_transactions 
        WHERE client_id = ? 
        GROUP BY item_id
    ) st_all ON st_all.item_id = i.id
    WHERE i.client_id = ?
    ORDER BY i.current_stock <= i.min_alert_qty DESC, i.name ASC
";
$itemsStmt = $pdo->prepare($itemsQuery);
$itemsStmt->execute([$client_id, $client_id, $client_id, $client_id, $client_id]);
$items_list = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------
// Stock Ledger / Report Query with Filters
// -------------------------------------------------------------
$filter_from = $_GET['from_date'] ?? '';
$filter_to   = $_GET['to_date'] ?? '';
$filter_item = (int)($_GET['filter_item'] ?? 0);
$filter_type = trim($_GET['filter_type'] ?? '');

$logSql = "
    SELECT 
        st.*,
        i.name as item_name,
        i.unit as item_unit,
        i.category as item_category,
        i.model_code as item_model
    FROM stock_transactions st
    JOIN items i ON st.item_id = i.id
    WHERE st.client_id = ?
";
$logParams = [$client_id];

if (!empty($filter_from)) {
    $logSql .= " AND DATE(st.created_at) >= ?";
    $logParams[] = $filter_from;
}
if (!empty($filter_to)) {
    $logSql .= " AND DATE(st.created_at) <= ?";
    $logParams[] = $filter_to;
}
if ($filter_item > 0) {
    $logSql .= " AND st.item_id = ?";
    $logParams[] = $filter_item;
}
if (in_array($filter_type, ['IN', 'OUT'])) {
    $logSql .= " AND st.type = ?";
    $logParams[] = $filter_type;
}

$logSql .= " ORDER BY st.id DESC LIMIT 1500";
$logStmt = $pdo->prepare($logSql);
$logStmt->execute($logParams);
$transactions_list = $logStmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesList = [
    'Routers & Networking',
    'Fiber & Optical Cable',
    'ONUs & Modems',
    'Patch Cords & Adapters',
    'Joint Closures & Enclosures',
    'Splitters & Couplers',
    'SFP Modules & Media Converters',
    'Splicing Tools & Accessories',
    'Power, UPS & Batteries',
    'Racks, Cabinets & Hardware',
    'General / Other'
];

$commonUnits = ['Nos.', 'Km', 'Mtr', 'Roll', 'Box', 'Pkt', 'Set', 'Feet'];
?>

<!-- DataTables CSS & Export Buttons -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .stock-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
        transition: all 0.25s ease;
    }
    .kpi-card {
        border-radius: 14px;
        padding: 18px 20px;
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        background: #fff;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.06);
    }
    .kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .nav-tabs-stock {
        border-bottom: 2px solid #e2e8f0;
        gap: 8px;
    }
    .nav-tabs-stock .nav-link {
        border: none;
        color: #64748b;
        font-weight: 600;
        font-size: 0.95rem;
        padding: 12px 20px;
        border-radius: 10px 10px 0 0;
        transition: all 0.2s ease;
        position: relative;
    }
    .nav-tabs-stock .nav-link:hover {
        color: #2563eb;
        background: #f8fafc;
    }
    .nav-tabs-stock .nav-link.active {
        color: #2563eb;
        background: transparent;
        border-bottom: 3px solid #2563eb;
    }
    .badge-stock-healthy {
        background: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
        font-weight: 600;
    }
    .badge-stock-warning {
        background: #fffbeb;
        color: #d97706;
        border: 1px solid #fde68a;
        font-weight: 600;
    }
    .badge-stock-danger {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
        font-weight: 600;
    }
    .btn-action-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        transition: all 0.15s ease;
    }
    .btn-action-icon:hover {
        background: #f1f5f9;
        color: #0f172a;
        transform: scale(1.05);
    }
    .table > :not(caption) > * > * {
        padding: 12px 14px;
        vertical-align: middle;
    }
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Flash Message -->
    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> fs-5"></i>
            <div><?= htmlspecialchars($flash['msg']) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Top Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <div class="rounded-3 p-2 d-flex align-items-center justify-content-center" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-boxes-stacked fs-4"></i>
                </div>
                <div>
                    <h4 class="fw-bold text-dark mb-0">Stock &amp; Inventory Management</h4>
                    <span class="text-muted small">Real-time stock balance, daily/weekly/monthly usage analytics, and ledger reports</span>
                </div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-success fw-bold px-3 py-2 rounded-pill shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#stockInModal">
                <i class="fa-solid fa-circle-plus"></i>
                <span>Stock IN (+)</span>
            </button>
            <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 rounded-pill shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#stockOutModal">
                <i class="fa-solid fa-circle-minus"></i>
                <span>Stock OUT (-)</span>
            </button>
            <button type="button" class="btn btn-primary fw-bold px-3 py-2 rounded-pill shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa-solid fa-plus"></i>
                <span>Add Item</span>
            </button>
        </div>
    </div>

    <!-- KPI Metric Cards Grid -->
    <div class="row g-3 mb-4">
        <!-- 1. Unique Items -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="kpi-card" style="border-left: 4px solid #2563eb;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Item Catalog</span>
                    <div class="kpi-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fa-solid fa-cube"></i>
                    </div>
                </div>
                <div>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($kpi['total_items'] ?? 0) ?></h3>
                    <small class="text-muted" style="font-size: 0.75rem;">Total registered items</small>
                </div>
            </div>
        </div>

        <!-- 2. Stock Valuation -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="kpi-card" style="border-left: 4px solid #10b981;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Valuation</span>
                    <div class="kpi-icon bg-success bg-opacity-10 text-success">
                        <i class="fa-solid fa-sack-dollar"></i>
                    </div>
                </div>
                <div>
                    <h3 class="fw-bold text-dark mb-0">Rs. <?= number_format($kpi['total_valuation'] ?? 0) ?></h3>
                    <small class="text-muted" style="font-size: 0.75rem;">Est. Current Stock Value</small>
                </div>
            </div>
        </div>

        <!-- 3. Daily Usage (Today) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="kpi-card" style="border-left: 4px solid #0284c7;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Today's Usage</span>
                    <div class="kpi-icon bg-info bg-opacity-10 text-info">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>
                </div>
                <div>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($usageStats['today_usage'] ?? 0, 1) ?></h3>
                    <small class="text-primary fw-semibold" style="font-size: 0.75rem;">Rs. <?= number_format($usageStats['today_usage_val'] ?? 0) ?> used today</small>
                </div>
            </div>
        </div>

        <!-- 4. Weekly Usage -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="kpi-card" style="border-left: 4px solid #8b5cf6;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Weekly Usage</span>
                    <div class="kpi-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                        <i class="fa-solid fa-calendar-week"></i>
                    </div>
                </div>
                <div>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($usageStats['weekly_usage'] ?? 0, 1) ?></h3>
                    <small class="text-muted" style="font-size: 0.75rem;">Rs. <?= number_format($usageStats['weekly_usage_val'] ?? 0) ?> last 7 days</small>
                </div>
            </div>
        </div>

        <!-- 5. Monthly Usage -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="kpi-card" style="border-left: 4px solid #f59e0b;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Monthly Usage</span>
                    <div class="kpi-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fa-solid fa-calendar-alt"></i>
                    </div>
                </div>
                <div>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($usageStats['monthly_usage'] ?? 0, 1) ?></h3>
                    <small class="text-muted" style="font-size: 0.75rem;">Rs. <?= number_format($usageStats['monthly_usage_val'] ?? 0) ?> last 30 days</small>
                </div>
            </div>
        </div>

        <!-- 6. Low Stock Alerts -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="kpi-card" style="border-left: 4px solid #ef4444;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Low Stock Alerts</span>
                    <div class="kpi-icon bg-danger bg-opacity-10 text-danger">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
                <div>
                    <h3 class="fw-bold <?= ($kpi['low_stock_alerts'] ?? 0) > 0 ? 'text-danger' : 'text-dark' ?> mb-0">
                        <?= number_format($kpi['low_stock_alerts'] ?? 0) ?>
                    </h3>
                    <small class="text-muted" style="font-size: 0.75rem;">Items below alert limit</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Navigation Tabs -->
    <div class="stock-card p-3 p-md-4 mb-4">
        <ul class="nav nav-tabs nav-tabs-stock mb-4" id="stockTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#tab-inventory" type="button" role="tab">
                    <i class="fa-solid fa-boxes-packing me-2"></i> Inventory &amp; Stock Balance
                    <span class="badge bg-primary bg-opacity-10 text-primary ms-1 rounded-pill"><?= count($items_list) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="usage-tab" data-bs-toggle="tab" data-bs-target="#tab-usage" type="button" role="tab">
                    <i class="fa-solid fa-chart-line me-2"></i> Daily, Weekly &amp; Monthly Usage
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="ledger-tab" data-bs-toggle="tab" data-bs-target="#tab-ledger" type="button" role="tab">
                    <i class="fa-solid fa-book-bookmark me-2"></i> Stock Ledger &amp; Reports
                    <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1 rounded-pill"><?= count($transactions_list) ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="stockTabsContent">

            <!-- ================= TAB 1: INVENTORY & STOCK BALANCE ================= -->
            <div class="tab-pane fade show active" id="tab-inventory" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="tableInventory">
                        <thead class="table-light text-secondary small fw-bold">
                            <tr>
                                <th>#</th>
                                <th>Item Name &amp; Model</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Unit</th>
                                <th>Unit Cost</th>
                                <th>Valuation</th>
                                <th>Alert Limit</th>
                                <th>Status</th>
                                <th class="text-center">Quick Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items_list as $idx => $it): 
                                $isLow = ((float)$it['current_stock'] <= (float)$it['min_alert_qty']);
                                $isOut = ((float)$it['current_stock'] <= 0);
                            ?>
                            <tr>
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($it['name']) ?></div>
                                    <?php if (!empty($it['model_code'])): ?>
                                        <div class="text-muted small font-monospace"><i class="fa-solid fa-tag me-1" style="font-size: 0.70rem;"></i><?= htmlspecialchars($it['model_code']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 rounded-pill" style="font-size: 0.75rem;">
                                        <?= htmlspecialchars($it['category'] ?: 'General') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge <?= $isOut ? 'badge-stock-danger' : ($isLow ? 'badge-stock-warning' : 'badge-stock-healthy') ?> px-2.5 py-1 rounded-pill fs-6 fw-bold">
                                            <?= number_format($it['current_stock'], ($it['unit'] === 'Nos.' ? 0 : 2)) ?>
                                        </span>
                                        <?php if ($isOut): ?>
                                            <span class="badge bg-danger text-white rounded-pill px-2 py-0.5" style="font-size: 0.65rem;">OUT OF STOCK</span>
                                        <?php elseif ($isLow): ?>
                                            <span class="badge bg-warning text-dark rounded-pill px-2 py-0.5" style="font-size: 0.65rem;">LOW</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="fw-semibold text-secondary small"><?= htmlspecialchars($it['unit']) ?></td>
                                <td class="text-secondary small">Rs. <?= number_format($it['unit_price'], 2) ?></td>
                                <td class="fw-bold text-dark small">Rs. <?= number_format($it['current_stock'] * $it['unit_price'], 2) ?></td>
                                <td class="text-muted small"><?= number_format($it['min_alert_qty']) ?> <?= htmlspecialchars($it['unit']) ?></td>
                                <td>
                                    <span class="badge <?= $it['status'] === 'active' ? 'bg-success bg-opacity-10 text-success border border-success' : 'bg-secondary bg-opacity-10 text-secondary border' ?> rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                        <?= ucfirst($it['status']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- Quick IN -->
                                        <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1 fw-bold" style="font-size: 0.75rem;" title="Stock IN" onclick="openQuickInModal(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)">
                                            <i class="fa-solid fa-plus me-1"></i> IN
                                        </button>
                                        <!-- Quick OUT -->
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-2 py-1 fw-bold text-dark" style="font-size: 0.75rem;" title="Stock OUT" onclick="openQuickOutModal(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)">
                                            <i class="fa-solid fa-minus me-1"></i> OUT
                                        </button>
                                        <!-- Edit -->
                                        <button type="button" class="btn-action-icon" title="Edit Item Details" onclick="openEditItemModalDirect(<?= htmlspecialchars(json_encode($it), ENT_QUOTES) ?>)">
                                            <i class="fa-solid fa-pen-to-square text-primary" style="font-size: 0.85rem;"></i>
                                        </button>
                                        <!-- Delete -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete item \'<?= addslashes($it['name']) ?>\'? This will also remove its transaction history.');">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                            <button type="submit" class="btn-action-icon text-danger" title="Delete Item">
                                                <i class="fa-solid fa-trash-can" style="font-size: 0.85rem;"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ================= TAB 2: DAILY, WEEKLY, MONTHLY USAGE ================= -->
            <div class="tab-pane fade" id="tab-usage" role="tabpanel">
                <div class="alert alert-primary bg-primary bg-opacity-10 border-0 rounded-4 d-flex align-items-center justify-content-between p-3 mb-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-chart-pie text-primary fs-4"></i>
                        <div>
                            <div class="fw-bold text-dark">Consumption &amp; Material Usage Analysis</div>
                            <div class="text-muted small">Compare daily issuance against weekly and monthly rates to prevent material leaks and forecast replenishment.</div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="tableUsage">
                        <thead class="table-light text-secondary small fw-bold">
                            <tr>
                                <th>#</th>
                                <th>Item &amp; Category</th>
                                <th>Current Balance</th>
                                <th class="text-primary"><i class="fa-solid fa-calendar-day me-1"></i> Today's Out (Daily)</th>
                                <th class="text-purple" style="color: #8b5cf6;"><i class="fa-solid fa-calendar-week me-1"></i> This Week (7 Days)</th>
                                <th class="text-warning"><i class="fa-solid fa-calendar-alt me-1"></i> This Month (30 Days)</th>
                                <th class="text-secondary">All-Time Used</th>
                                <th>Activity Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items_list as $idx => $it): 
                                $activityLabel = 'Idle';
                                $activityClass = 'bg-secondary bg-opacity-10 text-secondary';
                                if ($it['today_out'] > 0) {
                                    $activityLabel = 'High (Active Today)';
                                    $activityClass = 'bg-success bg-opacity-10 text-success border border-success';
                                } elseif ($it['week_out'] > 0) {
                                    $activityLabel = 'Moderate (This Week)';
                                    $activityClass = 'bg-info bg-opacity-10 text-info border border-info';
                                } elseif ($it['month_out'] > 0) {
                                    $activityLabel = 'Low (This Month)';
                                    $activityClass = 'bg-warning bg-opacity-10 text-warning border border-warning';
                                }
                            ?>
                            <tr>
                                <td class="text-muted small"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($it['name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($it['category']) ?> &bull; Unit: <?= htmlspecialchars($it['unit']) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1 rounded-pill fw-bold">
                                        <?= number_format($it['current_stock'], 1) ?> <?= htmlspecialchars($it['unit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold <?= $it['today_out'] > 0 ? 'text-primary fs-6' : 'text-muted' ?>">
                                        <?= number_format($it['today_out'], 1) ?> <?= htmlspecialchars($it['unit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold <?= $it['week_out'] > 0 ? 'text-dark' : 'text-muted' ?>">
                                        <?= number_format($it['week_out'], 1) ?> <?= htmlspecialchars($it['unit']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold <?= $it['month_out'] > 0 ? 'text-dark' : 'text-muted' ?>">
                                        <?= number_format($it['month_out'], 1) ?> <?= htmlspecialchars($it['unit']) ?>
                                    </span>
                                </td>
                                <td class="text-secondary small fw-semibold">
                                    <?= number_format($it['total_out'], 1) ?> <?= htmlspecialchars($it['unit']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $activityClass ?> rounded-pill px-2.5 py-1" style="font-size: 0.72rem;">
                                        <?= $activityLabel ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ================= TAB 3: STOCK LEDGER & AUDIT REPORTS ================= -->
            <div class="tab-pane fade" id="tab-ledger" role="tabpanel">

                <!-- Filter Controls -->
                <div class="p-3 bg-light rounded-4 mb-4 border">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-2 col-6">
                            <label class="form-label small fw-bold text-secondary mb-1">From Date</label>
                            <input type="date" name="from_date" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($filter_from) ?>">
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label small fw-bold text-secondary mb-1">To Date</label>
                            <input type="date" name="to_date" class="form-control form-control-sm rounded-3" value="<?= htmlspecialchars($filter_to) ?>">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small fw-bold text-secondary mb-1">Filter Item</label>
                            <select name="filter_item" class="form-select form-select-sm rounded-3">
                                <option value="">-- All Items --</option>
                                <?php foreach ($items_list as $it): ?>
                                    <option value="<?= $it['id'] ?>" <?= $filter_item == $it['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($it['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <label class="form-label small fw-bold text-secondary mb-1">Movement Type</label>
                            <select name="filter_type" class="form-select form-select-sm rounded-3">
                                <option value="">-- All Types --</option>
                                <option value="IN" <?= $filter_type === 'IN' ? 'selected' : '' ?>>Stock IN (+)</option>
                                <option value="OUT" <?= $filter_type === 'OUT' ? 'selected' : '' ?>>Stock OUT (-)</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold flex-grow-1">
                                <i class="fa-solid fa-filter me-1"></i> Filter Report
                            </button>
                            <a href="stock" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                <i class="fa-solid fa-rotate-left"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="tableLedger">
                        <thead class="table-light text-secondary small fw-bold">
                            <tr>
                                <th># ID</th>
                                <th>Date &amp; Time</th>
                                <th>Item Name</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Unit Rate</th>
                                <th>Total Cost</th>
                                <th>Balance Shift</th>
                                <th>Party / Issued To</th>
                                <th>Remarks / Ref</th>
                                <th>Logged By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions_list as $tx): ?>
                            <tr>
                                <td class="text-muted small font-monospace">#<?= $tx['id'] ?></td>
                                <td class="small" style="white-space: nowrap;">
                                    <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($tx['created_at'])) ?></div>
                                    <div class="text-muted small"><?= date('h:i A', strtotime($tx['created_at'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($tx['item_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($tx['item_category']) ?></div>
                                </td>
                                <td>
                                    <?php if ($tx['type'] === 'IN'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill fw-bold">
                                            <i class="fa-solid fa-arrow-down-left me-1"></i> + IN
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning border-opacity-50 px-2 py-1 rounded-pill fw-bold">
                                            <i class="fa-solid fa-arrow-up-right me-1"></i> - OUT
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold <?= $tx['type'] === 'IN' ? 'text-success' : 'text-danger' ?> fs-6">
                                        <?= ($tx['type'] === 'IN' ? '+' : '-') . number_format($tx['quantity'], 2) ?>
                                    </span>
                                    <span class="text-muted small"><?= htmlspecialchars($tx['item_unit']) ?></span>
                                </td>
                                <td class="small text-secondary">Rs. <?= number_format($tx['unit_price'], 2) ?></td>
                                <td class="small fw-bold text-dark">Rs. <?= number_format($tx['total_price'], 2) ?></td>
                                <td class="small font-monospace" style="white-space: nowrap;">
                                    <span class="text-muted"><?= number_format($tx['balance_before'], 1) ?></span>
                                    <i class="fa-solid fa-arrow-right text-secondary mx-1" style="font-size: 0.70rem;"></i>
                                    <span class="fw-bold text-dark"><?= number_format($tx['balance_after'], 1) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark small"><?= htmlspecialchars($tx['party_name'] ?: 'N/A') ?></div>
                                    <?php if (!empty($tx['issued_to_type']) && $tx['issued_to_type'] !== 'general'): ?>
                                        <span class="badge bg-light text-muted border rounded-pill" style="font-size: 0.65rem;">
                                            <?= ucfirst($tx['issued_to_type']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted" style="max-width: 200px;">
                                    <?php if (!empty($tx['reference_no'])): ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border me-1">Ref: <?= htmlspecialchars($tx['reference_no']) ?></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($tx['remarks'] ?: '') ?>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($tx['created_by']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>

</div>

<!-- ============================================================= -->
<!-- MODAL 1: ADD NEW ITEM -->
<!-- ============================================================= -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom p-3 px-4 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-plus"></i>
                    </div>
                    <h5 class="modal-title fw-bold text-dark">Add New Hardware / Inventory Item</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_item">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-secondary">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. MikroTik hEX S, Fiber Drop Cable 2-Core, Huawei ONU" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-secondary">Category</label>
                            <select name="category" class="form-select rounded-3">
                                <?php foreach ($categoriesList as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Unit of Measure <span class="text-danger">*</span></label>
                            <input type="text" name="unit" list="unitsList" class="form-control rounded-3" value="Nos." placeholder="Nos., Km, Mtr, Roll" required>
                            <datalist id="unitsList">
                                <?php foreach ($commonUnits as $u): ?>
                                    <option value="<?= htmlspecialchars($u) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Model / Part Code</label>
                            <input type="text" name="model_code" class="form-control rounded-3 font-monospace" placeholder="e.g. RB760iGS, HG8310M">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Status</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Unit Purchase Price (Rs.)</label>
                            <input type="number" step="0.01" name="unit_price" class="form-control rounded-3" placeholder="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Initial Stock (Opening Qty)</label>
                            <input type="number" step="0.01" name="initial_stock" class="form-control rounded-3" placeholder="0.00" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Low Stock Alert Limit</label>
                            <input type="number" step="0.01" name="min_alert_qty" class="form-control rounded-3" value="5">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary">Description / Remarks</label>
                            <textarea name="description" class="form-control rounded-3" rows="2" placeholder="Specifications, warranty, supplier info or storage location remarks..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 2: EDIT ITEM -->
<!-- ============================================================= -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom p-3 px-4 bg-light">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-pen"></i>
                    </div>
                    <h5 class="modal-title fw-bold text-dark">Edit Item Details</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-secondary">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-secondary">Category</label>
                            <select name="category" id="edit_category" class="form-select rounded-3">
                                <?php foreach ($categoriesList as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Unit of Measure <span class="text-danger">*</span></label>
                            <input type="text" name="unit" id="edit_unit" list="unitsList" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Model / Part Code</label>
                            <input type="text" name="model_code" id="edit_model_code" class="form-control rounded-3 font-monospace">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-secondary">Status</label>
                            <select name="status" id="edit_status" class="form-select rounded-3">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Unit Purchase Price (Rs.)</label>
                            <input type="number" step="0.01" name="unit_price" id="edit_unit_price" class="form-control rounded-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Low Stock Alert Limit</label>
                            <input type="number" step="0.01" name="min_alert_qty" id="edit_min_alert_qty" class="form-control rounded-3">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary">Description / Remarks</label>
                            <textarea name="description" id="edit_description" class="form-control rounded-3" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 3: STOCK IN (PURCHASE / RECEIVED) -->
<!-- ============================================================= -->
<div class="modal fade" id="stockInModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom p-3 px-4 bg-success bg-opacity-10">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-circle-plus"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0">Record Stock IN (+)</h5>
                        <small class="text-success fw-semibold">Add incoming purchase or received inventory</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="stock_in">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Select Item <span class="text-danger">*</span></label>
                        <select name="item_id" id="in_item_id" class="form-select rounded-3 fw-bold" required onchange="handleStockInItemChange(this)">
                            <option value="">-- Choose Item --</option>
                            <?php foreach ($items_list as $it): ?>
                                <option value="<?= $it['id'] ?>" data-price="<?= $it['unit_price'] ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>" data-stock="<?= $it['current_stock'] ?>">
                                    <?= htmlspecialchars($it['name']) ?> (Current: <?= number_format($it['current_stock'], 1) ?> <?= htmlspecialchars($it['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="inItemStockInfo" class="small text-muted mt-1" style="display: none;"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Quantity IN <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" id="in_quantity" class="form-control rounded-3 fw-bold font-monospace" placeholder="0.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-secondary">Unit Rate (Rs.)</label>
                            <input type="number" step="0.01" name="unit_price" id="in_unit_price" class="form-control rounded-3 font-monospace" placeholder="0.00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Supplier / Vendor / Received From</label>
                        <input type="text" name="supplier" class="form-control rounded-3" placeholder="e.g. Hafeez Center Vendor, Fiber Tech, Import">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Bill No. / Reference #</label>
                        <input type="text" name="reference_no" class="form-control rounded-3 font-monospace" placeholder="e.g. INV-98234, DC-54">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-secondary">Remarks / Notes</label>
                        <textarea name="remarks" class="form-control rounded-3" rows="2" placeholder="Lot details, condition, storage shelf..."></textarea>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">Confirm Stock IN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 4: STOCK OUT (ISSUED / USAGE / DAMAGE) -->
<!-- ============================================================= -->
<div class="modal fade" id="stockOutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom p-3 px-4 bg-warning bg-opacity-10">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-circle-minus"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0">Record Stock OUT (-)</h5>
                        <small class="text-warning-emphasis fw-semibold">Issue material to Lineman, Customer or Maintenance</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="stock_out">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Select Item <span class="text-danger">*</span></label>
                        <select name="item_id" id="out_item_id" class="form-select rounded-3 fw-bold" required onchange="handleStockOutItemChange(this)">
                            <option value="">-- Choose Item --</option>
                            <?php foreach ($items_list as $it): ?>
                                <option value="<?= $it['id'] ?>" data-stock="<?= $it['current_stock'] ?>" data-unit="<?= htmlspecialchars($it['unit']) ?>" <?= $it['current_stock'] <= 0 ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($it['name']) ?> (Available: <?= number_format($it['current_stock'], 1) ?> <?= htmlspecialchars($it['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="outStockAlert" class="small fw-semibold mt-1"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Quantity to Issue <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="quantity" id="out_quantity" class="form-control rounded-3 fw-bold font-monospace" placeholder="0.00" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Issued To Category <span class="text-danger">*</span></label>
                        <select name="issued_to_type" id="issued_to_type" class="form-select rounded-3" required onchange="toggleIssuedToFields(this.value)">
                            <option value="lineman">Lineman / Field Staff</option>
                            <option value="dealer">Dealer / Reseller</option>
                            <option value="subscriber">Subscriber / Customer Direct</option>
                            <option value="maintenance">Network Maintenance / Joint Repair</option>
                            <option value="damage">Damaged / Faulty / Scrapped</option>
                            <option value="general">Other / Office Use</option>
                        </select>
                    </div>

                    <!-- Lineman Select -->
                    <div class="mb-3" id="linemanSelectBox">
                        <label class="form-label small fw-bold text-secondary">Select Lineman</label>
                        <select name="lineman_id" class="form-select rounded-3">
                            <option value="">-- Select Lineman --</option>
                            <?php foreach ($all_linemen as $lm): ?>
                                <option value="<?= $lm['id'] ?>"><?= htmlspecialchars($lm['full_name']) ?> (<?= htmlspecialchars($lm['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Dealer Select -->
                    <div class="mb-3" id="dealerSelectBox" style="display: none;">
                        <label class="form-label small fw-bold text-secondary">Select Dealer</label>
                        <select name="dealer_id" class="form-select rounded-3">
                            <option value="">-- Select Dealer --</option>
                            <?php foreach ($all_dealers as $dl): ?>
                                <option value="<?= $dl['id'] ?>"><?= htmlspecialchars($dl['full_name']) ?> (@<?= htmlspecialchars($dl['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- General / Subscriber Text Input -->
                    <div class="mb-3" id="partyNameBox" style="display: none;">
                        <label class="form-label small fw-bold text-secondary" id="partyNameLabel">Party / Recipient Name</label>
                        <input type="text" name="party_name" id="party_name_input" class="form-control rounded-3" placeholder="Enter recipient name / customer username">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Work Order / Complain # / Reference</label>
                        <input type="text" name="reference_no" class="form-control rounded-3 font-monospace" placeholder="e.g. TICKET-432, WO-102">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-secondary">Remarks / Installation Address</label>
                        <textarea name="remarks" class="form-control rounded-3" rows="2" placeholder="e.g. Installed at user house, joint spliced at main street..."></textarea>
                    </div>
                </div>
                <div class="modal-footer p-3 px-4 bg-light">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold text-dark">Confirm Stock OUT</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Scripts -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // 1. Inventory Table
    $('#tableInventory').DataTable({
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        order: [[3, 'asc']], // Order by current stock
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search stock items, category, model..."
        }
    });

    // 2. Usage Table
    $('#tableUsage').DataTable({
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        order: [[3, 'desc']], // Order by today's out descending
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search material usage..."
        }
    });

    // 3. Ledger Table with Export Buttons
    $('#tableLedger').DataTable({
        pageLength: 25,
        lengthMenu: [25, 50, 100, 250],
        order: [[0, 'desc']], // Most recent transaction first
        dom: "<'d-flex flex-wrap justify-content-between align-items-center mb-3'<'d-flex align-items-center gap-2'B><'d-flex align-items-center'f>>" +
             "<'table-responsive'tr>" +
             "<'d-flex flex-wrap justify-content-between align-items-center mt-3'<'small text-muted'i><'d-flex'p>>",
        buttons: [
            {
                extend: 'copy',
                className: 'btn btn-sm btn-outline-secondary rounded-pill px-3',
                text: '<i class="fa-regular fa-copy me-1"></i> Copy'
            },
            {
                extend: 'csv',
                className: 'btn btn-sm btn-outline-primary rounded-pill px-3',
                text: '<i class="fa-solid fa-file-csv me-1"></i> CSV',
                filename: 'Stock_Ledger_' + new Date().toISOString().slice(0, 10)
            },
            {
                extend: 'excel',
                className: 'btn btn-sm btn-outline-success rounded-pill px-3',
                text: '<i class="fa-solid fa-file-excel me-1"></i> Excel',
                filename: 'Stock_Ledger_' + new Date().toISOString().slice(0, 10)
            },
            {
                extend: 'pdf',
                className: 'btn btn-sm btn-outline-danger rounded-pill px-3',
                text: '<i class="fa-solid fa-file-pdf me-1"></i> PDF',
                filename: 'Stock_Ledger_' + new Date().toISOString().slice(0, 10)
            },
            {
                extend: 'print',
                className: 'btn btn-sm btn-outline-dark rounded-pill px-3',
                text: '<i class="fa-solid fa-print me-1"></i> Print'
            }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search transactions..."
        }
    });
});

// Edit Item Modal Trigger
function openEditItemModal(itemId) {
    fetch('stock?ajax=get_item&id=' + itemId)
        .then(res => res.json())
        .then(data => {
            if (data.success && data.item) {
                var it = data.item;
                document.getElementById('edit_item_id').value = it.id;
                document.getElementById('edit_name').value = it.name;
                document.getElementById('edit_category').value = it.category || 'General';
                document.getElementById('edit_unit').value = it.unit;
                document.getElementById('edit_model_code').value = it.model_code || '';
                document.getElementById('edit_status').value = it.status;
                document.getElementById('edit_unit_price').value = it.unit_price;
                document.getElementById('edit_min_alert_qty').value = it.min_alert_qty;
                document.getElementById('edit_description').value = it.description || '';

                var modal = new bootstrap.Modal(document.getElementById('editItemModal'));
                modal.show();
            } else {
                alert('Could not fetch item details.');
            }
        })
        .catch(err => {
            alert('Error loading item details.');
        });
}

// Quick Stock IN Modal Trigger
function openQuickInModal(item) {
    var sel = document.getElementById('in_item_id');
    sel.value = item.id;
    handleStockInItemChange(sel);
    var modal = new bootstrap.Modal(document.getElementById('stockInModal'));
    modal.show();
}

// Quick Stock OUT Modal Trigger
function openQuickOutModal(item) {
    var sel = document.getElementById('out_item_id');
    sel.value = item.id;
    handleStockOutItemChange(sel);
    var modal = new bootstrap.Modal(document.getElementById('stockOutModal'));
    modal.show();
}

// Stock IN item change
function handleStockInItemChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var price = opt ? opt.getAttribute('data-price') : '';
    var unit = opt ? opt.getAttribute('data-unit') : '';
    var stock = opt ? opt.getAttribute('data-stock') : '';
    var priceInput = document.getElementById('in_unit_price');
    var info = document.getElementById('inItemStockInfo');

    if (opt && opt.value) {
        if (priceInput && (!priceInput.value || priceInput.value == '0.00')) {
            priceInput.value = price;
        }
        info.style.display = 'block';
        info.innerHTML = '<i class="fa-solid fa-circle-info text-primary me-1"></i> Current balance: <b>' + stock + ' ' + unit + '</b>. Est. rate: <b>Rs. ' + price + '</b>.';
    } else {
        info.style.display = 'none';
    }
}

// Stock OUT item change
function handleStockOutItemChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var stock = opt ? parseFloat(opt.getAttribute('data-stock') || '0') : 0;
    var unit = opt ? opt.getAttribute('data-unit') : '';
    var qtyInput = document.getElementById('out_quantity');
    var alertEl = document.getElementById('outStockAlert');

    if (opt && opt.value) {
        qtyInput.max = stock;
        alertEl.style.display = 'block';
        if (stock <= 0) {
            alertEl.className = 'small fw-bold text-danger mt-1';
            alertEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Out of stock (0 ' + unit + ' available). Cannot issue!';
            qtyInput.disabled = true;
        } else {
            alertEl.className = 'small fw-semibold text-success mt-1';
            alertEl.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Available in stock: <b>' + stock + ' ' + unit + '</b>';
            qtyInput.disabled = false;
        }
    } else {
        alertEl.style.display = 'none';
        qtyInput.disabled = false;
    }
}

// Toggle Issued To Fields based on Type
function toggleIssuedToFields(type) {
    var lmBox = document.getElementById('linemanSelectBox');
    var dlBox = document.getElementById('dealerSelectBox');
    var ptBox = document.getElementById('partyNameBox');
    var ptLbl = document.getElementById('partyNameLabel');

    lmBox.style.display = (type === 'lineman') ? 'block' : 'none';
    dlBox.style.display = (type === 'dealer') ? 'block' : 'none';

    if (type === 'subscriber') {
        ptBox.style.display = 'block';
        ptLbl.innerText = 'Subscriber Username / Full Name';
    } else if (type === 'maintenance') {
        ptBox.style.display = 'block';
        ptLbl.innerText = 'Maintenance Area / Tower / Street';
    } else if (type === 'damage') {
        ptBox.style.display = 'block';
        ptLbl.innerText = 'Damage Reason / Discard Details';
    } else if (type === 'general') {
        ptBox.style.display = 'block';
        ptLbl.innerText = 'Recipient / Purpose Details';
    } else {
        ptBox.style.display = 'none';
    }
}
</script>

<?php require_once 'footer.php'; ?>
