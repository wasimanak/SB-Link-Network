<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Safe default values
$client_id = (int)($_SESSION['client_id'] ?? 0);
$rm_id = (int)($_SESSION['rm_id'] ?? 0);
$rm_name = $_SESSION['rm_name'] ?? 'Recovery Agent';
$return_url = !empty($_GET['return']) ? $_GET['return'] : 'dashboard.php';
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : 0;
$paid_amount = isset($_GET['paid']) ? (float)$_GET['paid'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'renew'; // renew or collect

// Authentication Check: RecoveryMan, Operator, or Admin
$is_authenticated = false;
if ($rm_id > 0) {
    $is_authenticated = true;
} elseif (!empty($_SESSION['operator_logged_in']) || !empty($_SESSION['operator_id'])) {
    $client_id = (int)$_SESSION['operator_id'];
    if (!empty($_SESSION['is_manager'])) {
        $rm_name = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] . ' (Manager)' : 'Manager';
    } else {
        $rm_name = !empty($_SESSION['operator_name']) ? $_SESSION['operator_name'] . ' (Admin)' : 'Admin';
    }
    $is_authenticated = true;
} elseif (!empty($_SESSION['admin_logged_in'])) {
    $rm_name = 'Admin';
    $is_authenticated = true;
}

if (!$is_authenticated) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Unauthorized</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Unauthorized Access</h5>";
    echo "<p class='text-muted small mb-3'>Please log in to your account to view this receipt.</p>";
    echo "<a href='login.php' class='btn btn-primary rounded-pill px-4'>Go to Login</a>";
    echo "</div></body></html>";
    exit;
}

if ($sub_id <= 0) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Invalid Request</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Invalid Request</h5>";
    echo "<p class='text-muted small mb-3'>No subscriber ID was provided.</p>";
    echo "<a href='" . htmlspecialchars($return_url) . "' class='btn btn-secondary rounded-pill px-4'>Back to Dashboard</a>";
    echo "</div></body></html>";
    exit;
}

// 1. Fetch Subscriber Details
$sub = null;
try {
    if ($client_id > 0) {
        $subStmt = $pdo->prepare("
            SELECT s.id, s.client_id, s.username, s.full_name, s.balance, s.expiry_date, s.mobile, s.phone, s.address, 
                   p.name as package_name, p.price as package_price 
            FROM subscribers s 
            LEFT JOIN packages p ON s.package_id = p.id 
            WHERE s.id = ? AND s.client_id = ?
        ");
        $subStmt->execute([$sub_id, $client_id]);
        $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$sub) {
        $subStmt = $pdo->prepare("
            SELECT s.id, s.client_id, s.username, s.full_name, s.balance, s.expiry_date, s.mobile, s.phone, s.address, 
                   p.name as package_name, p.price as package_price 
            FROM subscribers s 
            LEFT JOIN packages p ON s.package_id = p.id 
            WHERE s.id = ?
        ");
        $subStmt->execute([$sub_id]);
        $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
        if ($sub && $client_id === 0) {
            $client_id = (int)($sub['client_id'] ?? 0);
        }
    }
} catch (Exception $e) {
    $sub = null;
}

if (!$sub) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Subscriber Not Found</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Subscriber Not Found</h5>";
    echo "<p class='text-muted small mb-3'>The subscriber record (ID: {$sub_id}) was not found in database.</p>";
    echo "<a href='" . htmlspecialchars($return_url) . "' class='btn btn-secondary rounded-pill px-4'>Back to Dashboard</a>";
    echo "</div></body></html>";
    exit;
}

// 2. Fetch Recovery Agent Name if available
if ($rm_id > 0) {
    try {
        $rmStmt = $pdo->prepare("SELECT full_name, phone FROM recovery_men WHERE id = ?");
        $rmStmt->execute([$rm_id]);
        $rmData = $rmStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($rmData['full_name'])) {
            $rm_name = $rmData['full_name'];
        }
    } catch (Exception $e) {
        if (!empty($_SESSION['rm_name'])) {
            $rm_name = $_SESSION['rm_name'];
        }
    }
}

// 3. Fetch Operator / Company Details
$operator = null;
if ($client_id > 0) {
    try {
        $opStmt = $pdo->prepare("SELECT company_name, phone FROM clients WHERE id = ?");
        $opStmt->execute([$client_id]);
        $operator = $opStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $operator = null;
    }
}

