<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/db.php';

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
} elseif (!empty($_SESSION['operator_id'])) {
    $client_id = (int)$_SESSION['operator_id'];
    $rm_name = $_SESSION['operator_name'] ?? 'Office / Operator';
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
    echo "<a href='{$return_url}' class='btn btn-secondary rounded-pill px-4'>Back to Dashboard</a>";
    echo "</div></body></html>";
    exit;
}

// Fetch Subscriber Details
$sub = null;
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

// Fallback search by sub_id only if client_id was 0 or not matched
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
        $client_id = (int)$sub['client_id'];
    }
}

if (!$sub) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Subscriber Not Found</title>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head>";
    echo "<body class='bg-light d-flex align-items-center justify-content-center' style='min-height:100vh;'>";
    echo "<div class='card p-4 text-center rounded-4 shadow-sm' style='max-width:400px;'>";
    echo "<h5 class='fw-bold text-danger mb-2'>Subscriber Not Found</h5>";
    echo "<p class='text-muted small mb-3'>The subscriber record (ID: {$sub_id}) was not found in database.</p>";
    echo "<a href='{$return_url}' class='btn btn-secondary rounded-pill px-4'>Back to Dashboard</a>";
    echo "</div></body></html>";
    exit;
}

// Fetch Recovery Agent Name if available
if ($rm_id > 0) {
    $rmStmt = $pdo->prepare("SELECT name, phone FROM recovery_men WHERE id = ?");
    $rmStmt->execute([$rm_id]);
    $rmData = $rmStmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($rmData['name'])) {
        $rm_name = $rmData['name'];
    }
}

// Fetch Operator / Company Details
$operator = null;
if ($client_id > 0) {
    $opStmt = $pdo->prepare("SELECT company_name, phone, address FROM clients WHERE id = ?");
    $opStmt->execute([$client_id]);
    $operator = $opStmt->fetch(PDO::FETCH_ASSOC);
}

$company_name = !empty($operator['company_name']) ? $operator['company_name'] : (defined('BRAND_NAME') ? BRAND_NAME : 'SB Link Network');
$company_phone = !empty($operator['phone']) ? $operator['phone'] : 'N/A';
$date = date('d M Y h:i A');

// Fetch Latest Transaction ID for Invoice#
$invoice_id = 0;
if (!empty($sub['username'])) {
    $invStmt = $pdo->prepare("SELECT id FROM user_ledger WHERE username = ? ORDER BY id DESC LIMIT 1");
    $invStmt->execute([$sub['username']]);
    $invoice_id = $invStmt->fetchColumn();
}
if (!$invoice_id) {
    $invoice_id = rand(10000, 99999);
}
$invoice_number = "INV-" . str_pad($invoice_id, 6, "0", STR_PAD_LEFT);

