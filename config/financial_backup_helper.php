<?php
// =========================================================================
// Financial Backup Engine & Multi-Sheet SpreadsheetML Generator
// 30-Day Auto-Retention, Multi-Tenant Isolation, 1-Click Disaster Recovery
// =========================================================================

if (!defined('FINANCIAL_BACKUP_RETENTION_DAYS')) {
    define('FINANCIAL_BACKUP_RETENTION_DAYS', 30);
}

/**
 * Helper to identify who collected/recorded a transaction from its description
 */
if (!function_exists('parse_transaction_collector')) {
    function parse_transaction_collector($desc) {
        $desc = (string)$desc;
        // 1. Manager: Name
        if (preg_match('/(?:by Manager:\s*|Manager:\s*)([^\s,\(\)]+)/i', $desc, $m)) {
            return ['name' => trim($m[1]), 'role' => 'Manager'];
        }
        // 2. Name RM (e.g. Saleem Balouch RM, Shabir RM, yasir munir RM)
        if (preg_match('/(?:collected by|by)\s+([A-Za-z0-9_\s]+?)\s+RM\b/i', $desc, $m)) {
            return ['name' => trim($m[1]), 'role' => 'Operator'];
        }
        // 3. RM: username
        if (preg_match('/(?:RM:\s*)([^\s,\(\)]+)/i', $desc, $m)) {
            return ['name' => trim($m[1]), 'role' => 'Operator'];
        }
        // 4. Admin
        if (preg_match('/(?:by Admin|Admin\s*\([^\)]+\)|Admin)/i', $desc)) {
            return ['name' => 'Admin', 'role' => 'Admin'];
        }
        return ['name' => 'System / Staff', 'role' => 'Staff'];
    }
}

/**
 * Reliable cross-platform directory write check (bypasses Windows NTFS is_writable bug)
 */
if (!function_exists('is_dir_actually_writable')) {
    function is_dir_actually_writable($dir) {
        if (!is_dir($dir)) return false;
        $testFile = rtrim($dir, '/\\') . '/.wtest_' . bin2hex(random_bytes(4));
        $written = @file_put_contents($testFile, '1');
        if ($written !== false) {
            @unlink($testFile);
            return true;
        }
        return false;
    }
}

/**
 * Get all possible storage candidate directories for a client.
 * 1. uploads/backups_storage/client_{id} (Preferred because uploads/ is already owned/writable by www-data on VPS)
 * 2. backups_storage/client_{id} (Root-level fallback)
 */
if (!function_exists('get_financial_backup_storage_candidates')) {
    function get_financial_backup_storage_candidates($client_id) {
        $client_id = (int)$client_id;
        return [
            dirname(__DIR__) . '/uploads/backups_storage/client_' . $client_id,
            dirname(__DIR__) . '/backups_storage/client_' . $client_id
        ];
    }
}

/**
 * Get or create the isolated secure backup storage directory for a client.
 * Automatically chooses the best writable directory between uploads/ and root.
 * Includes .htaccess and index.php to block direct HTTP web access.
 */
if (!function_exists('get_financial_backup_storage_dir')) {
    function get_financial_backup_storage_dir($client_id) {
        $client_id = (int)$client_id;
        $candidates = get_financial_backup_storage_candidates($client_id);
        
        $selected = null;
        // Check if an existing candidate is already writable
        foreach ($candidates as $dir) {
            if (is_dir_actually_writable($dir)) {
                $selected = $dir;
                break;
            }
        }
        
        // If neither exists and is writable, try creating them (starting with uploads/ which is standard www-data writable)
        if (!$selected) {
            foreach ($candidates as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0777, true);
                    @chmod($dir, 0777);
                }
                if (is_dir_actually_writable($dir)) {
                    $selected = $dir;
                    break;
                }
            }
        }
        
        // Default to uploads/ candidate
        if (!$selected) {
            $selected = $candidates[0];
            if (!is_dir($selected)) {
                @mkdir($selected, 0777, true);
                @chmod($selected, 0777);
            }
        }
        
        // Strict Apache Access Control: block direct download of data files
        $htaccess = $selected . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "# Protected Financial Backups Storage\n<FilesMatch \"\\.(xls|xml|csv|json|sql|dump|bak|gz|tar|zip)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n");
        }
        
        // Block directory listing
        $index = $selected . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php header('HTTP/1.0 403 Forbidden'); exit('Access Denied');\n");
        }
        
        return $selected;
    }
}

/**
 * Fetch complete financial dataset for a client
 */
