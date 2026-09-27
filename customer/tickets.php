<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject'], $_POST['message'])) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    
    if ($subject !== '' && $message !== '') {
        $pdo->beginTransaction();
        try {
            // Create ticket
            $stmt = $pdo->prepare("INSERT INTO support_tickets (client_id, subscriber_id, subject) VALUES (?, ?, ?)");
            $stmt->execute([$client_id, $current_user['id'], $subject]);
            $ticket_id = $pdo->lastInsertId();
            
            // Insert initial message
            $stmt = $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message) VALUES (?, 'customer', ?)");
            $stmt->execute([$ticket_id, $message]);
            
            $pdo->commit();
            echo "<script>window.location='view_ticket.php?id=$ticket_id';</script>";
            exit;
        } catch(Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error creating ticket.'); window.history.back();</script>";
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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-light fw-bold"><i class="fa-solid fa-headset text-accent me-2"></i> Support Tickets</h4>
    <button class="btn btn-accent rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#newTicketModal"><i class="fa-solid fa-plus me-1"></i> New Ticket</button>
</div>

<div class="card bg-dark border-secondary shadow-lg rounded-4 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Ticket ID</th>
                        <th>Subject</th>
                        <th>Replies</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!$tickets): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">You have no support tickets.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($tickets as $t): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-secondary">#<?= $t['id'] ?></td>
                        <td class="fw-bold text-light"><?= htmlspecialchars($t['subject']) ?></td>
                        <td><span class="badge bg-secondary bg-opacity-25 text-light rounded-pill border border-secondary"><?= $t['msgs'] ?> Replies</span></td>
                        <td>
                            <?php if($t['status'] === 'open'): ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning rounded-pill px-3">Open</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary rounded-pill px-3">Closed</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary small"><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></td>
                        <td class="text-end pe-4">
                            <a href="view_ticket.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-info rounded-pill px-3">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New Ticket Modal -->
<div class="modal fade" id="newTicketModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark border border-secondary text-light">
      <div class="modal-header border-bottom border-secondary">
        <h5 class="modal-title fw-bold text-accent"><i class="fa-solid fa-plus me-2"></i> Open New Ticket</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label text-secondary fw-bold">Subject</label>
                <input type="text" name="subject" class="form-control bg-dark border-secondary text-light" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-secondary fw-bold">Describe your issue</label>
                <textarea name="message" class="form-control bg-dark border-secondary text-light" rows="5" required></textarea>
            </div>
        </div>
        <div class="modal-footer border-top border-secondary">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent rounded-pill px-4 fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
