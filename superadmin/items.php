<?php
require_once 'auth_check.php';
require_once '../config/db.php';

// -------------------------------------------------------------
// Self-Healing Table Schema for Items
// -------------------------------------------------------------
try {
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

    // Pre-seed initial standard ISP items if table is brand new
    $chkCount = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
    if ($chkCount == 0) {
        $sampleItems = [
            ['Mikrotik CCR2116', 'Nos.', 'Routers & Networking', 'CCR2116-12G-4S+', '16 Core High-performance Router with 10G SFP+'],
            ['Fibre 8 Core', 'Km', 'Fiber & Optical Cable', '8-Core Single Mode', 'Outdoor armored fiber optic cable'],
            ['ONU (EPON/GPON)', 'Nos.', 'ONUs & Modems', 'XPON-V2801', 'Gigabit Dual-mode Optical Network Unit'],
            ['Drop Wire 2 Core', 'Mtr', 'Fiber & Optical Cable', 'FTTH-2C', 'FTTH Self-supporting bow-type drop cable'],
            ['Patch Cord 3m SC/UPC', 'Nos.', 'Patch Cords & Adapters', 'SC-SC-3M', 'Single Mode Simplex Fiber Patch Cord'],
            ['Joint Enclosure 24F', 'Nos.', 'Joint Closures & Enclosures', 'GJS-24F', 'Waterproof dome fiber splice closure'],
            ['Splitter 1x8 PLC', 'Nos.', 'Splitters & Couplers', 'PLC-1x8-SC', 'Planar Lightwave Circuit Optical Splitter with SC/UPC connectors']
        ];
        $seedStmt = $pdo->prepare("INSERT INTO items (name, unit, category, model_code, description) VALUES (?, ?, ?, ?, ?)");
        foreach ($sampleItems as $s) {
            $seedStmt->execute($s);
        }
    }
} catch (Exception $e) {
    // Fallback gracefully
}

// -------------------------------------------------------------
// AJAX Endpoint: Fetch Single Item Details for Edit
// (Runs BEFORE header.php so it outputs 100% clean JSON)
// -------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_item' && !empty($_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $itemId = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        echo json_encode(['success' => true, 'item' => $item]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
    }
    exit;
}

