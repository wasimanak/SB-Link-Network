<?php
require_once 'header.php';

$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delId = (int)$_POST['delete_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM nas WHERE id = :id");
        $stmt->execute(['id' => $delId]);
        
        $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Router (MikroTik) successfully deleted from FreeRADIUS and the database. Remember to restart FreeRADIUS to flush cache.</div></div>";
    } catch (Exception $e) {
        $flashMsg = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-exclamation fs-5'></i> <div>Error deleting router: " . htmlspecialchars($e->getMessage()) . "</div></div>";
    }
}

$msg = $_GET['msg'] ?? '';
if ($msg === 'deleted' && empty($flashMsg)) {
    $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Router (MikroTik) deleted successfully from the database.</div></div>";
}

try {
    $stmt = $pdo->query("SELECT nas.*, clients.company_name FROM nas JOIN clients ON nas.client_id = clients.id ORDER BY nas.id DESC");
    $routers = $stmt->fetchAll();
} catch (PDOException $e) {
    die("<div class='alert alert-danger m-4'>Error loading routers.</div>");
}

// Stats
$totalRouters = count($routers);
$distinctOperators = [];
foreach ($routers as $r) {
    $distinctOperators[$r['client_id']] = true;
}
$operatorCount = count($distinctOperators);
?>

