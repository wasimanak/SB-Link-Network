<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

$find_html = <<<'HTML'
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong style="font-size:0.9rem;" class="<?= $u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($u['balance'], 2) ?></strong>
                                </div>
                                <button class="btn btn-danger btn-sm px-3 py-1 fw-bold shadow-sm" onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>')">
                                    <i class="fa-solid fa-rotate me-1"></i> Renew / Collect
                                </button>
                            </div>
HTML;

$replace_html = <<<'HTML'
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong style="font-size:0.9rem;" class="<?= $u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($u['balance'], 2) ?></strong>
                                </div>
                                <div class="text-end">
                                    <button class="btn btn-success btn-sm px-2 py-1 fw-bold shadow-sm mb-1 d-block w-100" data-bs-toggle="modal" data-bs-target="#collectModal<?= $u['id'] ?>">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> Collect
                                    </button>
                                    <button class="btn btn-danger btn-sm px-2 py-1 fw-bold shadow-sm d-block w-100" onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>')">
                                        <i class="fa-solid fa-rotate"></i> Renew
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Collect Payment Modal -->
                            <div class="modal fade" id="collectModal<?= $u['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                        <div class="modal-header border-bottom p-4">
                                            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Payment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="collect_only">
                                            <input type="hidden" name="subscriber_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($u['username']) ?>">
                                            
                                            <div class="modal-body p-4 text-start">
                                                <div class="alert alert-success bg-opacity-10 text-success border-success border-opacity-25 py-2 mb-3">
                                                    <strong>User:</strong> <?= htmlspecialchars($u['username']) ?><br>
                                                    <strong>Current Balance:</strong> Rs. <?= number_format($u['balance'], 2) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                                    <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Collected Amount (Rs) <span class="text-danger">*</span></label>
                                                    <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 1500" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Note (Optional)</label>
                                                    <input type="text" name="note" class="form-control" placeholder="e.g. Previous month pending">
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top p-3">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
HTML;

$c = str_replace($find_html, $replace_html, $c);
file_put_contents($f, $c);
echo "Injected successfully.\n";
?>
