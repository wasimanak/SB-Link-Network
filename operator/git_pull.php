<?php
// =========================================================================
// NetBee / SB Link Network - 1-Click Git System Synchronizer
// Safely pulls latest code updates from GitHub without requiring SSH
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Auth Guard: Only Admin / Operator can trigger system sync
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: ../login.php");
    exit;
}
if (!empty($_SESSION['is_manager'])) {
    echo "<script>alert('Permission Denied.'); window.location='dashboard.php';</script>";
    exit;
}

$repoDir = realpath(__DIR__ . '/..');
$syncOutput = '';
$currentCommit = '';
$isSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['confirm'])) {
    // Run git fetch and reset hard to origin/main
    $cmd = "cd " . escapeshellarg($repoDir) . " && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1";
    $syncOutput = shell_exec($cmd);

    $logCmd = "cd " . escapeshellarg($repoDir) . " && git log -1 --format=\"%h - %s (%cd)\" --date=local 2>&1";
    $currentCommit = shell_exec($logCmd);

    $isSuccess = (strpos($syncOutput, 'HEAD is now at') !== false || strpos($syncOutput, 'Updating') !== false || !empty($currentCommit));
}

require_once 'header.php';
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 rounded-4 shadow-sm overflow-hidden">
                <div class="card-header bg-primary text-white p-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-white bg-opacity-25 rounded-3 p-3 fs-3">
                            <i class="fa-solid fa-arrows-rotate fa-spin-pulse"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-1">System Live Code Synchronizer</h4>
                            <p class="mb-0 text-white-50 small">Pull and activate latest GitHub updates on live server instantly</p>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <?php if (!empty($syncOutput)): ?>
                        <?php if ($isSuccess): ?>
                            <div class="alert alert-success rounded-3 border-0 shadow-sm p-3 mb-4">
                                <h6 class="fw-bold mb-1"><i class="fa-solid fa-circle-check me-2"></i> Update Applied Successfully!</h6>
                                <p class="small mb-0">Your server has been updated to the latest GitHub release.</p>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning rounded-3 border-0 shadow-sm p-3 mb-4">
                                <h6 class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-2"></i> Sync Command Executed</h6>
                                <p class="small mb-0">Check the terminal log below for details.</p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($currentCommit)): ?>
                            <div class="mb-3">
                                <label class="small text-muted fw-bold text-uppercase">Current Active Commit:</label>
                                <div class="p-2 bg-light rounded-3 font-monospace small text-primary fw-bold">
                                    <i class="fa-solid fa-code-commit me-1"></i> <?= htmlspecialchars(trim($currentCommit)) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <label class="small text-muted fw-bold text-uppercase">Git Terminal Output:</label>
                            <pre class="bg-dark text-light p-3 rounded-3 small font-monospace" style="max-height: 250px; overflow-y: auto;"><?= htmlspecialchars(trim($syncOutput)) ?></pre>
                        </div>

                        <div class="d-flex gap-2">
                            <a href="reminders.php?tab=custom" class="btn btn-primary fw-bold px-4 rounded-3">
                                <i class="fa-solid fa-arrow-left me-1"></i> Open SMS Reminders &amp; Custom SMS
                            </a>
                            <form method="POST" class="d-inline">
                                <button type="submit" class="btn btn-outline-secondary fw-bold px-3 rounded-3">
                                    <i class="fa-solid fa-rotate me-1"></i> Re-Sync Again
                                </button>
                            </form>
                        </div>

                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fa-brands fa-github text-primary fs-1 mb-3"></i>
                            <h5 class="fw-bold text-dark">Apply Latest Updates from GitHub</h5>
                            <p class="text-muted small mb-4" style="max-width: 500px; margin: 0 auto;">
                                Clicking below will fetch the newest updates (SMS Gateway SSE stream, Custom SMS Direct Dispatch, and Scheduler toggle) and apply them to your live server.
                            </p>
                            <form method="POST">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold px-5 rounded-pill shadow">
                                    <i class="fa-solid fa-cloud-arrow-down me-2"></i> Update System Now
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
