<?php
session_start();

// 1. Subdomain Check: If accessed via customer subdomain (e.g. customer.lasani.tech)
$host = $_SERVER['HTTP_HOST'] ?? '';
if (stripos($host, 'customer.') === 0 || stripos($host, 'customer') !== false) {
    if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
        header("Location: customer/dashboard");
    } else {
        header("Location: customer/login");
    }
    exit;
}

// 2. Load Centralized Branding & Configuration
require_once __DIR__ . '/config/branding.php';

// Contact & Support info fetched DIRECTLY from config/branding.php as requested
$site_phone          = app_brand('support_phone', '0301-6542060');
$site_whatsapp       = brand_whatsapp_number();
$site_email          = app_brand('support_email', 'malikmobiles4004@gmail.com');
$site_address        = app_brand('support_address', 'Al Munir Mobile Shop Nearr MCB Bank, Piplan, District Mianwali, Pakistan');
$customer_portal_url = app_brand('customer_portal_url', '/customer/login');
$login_url           = app_brand('login_url', '/login');

// Speed formatter helper
if (!function_exists('format_pkg_speed')) {
    function format_pkg_speed($rate_limit, $name = '') {
        if (!empty($rate_limit)) {
            if (preg_match('/(\d+)\s*[mM]/', $rate_limit, $m)) {
                return $m[1] . ' Mbps';
            }
            if (preg_match('/(\d+)\s*[kK]/', $rate_limit, $m)) {
                $mb = round($m[1] / 1024);
                return ($mb > 0 ? $mb : 1) . ' Mbps';
            }
        }
        if (!empty($name)) {
            if (preg_match('/(\d+)\s*Mbps/i', $name, $m)) {
                return $m[1] . ' Mbps';
            }
            if (preg_match('/(\d+)\s*MB/i', $name, $m)) {
                return $m[1] . ' Mbps';
            }
        }
        return (!empty($rate_limit) && $rate_limit !== 'Unlimited') ? $rate_limit : 'High-Speed';
    }
}

