<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$ticket_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT t.*, s.id as subscriber_id, s.username, s.full_name, s.mobile, s.status as sub_status 
    FROM support_tickets t 
    JOIN subscribers s ON t.subscriber_id = s.id 
    WHERE t.id = ? AND t.client_id = ?");
$stmt->execute([$ticket_id, $client_id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    echo "<script>alert('Ticket not found.'); window.location='support_tickets.php';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message']);
    if ($msg !== '' && $ticket['status'] === 'open') {
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'operator', ?, ?)")->execute([$ticket_id, $client_id, $msg]);
    }
    echo "<script>window.location='view_ticket.php?id=$ticket_id';</script>";
    exit;
}

$msgs = $pdo->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY id ASC");
$msgs->execute([$ticket_id]);
$messages = $msgs->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
    <div>
        <h4 class="mb-1 text-secondary fw-bold">Ticket #<?= $ticket['id'] ?></h4>
        <div class="text-muted small d-flex align-items-center flex-wrap gap-1">
            <span>Customer:</span>
            <a href="subscriber_view.php?id=<?= $ticket['subscriber_id'] ?>" class="fw-bold text-primary text-decoration-none d-inline-flex align-items-center gap-1" title="View Subscriber Profile">
                <i class="fa-solid fa-circle-user text-primary"></i>
                <span class="text-decoration-underline"><?= htmlspecialchars($ticket['username']) ?></span>
                <?php if (!empty($ticket['full_name'])): ?>
                    <span class="text-muted fw-normal">(<?= htmlspecialchars($ticket['full_name']) ?>)</span>
                <?php endif; ?>
            </a>
            <button type="button" class="btn btn-sm btn-light border py-0 px-2 rounded-pill ms-1 text-secondary d-inline-flex align-items-center gap-1 copy-btn shadow-xs" onclick="copyUsername('<?= htmlspecialchars($ticket['username'], ENT_QUOTES) ?>', this)" title="Copy Username to Clipboard" style="font-size: 0.72rem; height: 22px;">
                <i class="fa-regular fa-copy"></i>
                <span>Copy</span>
            </button>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="subscriber_view.php?id=<?= $ticket['subscriber_id'] ?>" class="btn btn-primary rounded-pill px-3 shadow-sm">
            <i class="fa-solid fa-user me-1"></i> View Profile
        </a>
        <a href="support_tickets.php" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="fa-solid fa-arrow-left me-1"></i> Back
        </a>
    </div>
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
                        <div class="fw-bold text-primary small mb-1 d-flex align-items-center gap-1 flex-wrap">
                            <a href="subscriber_view.php?id=<?= $ticket['subscriber_id'] ?>" class="text-primary text-decoration-none fw-bold" title="Open Subscriber Profile">
                                <?= htmlspecialchars($ticket['username']) ?>
                            </a>
                            <button type="button" class="btn btn-link text-muted p-0 ms-1 text-decoration-none" onclick="copyUsername('<?= htmlspecialchars($ticket['username'], ENT_QUOTES) ?>', this)" title="Copy Username" style="font-size: 0.72rem; line-height: 1;">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                            <span class="text-muted fw-normal ms-2" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></span>
                        </div>
                        <div class="text-dark" style="white-space: pre-wrap;"><?= htmlspecialchars($m['message']) ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex mb-4 justify-content-end">
                    <div class="bg-primary p-3 rounded-4 shadow-sm text-white" style="max-width: 75%; border-top-right-radius: 0;">
                        <div class="fw-bold text-white-50 small mb-1">Admin <span class="text-light fw-normal ms-2" style="font-size: 0.7rem;"><?= date('d M, h:i A', strtotime($m['created_at'])) ?></span></div>
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
    // Copy username to clipboard function
    function copyUsername(text, btn) {
        if (!navigator.clipboard) {
            var dummy = document.createElement("input");
            document.body.appendChild(dummy);
            dummy.value = text;
            dummy.select();
            document.execCommand("copy");
            document.body.removeChild(dummy);
        } else {
            navigator.clipboard.writeText(text);
        }
        
        var origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-success"></i> <span class="text-success" style="font-size:0.7rem;">Copied!</span>';
        btn.classList.add('border-success');
        setTimeout(function() {
            btn.innerHTML = origHtml;
            btn.classList.remove('border-success');
        }, 1500);
    }

    // Auto scroll to bottom of chat
    var chatBox = document.getElementById('chatBox');
    if (chatBox) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
</script>

<?php require_once 'footer.php'; ?>
