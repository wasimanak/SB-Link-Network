<?php
require_once 'header.php';

// Auto-upgrade / create support ticket tables if not present or missing columns
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `support_tickets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `dealer_id` int(11) DEFAULT NULL,
        `subscriber_id` int(11) DEFAULT NULL,
        `subject` varchar(255) NOT NULL,
        `status` varchar(50) DEFAULT 'open',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `ticket_messages` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ticket_id` int(11) NOT NULL,
        `sender_type` varchar(50) NOT NULL,
        `sender_id` int(11) DEFAULT 0,
        `message` text NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Make sender_id default 0 and loosen enums to avoid strict mode errors
    $pdo->exec("ALTER TABLE `ticket_messages` MODIFY COLUMN `sender_id` int(11) DEFAULT 0");
    $pdo->exec("ALTER TABLE `ticket_messages` MODIFY COLUMN `sender_type` varchar(50) NOT NULL");
    $pdo->exec("ALTER TABLE `support_tickets` MODIFY COLUMN `status` varchar(50) DEFAULT 'open'");
} catch (Exception $e) {}

$effective_client_id = !empty($client_id) ? (int)$client_id : (int)($current_user['client_id'] ?? 1);
$dealer_id = !empty($current_user['dealer_id']) ? (int)$current_user['dealer_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject'], $_POST['message'])) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    
    if ($subject !== '' && $message !== '') {
        $pdo->beginTransaction();
        try {
            // Create ticket
            $stmt = $pdo->prepare("INSERT INTO support_tickets (client_id, dealer_id, subscriber_id, subject, status) VALUES (?, ?, ?, ?, 'open')");
            $stmt->execute([$effective_client_id, $dealer_id, $current_user['id'], $subject]);
            $ticket_id = $pdo->lastInsertId();
            
            // Insert initial message
            $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'customer', ?, ?)");
            $stmt->execute([$ticket_id, $current_user['id'], $message]);
            
            $pdo->commit();
            echo "<script>window.location='view_ticket.php?id=$ticket_id';</script>";
            exit;
        } catch(Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $err_msg = addslashes($e->getMessage());
            echo "<script>alert('Error creating ticket: $err_msg'); window.history.back();</script>";
            exit;
        }
    }
}

