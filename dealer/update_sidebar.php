<?php
$file = 'C:/xampp/htdocs/SB Link Network/dealer/header.php';
$content = file_get_contents($file);

$oldCssStart = '<style>';
$oldCssEnd = '</style>';

// We'll replace the CSS for #sidebar and navigation links.
$newCss = <<<'CSS'
    <style>
        :root {
            --primary-bg: #f8fafc;
            --accent-color: #3b82f6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--primary-bg); color: #334155; }
        
        /* Light Sidebar Styles (Matches Operator) */
        #sidebar {
            width: 260px; height: 100vh; position: fixed; background-color: #ffffff; 
            top: 0; left: 0; border-right: 1px solid #e5e7eb; overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.02); z-index: 1000;
        }
        
        .sidebar-brand {
            padding: 1.5rem;
            color: #0f172a;
            font-size: 1.25rem;
            font-weight: 700;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-item { margin: 0; }
        
        .nav-link {
            color: #4b5563; text-decoration: none; padding: 14px 20px; 
            display: flex; align-items: center; font-size: 1rem; font-weight: 500;
            transition: all 0.2s ease;
            border-radius: 0;
            gap: 0;
        }
        
        .nav-link:hover {
            background-color: #f8fafc; color: #1e293b;
        }
        
        .nav-link i {
            width: 30px; text-align: left; font-size: 1.1rem; color: #64748b;
        }
        
        .nav-link.active {
            background-color: #f1f5f9; color: #0f172a; border-left: 4px solid #3b82f6; padding-left: 16px;
        }
        
        .nav-link.active i {
            color: #3b82f6;
        }
        
        .nav-link.text-danger:hover {
            background-color: #fef2f2; color: #dc2626 !important;
        }
        
        .nav-link.text-danger:hover i {
            color: #dc2626 !important;
        }

        /* Main Content */
        #main-content { margin-left: 260px; padding: 2rem; min-height: 100vh; transition: all 0.3s ease; }
        
        /* Top Header */
        .top-header {
            background: white;
            padding: 1rem 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dealer-badge {
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #334155;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border: none;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .stat-icon {
            width: 60px; height: 60px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
        }
        .bg-light-primary { background: #eff6ff; color: #3b82f6; }
        .bg-light-success { background: #f0fdf4; color: #22c55e; }
        .bg-light-warning { background: #fefce8; color: #eab308; }
        .bg-light-danger { background: #fef2f2; color: #ef4444; }
        
        .stat-details h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .stat-details p { margin: 0; color: #64748b; font-size: 0.875rem; font-weight: 500; }
    </style>
CSS;

$content = preg_replace('/<style>.*?<\/style>/s', $newCss, $content);

// Change the sidebar brand text color and dealer info block text color
$content = str_replace('<div class="text-white fw-bold">', '<div class="text-dark fw-bold">', $content);

file_put_contents($file, $content);
echo "Updated sidebar to light theme.\n";
?>
