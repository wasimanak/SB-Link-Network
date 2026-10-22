<?php
// =========================================================================
// SB Link Network - Staff Salary & Advance Ledger Engine
// Handles: Monthly Salary auto-accrual (1st of month), Advances, Remaining
// Salary calculation, Red minus balance for excess advance, and auto-logging
// to the Daily Expenses module.
// =========================================================================

if (!function_exists('init_staff_salary_schema')) {
    function init_staff_salary_schema($pdo) {
        static $initialized = false;
        if ($initialized) return;

        try {
            // 1. Add monthly_salary to recovery_men
            $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN `monthly_salary` INT NOT NULL DEFAULT 0");
        } catch (Exception $e) {}

        try {
            // 2. Add monthly_salary to linemen
            $pdo->exec("ALTER TABLE `linemen` ADD COLUMN `monthly_salary` INT NOT NULL DEFAULT 0");
        } catch (Exception $e) {}

        try {
            // 3. Add monthly_salary to managers
            $pdo->exec("ALTER TABLE `managers` ADD COLUMN `monthly_salary` INT NOT NULL DEFAULT 0");
        } catch (Exception $e) {}

        try {
            // 4. Create staff_salary_ledger table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `staff_salary_ledger` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `client_id` INT NOT NULL,
                `staff_type` ENUM('recoveryman', 'lineman', 'manager', 'staff') NOT NULL,
                `staff_id` INT NOT NULL,
                `staff_name` VARCHAR(100) NOT NULL,
                `trans_type` ENUM('salary_accrual', 'advance_payment', 'salary_payment', 'bonus', 'deduction') NOT NULL,
                `amount` INT NOT NULL,
                `direction` ENUM('credit', 'debit') NOT NULL,
                `balance_after` INT NOT NULL,
                `payment_method` VARCHAR(50) DEFAULT 'Cash',
                `reference_no` VARCHAR(100) DEFAULT NULL,
                `expense_id` INT DEFAULT NULL,
                `notes` TEXT DEFAULT NULL,
                `trans_date` DATE NOT NULL,
                `created_by` VARCHAR(100) DEFAULT 'Operator',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_staff_lookup` (`client_id`, `staff_type`, `staff_id`),
                INDEX `idx_trans_date` (`trans_date`),
                INDEX `idx_exp_id` (`expense_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $e) {}

        $initialized = true;
    }
}

/**
 * Auto-Accrual of Monthly Salary on 1st of every month
 * Checks if the salary for month YYYY-MM has already been credited.
 * If not, automatically credits the monthly_salary to staff ledger.
 */