<!-- DataTables CSS -->
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

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
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
        font-size: 1.45rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
    }
    .stat-pill-lbl {
        font-size: 0.74rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Modern Table */
    .modern-table {
        margin-bottom: 0;
        width: 100% !important;
    }
    .modern-table thead th {
        background: #f8fafc !important;
        color: #475569 !important;
        font-size: 0.74rem !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        font-weight: 700 !important;
        padding: 14px 20px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        white-space: nowrap !important;
    }
    .modern-table tbody td {
        padding: 16px 20px !important;
        color: #1e293b !important;
        font-size: 0.88rem !important;
        border-bottom: 1px solid #f1f5f9 !important;
        vertical-align: middle !important;
    }
    .modern-table tbody tr:hover {
        background-color: #f8fafc !important;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none !important;
    }

    /* Action Buttons in Table */
    .btn-action-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.88rem;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        transition: all 0.15s ease;
    }
    .btn-action-icon:hover {
        transform: translateY(-2px);
    }
    .btn-action-delete:hover {
        background: #fef2f2;
        border-color: #ef4444;
        color: #dc2626;
    }

    /* Secret Pill */
    .secret-reveal-btn {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .secret-reveal-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    /* Copy Button */
    .btn-copy-chip {
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-copy-chip:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
</style>

<div class="container-fluid px-4 py-4">

    <?php if(!empty($flashMsg)) echo $flashMsg; ?>

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-server"></i> Network Access Servers
                </div>
                <h3 class="fw-bold text-dark mb-1">Global MikroTik Routers (NAS)</h3>
                <p class="text-muted small mb-0">Centralized database of all MikroTik routers authenticated by FreeRADIUS across all tenant ISPs.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="live_routers.php" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-network-wired me-1"></i> Live Routers
                </a>
                <a href="router_add.php" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-plus me-1"></i> Add New Router
                </a>
            </div>
        </div>
    </div>

    <!-- 3 Stat Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Routers -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Configured NAS</div>
                    <div class="stat-pill-val"><?= number_format($totalRouters) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">MikroTik RADIUS clients</div>
                </div>
            </div>
        </div>

        <!-- 2. Associated Operators -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">ISP Tenants</div>
                    <div class="stat-pill-val"><?= number_format($operatorCount) ?></div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;"><i class="fa-solid fa-circle-check"></i> Operating Network Gateways</div>
                </div>
            </div>
        </div>

        <!-- 3. FreeRADIUS Status -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">RADIUS Protocol</div>
                    <div class="stat-pill-val">Active Client Sync</div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Ports 1812/auth, 1813/acct</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Routers Card & Table -->
    <div class="card-ui overflow-hidden mb-5">
        
        <!-- Loading Dialog State -->
        <div id="routersLoadingBox" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" style="width: 2.75rem; height: 2.75rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h6 class="fw-bold text-dark mb-1">Loading Routers Directory...</h6>
            <div class="text-muted small">Synchronizing MikroTik NAS clients and IP configurations</div>
        </div>

        <!-- Hidden Table Container until DataTables initComplete -->
        <div id="routersTableContainer" style="display: none;">
            <div class="table-responsive">
                <table class="modern-table" id="routersTable">
                    <thead>
                        <tr>
                            <th class="ps-4">Router Name</th>
                            <th>Admin / Tenant</th>
                            <th>NAS IP Address</th>
                            <th>RADIUS Secret</th>
                            <th>API Port</th>
                            <th>Status</th>
                            <th class="pe-4 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routers as $r): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center border" style="width: 38px; height: 38px; font-size: 0.95rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-network-wired"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($r['shortname']) ?></div>
                                        <div class="text-muted font-monospace" style="font-size: 0.72rem;">NAS ID: #<?= $r['id'] ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark border rounded-pill px-3 py-1 fw-semibold">
                                    <i class="fa-solid fa-building text-primary me-1"></i> <?= htmlspecialchars($r['company_name']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="d-inline-flex align-items-center gap-2 bg-light px-2 py-1 rounded-2 border">
                                    <span class="font-monospace text-primary fw-bold" style="font-size: 0.85rem;"><?= htmlspecialchars($r['nasname']) ?></span>
                                    <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($r['nasname']) ?>', this)">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>
                            </td>

                            <td>
                                <div class="d-inline-flex align-items-center gap-2">
                                    <span class="font-monospace text-muted secret-masked" id="secret_text_<?= $r['id'] ?>" style="font-size: 0.82rem;">••••••••</span>
                                    <button type="button" class="secret-reveal-btn" onclick="toggleSecret(<?= $r['id'] ?>, '<?= htmlspecialchars($r['secret']) ?>', this)">
                                        <i class="fa-regular fa-eye"></i> Show
                                    </button>
                                </div>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark border rounded-pill px-2 py-1 font-monospace" style="font-size: 0.75rem;">
                                    Port <?= htmlspecialchars($r['api_port']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                    <span class="spinner-grow spinner-grow-sm me-1" style="width:0.35rem; height:0.35rem;"></span>Online
                                </span>
                            </td>

                            <td class="pe-4 text-end">
                                <!-- Delete Form -->
                                <form method="POST" class="d-inline m-0" onsubmit="return confirm('DANGER: Delete this MikroTik Router (#<?= $r['id'] ?> - <?= htmlspecialchars($r['shortname']) ?>)? FreeRADIUS will stop authenticating requests from this IP until re-added.');">
                                    <input type="hidden" name="delete_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn-action-icon btn-action-delete" title="Delete Router">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
var routerTable;

$(document).ready(function() {
    routerTable = $('#routersTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search routers, IP, admin...",
            lengthMenu: "Show _MENU_ entries"
        },
        initComplete: function() {
            $('#routersLoadingBox').fadeOut(120, function() {
                $('#routersTableContainer').fadeIn(180, function() {
                    routerTable.columns.adjust().draw();
                });
            });
        }
    });
});

function toggleSecret(id, actualSecret, btnElement) {
    var secretEl = document.getElementById('secret_text_' + id);
    if (secretEl.innerText === '••••••••') {
        secretEl.innerText = actualSecret;
        secretEl.classList.remove('text-muted');
        secretEl.classList.add('text-dark', 'fw-bold');
        btnElement.innerHTML = '<i class="fa-regular fa-eye-slash"></i> Hide';
    } else {
        secretEl.innerText = '••••••••';
        secretEl.classList.add('text-muted');
        secretEl.classList.remove('text-dark', 'fw-bold');
        btnElement.innerHTML = '<i class="fa-regular fa-eye"></i> Show';
    }
}

function copyText(text, btnElement) {
    if (!navigator.clipboard) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    } else {
        navigator.clipboard.writeText(text);
    }
    
    var originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
    setTimeout(function() {
        btnElement.innerHTML = originalHTML;
    }, 2000);
}
</script>

<?php require_once 'footer.php'; ?>