// Financial amounts
$package_price = (float)($sub['package_price'] ?? 0);
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
$wa_text .= "Paid: Rs. " . number_format($paid_amount, 0) . "\n";
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
           80mm Thermal Paper Specifications
           Standard roll width: 80mm (~302px at 96 DPI)
           Printable area: 72mm (~272px at 96 DPI)
           Side margins: 4mm left & right (safe margins to prevent cutting)
           Top/Bottom margins: 3mm - 4mm
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
            font-family: 'Courier New', Courier, monospace, 'Segoe UI', Tahoma, sans-serif;
            color: #000000;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            font-size: 13px;
            line-height: 1.35;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: bold; }
        .mb-1 { margin-bottom: 4px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-3 { margin-bottom: 12px; }
        .border-top { border-top: 1px dashed #000; padding-top: 6px; margin-top: 6px; }
        .border-bottom { border-bottom: 1px dashed #000; padding-bottom: 6px; margin-bottom: 6px; }
        .border-double { border-bottom: 3px double #000; padding-bottom: 6px; margin-bottom: 6px; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        td {
            padding: 3px 0;
            vertical-align: top;
        }

        h3 {
            margin: 0 0 4px 0;
            font-size: 17px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        p {
            margin: 0;
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
                background: #dcfce7;
                color: #15803d;
                border: 1px solid #bbf7d0;
                padding: 10px 14px;
                border-radius: 10px;
                font-weight: 700;
                font-size: 0.88rem;
                text-align: center;
                margin-bottom: 12px;
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
                gap: 6px;
                padding: 10px 12px;
                border-radius: 8px;
                font-weight: 700;
                font-size: 0.85rem;
                text-decoration: none;
                cursor: pointer;
                border: none;
                box-shadow: 0 2px 4px rgba(0,0,0,0.08);
                transition: transform 0.1s ease, box-shadow 0.1s ease;
            }
            .btn-action:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.12);
            }
            .btn-action:active {
                transform: translateY(1px);
            }

            .btn-print { background: #0f172a; color: #ffffff; }
            .btn-pdf { background: #dc2626; color: #ffffff; }
            .btn-whatsapp { background: #16a34a; color: #ffffff; }
            .btn-back { background: #475569; color: #ffffff; }

            .receipt-wrapper {
                background: #ffffff;
                width: 360px;
                max-width: 95vw;
                padding: 18px 20px;
                border-radius: 12px;
                border: 1px solid #cbd5e1;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
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
                padding: 2mm 3mm 6mm 3mm !important;
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
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body>

    <!-- Success Confirmation Banner -->
    <div class="alert-top no-print">
        <i class="fa-solid fa-circle-check me-1"></i>
        <?= ($type === 'renew') ? 'Package Renewed Successfully!' : 'Payment Collected Successfully!' ?>
    </div>

    <!-- Action Toolbar (Hidden during print / PDF) -->
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
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- 80mm Thermal Receipt Container -->
    <div class="receipt-wrapper">
        <div id="receipt-content">

            <!-- Company / Header -->
            <div class="text-center border-bottom pb-2 mb-2">
                <h3><?= htmlspecialchars($company_name) ?></h3>
                <p class="mb-1" style="font-size: 12px;"><strong>Phone:</strong> <?= htmlspecialchars($company_phone) ?></p>
                <?php if(!empty($operator['address'])): ?>
                    <p style="font-size: 11px; color: #333;"><?= htmlspecialchars($operator['address']) ?></p>
                <?php endif; ?>
            </div>

            <!-- Receipt Title -->
            <div class="text-center border-bottom pb-2 mb-2">
                <div class="fw-bold" style="font-size: 15px; letter-spacing: 1px;">PAYMENT RECEIPT</div>
                <div style="font-size: 11px; text-transform: uppercase;">
                    <?= ($type === 'renew') ? 'Package Renewal / Upgrade' : 'Payment Collection' ?>
                </div>
            </div>

            <!-- Transaction Details Table -->
            <table class="mb-2 border-bottom">
                <tr>
                    <td style="width: 38%;">Date:</td>
                    <td class="text-right"><?= $date ?></td>
                </tr>
                <tr>
                    <td>Invoice#:</td>
                    <td class="text-right fw-bold"><?= $invoice_number ?></td>
                </tr>
                <tr>
                    <td>Username:</td>
                    <td class="text-right fw-bold"><?= htmlspecialchars($sub['username']) ?></td>
                </tr>
                <?php if(!empty($sub['full_name'])): ?>
                <tr>
                    <td>Name:</td>
                    <td class="text-right"><?= htmlspecialchars($sub['full_name']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if(!empty($cust_phone)): ?>
                <tr>
                    <td>Phone:</td>
                    <td class="text-right"><?= htmlspecialchars($cust_phone) ?></td>
                </tr>
                <?php endif; ?>
                <?php if(!empty($sub['package_name'])): ?>
                <tr>
                    <td>Package:</td>
                    <td class="text-right"><?= htmlspecialchars($sub['package_name']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if(!empty($sub['expiry_date'])): ?>
                <tr>
                    <td>New Expiry:</td>
                    <td class="text-right fw-bold"><?= date('d M Y', strtotime($sub['expiry_date'])) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Collected By:</td>
                    <td class="text-right"><?= htmlspecialchars($rm_name) ?></td>
                </tr>
            </table>

            <!-- Financial Table -->
            <table class="mb-2 border-bottom">
                <?php if($type === 'renew' && $package_price > 0): ?>
                <tr>
                    <td>Package Price:</td>
                    <td class="text-right">Rs. <?= number_format($package_price, 0) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="fw-bold" style="font-size: 14px; padding: 4px 0;">Paid Amount:</td>
                    <td class="text-right fw-bold" style="font-size: 14px; padding: 4px 0;">Rs. <?= number_format($paid_amount, 0) ?></td>
                </tr>
            </table>

            <!-- Balance Summary -->
            <table class="mb-2 border-double">
                <?php if($due_amount > 0): ?>
                <tr>
                    <td class="fw-bold" style="font-size: 13px;">Due Balance:</td>
                    <td class="text-right fw-bold" style="font-size: 13px;">Rs. <?= number_format($due_amount, 0) ?></td>
                </tr>
                <?php elseif($adv_amount > 0): ?>
                <tr>
                    <td class="fw-bold" style="font-size: 13px;">Advance Balance:</td>
                    <td class="text-right fw-bold" style="font-size: 13px;">Rs. <?= number_format($adv_amount, 0) ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td>Current Balance:</td>
                    <td class="text-right fw-bold">Rs. 0.00 (Clear)</td>
                </tr>
                <?php endif; ?>
            </table>

            <!-- Footer Message -->
            <div class="text-center pt-2 mt-2" style="font-size: 11px;">
                <p class="fw-bold">Thank you for your payment!</p>
                <p style="font-size: 10px; margin-top: 4px; color: #444;">
                    Powered by <?= defined('BRAND_POWERED_BY') ? BRAND_POWERED_BY : 'SB Link Network' ?>
                </p>
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
