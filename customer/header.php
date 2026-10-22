<?php require_once 'auth_check.php'; ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= BRAND_NAME ?> - Customer Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #2563eb;
            --primary-dark: #1e40af;
            --surface-bg: #f8fafc;
            --card-border: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }

        body {
            background-color: #f8fafc;
            color: #0f172a;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .navbar-customer {
            background: #ffffff !important;
            border-bottom: 1px solid #e2e8f0 !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03) !important;
        }

        .navbar-brand {
            color: #1e3a8a !important;
            font-weight: 800 !important;
            letter-spacing: -0.5px;
        }

        .nav-link {
            color: #475569 !important;
            font-weight: 600;
            padding: 8px 14px !important;
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .nav-link:hover, .nav-link.active {
            color: #2563eb !important;
            background: #eff6ff !important;
        }

        .navbar-toggler {
            border: 1px solid #cbd5e1 !important;
            padding: 6px 10px !important;
            border-radius: 8px !important;
        }

        .navbar-toggler:focus {
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
        }

        .card-ui { 
            background: #ffffff; 
            border-radius: 16px; 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04); 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card-ui:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.07); 
            border-color: #cbd5e1; 
        }

        .text-accent { color: #0284c7; }

        .btn-accent { 
            background: linear-gradient(135deg, #2563eb, #1d4ed8); 
            color: #fff; font-weight: 600; border: none; border-radius: 10px; 
            padding: 10px 20px; transition: 0.3s; 
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3); 
        }
        .btn-accent:hover { 
            background: linear-gradient(135deg, #1d4ed8, #1e40af); 
            color: #fff; transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.45); 
        }

        .badge-online { 
            background: #dcfce7; 
            color: #15803d; 
            border: 1px solid #bbf7d0; 
            position: relative; 
            padding-left: 28px !important;
            font-weight: 700;
        }
        .badge-online::before {
            content: ''; position: absolute; left: 10px; top: 50%; transform: translateY(-50%); 
            width: 8px; height: 8px; background: #22c55e; border-radius: 50%;
            box-shadow: 0 0 10px #22c55e; animation: pulse 2s infinite;
        }
        @keyframes pulse { 
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); } 
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); } 
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } 
        }
        
        .badge-offline { background-color: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; font-weight: 600; }

        @media (max-width: 991.98px) {
            #navbarNav {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                padding: 16px;
                margin-top: 12px;
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            }
            #navbarNav .nav-link {
                color: #1e293b !important;
                font-size: 0.95rem;
                font-weight: 600;
                padding: 10px 14px !important;
            }
            #navbarNav .nav-link:hover, #navbarNav .nav-link.active {
                background: #eff6ff !important;
                color: #2563eb !important;
            }
        }
    </style>
</head>
<body>

    <!-- Global Loading Overlay -->
    <div id="global-loader" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); z-index: 99999; align-items: center; justify-content: center; flex-direction: column; backdrop-filter: blur(5px);">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5 class="text-white mt-3 fw-bold">Processing, please wait...</h5>
        <div class="text-white-50 small">Do not close or refresh this page</div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                if(!form.classList.contains('no-loader') && form.checkValidity()) {
                    document.getElementById('global-loader').style.display = 'flex';
                    setTimeout(() => { document.getElementById('global-loader').style.display = 'none'; }, 15000); 
                }
            });
        });
    });
    function showGlobalLoader() { document.getElementById('global-loader').style.display = 'flex'; }
    function hideGlobalLoader() { document.getElementById('global-loader').style.display = 'none'; }
    </script>

<nav class="navbar navbar-expand-lg navbar-light navbar-customer mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="dashboard">
            <i class="fa-solid fa-wifi me-2 text-primary"></i>
            <span><?= BRAND_SHORT_NAME ?></span>
        </a>

        <!-- Mobile Controls: Direct Logout button and Hamburger Toggler -->
        <div class="d-flex align-items-center gap-2 d-lg-none">
            <a class="btn btn-outline-danger btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm" href="logout" title="Sign Out">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item me-lg-1">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="dashboard">
                        <i class="fa-solid fa-chart-pie me-1 text-primary"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item me-lg-1">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'packages.php' ? 'active' : '' ?>" href="packages">
                        <i class="fa-solid fa-box-open me-1 text-primary"></i> Packages
                    </a>
                </li>
                <li class="nav-item me-lg-1">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'history.php' ? 'active' : '' ?>" href="history">
                        <i class="fa-solid fa-clock-rotate-left me-1 text-primary"></i> History
                    </a>
                </li>
                <li class="nav-item me-lg-1">
                    <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'tickets.php' ? 'active' : '' ?>" href="tickets">
                        <i class="fa-solid fa-headset me-1 text-primary"></i> Support
                    </a>
                </li>
                <li class="nav-item ms-lg-3 mt-2 mt-lg-0 d-none d-lg-block">
                    <a class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2 fw-bold shadow-sm" href="logout">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container pb-5">