if (!function_exists('sync_staff_monthly_salary')) {
    function sync_staff_monthly_salary($pdo, $client_id, $staff_type, $staff_id, $staff_name, $monthly_salary, $joined_date = null) {
        init_staff_salary_schema($pdo);

        $salary = (int)$monthly_salary;
        if ($salary <= 0 || empty($staff_id) || empty($client_id)) {
            return;
        }

        // Determine starting month (max 6 months back or joined month)
        $startTs = !empty($joined_date) ? strtotime($joined_date) : strtotime('-2 months');
        $currentTs = strtotime(date('Y-m-01'));

        // Normalize startTs to 1st of that month
        $iterTs = strtotime(date('Y-m-01', $startTs));
        // Don't go back more than 6 months
        $minTs = strtotime(date('Y-m-01', strtotime('-6 months')));
        if ($iterTs < $minTs) {
            $iterTs = $minTs;
        }

        while ($iterTs <= $currentTs) {
            $monthKey = date('Y-m', $iterTs);
            $monthDate = date('Y-m-01', $iterTs);

            // Check if salary for this month is already posted
            $chk = $pdo->prepare("SELECT id FROM staff_salary_ledger 
                WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
                  AND trans_type = 'salary_accrual' 
                  AND DATE_FORMAT(trans_date, '%Y-%m') = ? LIMIT 1");
            $chk->execute([$client_id, $staff_type, $staff_id, $monthKey]);
            $alreadyExists = $chk->fetchColumn();

            if (!$alreadyExists) {
                // Fetch current latest balance
                $balStmt = $pdo->prepare("SELECT balance_after FROM staff_salary_ledger 
                    WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
                    ORDER BY trans_date DESC, id DESC LIMIT 1");
                $balStmt->execute([$client_id, $staff_type, $staff_id]);
                $currBal = (int)$balStmt->fetchColumn(); // 0 if none

                $newBal = $currBal + $salary;
                $monthName = date('F Y', $iterTs);
                $note = "Monthly Salary for {$monthName} (1st of month auto-accrual)";

                $ins = $pdo->prepare("INSERT INTO staff_salary_ledger 
                    (client_id, staff_type, staff_id, staff_name, trans_type, amount, direction, balance_after, trans_date, notes, created_by)
                    VALUES (?, ?, ?, ?, 'salary_accrual', ?, 'credit', ?, ?, ?, 'System Auto-Salary')");
                $ins->execute([$client_id, $staff_type, $staff_id, $staff_name, $salary, $newBal, $monthDate, $note]);
            }

            // Advance to next month
            $iterTs = strtotime('+1 month', $iterTs);
        }
    }
}

/**
 * Fetch staff current balance and summary stats
 */
if (!function_exists('get_staff_salary_summary')) {
    function get_staff_salary_summary($pdo, $client_id, $staff_type, $staff_id, $monthly_salary = 0) {
        init_staff_salary_schema($pdo);

        // Latest balance
        $balStmt = $pdo->prepare("SELECT balance_after FROM staff_salary_ledger 
            WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
            ORDER BY trans_date DESC, id DESC LIMIT 1");
        $balStmt->execute([$client_id, $staff_type, $staff_id]);
        $latestBal = $balStmt->fetchColumn();
        $balance = ($latestBal !== false) ? (int)$latestBal : 0;

        // Current month totals (paid & advance)
        $curMonth = date('Y-m');
        $paidMonthStmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS paid_month,
                COALESCE(SUM(CASE WHEN trans_type = 'advance_payment' THEN amount ELSE 0 END), 0) AS advance_month,
                COALESCE(SUM(CASE WHEN trans_type = 'salary_payment' THEN amount ELSE 0 END), 0) AS salary_month
            FROM staff_salary_ledger 
            WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
              AND DATE_FORMAT(trans_date, '%Y-%m') = ?");
        $paidMonthStmt->execute([$client_id, $staff_type, $staff_id, $curMonth]);
        $mStats = $paidMonthStmt->fetch(PDO::FETCH_ASSOC);

        // Lifetime paid
        $lifeStmt = $pdo->prepare("SELECT 
                COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS lifetime_paid,
                COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS lifetime_earned
            FROM staff_salary_ledger 
            WHERE client_id = ? AND staff_type = ? AND staff_id = ?");
        $lifeStmt->execute([$client_id, $staff_type, $staff_id]);
        $lStats = $lifeStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'balance'               => $balance, // > 0: Payable, < 0: Excess advance taken
            'monthly_salary'        => (int)$monthly_salary,
            'paid_this_month'       => (int)($mStats['paid_month'] ?? 0),
            'advance_this_month'    => (int)($mStats['advance_month'] ?? 0),
            'salary_paid_this_month'=> (int)($mStats['salary_month'] ?? 0),
            'lifetime_paid'         => (int)($lStats['lifetime_paid'] ?? 0),
            'lifetime_earned'       => (int)($lStats['lifetime_earned'] ?? 0)
        ];
    }
}

/**
 * Record a payment given to staff (Advance or Salary)
 * Automatically writes to both staff_salary_ledger AND expenses table!
 */
if (!function_exists('record_staff_payment')) {
    function record_staff_payment($pdo, $client_id, $staff_type, $staff_id, $staff_name, $postData, $actor_name = 'Operator') {
        init_staff_salary_schema($pdo);

        $amount       = (int)($postData['amount'] ?? 0);
        $trans_type   = in_array($postData['trans_type'] ?? '', ['advance_payment', 'salary_payment', 'bonus', 'deduction']) ? $postData['trans_type'] : 'advance_payment';
        $payment_date = !empty($postData['trans_date']) ? trim($postData['trans_date']) : date('Y-m-d');
        $payment_method = trim($postData['payment_method'] ?? 'Cash');
        $reference_no = trim($postData['reference_no'] ?? '');
        $notes        = trim($postData['notes'] ?? '');

        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Please enter a valid amount greater than 0.'];
        }

        // Determine direction: bonus is credit (+), payments & deductions are debit (-)
        $direction = ($trans_type === 'bonus') ? 'credit' : 'debit';

        // Fetch latest balance
        $balStmt = $pdo->prepare("SELECT balance_after FROM staff_salary_ledger 
            WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
            ORDER BY trans_date DESC, id DESC LIMIT 1");
        $balStmt->execute([$client_id, $staff_type, $staff_id]);
        $currBal = (int)$balStmt->fetchColumn();

        $newBal = ($direction === 'credit') ? ($currBal + $amount) : ($currBal - $amount);

        // Auto-Insert into Daily Expenses table if actual cash was paid out (Advance or Salary Payment)
        $expense_id = null;
        if (in_array($trans_type, ['advance_payment', 'salary_payment'])) {
            $typeLabel = ($trans_type === 'advance_payment') ? 'Advance Salary' : 'Salary Payout';
            $staffTypeTitle = ucfirst($staff_type);
            $expTitle = "Staff {$typeLabel}: {$staff_name} ({$staffTypeTitle})";
            $expCat   = 'Staff Salary & Advance';

            try {
                $insExp = $pdo->prepare("INSERT INTO expenses 
                    (client_id, category, title, amount, expense_date, payment_method, reference_no, payee, notes, created_by, created_by_type, created_by_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Operator', ?)");
                $insExp->execute([
                    $client_id, $expCat, $expTitle, $amount, $payment_date, $payment_method, $reference_no,
                    $staff_name, $notes ?: "Paid {$typeLabel} to {$staff_name}", $actor_name, $client_id
                ]);
                $expense_id = (int)$pdo->lastInsertId();
            } catch (Exception $e) {
                // If expenses insert fails, continue recording in ledger
            }
        }

        // Insert into staff_salary_ledger
        $ins = $pdo->prepare("INSERT INTO staff_salary_ledger 
            (client_id, staff_type, staff_id, staff_name, trans_type, amount, direction, balance_after, payment_method, reference_no, expense_id, notes, trans_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $client_id, $staff_type, $staff_id, $staff_name, $trans_type, $amount, $direction,
            $newBal, $payment_method, $reference_no, $expense_id, $notes, $payment_date, $actor_name
        ]);

        // Activity log
        try {
            $typeText = ($trans_type === 'advance_payment') ? 'Advance' : (($trans_type === 'salary_payment') ? 'Salary' : ucfirst($trans_type));
            $act = "Recorded Staff {$typeText}: Rs. " . number_format($amount, 0) . " for {$staff_name} ({$staff_type})";
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Staff Salary', 'Payroll', ?)")
                ->execute([$client_id, $actor_name, $act]);
        } catch (Exception $e) {}

        return ['success' => true, 'message' => "Payment of Rs. " . number_format($amount, 0) . " recorded successfully!"];
    }
}

/**
 * Delete a ledger entry and sync back linked expenses & recalculate balances
 */
if (!function_exists('delete_staff_transaction')) {
    function delete_staff_transaction($pdo, $client_id, $entry_id, $actor_name = 'Operator') {
        init_staff_salary_schema($pdo);

        $chk = $pdo->prepare("SELECT * FROM staff_salary_ledger WHERE id = ? AND client_id = ?");
        $chk->execute([(int)$entry_id, $client_id]);
        $entry = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            return ['success' => false, 'message' => 'Ledger record not found.'];
        }

        // If linked to an expense, delete the expense record
        if (!empty($entry['expense_id'])) {
            try {
                $pdo->prepare("DELETE FROM expenses WHERE id = ? AND client_id = ?")
                    ->execute([(int)$entry['expense_id'], $client_id]);
            } catch (Exception $e) {}
        }

        // Delete ledger entry
        $pdo->prepare("DELETE FROM staff_salary_ledger WHERE id = ? AND client_id = ?")
            ->execute([(int)$entry_id, $client_id]);

        // Recalculate subsequent balances in chronological order
        $staff_type = $entry['staff_type'];
        $staff_id   = $entry['staff_id'];

        $rowsStmt = $pdo->prepare("SELECT id, amount, direction FROM staff_salary_ledger 
            WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
            ORDER BY trans_date ASC, id ASC");
        $rowsStmt->execute([$client_id, $staff_type, $staff_id]);
        $allRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $running = 0;
        $upd = $pdo->prepare("UPDATE staff_salary_ledger SET balance_after = ? WHERE id = ?");
        foreach ($allRows as $r) {
            if ($r['direction'] === 'credit') {
                $running += (int)$r['amount'];
            } else {
                $running -= (int)$r['amount'];
            }
            $upd->execute([$running, $r['id']]);
        }

        return ['success' => true, 'message' => 'Ledger entry deleted and balances updated.'];
    }
}
