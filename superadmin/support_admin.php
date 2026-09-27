<?php
require_once 'header.php';

// Handle ticket close
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_ticket_id'])) {
    $tid = (int)$_POST['close_ticket_id'];
    $pdo->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ?")->execute([$tid]);
    echo "<div class='alert alert-success mt-3'>Ticket closed successfully.</div>";
}

// Fetch all tickets globally
$tickets = $pdo->query("
    SELECT t.*, c.company_name as operator_name, s.username as customer_username
    FROM support_tickets t
    LEFT JOIN clients c ON t.client_id = c.id
    LEFT JOIN subscribers s ON t.subscriber_id = s.id
    ORDER BY t.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch messages for modals
$messages = [];
if (!empty($tickets)) {
    $stmt = $pdo->query("SELECT * FROM ticket_messages ORDER BY created_at ASC");
    $allMsgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($allMsgs as $m) {
        $messages[$m['ticket_id']][] = $m;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 mt-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-headset me-2 text-primary"></i> Global Support Tickets</h4>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Ticket ID</th>
                        <th>Operator</th>
                        <th>Customer</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($tickets)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No support tickets found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach($tickets as $t): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-secondary">#<?= $t['id'] ?></td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($t['operator_name'] ?? 'N/A') ?></td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25"><?= htmlspecialchars($t['customer_username'] ?? 'N/A') ?></span></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($t['subject']) ?></td>
                            <td>
                                <?php if($t['status'] === 'open'): ?>
                                    <span class="badge rounded-pill bg-success">Open</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill bg-secondary">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small fw-bold"><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewTicketModal<?= $t['id'] ?>">
                                    <i class="fa-solid fa-eye"></i> View
                                </button>
                                <?php if($t['status'] === 'open'): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to close this ticket?');">
                                    <input type="hidden" name="close_ticket_id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-times"></i> Close
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- View Modal -->
                                <div class="modal fade" id="viewTicketModal<?= $t['id'] ?>" tabindex="-1">
                                  <div class="modal-dialog modal-lg">
                                    <div class="modal-content border-0 shadow">
                                      <div class="modal-header bg-light">
                                        <h5 class="modal-title fw-bold text-dark">Ticket #<?= $t['id'] ?> - <?= htmlspecialchars($t['subject']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <div class="modal-body text-start" style="background-color: #f1f5f9; max-height: 500px; overflow-y: auto;">
                                          <?php 
                                          $tMsgs = $messages[$t['id']] ?? [];
                                          if(empty($tMsgs)): ?>
                                            <p class="text-muted text-center py-4">No messages yet.</p>
                                          <?php else: ?>
                                              <?php foreach($tMsgs as $m): ?>
                                                  <div class="mb-3 <?= $m['sender_type'] === 'operator' ? 'text-end' : '' ?>">
                                                      <div class="d-inline-block p-3 rounded shadow-sm <?= $m['sender_type'] === 'operator' ? 'bg-primary text-white' : 'bg-white text-dark border' ?>" style="max-width: 75%; text-align: left;">
                                                          <div class="small fw-bold mb-2 <?= $m['sender_type'] === 'operator' ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.7rem; border-bottom: 1px solid <?= $m['sender_type'] === 'operator' ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.05)' ?>; padding-bottom: 4px;">
                                                              <i class="fa-solid <?= $m['sender_type'] === 'operator' ? 'fa-user-tie' : 'fa-user' ?> me-1"></i>
                                                              <?= $m['sender_type'] === 'operator' ? 'Operator ('.htmlspecialchars($t['operator_name'] ?? '').')' : 'Customer ('.htmlspecialchars($t['customer_username'] ?? '').')' ?> 
                                                              <span class="ms-2 fw-normal"><i class="fa-regular fa-clock me-1"></i><?= date('d M Y h:i A', strtotime($m['created_at'])) ?></span>
                                                          </div>
                                                          <div style="font-size: 0.95rem;">
                                                              <?= nl2br(htmlspecialchars($m['message'])) ?>
                                                          </div>
                                                      </div>
                                                  </div>
                                              <?php endforeach; ?>
                                          <?php endif; ?>
                                      </div>
                                      <div class="modal-footer bg-light">
                                        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                                <!-- End Modal -->

                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
