<?php
$f = 'recoveryman/print_receipt.php';
$c = file_get_contents($f);

// 1. Add html2pdf script
$c = str_replace('</head>', "    <script src=\"https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js\"></script>\n</head>", $c);

// 2. Fix Emojis and add Share button
$find_buttons = <<<'HTML'
    <div class="no-print">
        <button onclick="window.print()">dY-",? Print Receipt</button>
        <button class="btn-success" onclick="window.close()">o- Close</button>
    </div>
HTML;
// Note: because the corrupted text is weird, let's use regex
$replace_buttons = <<<'HTML'
    <div class="no-print">
        <button onclick="window.print()">Print</button>
        <button onclick="sharePDF()" style="background: #25D366;">Share PDF</button>
        <button class="btn-success" onclick="window.close()" style="background: #dc3545;">Close</button>
    </div>
    
    <div id="receipt-content" style="padding: 2mm;">
HTML;
$c = preg_replace('/<div class="no-print">.*?<\/div>/s', $replace_buttons, $c, 1);

// 3. Close the #receipt-content div before the script tag at the bottom
$c = str_replace('    <script>', "    </div>\n\n    <script>", $c);

// 4. Add the sharePDF javascript function
$js = <<<'JS'
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

$c = str_replace('window.onload = function() {', $js . "\n\n        window.onload = function() {", $c);

file_put_contents($f, $c);
echo "Added Share PDF feature.\n";
?>
