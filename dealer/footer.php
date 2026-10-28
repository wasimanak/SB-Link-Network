    </div> <!-- End Main Content -->
    
    <!-- Add Funds Modal (Global for Dealer) -->
    <div class="modal fade" id="addFundsModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
          <div class="modal-header border-bottom border-secondary p-4">
            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-wallet me-2"></i> Recharge Wallet</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form action="fund_action.php" method="POST">
            <div class="modal-body p-3">
                <div class="text-center mb-3">
                    <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                    <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                    <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                        <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?= strtoupper(BRAND_SHORT_NAME) ?>-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label text-secondary fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                    <input type="number" name="amount" id="fund_amount" class="form-control form-control-sm bg-dark border-secondary text-light fs-6" placeholder="e.g. 500" required onkeyup="updateFundQR()">
                </div>
                
                <div class="mb-2">
                    <label class="form-label text-secondary fw-bold small mb-1">Transaction Reference ID</label>
                    <input type="text" name="payment_reference" class="form-control form-control-sm bg-dark border-secondary text-light" placeholder="e.g. TID987654321" required>
                </div>
                <div class="text-secondary" style="font-size: 0.75rem;">Scan the QR Code to pay. After paying, submit the request.</div>
            </div>
            <div class="modal-footer border-top border-secondary p-2 d-flex justify-content-between">
              <button type="button" class="btn btn-sm btn-outline-secondary px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateFundQR() {
        var amount = document.getElementById('fund_amount').value || "0";
        var qrData = encodeURIComponent("FUNDS_DEALER_<?= $current_dealer['id'] ?>_AMT_" + amount + "_TS_" + Date.now());
        var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" + qrData;
        document.getElementById('fund_qr_code').src = qrUrl;
    }
    </script>
    <style>
        body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
        input, textarea, select, [contenteditable="true"] { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }

        .copy-user-btn {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            color: #64748b;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            cursor: pointer;
            transition: all 0.2s ease;
            vertical-align: middle;
            flex-shrink: 0;
            user-select: auto !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .copy-user-btn:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
            border-color: #cbd5e1 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .copy-user-btn:active {
            transform: translateY(0);
        }
        .copy-user-btn.copied {
            background: #ecfdf5 !important;
            color: #059669 !important;
            border-color: #a7f3d0 !important;
        }

        #copy-toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 100000;
            pointer-events: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .copy-toast-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 15px;
            background: #0f172a;
            color: #ffffff;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.35);
            opacity: 0;
            transform: translateY(15px);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            pointer-events: none;
        }
        .copy-toast-item.show {
            opacity: 1;
            transform: translateY(0);
        }
        .copy-toast-item code {
            background: rgba(255, 255, 255, 0.18);
            color: #38bdf8;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: var(--bs-font-monospace, monospace);
            font-size: 0.88rem;
        }
    </style>
    <script>
    window.copyToClipboard = function(event, text, btn) {
        if (event) {
            if (typeof event.preventDefault === 'function') event.preventDefault();
            if (typeof event.stopPropagation === 'function') event.stopPropagation();
        }
        if (!text) return;

        function handleSuccess() {
            if (btn) {
                var origHtml = btn.innerHTML;
                btn.classList.add('copied');
                btn.innerHTML = '<i class="fa-solid fa-check" style="font-size: 0.8rem; color: #10b981;"></i>';
                btn.setAttribute('title', 'Copied!');
                setTimeout(function() {
                    btn.innerHTML = origHtml;
                    btn.classList.remove('copied');
                    btn.setAttribute('title', 'Copy Username');
                }, 1800);
            }
            showCopyToast(text);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(handleSuccess).catch(function() {
                fallbackCopy(text, handleSuccess);
            });
        } else {
            fallbackCopy(text, handleSuccess);
        }
    };

    function fallbackCopy(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.left = '0';
        ta.style.width = '2em';
        ta.style.height = '2em';
        ta.style.padding = '0';
        ta.style.border = 'none';
        ta.style.outline = 'none';
        ta.style.boxShadow = 'none';
        ta.style.background = 'transparent';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            var successful = document.execCommand('copy');
            if (successful && cb) cb();
        } catch (err) {
            prompt('Copy username:', text);
        }
        document.body.removeChild(ta);
    }

    function showCopyToast(username) {
        var container = document.getElementById('copy-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'copy-toast-container';
            document.body.appendChild(container);
        }
        var toast = document.createElement('div');
        toast.className = 'copy-toast-item';
        var safeUser = String(username).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        toast.innerHTML = '<i class="fa-solid fa-circle-check text-success fs-6"></i> Copied <code>' + safeUser + '</code> to clipboard!';
        container.appendChild(toast);
        setTimeout(function() { toast.classList.add('show'); }, 10);
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }, 2200);
    }
    </script>
    <script src="/security-shield.js?v=2.1"></script>
</body>
</html>