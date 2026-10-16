<?php
// policies.php - Official Legal, Privacy, Refund, Terms & Compliance Portal
require_once __DIR__ . '/config/branding.php';

$site_phone    = app_brand('support_phone', '0303-6542000');
$site_whatsapp = brand_whatsapp_number();
$site_email    = app_brand('support_email', 'support@sblink.tech');
$site_address  = app_brand('support_address', 'Main Optical Fiber NOC, Piplan / Liaqatabad, District Mianwali, Punjab, Pakistan');
$active_tab    = $_GET['tab'] ?? 'privacy';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legal &amp; Compliance Policies - <?= BRAND_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">

    <!-- Fonts & Bootstrap 5 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #06b6d4;
            --electric-blue: #2563eb;
            --bg-deep: #070d19;
            --bg-card: #0f172a;
            --bg-card-border: rgba(255, 255, 255, 0.08);
            --text-muted: #94a3b8;
        }

        body {
            background-color: var(--bg-deep);
            color: #f8fafc;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar-main {
            background: rgba(7, 13, 25, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--bg-card-border);
        }

        .policy-card {
            background: var(--bg-card);
            border: 1px solid var(--bg-card-border);
            border-radius: 20px;
            padding: 36px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        .nav-pills .nav-link {
            color: var(--text-muted);
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 600;
            margin-bottom: 8px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-pills .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.04);
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, var(--primary), var(--electric-blue));
            color: #fff;
            box-shadow: 0 4px 15px rgba(6, 182, 212, 0.3);
        }

        .info-pill-badge {
            background: rgba(6, 182, 212, 0.1);
            color: #38bdf8;
            border: 1px solid rgba(6, 182, 212, 0.2);
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .policy-section-title {
            color: #fff;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            border-bottom: 1px solid var(--bg-card-border);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .policy-content p, .policy-content li {
            color: #cbd5e1;
            line-height: 1.8;
            font-size: 0.95rem;
        }

        .accordion-button {
            background-color: #1e293b !important;
            color: #fff !important;
            border-radius: 12px !important;
            box-shadow: none !important;
        }
        .accordion-button:not(.collapsed) {
            color: #38bdf8 !important;
            background-color: #1e293b !important;
        }
        .accordion-item {
            background-color: transparent !important;
            border: 1px solid var(--bg-card-border) !important;
            margin-bottom: 12px;
            border-radius: 12px !important;
            overflow: hidden;
        }
        .accordion-body {
            background-color: #0f172a;
            color: #94a3b8;
            font-size: 0.92rem;
            line-height: 1.7;
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-main sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="/">
                <div style="width:36px; height:36px; border-radius:10px; background:linear-gradient(135deg, #06b6d4, #2563eb); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.1rem;">
                    <i class="fa-solid fa-bolt-lightning"></i>
                </div>
                <span class="fs-4 fw-bold text-white font-heading"><?= BRAND_NAME ?></span>
            </a>

            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="/" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Home
                </a>
                <a href="/customer/login.php" class="btn btn-primary rounded-pill px-3 py-1 btn-sm fw-bold">
                    <i class="fa-solid fa-right-to-bracket me-1"></i> Customer Login
                </a>
            </div>
        </div>
    </nav>

    <!-- HEADER HERO -->
    <header class="py-5 text-center border-bottom border-secondary border-opacity-25" style="background: linear-gradient(180deg, rgba(6, 182, 212, 0.05) 0%, transparent 100%);">
        <div class="container">
            <div class="info-pill-badge mb-3">
                <i class="fa-solid fa-shield-halved"></i> Official Regulatory &amp; Consumer Protection Policies
            </div>
            <h1 class="fw-bold text-white mb-2" style="font-family: 'Space Grotesk', sans-serif;">Legal, Privacy &amp; Service Terms</h1>
            <p class="text-muted small mx-auto mb-0" style="max-width: 650px;">
                Providing transparent, high-standard broadband services in compliance with Pakistan Telecommunication Authority (PTA) and State Bank of Pakistan consumer payment guidelines.
            </p>
        </div>
    </header>

    <!-- MAIN BODY -->
    <main class="container py-5 flex-grow-1">
        <div class="row g-4">
            
            <!-- Left Navigation Pills -->
            <div class="col-lg-3">
                <div class="policy-card p-3 sticky-top" style="top: 100px;">
                    <div class="nav flex-column nav-pills" id="policyTabs" role="tablist">
                        <button class="nav-link <?= $active_tab === 'privacy' ? 'active' : '' ?>" id="tab-privacy-btn" data-bs-toggle="pill" data-bs-target="#tab-privacy" type="button" role="tab">
                            <i class="fa-solid fa-user-shield text-info"></i> Privacy Policy
                        </button>
                        <button class="nav-link <?= $active_tab === 'refund' ? 'active' : '' ?>" id="tab-refund-btn" data-bs-toggle="pill" data-bs-target="#tab-refund" type="button" role="tab">
                            <i class="fa-solid fa-rotate-left text-warning"></i> Return &amp; Refund Policy
                        </button>
                        <button class="nav-link <?= $active_tab === 'terms' ? 'active' : '' ?>" id="tab-terms-btn" data-bs-toggle="pill" data-bs-target="#tab-terms" type="button" role="tab">
                            <i class="fa-solid fa-file-contract text-primary"></i> Terms &amp; Conditions
                        </button>
                        <button class="nav-link <?= $active_tab === 'shipping' ? 'active' : '' ?>" id="tab-shipping-btn" data-bs-toggle="pill" data-bs-target="#tab-shipping" type="button" role="tab">
                            <i class="fa-solid fa-truck-fast text-success"></i> Delivery &amp; Hardware
                        </button>
                        <button class="nav-link <?= $active_tab === 'faqs' ? 'active' : '' ?>" id="tab-faqs-btn" data-bs-toggle="pill" data-bs-target="#tab-faqs" type="button" role="tab">
                            <i class="fa-solid fa-circle-question text-info"></i> FAQs
                        </button>
                        <button class="nav-link <?= $active_tab === 'contact' ? 'active' : '' ?>" id="tab-contact-btn" data-bs-toggle="pill" data-bs-target="#tab-contact" type="button" role="tab">
                            <i class="fa-solid fa-headset text-danger"></i> Contact &amp; NOC Info
                        </button>
                    </div>

                    <div class="mt-4 pt-3 border-top border-secondary border-opacity-25 small text-muted">
                        <div><strong>Pricing Currency:</strong> PKR (₨)</div>
                        <div><strong>Helpline:</strong> <?= htmlspecialchars($site_phone) ?></div>
                        <div><strong>Hours:</strong> Mon–Sun (9 AM – 11 PM)</div>
                    </div>
                </div>
            </div>

            <!-- Right Content Area -->
            <div class="col-lg-9">
                <div class="policy-card">
                    <div class="tab-content" id="policyTabsContent">

                        <!-- TAB 1: PRIVACY POLICY -->
                        <div class="tab-pane fade <?= $active_tab === 'privacy' ? 'show active' : '' ?>" id="tab-privacy" role="tabpanel">
                            <h2 class="policy-section-title"><i class="fa-solid fa-user-shield text-info me-2"></i> Privacy Policy</h2>
                            <div class="policy-content">
                                <p>Last Updated: <?= date('F d, Y') ?></p>
                                <p>
                                    At <strong><?= BRAND_NAME ?></strong> (operated by <strong><?= BRAND_POWERED_BY ?></strong>), we value the trust of our subscribers and are committed to protecting their personal and account information. This Privacy Policy governs the collection, usage, and storage of subscriber data across our optical fiber internet network and self-care customer portals.
                                </p>

                                <h5 class="text-white fw-bold mt-4 mb-2">1. Information We Collect</h5>
                                <ul>
                                    <li><strong>Personal Identification Data:</strong> Full Name, National Identity Card (CNIC), Mobile Phone Number, WhatsApp Number, and Physical Installation/Billing Address.</li>
                                    <li><strong>Network &amp; Subscription Details:</strong> Assigned PPPoE Username, Static/Dynamic IP Address, MAC Address of Optical Network Unit (ONU), Speed Tier, and Volume Consumption.</li>
                                    <li><strong>Payment Transaction Records:</strong> Bank Transfer Transaction Reference Numbers (TID), Receipt Slips, and Gateway transaction identifiers. Note: We <em>never</em> store card CVV or sensitive banking passwords.</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">2. How Information is Used</h5>
                                <ul>
                                    <li>Provisioning and maintaining pure optical fiber broadband connectivity.</li>
                                    <li>Billing invoice generation, expiry alerts, and payment verification via SMS/WhatsApp.</li>
                                    <li>Network troubleshooting, bandwidth traffic management, and optical line latency diagnosis.</li>
                                    <li>Compliance with legal regulations enacted by the Pakistan Telecommunication Authority (PTA).</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">3. Data Security &amp; Protection</h5>
                                <p>
                                    All subscriber credentials and communications on this portal are protected with 256-bit SSL encryption (HTTPS), cryptographic password hashing (Bcrypt), and centralized FreeRADIUS firewalls. We do not sell, trade, or disclose subscriber personal data to any unauthorized commercial third party.
                                </p>
                            </div>
                        </div>

                        <!-- TAB 2: RETURN & REFUND POLICY -->
                        <div class="tab-pane fade <?= $active_tab === 'refund' ? 'show active' : '' ?>" id="tab-refund" role="tabpanel">
                            <h2 class="policy-section-title"><i class="fa-solid fa-rotate-left text-warning me-2"></i> Return, Refund &amp; Cancellation Policy</h2>
                            <div class="policy-content">
                                <p class="text-muted small">Effective from: <?= date('Y') ?></p>

                                <div class="alert alert-info border-0 rounded-4 p-3 mb-4 d-flex align-items-center gap-3">
                                    <i class="fa-solid fa-circle-check fs-3 text-info"></i>
                                    <div>
                                        <strong>100% Transparent Consumer Guarantee:</strong> All payment transactions on <?= BRAND_NAME ?> are accounted in Pakistani Rupees (PKR) and protected under our fair refund terms.
                                    </div>
                                </div>

                                <h5 class="text-white fw-bold mt-3 mb-2">1. Subscription Cancellation &amp; Refund Eligibility</h5>
                                <ul>
                                    <li><strong>Pre-Activation Cancellation:</strong> If a customer applies for a new broadband fiber connection and cancels before optical fiber line deployment/installation, <strong>100% of the advance subscription and deposit is refunded</strong> within 3 to 5 business days.</li>
                                    <li><strong>7-Day Service Assurance:</strong> If within the first 7 days of onboarding, <?= BRAND_NAME ?> is unable to provide promised speed or signal feasibility at the customer's premises due to network constraints, the monthly subscription will be refunded on a pro-rata basis.</li>
                                    <li><strong>Post-Activation Monthly Renewals:</strong> Once a 30-day package cycle is fully activated and bandwidth consumed, mid-month cancellations are non-refundable; however, the subscriber can schedule package changes or non-renewal at the end of the billing period.</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">2. Hardware (ONU / Router) Return Policy</h5>
                                <ul>
                                    <li>Optical Network Terminals (ONU/ONT) or Wi-Fi routers supplied on security deposit remain the property of <?= BRAND_NAME ?> and are eligible for 100% deposit return upon safe return of un-damaged equipment during service termination.</li>
                                    <li>Equipment dead-on-arrival or facing hardware defects within manufacturer warranty (1 year) will be replaced free of charge by our technical field team.</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">3. Refund Processing Timeline</h5>
                                <p>
                                    Approved refunds are processed via the customer's original mode of payment (Bank Transfer, EasyPaisa, JazzCash, or Debit Card) within <strong>5 to 7 working days</strong>. For refund requests, email <a href="mailto:<?= htmlspecialchars($site_email) ?>" class="text-info"><?= htmlspecialchars($site_email) ?></a> or call <a href="tel:<?= htmlspecialchars($site_phone) ?>" class="text-info"><?= htmlspecialchars($site_phone) ?></a>.
                                </p>
                            </div>
                        </div>

                        <!-- TAB 3: TERMS & CONDITIONS -->
                        <div class="tab-pane fade <?= $active_tab === 'terms' ? 'show active' : '' ?>" id="tab-terms" role="tabpanel">
                            <h2 class="policy-section-title"><i class="fa-solid fa-file-contract text-primary me-2"></i> Terms &amp; Conditions (Service Agreement)</h2>
                            <div class="policy-content">
                                <h5 class="text-white fw-bold mt-2 mb-2">1. Acceptance of Terms</h5>
                                <p>
                                    By accessing or using the services provided by <?= BRAND_NAME ?>, whether via web portals, mobile logins, or optical fiber customer premises equipment (CPE), you acknowledge and agree to comply with these terms.
                                </p>

                                <h5 class="text-white fw-bold mt-4 mb-2">2. Service Provision &amp; Billing</h5>
                                <ul>
                                    <li>All internet plans are billed on a 30-day prepaid cycle in Pakistani Rupees (PKR) unless specified otherwise.</li>
                                    <li>Automated bandwidth shaping and speed throttling conform to the specific package subscribed by the customer (e.g. 10 Mbps, 20 Mbps, 50 Mbps).</li>
                                    <li>Subscribers will receive automated notification reminders 3 days and 1 day prior to package expiry. Unpaid accounts will be smoothly suspended until renewal.</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">3. Fair Usage Policy (FUP) &amp; Lawful Use</h5>
                                <ul>
                                    <li>Connections are intended for private residential or commercial use as contracted. Reselling or distributing broadband bandwidth across unauthorized premises is strictly prohibited.</li>
                                    <li>Subscribers must not utilize the connection for any illegal activities, cyber-attacks, transmission of illicit content, or violations of PTA and PECA (Prevention of Electronic Crimes Act) laws.</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">4. Service Availability (SLA)</h5>
                                <p>
                                    <?= BRAND_NAME ?> operates redundant carrier backbones (PTCL, Telenor Fiber) ensuring a 99.5% uptime target. Scheduled maintenance will be announced with prior notification via SMS or portal notices.
                                </p>
                            </div>
                        </div>

                        <!-- TAB 4: DELIVERY & HARDWARE POLICY -->
                        <div class="tab-pane fade <?= $active_tab === 'shipping' ? 'show active' : '' ?>" id="tab-shipping" role="tabpanel">
                            <h2 class="policy-section-title"><i class="fa-solid fa-truck-fast text-success me-2"></i> Shipping &amp; Hardware Delivery Policy</h2>
                            <div class="policy-content">
                                <div class="alert alert-success border-0 rounded-4 p-3 mb-4 d-flex align-items-center gap-3">
                                    <i class="fa-solid fa-truck-ramp-box fs-3 text-success"></i>
                                    <div>
                                        <strong>Telecom Physical Delivery Notice:</strong> For internet telecom services, "Shipping and Delivery" refers to the dispatch, home delivery, and on-site engineering installation of optical fiber hardware and FTTH drop cabling.
                                    </div>
                                </div>

                                <h5 class="text-white fw-bold mt-3 mb-2">1. Delivery Timeline &amp; On-Site Installation</h5>
                                <ul>
                                    <li>Upon receipt of a new connection order or hardware replacement request, our certified field technician delivers the Optical Network Terminal (ONU) and patch cables within <strong>24 to 48 hours</strong>.</li>
                                    <li>Installation includes core optical fusion splicing from the nearest Optical Distribution Box (ODB) to the subscriber's home.</li>
                                    <li>Delivery and standard physical cabling within our coverage radius are arranged by local area engineering teams without external postal delays.</li>
                                </ul>

                                <h5 class="text-white fw-bold mt-4 mb-2">2. Hardware Inspection &amp; Testing</h5>
                                <p>
                                    Every delivered ONU router is tested live in the presence of the customer, verifying optical signal reception (-18 to -23 dBm) and speed benchmarks before signing the commissioning sheet.
                                </p>
                            </div>
                        </div>

                        <!-- TAB 5: FAQS -->
                        <div class="tab-pane fade <?= $active_tab === 'faqs' ? 'show active' : '' ?>" id="tab-faqs" role="tabpanel">
                            <h2 class="policy-section-title"><i class="fa-solid fa-circle-question text-info me-2"></i> Frequently Asked Questions (FAQs)</h2>
                            <div class="accordion" id="accordionFaqs">
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                            How do I pay or renew my internet package?
                                        </button>
                                    </h2>
                                    <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#accordionFaqs">
                                        <div class="accordion-body">
                                            You can easily renew your package via your <strong>Customer Portal Dashboard</strong> using Online Bank Transfer, JazzCash, EasyPaisa, or direct cash deposit through authorized recovery agents. Your package status updates automatically upon verification.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                            What should I do if my ONU optical router shows a red LOS light?
                                        </button>
                                    </h2>
                                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#accordionFaqs">
                                        <div class="accordion-body">
                                            A red blinking LOS light indicates an interruption in the optical fiber drop cable. Please submit a quick complaint ticket via the <strong>Support Tickets</strong> tab in your customer portal, or contact our WhatsApp helpline at <?= htmlspecialchars($site_phone) ?>. Our lineman team will repair the line promptly.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                            How long does new broadband installation take?
                                        </button>
                                    </h2>
                                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#accordionFaqs">
                                        <div class="accordion-body">
                                            Once your request is registered and location feasibility is verified, connection deployment and hardware installation are completed within <strong>24 to 48 hours</strong>.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                            Can I upgrade my speed mid-month?
                                        </button>
                                    </h2>
                                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#accordionFaqs">
                                        <div class="accordion-body">
                                            Yes! You can choose any higher package from the Available Internet Packages section on your dashboard. The speed change applies instantly on our MikroTik router once approved.
                                        </div>
                                    </div>
                                </div>

                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                            Are all prices in Pakistani Rupees (PKR)?
                                        </button>
                                    </h2>
                                    <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#accordionFaqs">
                                        <div class="accordion-body">
                                            Yes. All pricing shown across our packages and invoices is explicitly quoted in <strong>PKR (₨)</strong> with zero hidden international transaction surcharges.
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- TAB 6: CONTACT & OPERATIONAL HOURS -->
                        <div class="tab-pane fade <?= $active_tab === 'contact' ? 'show active' : '' ?>" id="tab-contact" role="tabpanel">
                            <h2 class="policy-section-title"><i class="fa-solid fa-headset text-danger me-2"></i> Customer Service &amp; Operational Hours</h2>
                            <div class="policy-content">
                                <div class="row g-4">
                                    
                                    <div class="col-md-6">
                                        <div class="p-4 rounded-4" style="background:#1e293b; border: 1px solid var(--bg-card-border);">
                                            <h5 class="fw-bold text-white mb-3"><i class="fa-solid fa-clock text-info me-2"></i> Operational Hours</h5>
                                            <ul class="list-unstyled mb-0">
                                                <li class="mb-2 d-flex justify-content-between border-bottom border-secondary border-opacity-25 pb-2">
                                                    <span>Customer Support Desk:</span>
                                                    <strong class="text-white">09:00 AM – 11:00 PM PKT</strong>
                                                </li>
                                                <li class="mb-2 d-flex justify-content-between border-bottom border-secondary border-opacity-25 pb-2">
                                                    <span>Days of Operation:</span>
                                                    <strong class="text-success">Monday – Sunday (7 Days)</strong>
                                                </li>
                                                <li class="d-flex justify-content-between pt-1">
                                                    <span>Network Operations Center (NOC):</span>
                                                    <strong class="text-info">24/7/365 Automated Monitoring</strong>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="p-4 rounded-4" style="background:#1e293b; border: 1px solid var(--bg-card-border);">
                                            <h5 class="fw-bold text-white mb-3"><i class="fa-solid fa-address-book text-warning me-2"></i> Contact Details</h5>
                                            <ul class="list-unstyled mb-0 small">
                                                <li class="mb-2">
                                                    <i class="fa-solid fa-phone text-info me-2"></i> <strong>Helpline:</strong> <a href="tel:<?= htmlspecialchars($site_phone) ?>" class="text-white text-decoration-none"><?= htmlspecialchars($site_phone) ?></a>
                                                </li>
                                                <li class="mb-2">
                                                    <i class="fa-brands fa-whatsapp text-success me-2"></i> <strong>WhatsApp:</strong> <a href="https://wa.me/<?= $site_whatsapp ?>" target="_blank" class="text-white text-decoration-none"><?= htmlspecialchars($site_phone) ?></a>
                                                </li>
                                                <li class="mb-2">
                                                    <i class="fa-solid fa-envelope text-info me-2"></i> <strong>Official Email:</strong> <a href="mailto:<?= htmlspecialchars($site_email) ?>" class="text-white text-decoration-none"><?= htmlspecialchars($site_email) ?></a>
                                                </li>
                                                <li class="mt-3 pt-2 border-top border-secondary border-opacity-25">
                                                    <i class="fa-solid fa-location-dot text-danger me-2"></i> <strong>Permanent Business Address:</strong><br>
                                                    <span class="text-muted"><?= htmlspecialchars($site_address) ?></span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- FOOTER -->
    <footer class="py-4 border-top border-secondary border-opacity-25" style="background: #050914;">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
            <div>
                &copy; <?= date('Y') ?> <?= BRAND_NAME ?>. All Rights Reserved. Fully compliant with Payment Gateway &amp; Consumer Fair Trade Standards.
            </div>
            <div>
                Powered by <strong class="text-info"><?= BRAND_POWERED_BY ?></strong>
            </div>
        </div>
    </footer>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Support URL hash routing (e.g. policies.php#refund)
        document.addEventListener('DOMContentLoaded', function() {
            var hash = window.location.hash;
            if (hash) {
                var tabTrigger = document.querySelector('button[data-bs-target="' + hash + '"]') ||
                                 document.querySelector('button[data-bs-target="#tab-' + hash.replace('#', '') + '"]');
                if (tabTrigger) {
                    var tab = new bootstrap.Tab(tabTrigger);
                    tab.show();
                }
            }
        });
    </script>
</body>
</html>
