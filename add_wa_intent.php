<?php
$f = 'recoveryman/print_receipt.php';
$c = file_get_contents($f);

// 1. Add mobile to the query
$c = str_replace(
    'SELECT s.username, s.full_name, s.balance, s.expiry_date, p.name as package_name, p.price as package_price',
    'SELECT s.username, s.full_name, s.balance, s.expiry_date, s.mobile, s.phone, p.name as package_name, p.price as package_price',
    $c
);

// 2. Extract number safely in PHP
$php_logic = <<<'PHP'
$company_name = $operator['company_name'] ?? 'ISP Provider';
$company_phone = $operator['phone'] ?? 'N/A';
$date = date('d M Y h:i A');

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
$wa_text .= "Paid Amount: Rs. " . number_format($paid_amount, 2) . "\n";
if($due_amount > 0) {
    $wa_text .= "Due Amount: Rs. " . number_format($due_amount, 2) . "\n";
}
$wa_text .= "------------------------\n";
$wa_text .= "Please find the PDF attachment as well.";
$wa_text_encoded = urlencode($wa_text);
PHP;

$c = preg_replace('/\$company_name = .*?\$date = date\(\'d M Y h:i A\'\);/s', $php_logic, $c);

// 3. Update the button and JS
$find_button = '<button onclick="sharePDF()" style="background: #25D366;">Share PDF</button>';
$replace_button = '<button onclick="shareWhatsApp()" style="background: #25D366;"><i class="fa-brands fa-whatsapp"></i> WhatsApp Share</button>';
$c = str_replace($find_button, $replace_button, $c);

// Also add fontawesome if not present
if(strpos($c, 'font-awesome') === false) {
    $c = str_replace('</head>', "    <link rel=\"stylesheet\" href=\"https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css\">\n</head>", $c);
}

// 4. Update the Javascript function
$find_js = <<<'JS'
        function sharePDF() {
            var element = document.getElementById('receipt-content');
            
            // Calculate dynamic height based on content
            var pxHeight = element.offsetHeight;
            var mmHeight = (pxHeight * 0.264583) + 15; // padding
            
            var opt = {
              margin:       0,
              filename:     'Receipt_<?= $sub["username"] ?>.pdf',
              image:        { type: 'jpeg', quality: 0.98 },
              html2canvas:  { scale: 3, useCORS: true },
              jsPDF:        { unit: 'mm', format: [58, mmHeight], orientation: 'portrait' }
            };

            // Generate PDF
            html2pdf().set(opt).from(element).output('blob').then(function(blob) {
                var file = new File([blob], 'Receipt_<?= $sub["username"] ?>.pdf', { type: 'application/pdf' });
                
                // Use Web Share API if available (Mobile devices)
                if (navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({
                        title: 'Payment Receipt',
                        text: 'Please find your payment receipt attached.',
                        files: [file]
                    }).catch(function(error) {
                        console.log('Error sharing:', error);
                    });
                } else {
                    // Fallback to Download (Desktop)
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'Receipt_<?= $sub["username"] ?>.pdf';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    alert("PDF Downloaded. You can now send it to your customer.");
                }
            });
        }
JS;

$replace_js = <<<'JS'
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

            // Generate and Download PDF automatically
            html2pdf().set(opt).from(element).output('blob').then(function(blob) {
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'Receipt_<?= $sub["username"] ?>.pdf';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                
                // Then redirect to WhatsApp Chat
                var waNumber = "<?= $wa_number ?>";
                var waText = "<?= $wa_text_encoded ?>";
                
                if (waNumber !== "") {
                    // Open specific chat
                    window.location.href = "https://wa.me/" + waNumber + "?text=" + waText;
                } else {
                    // Open general WhatsApp share
                    window.location.href = "https://wa.me/?text=" + waText;
                }
            });
        }
JS;

$c = str_replace($find_js, $replace_js, $c);

file_put_contents($f, $c);
echo "Added WhatsApp Intent Feature.\n";
?>
