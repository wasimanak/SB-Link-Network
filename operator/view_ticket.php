<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$ticket_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT t.*, s.username, s.full_name FROM support_tickets t JOIN subscribers s ON t.subscriber_id = s.id WHERE t.id = ? AND t.client_id = ?");
$stmt->execute([$ticket_id, $client_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    echo "<script>alert('Ticket not found.'); window.location='support_tickets.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message']);
    if ($msg !== '' && $ticket['status'] === 'open') {
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message) VALUES (?, 'operator', ?)")->execute([$ticket_id, $msg]);
    }
    echo "<script>window.location='view_ticket.php?id=$ticket_id';</script>";
    exit;
}

$msgs = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id ASC");
$msgs->execute([$ticket_id]);
$messages = $msgs->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 text-secondary fw-bold">Ticket #<?= $ticket['id'] ?></h4>
        <div class="text-muted small">Customer: <strong><?= htmlspecialchars($ticket['username']) ?></strong></div>
    </div>
    <a href="support_tickets.php" class="btn btn-outline-secondary rounded-pill px-4"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom pt-4 pb-3">
        <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($ticket['subject']) ?></h5>
        <?php if($ticket['status'] === 'open'): ?>
            <span class="badge bg-warning text-dark mt-2 px-3 rounded-pill border border-warning">Open</span>
        <?php else: ?>
            <span class="badge bg-secondary mt-2 px-3 rounded-pill">Closed</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-4" style="height: 500px; overflow-y: auto; background-color: #f8fafc;" id="chatBox">
        <?php foreach($messages as $m): ?>
            <?php if($m['sender_type'] === 'customer'): ?>
                <div class="d-flex mb-4">
                    <div class="bg-white p-3 rounded-4 shadow-sm" style="max-width: 75%; border: 1px solid #e2e8f0; border-top-left-radius: 0;">
                        <div class="fw-bold text-primary small mb-1"><?= htmlspecialchars($ticket['username']) ?> <span class="text-muted fw-normal ms-2" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></span></div>
                        <div class="text-dark" style="white-space: pre-wrap;"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex mb-4 justify-content-end">
                    <div class="bg-primary p-3 rounded-4 shadow-sm text-white" style="max-width: 75%; border-top-right-radius: 0;">
                        <div class="fw-bold text-white-50 small mb-1">Operator <span class="text-light fw-normal ms-2" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></span></div>
                        <div style="white-space: pre-wrap;"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <?php if($ticket['status'] === 'open'): ?>
    <div class="card-footer bg-white p-3 border-top">
        <form method="POST" class="d-flex gap-2">
            <textarea name="message" class="form-control rounded-4" rows="2" placeholder="Type your reply here..." required></textarea>
            <button type="submit" class="btn btn-primary rounded-4 px-4 fw-bold"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    </div>
    <?php else: ?>
    <div class="card-footer bg-light p-3 text-center text-muted border-top">
        This ticket is closed. No further replies can be added.
    </div>
    <?php endif; ?>
</div>

<script>
    // Auto scroll to bottom of chat
    var chatBox = document.getElementById('chatBox');
    chatBox.scrollTop = chatBox.scrollHeight;
</script>

<?php require_once 'footer.php'; ?>