if (!function_exists('fetch_client_financial_dataset')) {
    function fetch_client_financial_dataset($pdo, $client_id) {
        $client_id = (int)$client_id;

        // 1. Client / Tenant Profile
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $client['id'] = $client_id;
        $client['company_name'] = !empty($client['company_name']) ? $client['company_name'] : 'SB Link Network';
        $client['full_name'] = !empty($client['full_name']) ? $client['full_name'] : (!empty($client['name']) ? $client['name'] : 'Operator');
        $client['city'] = !empty($client['city']) ? $client['city'] : 'Head Office';

        // 2. All User Ledger Payments & Collections
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.client_id,
                l.username,
                l.type,
                l.amount,
                l.balance_after,
                l.description,
                l.created_at,
                s.full_name as subscriber_name,
                s.mobile as subscriber_mobile,
                s.status as subscriber_status,
                s.balance as current_balance,
                p.name as package_name,
                p.price as package_price
            FROM user_ledger l
            LEFT JOIN subscribers s ON l.username = s.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE l.client_id = ?
            ORDER BY l.created_at DESC, l.id DESC
        ");
        $stmt->execute([$client_id]);
        $all_ledgers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Separate Actual Money Collections (Credit) from Package Charges (Debit)
        $user_collections = [];
        $user_charges     = [];
        $total_collected_amount = 0;

        foreach ($all_ledgers as &$ul) {
            $collector = parse_transaction_collector($ul['description']);
            $ul['collector_name'] = $collector['name'];
            $ul['collector_role'] = $collector['role'];

            if ($ul['type'] === 'credit') {
                $user_collections[] = $ul;
                $total_collected_amount += (float)$ul['amount'];
            } else {
                $user_charges[] = $ul;
            }
        }
        unset($ul);

        // 3. All Managers & Their Lifetime Financial Records
        $stmt = $pdo->prepare("SELECT * FROM managers WHERE client_id = ? ORDER BY id ASC");
        $stmt->execute([$client_id]);
        $raw_managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $managers = [];

        foreach ($raw_managers as $mgr) {
            $m_id   = (int)$mgr['id'];
            $m_name = trim($mgr['full_name']);
            $m_user = trim($mgr['username']);

            // Fetch manager transactions from activity logs
            $stmtAct = $pdo->prepare("
                SELECT id, by_user, by_role, against_to, against_role, activity, created_at 
                FROM activity_logs 
                WHERE client_id = ? 
                  AND (
                      manager_id = ? 
                      OR (by_role = 'Manager' AND (by_user = ? OR by_user = ?))
                      OR (against_role = 'Manager' AND against_to = ?)
                  )
                ORDER BY created_at DESC, id DESC
            ");
            $stmtAct->execute([$client_id, $m_id, $m_user, $m_name, $m_name]);
            $mgr_acts = $stmtAct->fetchAll(PDO::FETCH_ASSOC);

            // Filter collections made by this manager from user_collections
            $mgr_cols = [];
            $mgr_total_collected = 0;
            foreach ($user_collections as $uc) {
                $desc = $uc['description'];
                $match = false;
                if (!empty($m_name) && (stripos($desc, $m_name) !== false || strcasecmp($uc['collector_name'], $m_name) === 0)) {
                    $match = true;
                } elseif (!empty($m_user) && (stripos($desc, $m_user) !== false || strcasecmp($uc['collector_name'], $m_user) === 0)) {
                    $match = true;
                }
                if ($match) {
                    $mgr_cols[] = $uc;
                    $mgr_total_collected += (float)$uc['amount'];
                }
            }

            $mgr['activities']       = $mgr_acts;
            $mgr['collections']      = $mgr_cols;
            $mgr['total_collected']  = $mgr_total_collected;
            $managers[] = $mgr;
        }

        // 4. Staff Recovery Men (Operators) & Their Individual Recoveries
        $stmt = $pdo->prepare("SELECT * FROM recovery_men WHERE client_id = ? ORDER BY id ASC");
        $stmt->execute([$client_id]);
        $raw_recovery_men = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $recovery_men = [];

        foreach ($raw_recovery_men as $rm) {
            $rm_id   = (int)$rm['id'];
            $rm_name = trim($rm['full_name']);
            $rm_user = trim($rm['username']);

            // Filter recoveries made by this specific RecoveryMan from user_collections
            $rm_cols = [];
            $rm_total_collected = 0;

            foreach ($user_collections as $uc) {
                $desc = $uc['description'];
                $match = false;
                if (!empty($rm_name) && (stripos($desc, $rm_name) !== false || strcasecmp($uc['collector_name'], $rm_name) === 0)) {
                    $match = true;
                } elseif (!empty($rm_user) && (stripos($desc, $rm_user) !== false || strcasecmp($uc['collector_name'], $rm_user) === 0)) {
                    $match = true;
                }
                if ($match) {
                    $rm_cols[] = $uc;
                    $rm_total_collected += (float)$uc['amount'];
                }
            }

            // Also fetch cash submissions to Admin / Manager from activity_logs
            $stmtRmAct = $pdo->prepare("
                SELECT id, by_user, by_role, against_to, against_role, activity, created_at 
                FROM activity_logs 
                WHERE client_id = ? 
                  AND against_role = 'RecoveryMan' 
                  AND (against_to = ? OR against_to = ?)
                ORDER BY created_at DESC, id DESC
            ");
            $stmtRmAct->execute([$client_id, $rm_name, $rm_user]);
            $rm_handovers = $stmtRmAct->fetchAll(PDO::FETCH_ASSOC);

            $rm['collections']     = $rm_cols;
            $rm['handovers']       = $rm_handovers;
            $rm['total_collected'] = $rm_total_collected;
            $recovery_men[]        = $rm;
        }

        // 5. Staff Line Men (Technicians)
        $stmt = $pdo->prepare("SELECT * FROM linemen WHERE client_id = ? ORDER BY id ASC");
        $stmt->execute([$client_id]);
        $linemen = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Staff Salary & Advances Ledger
        $staff_salaries = [];
        try {
            $stmt = $pdo->prepare("
                SELECT id, staff_type, staff_id, staff_name, trans_type, amount, direction, balance_after, payment_method, reference_no, expense_id, notes, trans_date, created_by, created_at
                FROM staff_salary_ledger
                WHERE client_id = ?
                ORDER BY trans_date DESC, id DESC
            ");
            $stmt->execute([$client_id]);
            $staff_salaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // 7. Daily Business Expenses
        $expenses = [];
        try {
            $stmt = $pdo->prepare("
                SELECT id, category, title, amount, expense_date, payment_method, reference_no, payee, notes, created_by, created_at
                FROM expenses
                WHERE client_id = ?
                ORDER BY expense_date DESC, id DESC
            ");
            $stmt->execute([$client_id]);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // 8. Consolidated Master Journal
        $all_transactions = [];

        // Add user collections (Money In - Credit)
        foreach ($user_collections as $ul) {
            $all_transactions[] = [
                'trans_date'   => $ul['created_at'],
                'ref_id'       => 'UL-' . $ul['id'],
                'category'     => 'Subscriber Payment (Collection)',
                'entity_type'  => 'Subscriber',
                'entity_name'  => (!empty($ul['subscriber_name']) ? $ul['subscriber_name'] : $ul['username']),
                'entity_sub'   => '@' . $ul['username'],
                'description'  => $ul['description'] ?: 'Payment received',
                'credit'       => (float)$ul['amount'],
                'debit'        => 0,
                'balance_after'=> (float)$ul['balance_after'],
                'recorded_by'  => $ul['collector_name'] ? ($ul['collector_name'] . ' (' . $ul['collector_role'] . ')') : 'Staff'
            ];
        }

        // Add user charges (Billing deductions - Debit)
        foreach ($user_charges as $ul) {
            $all_transactions[] = [
                'trans_date'   => $ul['created_at'],
                'ref_id'       => 'CHG-' . $ul['id'],
                'category'     => 'Package Renewal / Account Billing',
                'entity_type'  => 'Subscriber',
                'entity_name'  => (!empty($ul['subscriber_name']) ? $ul['subscriber_name'] : $ul['username']),
                'entity_sub'   => '@' . $ul['username'],
                'description'  => $ul['description'] ?: 'Package Renewal Fee',
                'credit'       => 0,
                'debit'        => (float)$ul['amount'],
                'balance_after'=> (float)$ul['balance_after'],
                'recorded_by'  => 'System'
            ];
        }

        // Add staff salary transactions
        foreach ($staff_salaries as $sl) {
            $is_payout = ($sl['trans_type'] === 'salary_payment' || $sl['trans_type'] === 'advance_payment');
            $all_transactions[] = [
                'trans_date'   => $sl['trans_date'] . ' ' . date('H:i:s', strtotime($sl['created_at'])),
                'ref_id'       => 'SAL-' . $sl['id'],
                'category'     => 'Staff Payroll (' . ucfirst($sl['trans_type']) . ')',
                'entity_type'  => ucfirst($sl['staff_type']),
                'entity_name'  => $sl['staff_name'],
                'entity_sub'   => $sl['payment_method'] ?: 'Cash',
                'description'  => ($sl['notes'] ?: "Staff " . str_replace('_', ' ', $sl['trans_type'])),
                'credit'       => 0,
                'debit'        => $is_payout ? (float)$sl['amount'] : 0,
                'balance_after'=> (float)$sl['balance_after'],
                'recorded_by'  => $sl['created_by'] ?: 'Admin'
            ];
        }

        // Add daily expenses
        foreach ($expenses as $ex) {
            $all_transactions[] = [
                'trans_date'   => $ex['expense_date'] . ' ' . date('H:i:s', strtotime($ex['created_at'])),
                'ref_id'       => 'EXP-' . $ex['id'],
                'category'     => 'Expense: ' . $ex['category'],
                'entity_type'  => 'Vendor / Payee',
                'entity_name'  => $ex['payee'] ?: 'General Vendor',
                'entity_sub'   => $ex['payment_method'] ?: 'Cash',
                'description'  => $ex['title'] . ($ex['notes'] ? " - " . $ex['notes'] : ''),
                'credit'       => 0,
                'debit'        => (float)$ex['amount'],
                'balance_after'=> 0,
                'recorded_by'  => $ex['created_by'] ?: 'Admin'
            ];
        }

        // Sort master journal strictly chronologically (newest first)
        usort($all_transactions, function($a, $b) {
            return strcmp($b['trans_date'], $a['trans_date']);
        });

        return [
            'client'                 => $client,
            'client_id'              => $client_id,
            'generated_at'           => date('Y-m-d H:i:s'),
            'user_collections'       => $user_collections,
            'user_charges'           => $user_charges,
            'user_ledgers'           => $all_ledgers,
            'total_collected_amount' => $total_collected_amount,
            'managers'               => $managers,
            'recovery_men'           => $recovery_men,
            'linemen'                => $linemen,
            'staff_salaries'         => $staff_salaries,
            'expenses'               => $expenses,
            'all_transactions'       => $all_transactions
        ];
    }
}

/**
 * Sanitize an Excel Worksheet tab name (Max 31 chars, alphanumeric/underscores)
 */
if (!function_exists('sanitize_excel_sheet_name')) {
    function sanitize_excel_sheet_name($name, &$used = []) {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:\s]+/u', '_', trim($name));
        $clean = preg_replace('/_+/', '_', $clean);
        $clean = trim($clean, '_');
        if (empty($clean)) $clean = 'Sheet';
        $sheet = mb_substr($clean, 0, 28, 'UTF-8');
        
        $final = $sheet;
        $counter = 1;
        while (isset($used[strtolower($final)])) {
            $counter++;
            $suffix = '_' . $counter;
            $final = mb_substr($sheet, 0, 31 - strlen($suffix), 'UTF-8') . $suffix;
        }
        $used[strtolower($final)] = true;
        return $final;
    }
}

/**
 * Escape XML data safely for SpreadsheetML
 */
if (!function_exists('xml_escape')) {
    function xml_escape($str) {
        if ($str === null) return '';
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

/**
 * Generate Multi-Sheet Microsoft Excel 2003 XML Workbook (SpreadsheetML)
 * Supports multiple sheets, cell formatting, colors, and auto-filters.
 * Opens seamlessly in MS Excel, Google Sheets, LibreOffice, and mobile apps.
 */
if (!function_exists('build_multisheet_excel_xml')) {
    function build_multisheet_excel_xml($data) {
        $company = !empty($data['client']['company_name']) ? $data['client']['company_name'] : 'SB Link Network';
        $used_sheet_names = [];

        ob_start();
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title><?= xml_escape($company) ?> - Comprehensive Financial Backup</Title>
  <Author><?= xml_escape($company) ?></Author>
  <Created><?= date('Y-m-d\TH:i:s\Z') ?></Created>
  <Company><?= xml_escape($company) ?></Company>
 </DocumentProperties>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Center"/>
   <Borders/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#1E293B"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <!-- Main Banner Header -->
  <Style ss:ID="CompanyBanner">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="15" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#1E3A8A" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="SheetSubtitle">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#2563EB" ss:Pattern="Solid"/>
  </Style>
  <!-- Table Header -->
  <Style ss:ID="TableHeader">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#0F172A" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#334155"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#334155"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#334155"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#334155"/>
   </Borders>
  </Style>
  <!-- Standard Cells -->
  <Style ss:ID="CellLeft">
   <Alignment ss:Horizontal="Left" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
  </Style>
  <Style ss:ID="CellCenter">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
  </Style>
  <Style ss:ID="CellCurrency">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10"/>
   <NumberFormat ss:Format="#,##0.00"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
  </Style>
  <Style ss:ID="CellCredit">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#15803D"/>
   <Interior ss:Color="#DCFCE7" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="#,##0.00"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
  </Style>
  <Style ss:ID="CellDebit">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#B91C1C"/>
   <Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="#,##0.00"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E2E8F0"/>
   </Borders>
  </Style>
  <Style ss:ID="TotalRow">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#0F172A"/>
   <Interior ss:Color="#E2E8F0" ss:Pattern="Solid"/>
   <NumberFormat ss:Format="#,##0.00"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#000000"/>
    <Border ss:Position="Bottom" ss:LineStyle="Double" ss:Weight="3" ss:Color="#000000"/>
   </Borders>
  </Style>
 </Styles>

 <!-- ================================================================= -->
 <!-- SHEET 1: ALL FINANCIAL TRANSACTIONS (MASTER JOURNAL) -->
 <!-- ================================================================= -->
 <?php $sheetName = sanitize_excel_sheet_name("All_Transactions", $used_sheet_names); ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="130"/>
   <Column ss:Width="80"/>
   <Column ss:Width="140"/>
   <Column ss:Width="140"/>
   <Column ss:Width="90"/>
   <Column ss:Width="260"/>
   <Column ss:Width="105"/>
   <Column ss:Width="105"/>
   <Column ss:Width="105"/>
   <Column ss:Width="120"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="9" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - MASTER FINANCIAL JOURNAL</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="9" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Snapshot: <?= xml_escape($data['generated_at']) ?> | Client ID: <?= (int)$data['client_id'] ?> | Total Collected: Rs. <?= number_format($data['total_collected_amount'], 2) ?></Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="9"><Data ss:Type="String"></Data></Cell></Row>

   <!-- Header -->
   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Date &amp; Time</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Trx Ref</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Category</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Entity Name</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Entity Detail</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Transaction Description</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Money In (Credit)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Money Out (Debit)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Balance After</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Recorded By</Data></Cell>
   </Row>

   <?php
   $totCredit = 0;
   $totDebit = 0;
   foreach ($data['all_transactions'] as $t):
       $totCredit += $t['credit'];
       $totDebit  += $t['debit'];
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($t['trans_date']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($t['ref_id']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($t['category']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($t['entity_name']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($t['entity_sub']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($t['description']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $t['credit'] > 0 ? 'CellCredit' : 'CellCurrency' ?>"><Data ss:Type="Number"><?= (float)$t['credit'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $t['debit'] > 0 ? 'CellDebit' : 'CellCurrency' ?>"><Data ss:Type="Number"><?= (float)$t['debit'] ?></Data></Cell>
    <Cell ss:StyleID="CellCurrency"><Data ss:Type="Number"><?= (float)$t['balance_after'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($t['recorded_by']) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="5" ss:StyleID="TotalRow"><Data ss:Type="String">TOTALS (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totCredit ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totDebit ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)($totCredit - $totDebit) ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="String">Net Cash Flow</Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ================================================================= -->
 <!-- SHEET 2: USER COLLECTIONS (SUBSCRIBER PAYMENTS RECEIVED) -->
 <!-- ================================================================= -->
 <?php $sheetName = sanitize_excel_sheet_name("User_Collections", $used_sheet_names); ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="65"/>
   <Column ss:Width="130"/>
   <Column ss:Width="110"/>
   <Column ss:Width="140"/>
   <Column ss:Width="105"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Column ss:Width="95"/>
   <Column ss:Width="140"/>
   <Column ss:Width="260"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="9" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - SUBSCRIBER COLLECTIONS &amp; PAYMENTS</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="9" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Received Cash &amp; Online Payments from Customers (Total: Rs. <?= number_format($data['total_collected_amount'], 2) ?>)</Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="9"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Trx ID</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Date &amp; Time</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Username</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Full Name</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Mobile</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Package</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Amount Collected (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Balance After</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Recovered By / Staff</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Receipt Description</Data></Cell>
   </Row>

   <?php
   $totSubColl = 0;
   foreach ($data['user_collections'] as $ul):
       $totSubColl += (float)$ul['amount'];
       $recBy = $ul['collector_name'] ? ($ul['collector_name'] . ' (' . $ul['collector_role'] . ')') : 'Staff';
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="Number"><?= (int)$ul['id'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($ul['created_at']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ul['username']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ul['subscriber_name'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($ul['subscriber_mobile'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($ul['package_name'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellCredit"><Data ss:Type="Number"><?= (float)$ul['amount'] ?></Data></Cell>
    <Cell ss:StyleID="CellCurrency"><Data ss:Type="Number"><?= (float)$ul['balance_after'] ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($recBy) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ul['description']) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="5" ss:StyleID="TotalRow"><Data ss:Type="String">TOTAL AMOUNT COLLECTED (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totSubColl ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="TotalRow"><Data ss:Type="String"><?= count($data['user_collections']) ?> Receipts (No Duplicates)</Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ================================================================= -->
 <!-- DEDICATED WORKSHEET FOR EACH MANAGER BY NAME -->
 <!-- ================================================================= -->
 <?php foreach ($data['managers'] as $mgr): ?>
 <?php
    $mName = !empty($mgr['full_name']) ? $mgr['full_name'] : $mgr['username'];
    $sheetTitle = "Mgr_" . $mName;
    $sheetName = sanitize_excel_sheet_name($sheetTitle, $used_sheet_names);
 ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="130"/>
   <Column ss:Width="100"/>
   <Column ss:Width="130"/>
   <Column ss:Width="260"/>
   <Column ss:Width="105"/>
   <Column ss:Width="105"/>
   <Column ss:Width="110"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="6" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - MANAGER FINANCIAL STATEMENT</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="6" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Manager: <?= xml_escape($mgr['full_name']) ?> (@<?= xml_escape($mgr['username']) ?>) | Mobile: <?= xml_escape($mgr['phone'] ?: 'N/A') ?> | Cash in Hand: Rs. <?= number_format($mgr['cash_in_hand'], 0) ?></Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="6"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Date &amp; Time</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Activity Type</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Subscriber / Entity</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Description</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Collected (Credit)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Admin Handover (Debit)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Running Cash (Rs.)</Data></Cell>
   </Row>

   <?php
   $mgrTotalCredit = 0;
   $mgrTotalDebit = 0;
   
   $mgrTrans = [];
   foreach ($mgr['collections'] as $c) {
       $mgrTrans[] = [
           'date' => $c['created_at'],
           'type' => 'User Collection',
           'entity' => $c['username'] . ($c['subscriber_name'] ? " ({$c['subscriber_name']})" : ''),
           'desc' => $c['description'] ?: 'Collected subscriber recharge',
           'credit' => (float)$c['amount'],
           'debit' => 0
       ];
   }
   foreach ($mgr['activities'] as $act) {
       $actText = $act['activity'];
       if (stripos($actText, 'Collected Cash') !== false && stripos($actText, 'from Manager') !== false) {
           $amt = 0;
           if (preg_match('/Rs\.?\s*([\d,.]+)/i', $actText, $m)) {
               $amt = (float)str_replace(',', '', $m[1]);
           }
           $mgrTrans[] = [
               'date' => $act['created_at'],
               'type' => 'Handover to Admin',
               'entity' => 'Admin Handover',
               'desc' => $actText,
               'credit' => 0,
               'debit' => $amt
           ];
       } elseif (stripos($actText, 'from Operator') !== false || stripos($actText, 'from RecoveryMan') !== false) {
           $amt = 0;
           if (preg_match('/Rs\.?\s*([\d,.]+)/i', $actText, $m)) {
               $amt = (float)str_replace(',', '', $m[1]);
           }
           $mgrTrans[] = [
               'date' => $act['created_at'],
               'type' => 'Collection from RM',
               'entity' => $act['against_to'] ?: 'Operator/RM',
               'desc' => $actText,
               'credit' => $amt,
               'debit' => 0
           ];
       }
   }

   usort($mgrTrans, function($a, $b) {
       return strcmp($a['date'], $b['date']);
   });

   $runningMgrBal = 0;
   foreach ($mgrTrans as &$mt) {
       $runningMgrBal += ($mt['credit'] - $mt['debit']);
       $mt['bal'] = $runningMgrBal;
       $mgrTotalCredit += $mt['credit'];
       $mgrTotalDebit += $mt['debit'];
   }
   $mgrTrans = array_reverse($mgrTrans);

   foreach ($mgrTrans as $mt):
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($mt['date']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($mt['type']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($mt['entity']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($mt['desc']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $mt['credit'] > 0 ? 'CellCredit' : 'CellCurrency' ?>"><Data ss:Type="Number"><?= (float)$mt['credit'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $mt['debit'] > 0 ? 'CellDebit' : 'CellCurrency' ?>"><Data ss:Type="Number"><?= (float)$mt['debit'] ?></Data></Cell>
    <Cell ss:StyleID="CellCurrency"><Data ss:Type="Number"><?= (float)$mt['bal'] ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="3" ss:StyleID="TotalRow"><Data ss:Type="String">TOTALS FOR <?= xml_escape($mgr['full_name']) ?> (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$mgrTotalCredit ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$mgrTotalDebit ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$mgr['cash_in_hand'] ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
 <?php endforeach; ?>

 <!-- ================================================================= -->
 <!-- DEDICATED WORKSHEET FOR EVERY RECOVERY MAN / OPERATOR BY NAME -->
 <!-- ================================================================= -->
 <?php foreach ($data['recovery_men'] as $rm): ?>
 <?php
    $rmName = !empty($rm['full_name']) ? $rm['full_name'] : $rm['username'];
    $sheetTitle = "RM_" . $rmName;
    $sheetName = sanitize_excel_sheet_name($sheetTitle, $used_sheet_names);
    $rmCols = $rm['collections'] ?? [];
 ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="130"/>
   <Column ss:Width="105"/>
   <Column ss:Width="140"/>
   <Column ss:Width="105"/>
   <Column ss:Width="120"/>
   <Column ss:Width="110"/>
   <Column ss:Width="105"/>
   <Column ss:Width="260"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="7" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - OPERATOR RECOVERY REPORT</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="7" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Operator: <?= xml_escape($rm['full_name']) ?> (@<?= xml_escape($rm['username']) ?>) | Mobile: <?= xml_escape($rm['phone'] ?: 'N/A') ?> | Area: <?= xml_escape($rm['area'] ?: 'All') ?> | Cash in Hand: Rs. <?= number_format($rm['cash_in_hand'], 0) ?></Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="7"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Date &amp; Time</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Username</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Subscriber Name</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Contact Mobile</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Package</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Recovered (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Balance After</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Receipt Description</Data></Cell>
   </Row>

   <?php
   $totRmCol = 0;
   foreach ($rmCols as $rc):
       $totRmCol += (float)$rc['amount'];
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($rc['created_at']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($rc['username']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($rc['subscriber_name'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($rc['subscriber_mobile'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($rc['package_name'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellCredit"><Data ss:Type="Number"><?= (float)$rc['amount'] ?></Data></Cell>
    <Cell ss:StyleID="CellCurrency"><Data ss:Type="Number"><?= (float)$rc['balance_after'] ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($rc['description']) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="4" ss:StyleID="TotalRow"><Data ss:Type="String">TOTAL RECOVERED BY <?= xml_escape($rm['full_name']) ?> (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totRmCol ?></Data></Cell>
    <Cell ss:MergeAcross="1" ss:StyleID="TotalRow"><Data ss:Type="String"><?= count($rmCols) ?> Recoveries Collected</Data></Cell>
   </Row>
  </Table>
 </Worksheet>
 <?php endforeach; ?>

 <!-- ================================================================= -->
 <!-- SHEET: STAFF RECOVERY MEN (OPERATORS SUMMARY) -->
 <!-- ================================================================= -->
 <?php $sheetName = sanitize_excel_sheet_name("Staff_RecoveryMen", $used_sheet_names); ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="65"/>
   <Column ss:Width="140"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Column ss:Width="80"/>
   <Column ss:Width="120"/>
   <Column ss:Width="110"/>
   <Column ss:Width="120"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="8" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - OPERATORS / RECOVERY MEN</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="8" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Field Recovery Team Financial Status &amp; Cash in Hand</Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="8"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Staff ID</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Full Name</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Username</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Contact Mobile</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Assigned Area</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Status</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Total Recoveries (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Cash In Hand (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Joined Date</Data></Cell>
   </Row>

   <?php
   $totRmCash = 0;
   $totRmColAll = 0;
   foreach ($data['recovery_men'] as $rm):
       $totRmCash += (float)$rm['cash_in_hand'];
       $totRmColAll += (float)$rm['total_collected'];
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="Number"><?= (int)$rm['id'] ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($rm['full_name']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String">@<?= xml_escape($rm['username']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($rm['phone'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($rm['area'] ?: 'All Areas') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape(strtoupper($rm['status'])) ?></Data></Cell>
    <Cell ss:StyleID="CellCredit"><Data ss:Type="Number"><?= (float)$rm['total_collected'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $rm['cash_in_hand'] > 0 ? 'CellCredit' : 'CellCurrency' ?>"><Data ss:Type="Number"><?= (float)$rm['cash_in_hand'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($rm['created_at'] ?? 'N/A') ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="5" ss:StyleID="TotalRow"><Data ss:Type="String">TOTALS (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totRmColAll ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totRmCash ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="String"><?= count($data['recovery_men']) ?> Operators</Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ================================================================= -->
 <!-- SHEET: STAFF LINE MEN (TECHNICIANS) -->
 <!-- ================================================================= -->
 <?php $sheetName = sanitize_excel_sheet_name("Staff_LineMen", $used_sheet_names); ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="65"/>
   <Column ss:Width="140"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Column ss:Width="110"/>
   <Column ss:Width="80"/>
   <Column ss:Width="110"/>
   <Column ss:Width="120"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="7" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - LINE MEN / FIELD TECHNICIANS</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="7" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Network Technical Staff Profiles &amp; Salary Structure</Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="7"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Staff ID</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Full Name</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Username</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Contact Mobile</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Assigned Area</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Status</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Monthly Salary (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Joined Date</Data></Cell>
   </Row>

   <?php
   $totLmSal = 0;
   foreach ($data['linemen'] as $lm):
       $totLmSal += (float)($lm['monthly_salary'] ?? 0);
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="Number"><?= (int)$lm['id'] ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($lm['full_name']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String">@<?= xml_escape($lm['username']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($lm['phone'] ?: 'N/A') ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($lm['area'] ?: 'All Areas') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape(strtoupper($lm['status'])) ?></Data></Cell>
    <Cell ss:StyleID="CellCurrency"><Data ss:Type="Number"><?= (float)($lm['monthly_salary'] ?? 0) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($lm['created_at'] ?? 'N/A') ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="5" ss:StyleID="TotalRow"><Data ss:Type="String">TOTAL MONTHLY SALARY COMMITMENT (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totLmSal ?></Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="String"><?= count($data['linemen']) ?> Technicians</Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ================================================================= -->
 <!-- SHEET: STAFF SALARIES & ADVANCES LEDGER -->
 <!-- ================================================================= -->
 <?php $sheetName = sanitize_excel_sheet_name("Staff_Salaries", $used_sheet_names); ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="65"/>
   <Column ss:Width="100"/>
   <Column ss:Width="90"/>
   <Column ss:Width="140"/>
   <Column ss:Width="110"/>
   <Column ss:Width="95"/>
   <Column ss:Width="70"/>
   <Column ss:Width="100"/>
   <Column ss:Width="90"/>
   <Column ss:Width="200"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="9" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - STAFF SALARY &amp; ADVANCE LEDGER</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="9" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Monthly Accruals, Advance Draws &amp; Cash Payouts</Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="9"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Trx ID</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Trans Date</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Staff Type</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Staff Name</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Trans Type</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Amount (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Direction</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Balance After</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Payment Method</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Notes &amp; Reference</Data></Cell>
   </Row>

   <?php
   $totSalPaid = 0;
   foreach ($data['staff_salaries'] as $sl):
       if ($sl['trans_type'] === 'salary_payment' || $sl['trans_type'] === 'advance_payment') {
           $totSalPaid += (float)$sl['amount'];
       }
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="Number"><?= (int)$sl['id'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($sl['trans_date']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape(ucfirst($sl['staff_type'])) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($sl['staff_name']) ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape(str_replace('_', ' ', $sl['trans_type'])) ?></Data></Cell>
    <Cell ss:StyleID="<?= $sl['direction'] === 'debit' ? 'CellDebit' : 'CellCredit' ?>"><Data ss:Type="Number"><?= (float)$sl['amount'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape(strtoupper($sl['direction'])) ?></Data></Cell>
    <Cell ss:StyleID="CellCurrency"><Data ss:Type="Number"><?= (float)$sl['balance_after'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($sl['payment_method'] ?: 'Cash') ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($sl['notes'] ?: ($sl['reference_no'] ? "Ref: " . $sl['reference_no'] : '')) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="4" ss:StyleID="TotalRow"><Data ss:Type="String">TOTAL DISBURSED SALARIES &amp; ADVANCES (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totSalPaid ?></Data></Cell>
    <Cell ss:MergeAcross="3" ss:StyleID="TotalRow"><Data ss:Type="String"><?= count($data['staff_salaries']) ?> Ledger Rows</Data></Cell>
   </Row>
  </Table>
 </Worksheet>

 <!-- ================================================================= -->
 <!-- SHEET: DAILY BUSINESS EXPENSES -->
 <!-- ================================================================= -->
 <?php $sheetName = sanitize_excel_sheet_name("Daily_Expenses", $used_sheet_names); ?>
 <Worksheet ss:Name="<?= $sheetName ?>">
  <Table ss:DefaultRowHeight="20">
   <Column ss:Width="65"/>
   <Column ss:Width="100"/>
   <Column ss:Width="120"/>
   <Column ss:Width="180"/>
   <Column ss:Width="100"/>
   <Column ss:Width="130"/>
   <Column ss:Width="100"/>
   <Column ss:Width="200"/>

   <Row ss:Height="30">
    <Cell ss:MergeAcross="7" ss:StyleID="CompanyBanner"><Data ss:Type="String"><?= xml_escape($company) ?> - DAILY BUSINESS EXPENSES</Data></Cell>
   </Row>
   <Row ss:Height="22">
    <Cell ss:MergeAcross="7" ss:StyleID="SheetSubtitle"><Data ss:Type="String">Operating Expenses, Hardware, Bandwidth &amp; Maintenance</Data></Cell>
   </Row>
   <Row><Cell ss:MergeAcross="7"><Data ss:Type="String"></Data></Cell></Row>

   <Row ss:Height="24">
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Exp ID</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Expense Date</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Category</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Expense Title</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Amount (Rs.)</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Payee</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Payment Mode</Data></Cell>
    <Cell ss:StyleID="TableHeader"><Data ss:Type="String">Notes &amp; Reference</Data></Cell>
   </Row>

   <?php
   $totExp = 0;
   foreach ($data['expenses'] as $ex):
       $totExp += (float)$ex['amount'];
   ?>
   <Row>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="Number"><?= (int)$ex['id'] ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($ex['expense_date']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ex['category']) ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ex['title']) ?></Data></Cell>
    <Cell ss:StyleID="CellDebit"><Data ss:Type="Number"><?= (float)$ex['amount'] ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ex['payee'] ?: 'General') ?></Data></Cell>
    <Cell ss:StyleID="CellCenter"><Data ss:Type="String"><?= xml_escape($ex['payment_method'] ?: 'Cash') ?></Data></Cell>
    <Cell ss:StyleID="CellLeft"><Data ss:Type="String"><?= xml_escape($ex['notes'] ?: ($ex['reference_no'] ? "Ref: " . $ex['reference_no'] : '')) ?></Data></Cell>
   </Row>
   <?php endforeach; ?>

   <Row ss:Height="24">
    <Cell ss:MergeAcross="3" ss:StyleID="TotalRow"><Data ss:Type="String">TOTAL EXPENSES (PKR):</Data></Cell>
    <Cell ss:StyleID="TotalRow"><Data ss:Type="Number"><?= (float)$totExp ?></Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="TotalRow"><Data ss:Type="String"><?= count($data['expenses']) ?> Expense Records</Data></Cell>
   </Row>
  </Table>
 </Worksheet>

</Workbook>
<?php
        return ob_get_clean();
    }
}

/**
 * Generate Master Flat CSV representation of all financial transactions
 */
if (!function_exists('build_flat_csv')) {
    function build_flat_csv($data) {
        $fh = fopen('php://memory', 'r+');
        // UTF-8 BOM
        fputs($fh, "\xEF\xBB\xBF");
        
        // Header Row
        fputcsv($fh, [
            'Date & Time',
            'Transaction Ref',
            'Category',
            'Entity Type',
            'Entity Name',
            'Entity Identifier',
            'Description',
            'Money In / Credit (Rs.)',
            'Money Out / Debit (Rs.)',
            'Balance After (Rs.)',
            'Recorded By'
        ]);

        foreach ($data['all_transactions'] as $t) {
            fputcsv($fh, [
                $t['trans_date'],
                $t['ref_id'],
                $t['category'],
                $t['entity_type'],
                $t['entity_name'],
                $t['entity_sub'],
                $t['description'],
                number_format($t['credit'], 2, '.', ''),
                number_format($t['debit'], 2, '.', ''),
                number_format($t['balance_after'], 2, '.', ''),
                $t['recorded_by']
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}

/**
 * Create a new Financial Backup snapshot on disk (Multi-Sheet .xls, .csv, and .json)
 * Automatically enforces 30-day retention pruning.
 */
if (!function_exists('create_client_financial_backup')) {
    function create_client_financial_backup($pdo, $client_id, $type_label = 'instant') {
        $client_id = (int)$client_id;
        $dir = get_financial_backup_storage_dir($client_id);
        
        // Fetch complete dataset
        $data = fetch_client_financial_dataset($pdo, $client_id);
        
        $timestamp = date('Y-m-d_His');
        $baseName = "fin_backup_client{$client_id}_{$timestamp}_{$type_label}";
        
        $xlsPath  = $dir . '/' . $baseName . '.xls';
        $csvPath  = $dir . '/' . $baseName . '.csv';
        $jsonPath = $dir . '/' . $baseName . '.json';

        // 1. Build Multi-Sheet XML
        $xmlContent = build_multisheet_excel_xml($data);
        $wXls = @file_put_contents($xlsPath, $xmlContent);
        if ($wXls === false || $wXls <= 0) {
            $err = error_get_last();
            $errMsg = $err['message'] ?? 'Permission denied';
            throw new Exception("Storage write failed for '{$xlsPath}'. Directory '{$dir}' is not writable by web server. ({$errMsg}). Please ensure permissions: chmod -R 777 uploads/backups_storage or chown -R www-data:www-data uploads/backups_storage");
        }

        // 2. Build Master Flat CSV
        $csvContent = build_flat_csv($data);
        $wCsv = @file_put_contents($csvPath, $csvContent);

        // 3. Build Full Database JSON Payload (For 100% loss-free database restore)
        $jsonPayload = [
            'version'       => '1.0',
            'client_id'     => $client_id,
            'company_name'  => $data['client']['company_name'],
            'created_at'    => date('Y-m-d H:i:s'),
            'type_label'    => $type_label,
            'summary'       => [
                'total_transactions'     => count($data['all_transactions']),
                'total_user_collections' => count($data['user_collections']),
                'total_collected_amount' => $data['total_collected_amount'],
                'total_managers'         => count($data['managers']),
                'total_recovery_men'     => count($data['recovery_men']),
                'total_linemen'          => count($data['linemen']),
                'total_salaries'         => count($data['staff_salaries']),
                'total_expenses'         => count($data['expenses'])
            ],
            'user_collections' => $data['user_collections'],
            'user_ledgers'     => $data['user_ledgers'],
            'managers'         => $data['managers'],
            'recovery_men'     => $data['recovery_men'],
            'linemen'          => $data['linemen'],
            'staff_salaries'   => $data['staff_salaries'],
            'expenses'         => $data['expenses']
        ];
        $wJson = @file_put_contents($jsonPath, json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Enforce 30-day retention
        prune_financial_backups($client_id, FINANCIAL_BACKUP_RETENTION_DAYS);

        // Log to activity logs
        try {
            $user = $_SESSION['operator_username'] ?? ($_SESSION['operator_name'] ?? 'System/Cron');
            $actMsg = "Created Financial Backup ({$type_label}): {$baseName} with " . count($data['user_collections']) . " collections (Rs. " . number_format($data['total_collected_amount'], 0) . ")";
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Financial Backup', 'System', ?)")
                ->execute([$client_id, $user, $actMsg]);
        } catch (Exception $e) {}

        $realSize = file_exists($xlsPath) ? filesize($xlsPath) : 0;
        return [
            'basename'      => $baseName,
            'xls_file'      => basename($xlsPath),
            'csv_file'      => basename($csvPath),
            'json_file'     => basename($jsonPath),
            'size_bytes'    => $realSize,
            'size_fmt'      => round($realSize / 1024, 1) . ' KB',
            'records_count' => count($data['user_collections']),
            'total_amount'  => $data['total_collected_amount'],
            'created_at'    => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * List all available financial backups for this client across candidate storage folders (Up to 30 most recent)
 */
if (!function_exists('list_client_financial_backups')) {
    function list_client_financial_backups($client_id) {
        $client_id = (int)$client_id;
        $candidates = get_financial_backup_storage_candidates($client_id);
        
        $allFiles = [];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                $found = glob($dir . '/fin_backup_*.xls');
                if ($found) {
                    foreach ($found as $f) {
                        $bName = basename($f);
                        if (!isset($allFiles[$bName])) {
                            $allFiles[$bName] = $f;
                        }
                    }
                }
            }
        }

        if (empty($allFiles)) return [];

        $list = [];
        foreach ($allFiles as $file) {
            $fDir = dirname($file);
            $base = pathinfo($file, PATHINFO_FILENAME);
            $jsonFile = $fDir . '/' . $base . '.json';
            $csvFile  = $fDir . '/' . $base . '.csv';
            
            $mtime = filemtime($file);
            $size = filesize($file);
            
            // Try to read summary from JSON
            $meta = [];
            if (file_exists($jsonFile)) {
                $raw = @file_get_contents($jsonFile);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            // Determine snapshot type label from filename
            $type = 'Auto Daily';
            if (stripos($base, 'instant') !== false) {
                $type = 'Instant Snapshot';
            } elseif (stripos($base, 'pre_restore') !== false) {
                $type = 'Pre-Restore Safety';
            } elseif (stripos($base, 'manual') !== false) {
                $type = 'Manual Snapshot';
            }

            $list[] = [
                'basename'      => $base,
                'xls_file'      => basename($file),
                'csv_file'      => file_exists($csvFile) ? basename($csvFile) : null,
                'json_file'     => file_exists($jsonFile) ? basename($jsonFile) : null,
                'file_date'     => date('Y-m-d H:i:s', $mtime),
                'timestamp'     => $mtime,
                'size_bytes'    => $size,
                'size_fmt'      => round($size / 1024, 1) . ' KB',
                'type'          => $type,
                'storage_dir'   => $fDir,
                'summary'       => $meta['summary'] ?? [
                    'total_user_collections' => 'N/A'
                ]
            ];
        }

        // Sort latest first
        usort($list, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $list;
    }
}

/**
 * Prune client backups strictly retaining only the newest $keep_count snapshots (30 days)
 */
if (!function_exists('prune_financial_backups')) {
    function prune_financial_backups($client_id, $keep_count = 30) {
        $client_id = (int)$client_id;
        $keep_count = max(5, (int)$keep_count);
        $candidates = get_financial_backup_storage_candidates($client_id);

        $allXls = [];
        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                $found = glob($dir . '/fin_backup_*.xls');
                if ($found) {
                    foreach ($found as $f) {
                        $allXls[basename($f)] = $f;
                    }
                }
            }
        }

        if (count($allXls) <= $keep_count) {
            return 0;
        }

        // Sort descending by modified time
        uasort($allXls, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $pruned = 0;
        $toRemove = array_slice($allXls, $keep_count);
        foreach ($toRemove as $oldXls) {
            $fDir = dirname($oldXls);
            $base = pathinfo($oldXls, PATHINFO_FILENAME);
            @unlink($oldXls);
            @unlink($fDir . '/' . $base . '.csv');
            @unlink($fDir . '/' . $base . '.json');
            $pruned++;
        }

        return $pruned;
    }
}

/**
 * 1-Click Restore Financial Backup into Database
 * Safely imports ledger entries, recovers subscriber balances, reconciles cash in hand.
 * Automatically creates a pre-restore safety snapshot before touching live tables.
 */
if (!function_exists('restore_client_financial_backup')) {
    function restore_client_financial_backup($pdo, $client_id, $backup_basename) {
        $client_id = (int)$client_id;
        $cleanBase = preg_replace('/[^a-zA-Z0-9_-]/', '', $backup_basename);
        $candidates = get_financial_backup_storage_candidates($client_id);
        
        $jsonFile = null;
        foreach ($candidates as $cDir) {
            $tryFile = $cDir . '/' . $cleanBase . '.json';
            if (file_exists($tryFile)) {
                $jsonFile = $tryFile;
                break;
            }
        }

        if (!$jsonFile || !file_exists($jsonFile)) {
            throw new Exception("Backup JSON payload not found for: {$cleanBase}");
        }

        $raw = file_get_contents($jsonFile);
        $payload = json_decode($raw, true);
        if (!$payload || !is_array($payload)) {
            throw new Exception("Corrupted backup payload: Invalid JSON format.");
        }

        // Client ID Safety Check
        if ((int)($payload['client_id'] ?? 0) !== $client_id) {
            throw new Exception("Security violation: Backup belongs to a different client ID.");
        }

        // STEP 1: Create an automated PRE-RESTORE safety snapshot
        create_client_financial_backup($pdo, $client_id, 'pre_restore');

        // STEP 2: Execute Restore inside atomic transaction
        $pdo->beginTransaction();
        $stats = [
            'restored_user_ledgers'  => 0,
            'updated_sub_balances'   => 0,
            'restored_salaries'      => 0,
            'restored_expenses'      => 0,
            'reconciled_managers'    => 0,
            'reconciled_recoverymen' => 0
        ];

        try {
            // A. Restore / Insert missing User Ledgers
            $ledgersToRestore = !empty($payload['user_ledgers']) ? $payload['user_ledgers'] : (!empty($payload['user_collections']) ? $payload['user_collections'] : []);
            if (!empty($ledgersToRestore)) {
                $checkStmt = $pdo->prepare("SELECT id FROM user_ledger WHERE client_id = ? AND username = ? AND created_at = ? AND amount = ? LIMIT 1");
                $insLedger = $pdo->prepare("
                    INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($ledgersToRestore as $ul) {
                    $uName = trim($ul['username'] ?? '');
                    $cAt   = $ul['created_at'] ?? date('Y-m-d H:i:s');
                    $amt   = (float)($ul['amount'] ?? 0);
                    $type  = $ul['type'] ?? 'credit';
                    $desc  = $ul['description'] ?? 'Restored financial transaction';
                    $bal   = (float)($ul['balance_after'] ?? 0);

                    if (empty($uName)) continue;

                    $checkStmt->execute([$client_id, $uName, $cAt, $amt]);
                    if (!$checkStmt->fetchColumn()) {
                        $insLedger->execute([$client_id, $uName, $type, $amt, $bal, $desc, $cAt]);
                        $stats['restored_user_ledgers']++;
                    }
                }
            }

            // B. Reconcile Subscriber Balances from snapshot
            if (!empty($ledgersToRestore)) {
                $updSub = $pdo->prepare("UPDATE subscribers SET balance = ? WHERE client_id = ? AND username = ?");
                $subSeen = [];
                foreach ($ledgersToRestore as $ul) {
                    $uName = trim($ul['username'] ?? '');
                    if (!empty($uName) && !isset($subSeen[$uName]) && isset($ul['balance_after'])) {
                        $subSeen[$uName] = true;
                        $updSub->execute([(float)$ul['balance_after'], $client_id, $uName]);
                        $stats['updated_sub_balances']++;
                    }
                }
            }

            // C. Restore Staff Salary Ledger
            if (!empty($payload['staff_salaries'])) {
                $chkSal = $pdo->prepare("SELECT id FROM staff_salary_ledger WHERE client_id = ? AND staff_type = ? AND staff_id = ? AND trans_date = ? AND trans_type = ? LIMIT 1");
                $insSal = $pdo->prepare("
                    INSERT INTO staff_salary_ledger (client_id, staff_type, staff_id, staff_name, trans_type, amount, direction, balance_after, payment_method, reference_no, notes, trans_date, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($payload['staff_salaries'] as $sl) {
                    $sType = $sl['staff_type'] ?? 'recoveryman';
                    $sId   = (int)($sl['staff_id'] ?? 0);
                    $tDate = $sl['trans_date'] ?? date('Y-m-d');
                    $tType = $sl['trans_type'] ?? 'salary_payment';

                    $chkSal->execute([$client_id, $sType, $sId, $tDate, $tType]);
                    if (!$chkSal->fetchColumn()) {
                        $insSal->execute([
                            $client_id,
                            $sType,
                            $sId,
                            $sl['staff_name'] ?? 'Staff',
                            $tType,
                            (int)($sl['amount'] ?? 0),
                            $sl['direction'] ?? 'debit',
                            (int)($sl['balance_after'] ?? 0),
                            $sl['payment_method'] ?? 'Cash',
                            $sl['reference_no'] ?? null,
                            $sl['notes'] ?? 'Restored salary record',
                            $tDate,
                            $sl['created_by'] ?? 'Restore Helper',
                            $sl['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        $stats['restored_salaries']++;
                    }
                }
            }

            // D. Restore Daily Expenses
            if (!empty($payload['expenses'])) {
                $chkExp = $pdo->prepare("SELECT id FROM expenses WHERE client_id = ? AND title = ? AND expense_date = ? AND amount = ? LIMIT 1");
                $insExp = $pdo->prepare("
                    INSERT INTO expenses (client_id, category, title, amount, expense_date, payment_method, reference_no, payee, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($payload['expenses'] as $ex) {
                    $eTitle = $ex['title'] ?? 'Expense';
                    $eDate  = $ex['expense_date'] ?? date('Y-m-d');
                    $eAmt   = (float)($ex['amount'] ?? 0);

                    $chkExp->execute([$client_id, $eTitle, $eDate, $eAmt]);
                    if (!$chkExp->fetchColumn()) {
                        $insExp->execute([
                            $client_id,
                            $ex['category'] ?? 'General',
                            $eTitle,
                            $eAmt,
                            $eDate,
                            $ex['payment_method'] ?? 'Cash',
                            $ex['reference_no'] ?? null,
                            $ex['payee'] ?? '',
                            $ex['notes'] ?? 'Restored expense',
                            $ex['created_by'] ?? 'Restore Helper',
                            $ex['created_at'] ?? date('Y-m-d H:i:s')
                        ]);
                        $stats['restored_expenses']++;
                    }
                }
            }

            // E. Reconcile Manager Cash in Hand from snapshot
            if (!empty($payload['managers'])) {
                $updMgr = $pdo->prepare("UPDATE managers SET cash_in_hand = ? WHERE id = ? AND client_id = ?");
                foreach ($payload['managers'] as $mgr) {
                    if (isset($mgr['id']) && isset($mgr['cash_in_hand'])) {
                        $updMgr->execute([(float)$mgr['cash_in_hand'], (int)$mgr['id'], $client_id]);
                        $stats['reconciled_managers']++;
                    }
                }
            }

            // F. Reconcile Recovery Men Cash in Hand from snapshot
            if (!empty($payload['recovery_men'])) {
                $updRm = $pdo->prepare("UPDATE recovery_men SET cash_in_hand = ? WHERE id = ? AND client_id = ?");
                foreach ($payload['recovery_men'] as $rm) {
                    if (isset($rm['id']) && isset($rm['cash_in_hand'])) {
                        $updRm->execute([(float)$rm['cash_in_hand'], (int)$rm['id'], $client_id]);
                        $stats['reconciled_recoverymen']++;
                    }
                }
            }

            // Log activity
            $actor = $_SESSION['operator_username'] ?? ($_SESSION['operator_name'] ?? 'Admin');
            $actMsg = "Restored Financial Backup {$cleanBase}: " . json_encode($stats);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Financial Backup', 'Restore', ?)")
                ->execute([$client_id, $actor, $actMsg]);

            $pdo->commit();
            return $stats;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/**
 * Automatically triggers daily backup if no backup has been generated in the last 24 hours.
 * Ensures the 30-day auto-backup guarantee works even without cron setup.
 */
if (!function_exists('auto_trigger_daily_backup_if_needed')) {
    function auto_trigger_daily_backup_if_needed($pdo, $client_id) {
        $client_id = (int)$client_id;
        $dir = get_financial_backup_storage_dir($client_id);
        
        $files = glob($dir . '/fin_backup_*.xls');
        $needs_backup = true;

        if ($files) {
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $newest = $files[0];
            $age_hours = (time() - filemtime($newest)) / 3600;
            if ($age_hours < 24) {
                $needs_backup = false;
            }
        }

        if ($needs_backup) {
            return create_client_financial_backup($pdo, $client_id, 'auto_daily');
        }
        return false;
    }
}
