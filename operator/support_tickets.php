<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Close ticket logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket'])) {
    $tid = (int)$_POST['ticket_id'];
    $pdo->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ? AND client_id = ?")->execute([$tid, $client_id]);
    echo "<script>window.location='support_tickets.php';</script>";
    exit;
}

$stmt = $pdo->prepare("
    SELECT t.*, s.username, s.full_name,
    (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as msgs
    FROM support_tickets t
    JOIN subscribers s ON t.subscriber_id = s.id
    WHERE t.client_id = ?
    ORDER BY t.status DESC, t.id DESC
");
$stmt->execute([$client_id]);
$tickets = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-headset text-primary me-2"></i> Support Tickets</h4>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="ticketsTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Ticket ID</th>
                        <th>Subscriber</th>
                        <th>Subject</th>
                        <th>Messages</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tickets as $t): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-secondary">#<?= $t['id'] ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($t['username']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($t['full_name']) ?></div>
                        </td>
                        <td class="fw-bold"><?= htmlspecialchars($t['subject']) ?></td>
                        <td><span class="badge bg-secondary rounded-pill"><?= $t['msgs'] ?> Replies</span></td>
                        <td>
                            <?php if($t['status'] === 'open'): ?>
                                <span class="badge bg-warning text-dark border border-warning rounded-pill px-3">Open</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary rounded-pill px-3">Closed</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <a href="view_ticket.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3 me-1">View / Reply</a>
                            <?php if($t['status'] === 'open'): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to close this ticket?');">
                                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                                <button type="submit" name="close_ticket" class="btn btn-sm btn-outline-danger rounded-pill px-3">Close</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#ticketsTable').DataTable({"order": []});
    });
</script>

<?php require_once 'footer.php'; ?>