$stmt = $pdo->prepare("
    SELECT t.*, 
    (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as msgs
    FROM support_tickets t
    WHERE t.subscriber_id = ?
    ORDER BY t.status DESC, t.id DESC
");
$stmt->execute([$current_user['id']]);
$tickets = $stmt->fetchAll();

// Count stats
$total_tickets = count($tickets);
$count_open = 0;
$count_closed = 0;
foreach ($tickets as $t) {
    if ($t['status'] === 'open') {
        $count_open++;
    } else {
        $count_closed++;
    }
}
?>

<script>
    // Harmonize Bootstrap Theme to clean modern mode
    document.documentElement.setAttribute('data-bs-theme', 'light');
</script>

<style>
    :root {
        --primary-blue: #2563eb;
        --surface-bg: #f8fafc;
        --card-border: #eef2f6;
        --text-dark: #0f172a;
        --text-muted: #64748b;
    }

    body {
        background-color: var(--surface-bg) !important;
        color: var(--text-dark) !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
    }

    /* Modern Navbar Override */
    .navbar {
        background: #ffffff !important;
        border-bottom: 1px solid #e2e8f0 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03) !important;
    }
    .navbar-brand {
        color: #1e3a8a !important;
        font-weight: 800 !important;
        letter-spacing: -0.5px;
    }
    .navbar .nav-link {
        color: #475569 !important;
        font-weight: 500;
        border-radius: 10px;
        padding: 8px 14px !important;
        transition: all 0.2s ease;
    }
    .navbar .nav-link:hover, .navbar .nav-link.active {
        color: #2563eb !important;
        background: #eff6ff !important;
    }

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
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
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
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
        font-size: 1.35rem;
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

    /* Filter Pills */
    .filter-pill-btn {
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        color: #64748b;
        font-size: 0.85rem;
        font-weight: 600;
        padding: 8px 18px;
        border-radius: 50px;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .filter-pill-btn:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #0f172a;
    }
    .filter-pill-btn.active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .filter-pill-btn.active .badge-count {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }
    .badge-count {
        background: #f1f5f9;
        color: #475569;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    /* Content Card & Table */
    .card-ui {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        overflow: hidden;
    }
    .modern-table {
        margin-bottom: 0;
        width: 100%;
    }
    .modern-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
        padding: 14px 20px;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .modern-table tbody td {
        padding: 16px 20px;
        color: #1e293b;
        font-size: 0.88rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .modern-table tbody tr:hover {
        background-color: #f8fafc;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Status Badges */
    .badge-open {
        background: #fef9c3;
        color: #854d0e;
        border: 1px solid #fef08a;
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.72rem;
        text-transform: uppercase;
    }
    .badge-closed {
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
        padding: 5px 12px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.72rem;
        text-transform: uppercase;
    }

    /* Clean Modal */
    .modal-content.clean-modal {
        background: #ffffff !important;
        color: #0f172a !important;
        border-radius: 24px !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15) !important;
    }
    .clean-modal .modal-header {
        border-bottom: 1px solid #f1f5f9;
        padding: 20px 24px;
    }
    .clean-modal .modal-footer {
        border-top: 1px solid #f1f5f9;
        padding: 16px 24px;
    }

    /* Footer modern look */
    footer.bg-black {
        background: #0f172a !important;
        border-top: 1px solid #1e293b !important;
    }
</style>

<!-- Top Capsule Header -->
<div class="page-header-card">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                <i class="fa-solid fa-headset"></i> Customer Helpdesk
            </div>
            <h3 class="fw-bold text-dark mb-1">Support & Inquiries</h3>
            <p class="text-muted small mb-0">Have an issue with your connection or billing? Open a ticket to receive quick assistance from our support team.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
            </a>
            <button class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                <i class="fa-solid fa-plus me-1"></i> Open New Ticket
            </button>
        </div>
    </div>
</div>

<!-- 3 Stat Cards Row -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="fa-solid fa-ticket"></i>
            </div>
            <div>
                <div class="stat-pill-lbl">Total Tickets</div>
                <div class="stat-pill-val"><?= $total_tickets ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;">All lifetime tickets filed</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #fefce8; color: #ca8a04;">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div>
                <div class="stat-pill-lbl">Active & Open</div>
                <div class="stat-pill-val"><?= $count_open ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;">Awaiting resolution or replies</div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <div class="stat-pill-lbl">Resolved & Closed</div>
                <div class="stat-pill-val"><?= $count_closed ?></div>
                <div class="text-muted small" style="font-size: 0.75rem;">Successfully solved inquiries</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Pills -->
<div class="d-flex align-items-center gap-2 mb-3 overflow-x-auto pb-1">
    <button type="button" class="filter-pill-btn active" onclick="filterTickets('all', this)">
        <span>All Tickets</span>
        <span class="badge-count"><?= $total_tickets ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterTickets('open', this)">
        <span><i class="fa-solid fa-circle text-warning me-1" style="font-size: 0.6rem;"></i> Open</span>
        <span class="badge-count"><?= $count_open ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterTickets('closed', this)">
        <span><i class="fa-solid fa-circle text-secondary me-1" style="font-size: 0.6rem;"></i> Closed</span>
        <span class="badge-count"><?= $count_closed ?></span>
    </button>
</div>

<!-- Tickets Card & Table -->
<div class="card-ui mb-5">
    <div class="table-responsive">
        <table class="modern-table" id="ticketsTable">
            <thead>
                <tr>
                    <th class="ps-4">Ticket</th>
                    <th>Subject</th>
                    <th>Messages</th>
                    <th>Status</th>
                    <th>Created On</th>
                    <th class="text-end pe-4">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$tickets): ?>
                    <tr id="emptyRow">
                        <td colspan="6" class="text-center py-5">
                            <div class="py-4">
                                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-muted mb-3" style="width: 64px; height: 64px;">
                                    <i class="fa-solid fa-headset fs-3"></i>
                                </div>
                                <h6 class="fw-bold text-dark mb-1">No Support Tickets Yet</h6>
                                <p class="text-muted small mb-3">If you are facing any internet speed or connection issue, feel free to open a ticket.</p>
                                <button class="btn btn-sm btn-primary rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#newTicketModal">
                                    <i class="fa-solid fa-plus me-1"></i> Open First Ticket
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                
                <?php foreach($tickets as $t): ?>
                <tr class="ticket-row" data-status="<?= $t['status'] ?>">
                    <td class="ps-4">
                        <span class="fw-bold text-primary font-monospace">#<?= $t['id'] ?></span>
                    </td>
                    <td>
                        <a href="view_ticket.php?id=<?= $t['id'] ?>" class="text-decoration-none fw-bold text-dark hover-primary">
                            <?= htmlspecialchars($t['subject']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border rounded-pill px-3 py-1 fw-semibold" style="font-size: 0.75rem;">
                            <i class="fa-regular fa-comments me-1 text-primary"></i> <?= $t['msgs'] ?> <?= $t['msgs'] == 1 ? 'Reply' : 'Replies' ?>
                        </span>
                    </td>
                    <td>
                        <?php if($t['status'] === 'open'): ?>
                            <span class="badge-open"><i class="fa-solid fa-clock me-1"></i>Open</span>
                        <?php else: ?>
                            <span class="badge-closed"><i class="fa-solid fa-check me-1"></i>Closed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="text-dark small fw-semibold"><?= date('d M Y', strtotime($t['created_at'])) ?></div>
                        <div class="text-muted" style="font-size: 0.74rem;"><?= date('h:i A', strtotime($t['created_at'])) ?></div>
                    </td>
                    <td class="text-end pe-4">
                        <a href="view_ticket.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                            View <i class="fa-solid fa-chevron-right ms-1" style="font-size: 0.7rem;"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Ticket Modal -->
<div class="modal fade" id="newTicketModal" tabindex="-1" aria-labelledby="newTicketModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content clean-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fa-solid fa-headset"></i>
            </div>
            <h5 class="modal-title fw-bold text-dark" id="newTicketModalLabel">Open New Support Ticket</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <form method="POST">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label text-dark fw-bold small mb-1">Ticket Subject</label>
                <input type="text" name="subject" class="form-control" placeholder="e.g. Slow internet speed / Frequent disconnects" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-dark fw-bold small mb-1">Describe your issue in detail</label>
                <textarea name="message" class="form-control" rows="5" placeholder="Please describe what issue you are experiencing, when it started, and any router LEDs status..." required></textarea>
            </div>
            <div class="alert alert-light border small text-muted mb-0">
                <i class="fa-solid fa-circle-info text-primary me-1"></i> Our support team will review your ticket and reply promptly. You can reply back anytime.
            </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold">
            <i class="fa-solid fa-paper-plane me-1"></i> Submit Ticket
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function filterTickets(status, btnElement) {
    document.querySelectorAll('.filter-pill-btn').forEach(b => b.classList.remove('active'));
    btnElement.classList.add('active');

    const rows = document.querySelectorAll('.ticket-row');
    rows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        if (status === 'all' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
