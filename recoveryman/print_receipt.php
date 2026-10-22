<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['rm_id']) || !isset($_SESSION['client_id'])) {
    die("Unauthorized");
}

$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['client_id'];
$sub_id = isset($_GET['sub_id']) ? (int)$_GET['sub_id'] : 0;
$paid_amount = isset($_GET['paid']) ? (float)$_GET['paid'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'collect'; // renew or collect

if ($sub_id == 0) {
    die("Invalid request");
}

// Fetch Operator Details
$opStmt = $pdo->prepare("SELECT company_name, phone FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator = $opStmt->fetch();

// Fetch Subscriber Details
$subStmt = $pdo->prepare("
    SELECT s.username, s.full_name, s.balance, s.expiry_date, s.mobile, s.phone, p.name as package_name, p.price as package_price 
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE s.id = ? AND s.client_id = ?
");
$subStmt->execute([$sub_id, $client_id]);
$sub = $subStmt->fetch();

if (!$sub) {
    die("Subscriber not found.");
}

$company_name = $operator['company_name'] ?? 'ISP Provider';
$company_phone = $operator['phone'] ?? 'N/A';
$date = date('d M Y h:i A');

// Fetch Latest Transaction ID for Invoice#
$invStmt = $pdo->prepare("SELECT id FROM user_ledger WHERE username = ? AND client_id = ? ORDER BY id DESC LIMIT 1");
$invStmt->execute([$sub['username'], $client_id]);
$invoice_id = $invStmt->fetchColumn() ?: rand(10000, 99999);
$invoice_number = "INV-" . str_pad($invoice_id, 6, "0", STR_PAD_LEFT);

// Customer Phone Logic
$cust_phone = $sub['mobile'] ?: $sub['phone'];
$wa_number = '';
if($cust_phone) {
    // Clean number (remove non-numeric)
    $clean = preg_replace('/[^0-9]/', '', $cust_phone);
    if(substr($clean, 0, 1) === '0') {
        $wa_number = '92' . substr($clean, 1);
    } else {
        $wa_number = $clean;
    }
}

// WhatsApp Text Receipt
$wa_text = "*PAYMENT RECEIPT*\n";
$wa_text .= "------------------------\n";
$wa_text .= "*{$company_name}*\n";
$wa_text .= "Phone: {$company_phone}\n";
$wa_text .= "------------------------\n";
$wa_text .= "Date: {$date}\n";
$wa_text .= "User: {$sub['username']}\n";
if($sub['package_name']) {
    $wa_text .= "Package: {$sub['package_name']}\n";
}
$wa_text .= "Paid Amount: Rs. " . number_format($paid_amount, 0) . "\n";
if($due_amount > 0) {
    $wa_text .= "Due Amount: Rs. " . number_format($due_amount, 0) . "\n";
}
$wa_text .= "------------------------\n";
$wa_text .= "Please find the PDF attachment as well.";
$wa_text_encoded = urlencode($wa_text);

// Total amount logic
$package_price = $sub['package_price'] ?? 0;
// If the user's balance is negative, it means they owe money.
// "Due amount" usually means the absolute value of negative balance.
$due_amount = $sub['balance'] < 0 ? abs($sub['balance']) : 0;
$adv_amount = $sub['balance'] > 0 ? $sub['balance'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?= htmlspecialchars($sub['username']) ?></title>
    <style>
        /* 58mm Thermal Printer Width is approx 219px (at 96 DPI), but 226px is safer for padding */
        @page {
            margin: 0;
            size: 58mm auto;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            width: 58mm;
            margin: 0 auto;
            padding: 5mm;
            background-color: #fff;
            color: #000;
            font-size: 12px;
            box-sizing: border-box;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: bold; }
        .mb-1 { margin-bottom: 5px; }
        .mb-2 { margin-bottom: 10px; }
        .border-top { border-top: 1px dashed #000; padding-top: 5px; margin-top: 5px; }
        .border-bottom { border-bottom: 1px dashed #000; padding-bottom: 5px; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        td { padding: 2px 0; vertical-align: top; }
        h3 { margin: 0 0 5px 0; font-size: 16px; font-weight: bold; }
        p { margin: 0; }
        
        /* Utility for screen viewing */
        @media screen {
            body {
                border: 1px solid #ccc;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
                margin-top: 20px;
                margin-bottom: 20px;
                padding: 15px;
                width: 280px; /* slightly larger for screen simulation of 58mm */
            }
            .no-print {
                display: flex;
                justify-content: center;
                gap: 10px;
                margin-bottom: 20px;
                border: none;
                box-shadow: none;
                width: 100%;
                background: #f8f9fa;
                padding: 10px;
            }
            .no-print button {
                padding: 8px 15px;
                background: #0d6efd;
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: bold;
            }
            .no-print button.btn-success { background: #198754; }
        }
        @media print {
            .no-print { display: none !important; }
            body { border: none; box-shadow: none; margin: 0; padding: 0; width: 58mm; }
        }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

        <div class="no-print">
        <button onclick="window.print()">Print</button>
        <button onclick="shareWhatsApp()" style="background: #25D366;"><i class="fa-brands fa-whatsapp"></i> WhatsApp Share</button>
        <button class="btn-success" onclick="window.close()" style="background: #dc3545;">Close</button>
    </div>
    
    <div id="receipt-content" style="padding: 2mm;">

    <div class="text-center border-bottom">
        <h3><?= htmlspecialchars($company_name) ?></h3>
        <p class="mb-1">Phone: <?= htmlspecialchars($company_phone) ?></p>
    </div>
    
    <div class="text-center mb-2">
        <p class="fw-bold" style="font-size:14px; margin-top:5px;">PAYMENT RECEIPT</p>
    </div>

        <table class="mb-2 border-bottom">
        <tr>
            <td style="width: 35%;">Date:</td>
            <td class="text-right"><?= $date ?></td>
        </tr>
        <tr>
            <td>Invoice#:</td>
            <td class="text-right fw-bold"><?= $invoice_number ?></td>
        </tr>
        <tr>
            <td>User:</td>
            <td class="text-right fw-bold"><?= htmlspecialchars($sub['username']) ?></td>
        </tr>
        <tr>
            <td>Name:</td>
            <td class="text-right"><?= htmlspecialchars($sub['full_name']) ?></td>
        </tr>
        <?php if($sub['package_name']): ?>
        <tr>
            <td>Package:</td>
            <td class="text-right"><?= htmlspecialchars($sub['package_name']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if(!empty($sub['expiry_date'])): ?>
        <tr>
            <td>Expiry:</td>
            <td class="text-right"><?= date('d M Y', strtotime($sub['expiry_date'])) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="mb-2 border-bottom">
        <?php if($type == 'renew' && $package_price > 0): ?>
        <tr>
            <td>Package Price:</td>
            <td class="text-right">Rs. <?= number_format($package_price, 0) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td class="fw-bold" style="font-size:13px;">Paid Amount:</td>
            <td class="text-right fw-bold" style="font-size:13px;">Rs. <?= number_format($paid_amount, 0) ?></td>
        </tr>
    </table>

    <table class="mb-2">
        <?php if($due_amount > 0): ?>
        <tr>
            <td class="fw-bold">Due Amount:</td>
            <td class="text-right fw-bold">Rs. <?= number_format($due_amount, 0) ?></td>
        </tr>
        <?php elseif($adv_amount > 0): ?>
        <tr>
            <td>Adv Balance:</td>
            <td class="text-right">Rs. <?= number_format($adv_amount, 0) ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <td>Balance:</td>
            <td class="text-right">Rs. 0.00</td>
        </tr>
        <?php endif; ?>
    </table>
    
    <div class="text-center border-top pt-2 mt-2">
        <p>Thank you for your business!</p>
        <p style="font-size: 10px; margin-top: 5px;">Powered by <?= BRAND_POWERED_BY ?></p>
    </div>

    </div>

    <script>
        // Auto trigger print dialog for convenience when opened
                function shareWhatsApp() {
            var element = document.getElementById('receipt-content');
            var pxHeight = element.offsetHeight;
            var mmHeight = (pxHeight * 0.264583) + 15;
            
            var opt = {
              margin:       0,
              filename:     'Receipt_<?= $sub["username"] ?>.pdf',
              image:        { type: 'jpeg', quality: 0.98 },
              html2canvas:  { scale: 3, useCORS: true },
              jsPDF:        { unit: 'mm', format: [58, mmHeight], orientation: 'portrait' }
            };

            // Generate PDF and send directly to mobile share sheet
            html2pdf().set(opt).from(element).output('blob').then(function(blob) {
                var file = new File([blob], 'Receipt_<?= $sub["username"] ?>.pdf', { type: 'application/pdf' });
                
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    // Opens Mobile Share Menu (Select WhatsApp from here)
                    navigator.share({
                        files: [file],
                        title: 'Payment Receipt'
                    }).catch(function(error) {
                        console.log('Error sharing:', error);
                    });
                } else {
                    // If on PC or unsupported browser, just download it
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'Receipt_<?= $sub["username"] ?>.pdf';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    alert("Aapka device direct file share support nahi karta, is liye PDF download ho gayi hai.");
                }
            });
        }

        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>