$company_name = !empty($operator['company_name']) ? $operator['company_name'] : (defined('BRAND_NAME') ? BRAND_NAME : 'SB Link Network');
$company_phone = !empty($operator['phone']) ? $operator['phone'] : 'N/A';
$date = date('d M Y h:i A');

// 4. Fetch Latest Transaction ID for Invoice#
$invoice_id = 0;
try {
    if (!empty($sub['username'])) {
        $invStmt = $pdo->prepare("SELECT id FROM user_ledger WHERE username = ? ORDER BY id DESC LIMIT 1");
        $invStmt->execute([$sub['username']]);
        $invoice_id = $invStmt->fetchColumn();
    }
} catch (Exception $e) {
    $invoice_id = 0;
}
if (!$invoice_id) {
    $invoice_id = rand(10000, 99999);
}
$invoice_number = "INV-" . str_pad($invoice_id, 6, "0", STR_PAD_LEFT);

// Financial amounts
$package_price = (float)($sub['package_price'] ?? 0);
if ($paid_amount <= 0 && $type === 'renew') {
    $paid_amount = $package_price;
}
$due_amount = $sub['balance'] < 0 ? abs((float)$sub['balance']) : 0;
$adv_amount = $sub['balance'] > 0 ? (float)$sub['balance'] : 0;

// Customer Phone Logic
$cust_phone = !empty($sub['mobile']) ? $sub['mobile'] : ($sub['phone'] ?? '');
$wa_number = '';
if (!empty($cust_phone)) {
    $clean = preg_replace('/[^0-9]/', '', $cust_phone);
    if (substr($clean, 0, 1) === '0') {
        $wa_number = '92' . substr($clean, 1);
    } else {
        $wa_number = $clean;
    }
}