// -------------------------------------------------------------
// Helper for Bulletproof Redirection (Prevents White Screens)
// -------------------------------------------------------------
function redirect_items_page() {
    $target = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'items';
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
// (Runs BEFORE header.php so redirects succeed without headers-already-sent)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD ITEM
    if ($action === 'add_item') {
        $name        = trim($_POST['name'] ?? '');
        $unit        = trim($_POST['unit'] ?? 'Nos.');
        $category    = trim($_POST['category'] ?? 'General');
        $model_code  = trim($_POST['model_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status      = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name) || empty($unit)) {
            $_SESSION['item_flash'] = ['type' => 'danger', 'msg' => 'Item Name and Unit of Measurement are required.'];
        } else {
            try {
                $ins = $pdo->prepare("INSERT INTO items (name, unit, category, model_code, description, status) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$name, $unit, $category, $model_code, $description, $status]);
                $_SESSION['item_flash'] = ['type' => 'success', 'msg' => "Item '{$name}' added successfully with unit '{$unit}'!"];
            } catch (Exception $e) {
                $_SESSION['item_flash'] = ['type' => 'danger', 'msg' => 'Error adding item: ' . $e->getMessage()];
            }
        }
        redirect_items_page();
    }

    // 2. EDIT ITEM
    if ($action === 'edit_item') {
        $item_id     = (int)($_POST['item_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $unit        = trim($_POST['unit'] ?? 'Nos.');
        $category    = trim($_POST['category'] ?? 'General');
        $model_code  = trim($_POST['model_code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status      = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($item_id) || empty($name) || empty($unit)) {
            $_SESSION['item_flash'] = ['type' => 'danger', 'msg' => 'Item ID, Name and Unit are required.'];
        } else {
            try {
                $upd = $pdo->prepare("UPDATE items SET name = ?, unit = ?, category = ?, model_code = ?, description = ?, status = ? WHERE id = ?");
                $upd->execute([$name, $unit, $category, $model_code, $description, $status, $item_id]);
                $_SESSION['item_flash'] = ['type' => 'success', 'msg' => "Item '{$name}' updated successfully!"];
            } catch (Exception $e) {
                $_SESSION['item_flash'] = ['type' => 'danger', 'msg' => 'Error updating item: ' . $e->getMessage()];
            }
        }
        redirect_items_page();
    }

    // 3. DELETE ITEM
    if ($action === 'delete_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        try {
            $chk = $pdo->prepare("SELECT name FROM items WHERE id = ?");
            $chk->execute([$item_id]);
            $delName = $chk->fetchColumn();

            $del = $pdo->prepare("DELETE FROM items WHERE id = ?");
            $del->execute([$item_id]);
            $_SESSION['item_flash'] = ['type' => 'success', 'msg' => "Item '{$delName}' deleted successfully."];
        } catch (Exception $e) {
            $_SESSION['item_flash'] = ['type' => 'danger', 'msg' => 'Error deleting item: ' . $e->getMessage()];
        }
        redirect_items_page();
    }
}

// Flash notification
$flash = null;
if (isset($_SESSION['item_flash'])) {
    $flash = $_SESSION['item_flash'];
    unset($_SESSION['item_flash']);
}

// Now include header for HTML page rendering
require_once 'header.php';

// -------------------------------------------------------------
// Fetch All Items & Calculate Stats
// -------------------------------------------------------------
$itemsStmt = $pdo->query("SELECT * FROM items ORDER BY id DESC");
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalItems   = count($items);
$countNos     = 0;
$countKmOrMtr = 0;
$countActive  = 0;

foreach ($items as $itm) {
    if ($itm['status'] === 'active') $countActive++;
    $u = strtolower(trim($itm['unit']));
    if (in_array($u, ['nos.', 'nos', 'pcs.', 'pcs', 'piece', 'unit'])) {
        $countNos++;
    } elseif (in_array($u, ['km', 'kilometer', 'mtr', 'meter', 'm', 'roll', 'feet', 'ft'])) {
        $countKmOrMtr++;
    }
}

// Preset standard categories
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

// Common units of measurement for quick chips
$commonUnits = [
    'Nos.',
    'Km',
    'Mtr',
    'Roll',
    'Box',
    'Pkt',
    'Set',
    'Feet'
];
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body {
        background-color: #f8fafc !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        color: #0f172a !important;
    }

    .card-ui {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        border: 1px solid #eef2f6;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 20px 22px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
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
        font-size: 1.55rem;
        font-weight: 800;
        line-height: 1.15;
        color: #0f172a;
    }
    .stat-pill-lbl {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Unit Quick Chips */
    .unit-chip {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 50px;
        font-size: 0.75rem;
        font-weight: 600;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        transition: all 0.15s ease;
        margin-right: 4px;
        margin-bottom: 4px;
        user-select: none;
    }
    .unit-chip:hover {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }

    /* Unit Badges */
    .badge-unit-nos { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700; }
    .badge-unit-km { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-weight: 700; }
    .badge-unit-mtr { background: #faf5ff; color: #6b21a8; border: 1px solid #e9d5ff; font-weight: 700; }
    .badge-unit-roll { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; font-weight: 700; }
    .badge-unit-other { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; font-weight: 700; }

    /* Premium DataTables Styling */
    #itemsTable {
        border-collapse: separate !important;
        border-spacing: 0 8px !important;
    }
    #itemsTable thead th {
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
    #itemsTable thead th:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    #itemsTable thead th:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    #itemsTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02) !important;
        border-radius: 14px !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease !important;
    }
    #itemsTable tbody tr:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06) !important;
    }
    #itemsTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 13px 14px !important;
        background: transparent !important;
        font-size: 0.88rem !important;
    }
    #itemsTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 14px !important;
        border-bottom-left-radius: 14px !important;
    }
    #itemsTable tbody td:last-child {
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

<div class="container-fluid px-2 px-md-4 py-2">

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
                <div class="rounded-3 text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; background: linear-gradient(135deg, #0284c7, #0369a1);">
                    <i class="fa-solid fa-boxes-stacked fs-4"></i>
                </div>
                <div>
                    <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.5px;">Items &amp; Units</h3>
                    <p class="text-muted small mb-0">Hardware catalog, networking devices, fiber cables &amp; units of measurement.</p>
                </div>
            </div>
        </div>
        <div>
            <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addItemModal" style="background: linear-gradient(135deg, #0284c7, #2563eb); border: none;">
                <i class="fa-solid fa-plus"></i>
                <span>Add Item</span>
            </button>
        </div>
    </div>

    <!-- 4 Summary KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Items -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #e0f2fe; color: #0284c7;">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Total Items</div>
                    <div class="stat-pill-val text-primary text-truncate"><?= $totalItems ?></div>
                    <div class="text-muted small" style="font-size: 0.72rem;">Catalogued Items</div>
                </div>
            </div>
        </div>

        <!-- 2. Devices & Quantities (Nos.) -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-microchip"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Counted in Nos./Pcs</div>
                    <div class="stat-pill-val text-dark text-truncate"><?= $countNos ?></div>
                    <div class="text-muted small" style="font-size: 0.72rem;">Mikrotik, ONUs, Devices</div>
                </div>
            </div>
        </div>

        <!-- 3. Cables & Lengths (Km / Mtr) -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #ecfdf5; color: #059669;">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Measured in Km / Mtr</div>
                    <div class="stat-pill-val text-success text-truncate"><?= $countKmOrMtr ?></div>
                    <div class="text-muted small" style="font-size: 0.72rem;">Fibre cables, Drop wires</div>
                </div>
            </div>
        </div>

        <!-- 4. Active Items -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Active Items</div>
                    <div class="stat-pill-val text-purple text-truncate"><?= $countActive ?></div>
                    <div class="text-muted small" style="font-size: 0.72rem;">Available in inventory</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Items Table Card -->
    <div class="card-ui p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold text-dark mb-0">Master Item Catalog</h5>
                <span class="text-muted small">All defined inventory items with names, units of measurement and categories</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="itemsTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Item Name</th>
                        <th>Unit of Measurement</th>
                        <th>Category</th>
                        <th>Model / Code</th>
                        <th>Description / Specs</th>
                        <th>Status</th>
                        <th class="text-end" style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $itm): 
                        $u = strtolower(trim($itm['unit']));
                        $badgeClass = 'badge-unit-other';
                        if (in_array($u, ['nos.', 'nos', 'pcs.', 'pcs'])) $badgeClass = 'badge-unit-nos';
                        elseif (in_array($u, ['km', 'kilometer'])) $badgeClass = 'badge-unit-km';
                        elseif (in_array($u, ['mtr', 'meter', 'm'])) $badgeClass = 'badge-unit-mtr';
                        elseif (in_array($u, ['roll', 'box'])) $badgeClass = 'badge-unit-roll';
                    ?>
                        <tr>
                            <td class="text-muted fw-semibold">
                                ITM-<?= str_pad($itm['id'], 4, '0', STR_PAD_LEFT) ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($itm['name']) ?></div>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-3 py-1 <?= $badgeClass ?>">
                                    <?= htmlspecialchars($itm['unit']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border rounded-pill px-2 py-1 small">
                                    <i class="fa-solid fa-tag me-1 text-muted"></i><?= htmlspecialchars($itm['category'] ?: 'General') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($itm['model_code'])): ?>
                                    <span class="font-monospace small fw-semibold text-dark"><?= htmlspecialchars($itm['model_code']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($itm['description'])): ?>
                                    <div class="text-muted small text-truncate" style="max-width: 280px;" title="<?= htmlspecialchars($itm['description']) ?>">
                                        <?= htmlspecialchars($itm['description']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($itm['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <!-- Edit -->
                                    <button type="button" 
                                        class="btn btn-sm btn-light border rounded-circle text-primary" 
                                        style="width: 32px; height: 32px;" 
                                        data-id="<?= (int)$itm['id'] ?>"
                                        data-name="<?= htmlspecialchars($itm['name'], ENT_QUOTES) ?>"
                                        data-unit="<?= htmlspecialchars($itm['unit'], ENT_QUOTES) ?>"
                                        data-category="<?= htmlspecialchars($itm['category'] ?? 'General', ENT_QUOTES) ?>"
                                        data-model="<?= htmlspecialchars($itm['model_code'] ?? '', ENT_QUOTES) ?>"
                                        data-description="<?= htmlspecialchars($itm['description'] ?? '', ENT_QUOTES) ?>"
                                        data-status="<?= htmlspecialchars($itm['status'] ?? 'active', ENT_QUOTES) ?>"
                                        onclick="openEditItemModalDirect(this)" 
                                        title="Edit Item">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <!-- Delete -->
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle text-danger" style="width: 32px; height: 32px;" onclick="confirmDeleteItem(<?= (int)$itm['id'] ?>, '<?= htmlspecialchars(addslashes($itm['name'])) ?>')" title="Delete Item">
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

</div>

<!-- ============================================================== -->
<!-- DIALOG BOX: ADD ITEM MODAL                                     -->
<!-- ============================================================== -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #0284c7, #0369a1);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-boxes-stacked fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="addItemModalLabel">Add New Item</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_item">
                
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Item Name -->
                        <div class="col-md-7">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Item Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" id="add_item_name" class="form-control rounded-3" placeholder="e.g. Mikrotik CCR2116 / Fibre 8 Core / ONU" required autofocus>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">
                                Hardware item, device ya cable ka poora naam.
                            </div>
                        </div>

                        <!-- Unit of Measurement -->
                        <div class="col-md-5">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Unit of Measurement <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="unit" id="add_item_unit" class="form-control rounded-3" placeholder="e.g. Nos. / Km / Mtr / Roll" value="Nos." required list="unitOptionsList">
                            <datalist id="unitOptionsList">
                                <?php foreach ($commonUnits as $u): ?>
                                    <option value="<?= htmlspecialchars($u) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>

                            <!-- Quick Chip Pill Selectors -->
                            <div class="mt-2">
                                <span class="text-muted small" style="font-size: 0.7rem; font-weight: 600;">Quick select unit:</span>
                                <div class="d-flex flex-wrap mt-1">
                                    <?php foreach ($commonUnits as $u): ?>
                                        <span class="unit-chip" onclick="document.getElementById('add_item_unit').value='<?= $u ?>'"><?= $u ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Category
                            </label>
                            <select name="category" class="form-select rounded-3">
                                <?php foreach ($categoriesList as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Model / Code -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Model Code / Part # (Optional)
                            </label>
                            <input type="text" name="model_code" class="form-control rounded-3" placeholder="e.g. CCR2116-12G-4S+ / XPON-V2801">
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Description &amp; Technical Specifications (Optional)
                            </label>
                            <textarea name="description" rows="2" class="form-control rounded-3" placeholder="Hardware details, ports, core count, warranty or any extra notes..."></textarea>
                        </div>

                        <!-- Status -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Status
                            </label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1" style="background: linear-gradient(135deg, #0284c7, #2563eb); border: none;">
                        <i class="fa-solid fa-check me-1"></i> Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DIALOG BOX: EDIT ITEM MODAL                                     -->
<!-- ============================================================== -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="editItemModalLabel">Edit Item</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                
                <div class="modal-body p-4">
                    <div id="edit_loading_spinner" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <div class="text-muted small mt-2">Loading item details...</div>
                    </div>

                    <div id="edit_item_fields" class="row g-3 d-none">
                        <!-- Item Name -->
                        <div class="col-md-7">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Item Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" id="edit_item_name" class="form-control rounded-3" required>
                        </div>

                        <!-- Unit of Measurement -->
                        <div class="col-md-5">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Unit of Measurement <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="unit" id="edit_item_unit" class="form-control rounded-3" required list="unitOptionsListEdit">
                            <datalist id="unitOptionsListEdit">
                                <?php foreach ($commonUnits as $u): ?>
                                    <option value="<?= htmlspecialchars($u) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>

                            <!-- Quick Chip Pill Selectors -->
                            <div class="mt-2">
                                <span class="text-muted small" style="font-size: 0.7rem; font-weight: 600;">Quick select unit:</span>
                                <div class="d-flex flex-wrap mt-1">
                                    <?php foreach ($commonUnits as $u): ?>
                                        <span class="unit-chip" onclick="document.getElementById('edit_item_unit').value='<?= $u ?>'"><?= $u ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Category
                            </label>
                            <select name="category" id="edit_item_category" class="form-select rounded-3">
                                <?php foreach ($categoriesList as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Model / Code -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Model Code / Part # (Optional)
                            </label>
                            <input type="text" name="model_code" id="edit_item_model_code" class="form-control rounded-3">
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Description &amp; Technical Specifications
                            </label>
                            <textarea name="description" id="edit_item_description" rows="2" class="form-control rounded-3"></textarea>
                        </div>

                        <!-- Status -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Status
                            </label>
                            <select name="status" id="edit_item_status" class="form-select rounded-3">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top border-light-subtle py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Update Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- DIALOG BOX: DELETE ITEM MODAL                                   -->
<!-- ============================================================== -->
<div class="modal fade" id="deleteItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-body p-4 text-center">
                <div class="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center shadow-sm mb-3" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-trash-can fs-3"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">Delete Item?</h5>
                <p class="text-muted small mb-3">
                    Are you sure you want to delete <strong id="delete_item_name_display" class="text-dark"></strong> from the item catalog?
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="item_id" id="delete_item_id_input">
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

<script>
if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }

var itemsDT;

$(document).ready(function() {
    itemsDT = $('#itemsTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6] }, text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6] }, text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'excel', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6] }, text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6] }, text: '<i class="fa-solid fa-file-csv"></i> CSV' },
            { extend: 'pdf', exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6] }, text: '<i class="fa-solid fa-file-pdf"></i> PDF' }
        ],
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            searchPlaceholder: "Search item, unit, category...",
            emptyTable: '<div class="text-center py-4"><i class="fa-solid fa-boxes-stacked fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No items defined yet.</div><div class="text-muted small">Click "+ Add Item" to define hardware items and their units.</div></div>',
            zeroRecords: '<div class="text-center py-4"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching items found.</div></div>'
        }
    });
});

// Open Edit Item Dialog Box (Instant client-side from row data attributes)
function openEditItemModalDirect(btn) {
    const id = btn.getAttribute('data-id');
    const name = btn.getAttribute('data-name');
    const unit = btn.getAttribute('data-unit');
    const category = btn.getAttribute('data-category');
    const model = btn.getAttribute('data-model');
    const description = btn.getAttribute('data-description');
    const status = btn.getAttribute('data-status');

    document.getElementById('edit_item_id').value = id;
    document.getElementById('edit_item_name').value = name;
    document.getElementById('edit_item_unit').value = unit;
    document.getElementById('edit_item_model_code').value = model || '';
    document.getElementById('edit_item_description').value = description || '';

    const catSelect = document.getElementById('edit_item_category');
    let foundCat = false;
    for (let i = 0; i < catSelect.options.length; i++) {
        if (catSelect.options[i].value === category) {
            catSelect.selectedIndex = i;
            foundCat = true;
            break;
        }
    }
    if (!foundCat && category) {
        const newOpt = new Option(category, category, true, true);
        catSelect.add(newOpt);
    }

    const statusSelect = document.getElementById('edit_item_status');
    if (statusSelect) statusSelect.value = status || 'active';

    document.getElementById('edit_loading_spinner').classList.add('d-none');
    document.getElementById('edit_item_fields').classList.remove('d-none');

    const modalEl = document.getElementById('editItemModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

// Open Edit Item Dialog Box (Fallback via pure AJAX)
function openEditItemModal(itemId) {
    const modalEl = document.getElementById('editItemModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    document.getElementById('edit_loading_spinner').classList.remove('d-none');
    document.getElementById('edit_item_fields').classList.add('d-none');

    fetch('items?ajax=get_item&id=' + itemId)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.item) {
                const item = res.item;
                document.getElementById('edit_item_id').value = item.id;
                document.getElementById('edit_item_name').value = item.name;
                document.getElementById('edit_item_unit').value = item.unit;
                document.getElementById('edit_item_model_code').value = item.model_code || '';
                document.getElementById('edit_item_description').value = item.description || '';
                
                // Select category
                const catSelect = document.getElementById('edit_item_category');
                let foundCat = false;
                for (let i = 0; i < catSelect.options.length; i++) {
                    if (catSelect.options[i].value === item.category) {
                        catSelect.selectedIndex = i;
                        foundCat = true;
                        break;
                    }
                }
                if (!foundCat && item.category) {
                    const newOpt = new Option(item.category, item.category, true, true);
                    catSelect.add(newOpt);
                }

                // Select status
                const statusSelect = document.getElementById('edit_item_status');
                if (statusSelect) statusSelect.value = item.status || 'active';

                document.getElementById('edit_loading_spinner').classList.add('d-none');
                document.getElementById('edit_item_fields').classList.remove('d-none');
            } else {
                alert(res.message || 'Error loading item.');
                modal.hide();
            }
        })
        .catch(err => {
            alert('Request failed: ' + err.message);
            modal.hide();
        });
}

// Delete Confirmation Dialog Box
function confirmDeleteItem(itemId, itemName) {
    document.getElementById('delete_item_id_input').value = itemId;
    document.getElementById('delete_item_name_display').innerText = itemName || ('ITM-' + itemId);
    const modal = new bootstrap.Modal(document.getElementById('deleteItemModal'));
    modal.show();
}
</script>

<?php require_once 'footer.php'; ?>
