<?php
session_start();
require_once 'config/db.php'; 

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    
    if ($_POST['reset_keyword'] === 'CLEANUP') {
        
        try {
            // Disable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // 1. Delete Users
            if (isset($_POST['wipe_users'])) {
                // Wipe related child tables (we use try-catch for child tables in case they don't exist)
                try { $pdo->exec("TRUNCATE TABLE package_requests"); } catch (Exception $e) {}
                
                $pdo->exec("TRUNCATE TABLE subscribers");
                
                try { $pdo->exec("TRUNCATE TABLE radcheck"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE radreply"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE radusergroup"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE radacct"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE radpostauth"); } catch (Exception $e) {}
            }

            // 2. Delete Routers (NAS)
            if (isset($_POST['wipe_routers'])) {
                $pdo->exec("TRUNCATE TABLE nas");
                $pdo->exec("UPDATE dealers SET assigned_routers = ''");
            }

            // 3. Delete Packages
            if (isset($_POST['wipe_packages'])) {
                $pdo->exec("TRUNCATE TABLE packages");
                try { $pdo->exec("TRUNCATE TABLE dealer_packages"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE radgroupreply"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE radgroupcheck"); } catch (Exception $e) {}
            }

            // 4. Delete Dealers (Optional)
            if (isset($_POST['wipe_dealers'])) {
                $pdo->exec("TRUNCATE TABLE dealers");
                try { $pdo->exec("TRUNCATE TABLE dealer_notes"); } catch (Exception $e) {}
                try { $pdo->exec("TRUNCATE TABLE dealer_documents"); } catch (Exception $e) {}
            }

            // Re-enable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            $message = "<div class='alert alert-success'>✅ Selected data has been successfully cleaned from the database!</div>";
        } catch (Exception $e) {
            // Ensure FK checks are re-enabled even if it fails
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $message = "<div class='alert alert-danger'>❌ Error cleaning data: " . $e->getMessage() . "</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>❌ Incorrect Keyword. Data was NOT deleted.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Cleanup Utility</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 40px; }
        .card { max-width: 600px; margin: 0 auto; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .danger-zone { border: 2px dashed #dc3545; padding: 20px; border-radius: 10px; background-color: #fff5f5; }
    </style>
</head>
<body>

<div class="card p-4">
    <h3 class="text-danger mb-4 text-center">⚠️ Database Cleanup Utility</h3>
    
    <?= $message ?>

    <p class="text-muted">Select the items you want to completely delete from the database. <b>This action cannot be undone.</b> Super Admins and Operators will not be deleted unless explicitly selected.</p>

    <form method="POST" onsubmit="return confirm('Are you absolutely sure you want to permanently delete this data?');">
        
        <div class="danger-zone mb-4">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="wipe_users" id="wipe_users" checked>
                <label class="form-check-label fw-bold text-dark" for="wipe_users">
                    Delete All Users (Subscribers)
                </label>
                <div class="small text-muted">Deletes panel users, active sessions, and FreeRADIUS (radcheck, radacct) data.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="wipe_routers" id="wipe_routers" checked>
                <label class="form-check-label fw-bold text-dark" for="wipe_routers">
                    Delete All Routers (NAS)
                </label>
                <div class="small text-muted">Deletes all MikroTik routers and unassigns them from Dealers.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="wipe_packages" id="wipe_packages" checked>
                <label class="form-check-label fw-bold text-dark" for="wipe_packages">
                    Delete All Packages
                </label>
                <div class="small text-muted">Deletes all Internet Packages, Dealer Prices, and RADIUS groups.</div>
            </div>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="wipe_dealers" id="wipe_dealers">
                <label class="form-check-label fw-bold text-dark" for="wipe_dealers">
                    Delete All Dealers (Optional)
                </label>
                <div class="small text-muted">Deletes all dealers, their balances, and history.</div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold text-danger">Type "CLEANUP" to confirm:</label>
            <input type="text" name="reset_keyword" class="form-control border-danger" required placeholder="Type CLEANUP here">
        </div>

        <button type="submit" name="confirm_reset" class="btn btn-danger w-100 fw-bold">Permanent Delete Selected Data</button>
    </form>
</div>

</body>
</html>