// WhatsApp Text Receipt
$wa_text = "*PAYMENT RECEIPT*\n";
$wa_text .= "--------------------------------\n";
$wa_text .= "*{$company_name}*\n";
$wa_text .= "Phone: {$company_phone}\n";
$wa_text .= "--------------------------------\n";
$wa_text .= "Date: {$date}\n";
$wa_text .= "Invoice#: {$invoice_number}\n";
$wa_text .= "User: {$sub['username']}\n";
if (!empty($sub['full_name'])) {
    $wa_text .= "Name: {$sub['full_name']}\n";
}
if (!empty($sub['package_name'])) {
    $wa_text .= "Package: {$sub['package_name']}\n";
}
if (!empty($sub['expiry_date'])) {
    $wa_text .= "New Expiry: " . date('d M Y', strtotime($sub['expiry_date'])) . "\n";
}
$wa_text .= "Paid Amount: Rs. " . number_format($paid_amount, 0) . "\n";
if ($due_amount > 0) {
    $wa_text .= "Due Balance: Rs. " . number_format($due_amount, 0) . "\n";
} elseif ($adv_amount > 0) {
    $wa_text .= "Adv Balance: Rs. " . number_format($adv_amount, 0) . "\n";
}
$wa_text .= "Agent: {$rm_name}\n";
$wa_text .= "--------------------------------\n";
$wa_text .= "Thank you for your payment!";
$wa_text_encoded = urlencode($wa_text);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt_80mm - <?= htmlspecialchars($sub['username']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =========================================================
           80mm Thermal Receipt Specifications
           Total Paper Roll: 80mm
           Printable Area: 72mm
           Safe Side Margins: 4mm (Left & Right)
           ========================================================= */
        @page {
            size: 80mm auto;
            margin: 3mm 4mm 5mm 4mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            font-family: 'Consolas', 'Courier New', Courier, monospace, 'Segoe UI', Tahoma, sans-serif;
            color: #000000;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            font-size: 13px;
            line-height: 1.4;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: bold; }
        .font-mono { font-family: 'Consolas', 'Courier New', monospace; }

        /* Thermal Divider Lines */
        .divider-dashed {
            border-bottom: 1px dashed #000000;
            margin: 7px 0;
            height: 1px;
        }
        .divider-double {
            border-bottom: 3px double #000000;
            margin: 8px 0;
            height: 3px;
        }
        .divider-solid {
            border-bottom: 1px solid #000000;
            margin: 7px 0;
            height: 1px;
        }

        /* Key-Value Flex Grid Rows */
        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 2.5px 0;
            font-size: 12.5px;
        }
        .receipt-row .label {
            color: #222222;
            flex-shrink: 0;
            margin-right: 8px;
        }
        .receipt-row .val {
            text-align: right;
            word-break: break-word;
            color: #000000;
        }

        /* Section Subheading */
        .section-tag {
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #333333;
            margin-bottom: 3px;
        }

        /* Screen Simulation / Interactive Toolbar */
        @media screen {
            body {
                background: #f1f5f9;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 24px 12px;
            }

            .alert-top {
                width: 360px;
                max-width: 95vw;
                background: #ecfdf5;
                color: #065f46;
                border: 1px solid #a7f3d0;
                padding: 10px 14px;
                border-radius: 12px;
                font-weight: 700;
                font-size: 0.88rem;
                text-align: center;
                margin-bottom: 14px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            }

            .no-print-toolbar {
                width: 360px;
                max-width: 95vw;
                margin-bottom: 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .btn-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                padding: 10px 12px;
                border-radius: 10px;
                font-weight: 700;
                font-size: 0.85rem;
                text-decoration: none;
                cursor: pointer;
                border: none;
                box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                transition: transform 0.1s ease, box-shadow 0.1s ease;
            }
            .btn-action:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 10px rgba(0,0,0,0.14);
            }
            .btn-action:active {
                transform: translateY(1px);
            }

            .btn-print { background: #0f172a; color: #ffffff; }
            .btn-pdf { background: #e11d48; color: #ffffff; }
            .btn-whatsapp { background: #16a34a; color: #ffffff; }
            .btn-back { background: #475569; color: #ffffff; }

            .receipt-wrapper {
                background: #ffffff;
                width: 360px;
                max-width: 95vw;
                padding: 22px 20px;
                border-radius: 14px;
                border: 1px solid #cbd5e1;
                box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.12), 0 6px 12px -4px rgba(0, 0, 0, 0.06);
                position: relative;
            }


        }

        /* 80mm Print Rules */
        @media print {
            .no-print, .no-print-toolbar, .alert-top {
                display: none !important;
            }

            body {
                background: #fff !important;
                width: 80mm !important;
                max-width: 80mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
            }

            .receipt-wrapper {
                width: 72mm !important;
                max-width: 72mm !important;
                margin: 0 auto !important;
                padding: 1mm 2mm 6mm 2mm !important;
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            #receipt-content {
                width: 72mm !important;
                max-width: 72mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
            }


        }

        /* Highlight Boxes */
        .amount-box {
            border: 1.5px solid #000000;
            padding: 6px 8px;
            margin: 6px 0;
            background: #fafafa;
        }
        @media print {
            .amount-box {
                background: #ffffff !important;
                border: 1.5px solid #000000 !important;
            }
        }

        .qr-section {
            margin-top: 10px;
            padding-top: 6px;
            text-align: center;
        }
        .qr-img {
            width: 85px;
            height: 85px;
            display: inline-block;
            margin-bottom: 3px;
        }
        .qr-caption {
            font-size: 10px;
            color: #333;
            letter-spacing: 0.3px;
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

    <!-- Top Success Confirmation -->
    <div class="alert-top no-print">
        <i class="fa-solid fa-circle-check me-1 text-success"></i>
        <?= ($type === 'renew') ? 'Package Renewed &amp; Activated!' : 'Payment Collected Successfully!' ?>
    </div>

    <!-- Screen Action Toolbar -->
    <div class="no-print-toolbar">
        <button type="button" class="btn-action btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print (80mm)
        </button>
        <button type="button" class="btn-action btn-pdf" id="btnDownloadPdf" onclick="downloadPDF()">
            <i class="fa-solid fa-file-pdf"></i> Download PDF
        </button>
        <button type="button" class="btn-action btn-whatsapp" id="btnShareWa" onclick="shareWhatsApp()">
            <i class="fa-brands fa-whatsapp"></i> WhatsApp Share
        </button>
        <a href="<?= htmlspecialchars($return_url) ?>" class="btn-action btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>

    <!-- 80mm Thermal Receipt Wrapper -->
    <div class="receipt-wrapper">
        <div id="receipt-content">

            <!-- Company Brand Header -->
            <div class="text-center">
                <div style="font-size: 14px; margin-bottom: 2px;">
                    <i class="fa-solid fa-wifi" style="font-size: 16px;"></i>
                </div>
                <div class="fw-bold" style="font-size: 17px; letter-spacing: 0.6px; text-transform: uppercase;">
                    <?= htmlspecialchars($company_name) ?>
                </div>
                <div style="font-size: 10px; letter-spacing: 1px; color: #444; margin-top: 1px;">
                    BROADBAND &amp; NETWORK SERVICES
                </div>
                <div style="font-size: 11px; margin-top: 2px;">
                    <strong>Helpline:</strong> <?= htmlspecialchars($company_phone) ?>
                </div>
            </div>

            <div class="divider-dashed"></div>

            <!-- Receipt Title -->
            <div class="text-center" style="padding: 2px 0;">
                <div class="fw-bold" style="font-size: 14px; letter-spacing: 1px;">
                    <?= ($type === 'renew') ? 'PACKAGE RENEWAL RECEIPT' : 'PAYMENT RECEIPT' ?>
                </div>
                <div style="font-size: 10px; color: #555;">*** OFFICIAL CUSTOMER COPY ***</div>
            </div>

            <div class="divider-dashed"></div>

            <!-- Transaction Metadata -->
            <div class="section-tag">TRANSACTION DETAILS</div>
            <div class="receipt-row">
                <span class="label">Invoice No:</span>
                <span class="val fw-bold font-mono"><?= $invoice_number ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Date &amp; Time:</span>
                <span class="val"><?= $date ?></span>
            </div>
            <div class="receipt-row">
                <span class="label">Collected By:</span>
                <span class="val"><?= htmlspecialchars($rm_name) ?></span>
            </div>

            <div class="divider-dashed"></div>

            <!-- Subscriber Information -->
            <div class="section-tag">CUSTOMER INFO</div>
            <div class="receipt-row">
                <span class="label">Username:</span>
                <span class="val fw-bold font-mono text-uppercase"><?= htmlspecialchars($sub['username']) ?></span>
            </div>
            <?php if(!empty($sub['full_name'])): ?>
            <div class="receipt-row">
                <span class="label">Full Name:</span>
                <span class="val fw-bold"><?= htmlspecialchars($sub['full_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if(!empty($cust_phone)): ?>
            <div class="receipt-row">
                <span class="label">Phone / Mobile:</span>
                <span class="val"><?= htmlspecialchars($cust_phone) ?></span>
            </div>
            <?php endif; ?>
            <?php if(!empty($sub['package_name'])): ?>
            <div class="receipt-row">
                <span class="label">Internet Plan:</span>
                <span class="val fw-bold"><?= htmlspecialchars($sub['package_name']) ?></span>
            </div>
            <?php endif; ?>
            <?php if(!empty($sub['expiry_date'])): ?>
            <div class="receipt-row">
                <span class="label">New Expiry:</span>
                <span class="val fw-bold font-mono" style="font-size: 13px;">
                    <?= date('d M Y', strtotime($sub['expiry_date'])) ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="divider-dashed"></div>

            <!-- Payment Breakdown -->
            <div class="section-tag">PAYMENT SUMMARY</div>
            <?php if($type === 'renew' && $package_price > 0): ?>
            <div class="receipt-row">
                <span class="label">Plan Monthly Rate:</span>
                <span class="val">Rs. <?= number_format($package_price, 0) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-row">
                <span class="label">Payment Mode:</span>
                <span class="val">Cash Collection</span>
            </div>

            <!-- Amount Highlight Box -->
            <div class="amount-box">
                <div class="receipt-row" style="font-size: 14px; padding: 2px 0;">
                    <span class="label fw-bold" style="font-size: 14px;">AMOUNT RECEIVED:</span>
                    <span class="val fw-bold font-mono" style="font-size: 16px;">Rs. <?= number_format($paid_amount, 0) ?></span>
                </div>
            </div>

            <!-- Balance Status -->
            <div style="padding-top: 2px;">
                <?php if($due_amount > 0): ?>
                <div class="receipt-row">
                    <span class="label fw-bold">Remaining Due:</span>
                    <span class="val fw-bold font-mono">Rs. <?= number_format($due_amount, 0) ?></span>
                </div>
                <div style="font-size: 10px; color: #444; margin-top: 1px;">
                    * Kindly clear pending due at your earliest.
                </div>
                <?php elseif($adv_amount > 0): ?>
                <div class="receipt-row">
                    <span class="label fw-bold">Advance Credit:</span>
                    <span class="val fw-bold font-mono">Rs. <?= number_format($adv_amount, 0) ?></span>
                </div>
                <div style="font-size: 10px; color: #444; margin-top: 1px;">
                    * Balance credited to your advance wallet.
                </div>
                <?php else: ?>
                <div class="receipt-row">
                    <span class="label fw-bold">Account Balance:</span>
                    <span class="val fw-bold">Rs. 0.00 (All Clear)</span>
                </div>
                <div style="font-size: 10px; color: #444; margin-top: 1px;">
                    * No outstanding dues pending on this account.
                </div>
                <?php endif; ?>
            </div>

            <div class="divider-double"></div>

            <!-- QR Code Verification Section -->
            <div class="qr-section">
                <?php if(!empty($wa_number)): ?>
                <div>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?= urlencode("https://wa.me/{$wa_number}?text=" . $wa_text_encoded) ?>" 
                         alt="Scan for Receipt" class="qr-img" onerror="this.parentElement.style.display='none'">
                </div>
                <div class="qr-caption">Scan with camera for WhatsApp E-Receipt</div>
                <?php endif; ?>
            </div>

            <!-- Footer Notes -->
            <div class="text-center" style="margin-top: 8px; font-size: 11px;">
                <div class="fw-bold" style="font-size: 12px; margin-bottom: 2px;">Thank you for your business!</div>
                <div style="font-size: 9.5px; color: #555; line-height: 1.3;">
                    This is a computer generated thermal receipt.<br>Valid without physical signature or stamp.
                </div>
                <div style="font-size: 10px; margin-top: 6px; color: #333;">
                    Powered by <strong><?= defined('BRAND_POWERED_BY') ? BRAND_POWERED_BY : 'SB Link Network' ?></strong>
                </div>
            </div>

        </div>
    </div>

    <script>
        function getPDFOptions(filename) {
            var element = document.getElementById('receipt-content');
            var pxHeight = element.offsetHeight;
            // 80mm thermal paper height calculation with safety margin
            var mmHeight = Math.ceil(pxHeight * 0.264583) + 20;
            if (mmHeight < 110) mmHeight = 110;

            return {
                margin: [4, 4, 6, 4], // Safe 4mm side margins on 80mm paper
                filename: filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 3, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: [80, mmHeight], orientation: 'portrait' }
            };
        }

        function downloadPDF() {
            var element = document.getElementById('receipt-content');
            var filename = 'Receipt_<?= $sub["username"] ?>_80mm.pdf';
            var opt = getPDFOptions(filename);
            var btn = document.getElementById('btnDownloadPdf');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
                btn.disabled = true;
            }

            html2pdf().set(opt).from(element).save().then(function() {
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
            }).catch(function(err) {
                console.error(err);
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                alert('PDF generation error: ' + err);
            });
        }

        function shareWhatsApp() {
            var element = document.getElementById('receipt-content');
            var filename = 'Receipt_<?= $sub["username"] ?>_80mm.pdf';
            var opt = getPDFOptions(filename);
            var btn = document.getElementById('btnShareWa');
            var origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing...';
                btn.disabled = true;
            }

            html2pdf().set(opt).from(element).output('blob').then(function(blob) {
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                var file = new File([blob], filename, { type: 'application/pdf' });

                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({
                        files: [file],
                        title: 'Payment Receipt - <?= htmlspecialchars($sub["username"]) ?>'
                    }).catch(function(error) {
                        console.log('Share canceled or failed:', error);
                    });
                } else {
                    // Download the file
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);

                    var waNum = '<?= $wa_number ?>';
                    var waText = '<?= $wa_text_encoded ?>';
                    if (waNum) {
                        window.open('https://api.whatsapp.com/send?phone=' + waNum + '&text=' + waText, '_blank');
                    } else {
                        alert("PDF 80mm receipt download ho gayi hai.");
                    }
                }
            }).catch(function(err) {
                console.error(err);
                if (btn) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                }
                alert('Error: ' + err);
            });
        }

        // Automatically trigger print dialog on page load
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 450);
        };
    </script>
</body>
</html>
