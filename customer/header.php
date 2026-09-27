<?php require_once 'auth_check.php'; ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Portal - SB Link Network</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #0b1120; color: #f8fafc; font-family: 'Inter', system-ui, sans-serif; }
        .navbar { background-color: rgba(15, 23, 42, 0.8) !important; backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
        .card-ui { 
            background: linear-gradient(145deg, #1e293b, #0f172a); 
            border-radius: 16px; 
            border: 1px solid rgba(255, 255, 255, 0.05); 
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.5); 
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card-ui:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 15px 35px -10px rgba(0, 0, 0, 0.6); 
            border-color: rgba(255, 255, 255, 0.1); 
        }
        .nav-link { color: #94a3b8 !important; font-weight: 500; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { color: #38bdf8 !important; }
        
        .badge-online { 
            background: rgba(16, 185, 129, 0.1); 
            color: #10b981; 
            border: 1px solid rgba(16, 185, 129, 0.2); 
            position: relative; 
            padding-left: 30px !important;
        }
        .badge-online::before {
            content: ''; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); 
            width: 8px; height: 8px; background: #10b981; border-radius: 50%;
            box-shadow: 0 0 10px #10b981; animation: pulse 2s infinite;
        }
        @keyframes pulse { 
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); } 
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); } 
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } 
        }
        
        .badge-offline { background-color: rgba(100, 116, 139, 0.1); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.2); }
        .text-accent { color: #38bdf8; }
        
        .btn-accent { 
            background: linear-gradient(135deg, #38bdf8, #0284c7); 
            color: #fff; font-weight: 600; border: none; border-radius: 10px; 
            padding: 10px 20px; transition: 0.3s; 
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.4); 
        }
        .btn-accent:hover { 
            background: linear-gradient(135deg, #0284c7, #0369a1); 
            color: #fff; transform: translateY(-2px); 
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.6); 
        }
        
        /* Premium Package Cards */
        .package-card { background: #1e293b; border: 2px solid rgba(255,255,255,0.02); border-radius: 16px; position: relative; overflow: hidden; }
        .package-card.active-plan { 
            border-color: rgba(56, 189, 248, 0.5); 
            background: linear-gradient(180deg, rgba(56, 189, 248, 0.05) 0%, #1e293b 100%); 
        }
        .package-card.active-plan::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: #38bdf8;
        }
        .current-badge { background: #38bdf8; color: #0b1120; font-size: 0.75rem; font-weight: 700; padding: 4px 12px; border-radius: 0 0 0 12px; position: absolute; top: 0; right: 0; }
        
        /* Modals */
        .modal-content { background: #1e293b; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
        .modal-header { border-bottom: 1px solid rgba(255,255,255,0.05); }
        .modal-footer { border-top: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body>

    <!-- Global Loading Overlay -->
    <div id="global-loader" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.7); z-index: 99999; align-items: center; justify-content: center; flex-direction: column; backdrop-filter: blur(5px);">
        <div class="spinner-border text-info" style="width: 3rem; height: 3rem;" role="status">
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

<nav class="navbar navbar-expand-lg navbar-dark mb-5 sticky-top">
    <div class="container">
        <a class="navbar-brand text-accent fw-bold" href="dashboard.php">
            <i class="fa-solid fa-wifi me-2"></i>SB Link
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">
                <li class="nav-item me-2"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-chart-pie me-1"></i> Dashboard</a></li>
                <li class="nav-item me-2"><a class="nav-link" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i> History</a></li>
                <li class="nav-item me-2"><a class="nav-link" href="tickets.php"><i class="fa-solid fa-headset me-1"></i> Support</a></li>
                <li class="nav-item ms-lg-3 mt-3 mt-lg-0">
                    <a class="btn btn-outline-danger btn-sm rounded-pill px-3" href="logout.php">
                        <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container pb-5">