// Fetch SuperAdmin Master & Global Packages
$site_packages = [];
try {
    if (file_exists(__DIR__ . '/config/db.php')) {
        @include_once __DIR__ . '/config/db.php';
        if (isset($pdo)) {
            // Priority 1: SuperAdmin Master & Global packages (is_master = 1 or client_id = 0)
            $pkgStmt = $pdo->query("SELECT id, name, price, rate_limit, validity_days, data_limit_gb 
                                    FROM packages 
                                    WHERE (is_master = 1 OR client_id = 0) 
                                      AND (master_id IS NULL OR master_id = 0)
                                      AND price > 0 
                                    ORDER BY price ASC");
            $site_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

            // Priority 2: Fallback to all global or master packages
            if (empty($site_packages)) {
                $pkgStmt = $pdo->query("SELECT id, name, price, rate_limit, validity_days, data_limit_gb 
                                        FROM packages 
                                        WHERE is_master = 1 OR client_id = 0 
                                        ORDER BY price ASC");
                $site_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Priority 3: Fallback to distinct packages with price
            if (empty($site_packages)) {
                $pkgStmt = $pdo->query("SELECT id, name, price, rate_limit, validity_days, data_limit_gb 
                                        FROM packages 
                                        WHERE price > 0 
                                        GROUP BY rate_limit, price 
                                        ORDER BY price ASC 
                                        LIMIT 8");
                $site_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (Exception $e) {}

// Fallback 4: Clean default packages matching SuperAdmin standard tier if database is offline
if (empty($site_packages)) {
    $site_packages = [
        ['id' => 1, 'name' => '4 Mbps Home Starter',    'rate_limit' => '4M/4M',   'price' => 1000, 'validity_days' => 30, 'data_limit_gb' => 0],
        ['id' => 2, 'name' => '6 Mbps Fiber Fast',       'rate_limit' => '6M/6M',   'price' => 1200, 'validity_days' => 30, 'data_limit_gb' => 0],
        ['id' => 3, 'name' => '10 Mbps Family Streamer', 'rate_limit' => '10M/10M', 'price' => 1600, 'validity_days' => 30, 'data_limit_gb' => 0],
        ['id' => 4, 'name' => '15 Mbps Ultra High-Speed','rate_limit' => '15M/15M', 'price' => 2000, 'validity_days' => 30, 'data_limit_gb' => 0],
        ['id' => 5, 'name' => '20 Mbps Gamer Pro',       'rate_limit' => '20M/20M', 'price' => 2500, 'validity_days' => 30, 'data_limit_gb' => 0],
        ['id' => 6, 'name' => '30 Mbps Turbo Streamer',  'rate_limit' => '30M/30M', 'price' => 3200, 'validity_days' => 30, 'data_limit_gb' => 0],
        ['id' => 7, 'name' => '50 Mbps Enterprise Fiber', 'rate_limit' => '50M/50M', 'price' => 4500, 'validity_days' => 30, 'data_limit_gb' => 0],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= BRAND_NAME ?> - High-Speed Pure Fiber Internet &amp; Telecom Solutions</title>
    <meta name="description" content="<?= BRAND_NAME ?> Pure Optical Fiber Internet (FTTH). Ultra-low latency, 99.9% uptime, buffer-free 4K streaming and high-speed gaming connectivity.">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #06b6d4;
            --primary-glow: rgba(6, 182, 212, 0.4);
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
            overflow-x: hidden;
            scroll-behavior: smooth;
        }

        h1, h2, h3, h4, .font-heading {
            font-family: 'Space Grotesk', sans-serif;
            letter-spacing: -0.02em;
        }

        /* Glassmorphic Navbar */
        .navbar-main {
            background: rgba(7, 13, 25, 0.82) !important;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--bg-card-border);
            transition: all 0.3s ease;
        }
        .navbar-main.scrolled {
            background: rgba(7, 13, 25, 0.96) !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .nav-link {
            color: #cbd5e1 !important;
            font-weight: 500;
            font-size: 0.95rem;
            padding: 8px 16px !important;
            transition: all 0.2s ease;
        }
        .nav-link:hover {
            color: var(--primary) !important;
            transform: translateY(-1px);
        }

        /* Sign In Button */
        .btn-signin {
            background: linear-gradient(135deg, #06b6d4, #2563eb);
            color: #ffffff !important;
            font-weight: 700;
            border: none;
            border-radius: 50px;
            padding: 9px 24px;
            font-size: 0.95rem;
            box-shadow: 0 0 20px rgba(6, 182, 212, 0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-signin:hover {
            background: linear-gradient(135deg, #0891b2, #1d4ed8);
            transform: translateY(-2px) scale(1.02);
            box-shadow: 0 0 30px rgba(6, 182, 212, 0.65);
            color: #ffffff !important;
        }
        .btn-signin-pulse {
            animation: signinGlow 2.5s infinite;
        }
        @keyframes signinGlow {
            0% { box-shadow: 0 0 0 0 rgba(6, 182, 212, 0.6); }
            70% { box-shadow: 0 0 0 14px rgba(6, 182, 212, 0); }
            100% { box-shadow: 0 0 0 0 rgba(6, 182, 212, 0); }
        }

        /* Hero Section */
        .hero-section {
            position: relative;
            padding: 140px 0 90px;
            background: radial-gradient(circle at 50% 20%, rgba(6, 182, 212, 0.15), transparent 70%),
                        radial-gradient(circle at 80% 50%, rgba(37, 99, 235, 0.12), transparent 60%);
        }
        .hero-badge {
            background: rgba(6, 182, 212, 0.1);
            color: var(--primary);
            border: 1px solid rgba(6, 182, 212, 0.3);
            border-radius: 50px;
            padding: 6px 18px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #ffffff 30%, #38bdf8 70%, #818cf8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        @media (max-width: 768px) {
            .hero-title { font-size: 2.35rem; }
            .hero-section { padding: 110px 0 60px; }
        }

        /* Feature Cards */
        .feature-card {
            background: var(--bg-card);
            border: 1px solid var(--bg-card-border);
            border-radius: 20px;
            padding: 30px;
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent, var(--primary), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            border-color: rgba(6, 182, 212, 0.3);
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.5);
        }
        .feature-card:hover::before {
            opacity: 1;
        }
        .feature-icon-box {
            width: 58px; height: 58px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(6, 182, 212, 0.15), rgba(37, 99, 235, 0.15));
            border: 1px solid rgba(6, 182, 212, 0.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 22px;
        }

        /* Package Cards */
        .pkg-card {
            background: var(--bg-card);
            border: 1px solid var(--bg-card-border);
            border-radius: 24px;
            padding: 36px 28px;
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .pkg-card.popular {
            border-color: var(--primary);
            background: linear-gradient(180deg, rgba(6, 182, 212, 0.08) 0%, #0f172a 100%);
            box-shadow: 0 10px 40px -10px rgba(6, 182, 212, 0.35);
            transform: scale(1.03);
        }
        .pkg-card:hover {
            transform: translateY(-8px) scale(1.02);
            border-color: rgba(6, 182, 212, 0.45);
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.6);
        }
        .popular-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #06b6d4, #2563eb);
            color: #fff;
            font-weight: 700;
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 5px 16px;
            border-radius: 50px;
            box-shadow: 0 4px 15px rgba(6, 182, 212, 0.5);
        }

        /* Work Showcase Gallery */
        .work-card {
            position: relative;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid var(--bg-card-border);
            background: #0f172a;
            transition: all 0.35s ease;
            height: 270px;
        }
        .work-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .work-card-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(7, 13, 25, 0.95) 10%, rgba(7, 13, 25, 0.4) 60%, transparent 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 22px;
            transition: all 0.3s ease;
        }
        .work-card:hover img {
            transform: scale(1.08);
        }
        .work-card:hover {
            border-color: rgba(6, 182, 212, 0.5);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.6);
        }

        /* Section Headings */
        .section-tag {
            color: var(--primary);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 0.85rem;
            margin-bottom: 8px;
            display: block;
        }
        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 16px;
        }
        @media (max-width: 768px) {
            .section-title { font-size: 1.95rem; }
        }

        /* Metric Stats Strip */
        .stats-bar {
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid var(--bg-card-border);
            border-radius: 20px;
            backdrop-filter: blur(12px);
            padding: 30px 20px;
            box-shadow: 0 15px 35px -10px rgba(0,0,0,0.5);
        }
        .stat-num {
            font-size: 2.3rem;
            font-weight: 800;
            font-family: 'Space Grotesk', sans-serif;
            background: linear-gradient(135deg, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Floating WhatsApp Button */
        .floating-whatsapp {
            position: fixed;
            bottom: 25px;
            right: 25px;
            width: 60px;
            height: 60px;
            background: #25d366;
            color: #ffffff !important;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            box-shadow: 0 4px 20px rgba(37, 211, 102, 0.5);
            z-index: 9999;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        .floating-whatsapp:hover {
            transform: scale(1.1) translateY(-3px);
            box-shadow: 0 8px 30px rgba(37, 211, 102, 0.7);
            color: #ffffff;
        }
    </style>
</head>
<body>

    <!-- Floating WhatsApp Helpline -->
    <a href="https://wa.me/<?= $site_whatsapp ?>?text=<?= urlencode('Salam! I am interested in getting a new Fiber Internet connection from ' . BRAND_NAME . '.') ?>" 
       target="_blank" class="floating-whatsapp" title="Chat with us on WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
    </a>

    <!-- ============================================================== -->
    <!-- NAVIGATION BAR -->
    <!-- ============================================================== -->
    <nav class="navbar navbar-expand-lg navbar-main fixed-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="#">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #06b6d4, #2563eb); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.2rem; box-shadow:0 0 15px rgba(6,182,212,0.4);">
                    <i class="fa-solid fa-bolt-lightning"></i>
                </div>
                <div>
                    <span class="fs-4 fw-bold text-white font-heading d-block lh-1"><?= BRAND_NAME ?></span>
                    <small style="font-size: 0.65rem; color: #38bdf8; letter-spacing: 0.12em; text-transform: uppercase;">Fiber Broadband</small>
                </div>
            </a>

            <button class="navbar-toggler border-0 shadow-none text-white" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
                <i class="fa-solid fa-bars fs-4"></i>
            </button>

            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#packages">Packages</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features">Fiber Advantage</a></li>
                    <li class="nav-item"><a class="nav-link" href="#work">Our Network &amp; Work</a></li>
                    <li class="nav-item"><a class="nav-link" href="#coverage">Coverage</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    <li class="nav-item"><a class="nav-link" href="/policies"><i class="fa-solid fa-shield-halved text-info me-1"></i> Policies</a></li>
                </ul>

                <div class="d-flex align-items-center">
                    <!-- Prominent Sign In Button -->
                    <a href="/login" class="btn-signin btn-signin-pulse" title="Sign In to Portal">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- ============================================================== -->
    <!-- HERO SECTION -->
    <!-- ============================================================== -->
    <section class="hero-section" id="home">
        <div class="container">
            <div class="row align-items-center gy-5">
                <div class="col-lg-7 text-center text-lg-start">
                    <div class="hero-badge">
                        <i class="fa-solid fa-circle-dot text-success animate-pulse"></i> Next-Gen Gigabit Pure Optical Fiber (FTTH)
                    </div>
                    <h1 class="hero-title">
                        Blazing-Fast Pure Fiber Internet For Home &amp; Enterprise
                    </h1>
                    <p class="text-muted fs-5 mb-4 pe-lg-4" style="line-height: 1.65;">
                        Experience gigabit speeds, ultra-low gaming ping (<15ms), and uninterrupted 4K/8K streaming backed by our multi-redundant 10Gbps backbone network across Pakistan.
                    </p>
                    <div class="d-flex flex-wrap gap-3 justify-content-center justify-content-lg-start mb-4">
                        <a href="#packages" class="btn btn-primary btn-lg rounded-pill px-4 py-3 fw-bold shadow" style="background: linear-gradient(135deg, #06b6d4, #2563eb); border: none;">
                            <i class="fa-solid fa-tags me-2"></i> View Internet Plans
                        </a>
                        <a href="/customer/login" class="btn btn-outline-light btn-lg rounded-pill px-4 py-3 fw-bold" style="border-color: rgba(255,255,255,0.25);">
                            <i class="fa-solid fa-user-lock me-2 text-info"></i> Customer Sign In
                        </a>
                    </div>

                    <div class="d-flex align-items-center gap-4 justify-content-center justify-content-lg-start text-muted small pt-2">
                        <div><i class="fa-solid fa-check text-info me-1"></i> No Data Limits</div>
                        <div><i class="fa-solid fa-check text-info me-1"></i> Free Wi-Fi Router</div>
                        <div><i class="fa-solid fa-check text-info me-1"></i> 24/7 Local Support</div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <!-- Interactive Visual Display Card -->
                    <div class="feature-card" style="background: linear-gradient(145deg, #0f172a, #1e293b); border-color: rgba(6,182,212,0.3);">
                        <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
                            <div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill">
                                    <i class="fa-solid fa-circle text-success me-1" style="font-size:0.5rem;"></i> Live Network Status: Optimal
                                </span>
                            </div>
                            <div class="text-info fw-bold font-monospace small"><i class="fa-solid fa-wifi me-1"></i> FTTH Core</div>
                        </div>

                        <!-- Speedometer Widget Visual -->
                        <div class="text-center py-3">
                            <div class="d-inline-flex align-items-center justify-content-center" style="width: 150px; height: 150px; border-radius: 50%; background: conic-gradient(from 180deg at 50% 50%, #06b6d4 0%, #2563eb 70%, #1e293b 70%); padding: 12px; box-shadow: 0 0 35px rgba(6, 182, 212, 0.35);">
                                <div class="w-100 h-100 rounded-circle bg-dark d-flex flex-column align-items-center justify-content-center">
                                    <span class="fs-1 fw-bold text-white font-heading lh-1">100</span>
                                    <small class="text-info fw-bold" style="font-size:0.75rem;">MBPS SPEED</small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mt-3 pt-3 border-top border-secondary text-center font-monospace">
                            <div class="col-4">
                                <small class="text-muted d-block">LATENCY</small>
                                <span class="fw-bold text-success">8 ms</span>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block">DOWNLOAD</small>
                                <span class="fw-bold text-info">98.4 Mbps</span>
                            </div>
                            <div class="col-4">
                                <small class="text-muted d-block">UPLOAD</small>
                                <span class="fw-bold text-primary">98.2 Mbps</span>
                            </div>
                        </div>

                        <div class="mt-4 pt-2">
                            <a href="#contact" class="btn btn-outline-info w-100 rounded-pill py-2 fw-bold" style="border-color: rgba(6,182,212,0.4);">
                                <i class="fa-solid fa-paper-plane me-1"></i> Apply For New Connection
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Metric Stats Strip -->
            <div class="stats-bar mt-5">
                <div class="row text-center gy-4">
                    <div class="col-6 col-md-3 border-end border-secondary border-opacity-25">
                        <div class="stat-num">99.98%</div>
                        <div class="text-muted small fw-semibold">Uptime Reliability Guarantee</div>
                    </div>
                    <div class="col-6 col-md-3 border-end border-secondary border-opacity-25">
                        <div class="stat-num">10 Gbps+</div>
                        <div class="text-muted small fw-semibold">Multi-WAN Redundant Backbone</div>
                    </div>
                    <div class="col-6 col-md-3 border-end border-secondary border-opacity-25">
                        <div class="stat-num">&lt; 15 ms</div>
                        <div class="text-muted small fw-semibold">Ultra-Low Gaming Latency</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-num">24 / 7</div>
                        <div class="text-muted small fw-semibold">Dedicated NOC &amp; Field Support</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- PACKAGES SECTION -->
    <!-- ============================================================== -->
    <section class="py-5" id="packages">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="section-tag">High-Speed Fiber Plans</span>
                <h2 class="section-title">Transparent &amp; Affordable Internet Packages</h2>
                <p class="text-muted mx-auto" style="max-width: 650px;">
                    Choose the perfect optical fiber plan for your household or enterprise. Unlimited downloads, zero throttling, and 100% symmetric speeds.
                </p>
            </div>

            <div class="row g-4 align-items-stretch justify-content-center">
                <?php 
                $total_pkgs = count($site_packages);
                foreach ($site_packages as $index => $pkg): 
                    $pkg_name = htmlspecialchars($pkg['name']);
                    $pkg_price = number_format((float)$pkg['price']);
                    $pkg_speed = format_pkg_speed($pkg['rate_limit'] ?? '', $pkg['name']);
                    $validity = !empty($pkg['validity_days']) ? $pkg['validity_days'] . ' Days' : 'Monthly';
                    $data_limit = (!empty($pkg['data_limit_gb']) && (int)$pkg['data_limit_gb'] > 0) ? (int)$pkg['data_limit_gb'] . ' GB Data Cap' : 'Unlimited Data (No FUP)';
                    
                    // Popular indicator (e.g. 2nd or 3rd package or around 10M)
                    $is_popular = ($index === 1 || ($total_pkgs > 2 && $index === 2 && stripos($pkg_name, '10') !== false));
                    $card_class = $is_popular ? 'pkg-card popular' : 'pkg-card';
                    
                    // Column responsiveness
                    $col_class = ($total_pkgs <= 3) ? 'col-lg-4 col-md-6' : 'col-xl-3 col-lg-4 col-md-6';
                    
                    // Dynamic Badge & Icon
                    $badge_text = 'High Speed';
                    $badge_class = 'bg-secondary-subtle text-secondary';
                    $icon_class = 'fa-solid fa-bolt-lightning text-info';
                    
                    if (stripos($pkg_name, '4m') !== false || stripos($pkg_name, 'starter') !== false || stripos($pkg_name, 'basic') !== false) {
                        $badge_text = 'Home Starter';
                        $badge_class = 'bg-secondary-subtle text-secondary';
                        $icon_class = 'fa-solid fa-home text-info';
                    } elseif (stripos($pkg_name, '6m') !== false || stripos($pkg_name, 'fast') !== false) {
                        $badge_text = 'Fiber Fast';
                        $badge_class = 'bg-info-subtle text-info';
                        $icon_class = 'fa-solid fa-network-wired text-info';
                    } elseif (stripos($pkg_name, '10m') !== false || stripos($pkg_name, 'family') !== false) {
                        $badge_text = 'Family Streamer';
                        $badge_class = 'bg-primary-subtle text-primary';
                        $icon_class = 'fa-solid fa-film text-primary';
                    } elseif (stripos($pkg_name, '15m') !== false || stripos($pkg_name, '20m') !== false || stripos($pkg_name, 'gamer') !== false) {
                        $badge_text = 'Gamer &amp; Pro';
                        $badge_class = 'bg-indigo-subtle text-light';
                        $icon_class = 'fa-solid fa-gamepad text-info';
                    } elseif (stripos($pkg_name, '30m') !== false || stripos($pkg_name, '50m') !== false || stripos($pkg_name, 'turbo') !== false || stripos($pkg_name, 'gigabit') !== false || stripos($pkg_name, 'enterprise') !== false) {
                        $badge_text = 'Gigabit Ultra';
                        $badge_class = 'bg-warning-subtle text-warning';
                        $icon_class = 'fa-solid fa-building text-warning';
                    }
                ?>
                <div class="<?= $col_class ?>">
                    <div class="<?= $card_class ?> h-100 d-flex flex-column justify-content-between">
                        <div>
                            <?php if ($is_popular): ?>
                                <div class="popular-badge"><i class="fa-solid fa-fire me-1"></i> Most Popular</div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge <?= $badge_class ?> px-3 py-1 rounded-pill"><?= $badge_text ?></span>
                                <i class="<?= $icon_class ?>"></i>
                            </div>
                            
                            <h3 class="fw-bold fs-2 text-white font-heading mb-1"><?= $pkg_speed ?></h3>
                            <div class="text-info small fw-bold mb-2"><?= $pkg_name ?></div>
                            <p class="text-muted small mb-3">Pure optical FTTH broadband with symmetric upload &amp; download speeds.</p>

                            <div class="mb-4">
                                <span class="fs-1 fw-bold text-white font-heading">Rs. <?= $pkg_price ?></span>
                                <span class="text-muted small">/ <?= htmlspecialchars($validity) ?></span>
                            </div>

                            <ul class="list-unstyled mb-4 small text-secondary">
                                <li class="mb-2"><i class="fa-solid fa-circle-check text-info me-2"></i> <strong><?= htmlspecialchars($data_limit) ?></strong></li>
                                <li class="mb-2"><i class="fa-solid fa-circle-check text-info me-2"></i> Pure Optical FTTH Line</li>
                                <li class="mb-2"><i class="fa-solid fa-circle-check text-info me-2"></i> Low-Latency Routing (&lt;15ms)</li>
                                <li class="mb-2"><i class="fa-solid fa-circle-check text-info me-2"></i> Buffer-Free 4K/8K Streaming</li>
                                <li class="mb-2"><i class="fa-solid fa-circle-check text-info me-2"></i> 24/7 Field &amp; NOC Support</li>
                            </ul>
                        </div>
                        
                        <div>
                            <a href="https://wa.me/<?= $site_whatsapp ?>?text=<?= urlencode('Salam! I want to order the ' . $pkg['name'] . ' package (' . $pkg_speed . ' for Rs. ' . $pkg_price . ') from ' . BRAND_NAME . '.') ?>" 
                               target="_blank" class="<?= $is_popular ? 'btn btn-signin w-100 rounded-pill py-2 text-center justify-content-center' : 'btn btn-outline-info w-100 rounded-pill py-2 fw-semibold' ?>">
                                <i class="fa-brands fa-whatsapp me-1"></i> Order Connection
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- REAL WORK & NETWORK INFRASTRUCTURE SHOWCASE -->
    <!-- ============================================================== -->
    <section class="py-5" id="work" style="background: rgba(15, 23, 42, 0.45);">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="section-tag">Engineering &amp; Infrastructure</span>
                <h2 class="section-title">Behind Our World-Class Network</h2>
                <p class="text-muted mx-auto" style="max-width: 650px;">
                    Take a look at our on-ground telecom engineering: from optical fiber splicing and server rack routing to high-power towers and FTTH home connections.
                </p>
            </div>

            <div class="row g-4">
                <!-- Photo 1: Datacenter Core Routing -->
                <div class="col-md-6 col-lg-4">
                    <div class="work-card">
                        <img src="https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=800&q=80" alt="Core Datacenter Routers & OLT" loading="lazy" decoding="async">
                        <div class="work-card-overlay">
                            <span class="badge bg-primary text-white w-auto align-self-start mb-2 px-3 py-1 rounded-pill small">Core Datacenter</span>
                            <h5 class="fw-bold text-white mb-1">Carrier-Grade Routing &amp; OLTs</h5>
                            <p class="text-muted small mb-0">High-capacity MikroTik CCR &amp; Huawei fiber OLT racks delivering redundant bandwidth.</p>
                        </div>
                    </div>
                </div>

                <!-- Photo 2: Precision Fiber Fusion Splicing -->
                <div class="col-md-6 col-lg-4">
                    <div class="work-card">
                        <img src="https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=800&q=80" alt="Optical Fiber Splicing & Patching" loading="lazy" decoding="async">
                        <div class="work-card-overlay">
                            <span class="badge bg-info text-white w-auto align-self-start mb-2 px-3 py-1 rounded-pill small">Field Engineering</span>
                            <h5 class="fw-bold text-white mb-1">Precision Fusion Splicing</h5>
                            <p class="text-muted small mb-0">Zero-loss core optical splicing ensuring minimal attenuation across district backbones.</p>
                        </div>
                    </div>
                </div>

                <!-- Photo 3: Field Telecom Work -->
                <div class="col-md-6 col-lg-4">
                    <div class="work-card">
                        <img src="https://images.unsplash.com/photo-1581092160607-ee22621dd758?auto=format&fit=crop&w=800&q=80" alt="Telecom Engineers On Duty" loading="lazy" decoding="async">
                        <div class="work-card-overlay">
                            <span class="badge bg-success text-white w-auto align-self-start mb-2 px-3 py-1 rounded-pill small">24/7 Field Team</span>
                            <h5 class="fw-bold text-white mb-1">Rapid Deployment &amp; Repair</h5>
                            <p class="text-muted small mb-0">Certified linemen and network technicians providing same-day resolution for fiber issues.</p>
                        </div>
                    </div>
                </div>

                <!-- Photo 4: Telecom Towers & Wireless P2P -->
                <div class="col-md-6 col-lg-4">
                    <div class="work-card">
                        <img src="https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=80" alt="Wireless Point-to-Point Towers" loading="lazy" decoding="async">
                        <div class="work-card-overlay">
                            <span class="badge bg-warning text-dark w-auto align-self-start mb-2 px-3 py-1 rounded-pill small">Microwave P2P</span>
                            <h5 class="fw-bold text-white mb-1">Long-Range Wireless Backhaul</h5>
                            <p class="text-muted small mb-0">High-frequency point-to-point links extending gigabit connectivity to remote sectors.</p>
                        </div>
                    </div>
                </div>

                <!-- Photo 5: FTTH Smart Home Connections -->
                <div class="col-md-6 col-lg-4">
                    <div class="work-card">
                        <img src="https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=800&q=80" alt="FTTH Smart Home Wi-Fi" loading="lazy" decoding="async">
                        <div class="work-card-overlay">
                            <span class="badge bg-secondary text-white w-auto align-self-start mb-2 px-3 py-1 rounded-pill small">Customer Premises</span>
                            <h5 class="fw-bold text-white mb-1">Smart Home FTTH Onboarding</h5>
                            <p class="text-muted small mb-0">Dual-band gigabit Wi-Fi ONUs installed with neat armored drop cables in homes.</p>
                        </div>
                    </div>
                </div>

                <!-- Photo 6: 24/7 Network Operations Center (NOC) -->
                <div class="col-md-6 col-lg-4">
                    <div class="work-card">
                        <img src="https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?auto=format&fit=crop&w=800&q=80" alt="Network Operations Center Monitoring" loading="lazy" decoding="async">
                        <div class="work-card-overlay">
                            <span class="badge bg-danger text-white w-auto align-self-start mb-2 px-3 py-1 rounded-pill small">24/7 NOC</span>
                            <h5 class="fw-bold text-white mb-1">Proactive Link Monitoring</h5>
                            <p class="text-muted small mb-0">Automated monitoring detects packet drops or line degradation before customers notice.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- FIBER ADVANTAGE (WHY CHOOSE US) -->
    <!-- ============================================================== -->
    <section class="py-5" id="features">
        <div class="container py-4">
            <div class="text-center mb-5">
                <span class="section-tag">Why Choose <?= BRAND_NAME ?></span>
                <h2 class="section-title">Engineered For Unmatched Reliability</h2>
                <p class="text-muted mx-auto" style="max-width: 600px;">
                    We don't use old copper cables. Our infrastructure is 100% end-to-end optical fiber built for modern high-bandwidth demands.
                </p>
            </div>

            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <i class="fa-solid fa-network-wired"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-2">100% Pure Optical FTTH</h4>
                        <p class="text-muted small mb-0">Direct fiber strand to your router. Immune to electrical noise, rain, and power surges with zero degradation.</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <i class="fa-solid fa-gamepad"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-2">Ultra-Low Gaming Ping</h4>
                        <p class="text-muted small mb-0">Direct fast-path peering with major gaming servers in Dubai, Singapore &amp; Europe. No lag, no jitter.</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <i class="fa-solid fa-shuffle"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-2">Multi-WAN Redundancy</h4>
                        <p class="text-muted small mb-0">Dual upstream routes (PTCL, Telenor, Zong) with automatic failover so you never face a blackout.</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <i class="fa-solid fa-gauge-high"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-2">Symmetric Speed Delivery</h4>
                        <p class="text-muted small mb-0">Upload speeds matched equally with downloads. Upload heavy video projects and backup files in seconds.</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <i class="fa-solid fa-user-gear"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-2">Customer Self-Care Portal</h4>
                        <p class="text-muted small mb-0">Check live connection status, monitor data usage, view invoices, and submit support tickets online.</p>
                    </div>
                </div>

                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon-box">
                            <i class="fa-solid fa-headset"></i>
                        </div>
                        <h4 class="fw-bold text-white mb-2">Dedicated Local Helpline</h4>
                        <p class="text-muted small mb-0">Real human support on call and WhatsApp. Fast on-site dispatch within minutes of reporting an issue.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- CUSTOMER PORTAL SPOTLIGHT -->
    <!-- ============================================================== -->
    <section class="py-5" style="background: radial-gradient(circle at 50% 50%, rgba(6,182,212,0.1), transparent 70%);">
        <div class="container py-4">
            <div class="row align-items-center gy-5">
                <div class="col-lg-6">
                    <span class="section-tag">Customer Self-Care</span>
                    <h2 class="section-title">Manage Your Account Anytime, Anywhere</h2>
                    <p class="text-muted fs-6 mb-4">
                        With the <?= BRAND_NAME ?> Customer Portal, you have full control over your internet connection right from your smartphone or PC:
                    </p>
                    <ul class="list-unstyled mb-4 text-secondary">
                        <li class="mb-3 d-flex align-items-center gap-3">
                            <div style="width:36px; height:36px; border-radius:50%; background:rgba(6,182,212,0.15); color:var(--primary); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div><strong>Live Expiry &amp; Validity Tracking:</strong> Never get surprised by unexpected disconnections.</div>
                        </li>
                        <li class="mb-3 d-flex align-items-center gap-3">
                            <div style="width:36px; height:36px; border-radius:50%; background:rgba(6,182,212,0.15); color:var(--primary); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <div><strong>Real-Time Data Consumption:</strong> Monitor your daily and monthly download/upload stats.</div>
                        </li>
                        <li class="mb-3 d-flex align-items-center gap-3">
                            <div style="width:36px; height:36px; border-radius:50%; background:rgba(6,182,212,0.15); color:var(--primary); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                            <div><strong>Instant Online Receipts &amp; Invoices:</strong> Download billing history and payment proofs.</div>
                        </li>
                    </ul>

                    <div class="d-flex flex-wrap gap-3">
                        <a href="<?= htmlspecialchars($customer_portal_url) ?>" class="btn-signin btn-signin-pulse">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i> Login to Customer Portal
                        </a>
                        <a href="#contact" class="btn btn-outline-light rounded-pill px-4 py-2 fw-semibold">
                            Get Support
                        </a>
                    </div>
                </div>

                <div class="col-lg-6">
                    <!-- Portal Mockup Card -->
                    <div class="feature-card p-4" style="background:#0b1120; border: 1px solid rgba(6,182,212,0.3); box-shadow: 0 20px 50px rgba(0,0,0,0.6);">
                        <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-secondary">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-danger" style="width:10px; height:10px;"></div>
                                <div class="rounded-circle bg-warning" style="width:10px; height:10px;"></div>
                                <div class="rounded-circle bg-success" style="width:10px; height:10px;"></div>
                                <span class="text-muted small ms-2 font-monospace">customer.<?= $host ?: 'lasani.tech' ?></span>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill small">Online</span>
                        </div>

                        <div class="p-3 rounded-4 bg-dark border border-secondary mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted d-block">ACTIVE SUBSCRIPTION</small>
                                    <h5 class="fw-bold text-white mb-0">10 Mbps Fiber Turbo</h5>
                                </div>
                                <span class="badge bg-primary px-3 py-2 rounded-pill">Active</span>
                            </div>
                        </div>

                        <div class="row g-2 text-center small mb-3">
                            <div class="col-6">
                                <div class="p-3 rounded bg-dark border border-secondary">
                                    <span class="text-muted d-block">Account Expiry</span>
                                    <strong class="text-info fs-6">30 Nov 2026</strong>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded bg-dark border border-secondary">
                                    <span class="text-muted d-block">IP Assignment</span>
                                    <strong class="text-success fs-6">10.10.4.52 (Static)</strong>
                                </div>
                            </div>
                        </div>

                        <div class="text-center pt-2">
                            <a href="<?= htmlspecialchars($customer_portal_url) ?>" class="btn btn-sm btn-outline-info rounded-pill px-4">
                                <i class="fa-solid fa-lock me-1"></i> Access Your Portal Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- COVERAGE & INQUIRY FORM -->
    <!-- ============================================================== -->
    <section class="py-5" id="coverage">
        <div class="container py-4">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <span class="section-tag">Network Footprint</span>
                    <h2 class="section-title">Expanded Optical Coverage Across Region</h2>
                    <p class="text-muted mb-4">
                        Our pure fiber optic network is currently live and actively expanding across towns, commercial hubs, and rural zones:
                    </p>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6">
                            <div class="p-3 rounded-4 bg-dark border border-secondary">
                                <h6 class="fw-bold text-info mb-1"><i class="fa-solid fa-location-dot me-2"></i> Piplan Zone</h6>
                                <small class="text-muted">Main City, Liaqatabad, Railway Colony, Commercial Markets</small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-4 bg-dark border border-secondary">
                                <h6 class="fw-bold text-info mb-1"><i class="fa-solid fa-location-dot me-2"></i> Jandanwala Sector</h6>
                                <small class="text-muted">High School Road, College Area, Rural Linking Nodes</small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-4 bg-dark border border-secondary">
                                <h6 class="fw-bold text-info mb-1"><i class="fa-solid fa-location-dot me-2"></i> Sajid Rodi Sector</h6>
                                <small class="text-muted">Rodi Town, Grid Station Link, Surrounding Clusters</small>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-4 bg-dark border border-secondary">
                                <h6 class="fw-bold text-info mb-1"><i class="fa-solid fa-location-dot me-2"></i> Chandni Chok &amp; Environs</h6>
                                <small class="text-muted">Bhakkar / Mianwali Highway corridors &amp; Smart Clusters</small>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info border-0 rounded-4 d-flex align-items-center gap-3">
                        <i class="fa-solid fa-satellite-dish fs-3 text-info"></i>
                        <small>Need fiber in your area? Contact our planning team. We regularly expand into new streets based on user demand!</small>
                    </div>
                </div>

                <div class="col-lg-6" id="contact">
                    <div class="feature-card p-4 p-md-5">
                        <h4 class="fw-bold text-white mb-2"><i class="fa-solid fa-paper-plane text-info me-2"></i> Get a New Connection</h4>
                        <p class="text-muted small mb-4">Leave your details and our local team will verify feasibility within 2 hours.</p>

                        <form onsubmit="handleInquirySubmit(event)">
                            <div class="mb-3">
                                <label class="form-label text-muted small fw-bold">YOUR FULL NAME</label>
                                <input type="text" id="inq_name" class="form-control bg-dark border-secondary text-white rounded-3 py-2" placeholder="e.g. Muhammad Ali" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small fw-bold">WHATSAPP / PHONE NUMBER</label>
                                <input type="tel" id="inq_phone" class="form-control bg-dark border-secondary text-white rounded-3 py-2" placeholder="03001234567" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small fw-bold">YOUR CITY / AREA / COLONY</label>
                                <input type="text" id="inq_area" class="form-control bg-dark border-secondary text-white rounded-3 py-2" placeholder="e.g. Main Bazar, Piplan" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label text-muted small fw-bold">DESIRED PACKAGE</label>
                                <select id="inq_pkg" class="form-select bg-dark border-secondary text-white rounded-3 py-2">
                                    <?php foreach ($site_packages as $idx => $p): 
                                        $p_spd = format_pkg_speed($p['rate_limit'] ?? '', $p['name']);
                                        $p_lbl = $p['name'] . ' (' . $p_spd . ' - Rs. ' . number_format((float)$p['price']) . ')';
                                    ?>
                                        <option value="<?= htmlspecialchars($p_lbl) ?>" <?= $idx === 1 ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p_lbl) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 rounded-pill py-3 fw-bold" style="background: linear-gradient(135deg, #06b6d4, #2563eb); border:none;">
                                <i class="fa-brands fa-whatsapp me-2"></i> Send Request via WhatsApp
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================== -->
    <!-- FOOTER -->
    <!-- ============================================================== -->
    <footer class="py-5 border-top border-secondary border-opacity-25" style="background: #050914;">
        <div class="container">
            <div class="row g-4 justify-content-between mb-5">
                <div class="col-lg-3 col-md-6">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <div style="width:36px; height:36px; border-radius:10px; background:linear-gradient(135deg, #06b6d4, #2563eb); display:flex; align-items:center; justify-content:center; color:#fff; font-size:1rem;">
                            <i class="fa-solid fa-bolt-lightning"></i>
                        </div>
                        <span class="fs-4 fw-bold text-white font-heading"><?= BRAND_NAME ?></span>
                    </div>
                    <p class="text-muted small mb-3">
                        <?= BRAND_POWERED_BY ?>. Providing blazing-fast optical fiber broadband, enterprise leased lines, and carrier-grade connectivity solutions.
                    </p>
                    <div class="text-muted small">
                        <div class="mb-1"><i class="fa-solid fa-phone text-info me-2"></i> <?= htmlspecialchars($site_phone) ?></div>
                        <div class="mb-1"><i class="fa-solid fa-envelope text-info me-2"></i> <?= htmlspecialchars($site_email) ?></div>
                        <div class="mb-1"><i class="fa-solid fa-clock text-info me-2"></i> Mon–Sun 09:00 AM – 11:00 PM</div>
                        <div class="mt-2"><i class="fa-solid fa-location-dot text-danger me-2"></i> <?= htmlspecialchars($site_address) ?></div>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <h6 class="fw-bold text-white mb-3">Quick Navigation</h6>
                    <ul class="list-unstyled small text-muted">
                        <li class="mb-2"><a href="#home" class="text-decoration-none text-secondary hover-white">Home</a></li>
                        <li class="mb-2"><a href="#packages" class="text-decoration-none text-secondary hover-white">Fiber Packages</a></li>
                        <li class="mb-2"><a href="#features" class="text-decoration-none text-secondary hover-white">Why Pure Fiber</a></li>
                        <li class="mb-2"><a href="#work" class="text-decoration-none text-secondary hover-white">Infrastructure</a></li>
                        <li class="mb-2"><a href="#coverage" class="text-decoration-none text-secondary hover-white">Coverage Zones</a></li>
                    </ul>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <h6 class="fw-bold text-white mb-3">Customer Care</h6>
                    <ul class="list-unstyled small text-muted">
                        <li class="mb-2">
                            <a href="<?= htmlspecialchars($customer_portal_url) ?>" class="text-info fw-bold text-decoration-none">
                                <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Customer Sign In
                            </a>
                        </li>
                        <li class="mb-2"><a href="<?= htmlspecialchars($customer_portal_url) ?>" class="text-decoration-none text-secondary hover-white">Self-Care Portal</a></li>
                        <li class="mb-2"><a href="<?= htmlspecialchars($customer_portal_url) ?>" class="text-decoration-none text-secondary hover-white">Check Bills &amp; Expiry</a></li>
                        <li class="mb-2"><a href="/policies?tab=faqs" class="text-decoration-none text-secondary hover-white">Frequently Asked FAQs</a></li>
                        <li class="mb-2"><a href="#contact" class="text-decoration-none text-secondary hover-white">Report an Issue</a></li>
                    </ul>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <h6 class="fw-bold text-white mb-3"><i class="fa-solid fa-shield-halved text-info me-1"></i> Legal &amp; Policies</h6>
                    <ul class="list-unstyled small text-muted">
                        <li class="mb-2"><a href="/policies?tab=privacy" class="text-decoration-none text-secondary hover-white">Privacy Policy</a></li>
                        <li class="mb-2"><a href="/policies?tab=refund" class="text-decoration-none text-secondary hover-white">Return &amp; Refund Policy</a></li>
                        <li class="mb-2"><a href="/policies?tab=terms" class="text-decoration-none text-secondary hover-white">Terms &amp; Conditions</a></li>
                        <li class="mb-2"><a href="/policies?tab=shipping" class="text-decoration-none text-secondary hover-white">Shipping &amp; Delivery</a></li>
                        <li class="mb-2"><a href="/policies?tab=contact" class="text-decoration-none text-secondary hover-white">Contact &amp; Support Hours</a></li>
                    </ul>
                </div>

                <div class="col-lg-3 col-md-6">
                    <h6 class="fw-bold text-white mb-3">Consumer Fair Trade</h6>
                    <p class="text-muted small mb-3">All tariff plans are officially quoted in Pakistani Rupees (PKR) with 100% price transparency.</p>
                    <a href="https://wa.me/<?= $site_whatsapp ?>" target="_blank" class="btn btn-outline-success rounded-pill w-100 py-2 btn-sm fw-bold mb-2">
                        <i class="fa-brands fa-whatsapp me-2"></i> WhatsApp Helpline
                    </a>
                    <a href="/policies" class="btn btn-outline-info rounded-pill w-100 py-2 btn-sm fw-bold">
                        <i class="fa-solid fa-scale-balanced me-2"></i> Full Policies Portal
                    </a>
                </div>
            </div>

            <div class="pt-4 border-top border-secondary border-opacity-25 d-flex flex-wrap justify-content-between align-items-center gap-2 small text-muted">
                <div>&copy; <?= date('Y') ?> <?= BRAND_NAME ?>. All Rights Reserved. Fully compliant with Payment Gateway &amp; Consumer Fair Trade Standards.</div>
                <div>Powered by <strong class="text-info"><?= BRAND_POWERED_BY ?></strong></div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Navbar Scrolled Effect
        window.addEventListener('scroll', function() {
            var navbar = document.querySelector('.navbar-main');
            if (window.scrollY > 40) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // WhatsApp Inquiry Form Handler
        function handleInquirySubmit(e) {
            e.preventDefault();
            var name = document.getElementById('inq_name').value.trim();
            var phone = document.getElementById('inq_phone').value.trim();
            var area = document.getElementById('inq_area').value.trim();
            var pkg = document.getElementById('inq_pkg').value;

            var msg = "Salam! I want to order a new <?= BRAND_NAME ?> Fiber Internet connection.\n\n" +
                      "👤 Name: " + name + "\n" +
                      "📞 Phone: " + phone + "\n" +
                      "📍 Area/City: " + area + "\n" +
                      "📦 Desired Plan: " + pkg;

            var waUrl = "https://wa.me/<?= $site_whatsapp ?>?text=" + encodeURIComponent(msg);
            window.open(waUrl, '_blank');
        }
    </script>
    <style>
        body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
        input, textarea, select, [contenteditable="true"] { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }
    </style>
    <script src="/security-shield.js?v=2.1"></script>
</body>
</html>