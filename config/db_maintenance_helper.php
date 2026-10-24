<?php
// Path: /config/db_maintenance_helper.php
// Centralized Database Maintenance & Auto-Prune Engine

function run_database_maintenance($pdo, $auth_retention_days = 7, $acct_retention_days = 30, $ghost_session_days = 2) {
    $startTime = microtime(true);
    $result = [
        'auth_deleted' => 0,
        'acct_deleted' => 0,
        'ghost_closed' => 0,
        'optimized_tables' => [],
        'duration_seconds' => 0,
        'timestamp' => date('Y-m-d H:i:s'),
        'errors' => []
    ];

    $auth_retention_days = max(1, intval($auth_retention_days));
    $acct_retention_days = max(1, intval($acct_retention_days));
    $ghost_session_days  = max(1, intval($ghost_session_days));

    // 1. Ensure high-speed indexes exist for fast deletions without table locking
    try {
        $pdo->exec("ALTER TABLE radpostauth ADD INDEX IF NOT EXISTS authdate_idx (authdate)");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acctstoptime_idx (acctstoptime)");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acctstarttime_idx (acctstarttime)");
    } catch (Exception $e) {}

    // 2. Prune radpostauth (delete authentication logs older than X days)
    // FreeRADIUS never reads old auth logs; keeping only recent logs keeps DB tiny
    try {
        $stmt = $pdo->prepare("DELETE FROM radpostauth WHERE authdate < NOW() - INTERVAL ? DAY");
        $stmt->execute([$auth_retention_days]);
        $result['auth_deleted'] = $stmt->rowCount();
    } catch (Exception $e) {
        $result['errors'][] = "radpostauth prune error: " . $e->getMessage();
    }

    // 3. Prune radacct closed sessions (older than X days)
    // CRITICAL: We NEVER delete active sessions (where acctstoptime IS NULL)
    try {
        $stmt = $pdo->prepare("DELETE FROM radacct WHERE acctstoptime IS NOT NULL AND acctstoptime < NOW() - INTERVAL ? DAY");
        $stmt->execute([$acct_retention_days]);
        $result['acct_deleted'] = $stmt->rowCount();
    } catch (Exception $e) {
        $result['errors'][] = "radacct prune error: " . $e->getMessage();
    }

    // 4. Close Ghost/Zombie Sessions
    // Sessions where router rebooted/power cut occurred without sending Stop packet
    try {
        $stmt = $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset-Ghost' WHERE acctstoptime IS NULL AND acctstarttime < NOW() - INTERVAL ? DAY");
        $stmt->execute([$ghost_session_days]);
        $result['ghost_closed'] = $stmt->rowCount();
    } catch (Exception $e) {
        $result['errors'][] = "ghost sessions error: " . $e->getMessage();
    }

    // 4b. Activity Logs Retention:
    // Login activities: strictly 7 Days retention
    // Financial & administrative actions: preserved for 2 Years / 730 Days
    // CRITICAL: Financial records (user_ledger, expenses, staff_salary_ledger, invoices)
    // are STRICTLY PRESERVED for at least 2 Years (730 Days) and NEVER touched by ephemeral session cleanup.
    try {
        $stmtLogin = $pdo->prepare("DELETE FROM activity_logs WHERE (activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') AND created_at < NOW() - INTERVAL 7 DAY");
        $stmtLogin->execute();
        $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 730 DAY");
        $stmt->execute();
        $result['activity_logs_pruned'] = $stmt->rowCount() + $stmtLogin->rowCount();
    } catch (Exception $e) {}

    // 5. Defragment & Optimize Tables (reclaims deleted disk space back to operating system)
    $tablesToOptimize = ['radpostauth', 'radacct', 'radcheck', 'subscribers'];
    foreach ($tablesToOptimize as $tbl) {
        try {
            $pdo->exec("OPTIMIZE TABLE `{$tbl}`");
            $result['optimized_tables'][] = $tbl;
        } catch (Exception $e) {
            // Some MySQL storage engines don't require optimize or return note
        }
    }

    $result['duration_seconds'] = round(microtime(true) - $startTime, 3);

    // 6. Record run stats in global_settings for SuperAdmin UI
    try {
        $lastRunJson = json_encode([
            'timestamp'    => $result['timestamp'],
            'auth_deleted' => $result['auth_deleted'],
            'acct_deleted' => $result['acct_deleted'],
            'ghost_closed' => $result['ghost_closed'],
            'duration'     => $result['duration_seconds'],
            'auth_days'    => $auth_retention_days,
            'acct_days'    => $acct_retention_days,
            'ghost_days'   => $ghost_session_days
        ]);
        $upStmt = $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $upStmt->execute(['db_maintenance_last_run', $result['timestamp'], $result['timestamp']]);
        $upStmt->execute(['db_maintenance_last_stats', $lastRunJson, $lastRunJson]);
    } catch (Exception $e) {}

    return $result;
}
