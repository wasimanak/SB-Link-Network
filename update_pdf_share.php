<?php
$f = 'recoveryman/print_receipt.php';
$c = file_get_contents($f);

// We need to replace the shareWhatsApp function completely.
$find_js = <<<'JS'
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
JS;

$c = str_replace($find_js, $replace_js, $c);
file_put_contents($f, $c);
echo "Reverted to Web Share API for direct PDF sharing.\n";
?>
