</div> <!-- End Main Container -->

<?php
if (!isset($operator_info) && isset($client_id)) {
    $opStmt = $pdo->prepare("SELECT company_name, phone, email, address FROM clients WHERE id = ?");
    $opStmt->execute([$client_id]);
    $operator_info = $opStmt->fetch();
}
?>

<!-- Premium ISP Footer -->
<footer class="mt-5 pt-5 pb-4 bg-black border-top border-secondary text-secondary">
    <div class="container-fluid">
        <div class="row px-md-4">
            <!-- Company Info -->
            <div class="col-lg-4 col-md-6 mb-4">
                <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-wifi text-accent me-2"></i> <?= htmlspecialchars($operator_info['company_name'] ?? BRAND_NAME) ?></h5>
                <p class="small mb-3">Providing ultra-fast, reliable, and unlimited internet connectivity for your home and business. Experience the internet like never before.</p>
                <div class="d-flex gap-3">
                    <a href="#" class="text-secondary text-decoration-none border border-secondary rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; transition:0.3s;"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" class="text-secondary text-decoration-none border border-secondary rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; transition:0.3s;"><i class="fa-brands fa-twitter"></i></a>
                    <a href="#" class="text-secondary text-decoration-none border border-secondary rounded-circle d-flex align-items-center justify-content-center" style="width:36px; height:36px; transition:0.3s;"><i class="fa-brands fa-instagram"></i></a>
                </div>
            </div>
            
            <!-- Quick Links -->
            <div class="col-lg-2 col-md-6 mb-4">
                <h6 class="text-white fw-bold mb-3">Quick Links</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="dashboard.php" class="text-secondary text-decoration-none hover-white">Dashboard</a></li>
                    <li class="mb-2"><a href="#" class="text-secondary text-decoration-none hover-white">My Package</a></li>
                    <li class="mb-2"><a href="#" class="text-secondary text-decoration-none hover-white">Billing History</a></li>
                    <li class="mb-2"><a href="#" class="text-secondary text-decoration-none hover-white">Support Tickets</a></li>
                </ul>
            </div>
            
            <!-- Legal -->
            <div class="col-lg-2 col-md-6 mb-4">
                <h6 class="text-white fw-bold mb-3">Legal</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><a href="#" class="text-secondary text-decoration-none hover-white">Terms of Service</a></li>
                    <li class="mb-2"><a href="#" class="text-secondary text-decoration-none hover-white">Privacy Policy</a></li>
                    <li class="mb-2"><a href="#" class="text-secondary text-decoration-none hover-white">Fair Usage Policy</a></li>
                </ul>
            </div>
            
            <!-- Contact -->
            <div class="col-lg-4 col-md-6 mb-4">
                <h6 class="text-white fw-bold mb-3">Contact Support</h6>
                <ul class="list-unstyled small">
                    <li class="mb-2"><i class="fa-solid fa-phone me-2 text-accent"></i> <?= htmlspecialchars($operator_info['phone'] ?? BRAND_SUPPORT_PHONE) ?></li>
                    <li class="mb-2"><i class="fa-solid fa-envelope me-2 text-accent"></i> <?= htmlspecialchars($operator_info['email'] ?? BRAND_SUPPORT_EMAIL) ?></li>
                    <li class="mb-2 d-flex"><i class="fa-solid fa-location-dot me-2 text-accent mt-1"></i> <span><?= htmlspecialchars($operator_info['address'] ?? 'Customer Care Center') ?></span></li>
                </ul>
            </div>
        </div>
        
        <div class="text-center mt-4 pt-3 border-top border-secondary small">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($operator_info['company_name'] ?? BRAND_NAME) ?>. All Rights Reserved. <br>
            <span class="text-muted" style="font-size:0.75rem;">Powered by <?= BRAND_POWERED_BY ?></span>
        </div>
    </div>
</footer>

<style>
.hover-white:hover { color: #fff !important; }
footer a.rounded-circle:hover { background: #3b82f6; color: #fff !important; border-color: #3b82f6 !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<style>
    body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
    input, textarea, select, [contenteditable="true"] { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }
</style>
<script src="/security-shield.js?v=2.1"></script>
</body>
</html>
