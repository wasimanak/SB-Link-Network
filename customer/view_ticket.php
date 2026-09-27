<?php
require_once 'header.php';
$ticket_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? AND subscriber_id = ?");
$stmt->execute([$ticket_id, $current_user['id']]);
$ticket = $stmt->fetch();

if (!$ticket) {
    echo "<script>window.location='tickets.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message']);
    if ($msg !== '' && $ticket['status'] === 'open') {
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, message) VALUES (?, 'customer', ?)")->execute([$ticket_id, $msg]);
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
        <h4 class="mb-1 text-light fw-bold">Ticket #<?= $ticket['id'] ?></h4>
        <div class="text-secondary small">Status: 
            <?php if($ticket['status'] === 'open'): ?>
                <span class="badge bg-warning text-dark ms-1">Open</span>
            <?php else: ?>
                <span class="badge bg-secondary ms-1">Closed</span>
            <?php endif; ?>
        </div>
    </div>
    <a href="tickets.php" class="btn btn-outline-secondary rounded-pill px-4"><i class="fa-solid fa-arrow-left me-1"></i> Back</a>
</div>

<div class="card bg-dark border-secondary shadow-lg rounded-4 mb-4">
    <div class="card-header border-bottom border-secondary pt-4 pb-3">
        <h5 class="fw-bold text-accent mb-0"><?= htmlspecialchars($ticket['subject']) ?></h5>
    </div>
    <div class="card-body p-4" style="height: 500px; overflow-y: auto; background-color: rgba(0,0,0,0.2);" id="chatBox">
        <?php foreach($messages as $m): ?>
            <?php if($m['sender_type'] === 'operator'): ?>
                <div class="d-flex mb-4">
                    <div class="bg-primary p-3 rounded-4 shadow-sm text-white" style="max-width: 75%; border-top-left-radius: 0;">
                        <div class="fw-bold text-white-50 small mb-1">Operator Support <span class="text-light fw-normal ms-2" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></span></div>
                        <div style="white-space: pre-wrap;"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex mb-4 justify-content-end">
                    <div class="bg-secondary bg-opacity-25 p-3 rounded-4 shadow-sm text-light border border-secondary" style="max-width: 75%; border-top-right-radius: 0;">
                        <div class="fw-bold text-secondary small mb-1">You <span class="text-muted fw-normal ms-2" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></span></div>
                        <div style="white-space: pre-wrap;"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <?php if($ticket['status'] === 'open'): ?>
    <div class="card-footer bg-transparent p-3 border-top border-secondary">
        <form method="POST" class="d-flex gap-2">
            <textarea name="message" class="form-control bg-dark border-secondary text-light rounded-4" rows="2" placeholder="Type your reply here..." required></textarea>
            <button type="submit" class="btn btn-accent rounded-4 px-4 fw-bold"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    </div>
    <?php else: ?>
    <div class="card-footer bg-transparent p-3 text-center text-muted border-top border-secondary">
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
