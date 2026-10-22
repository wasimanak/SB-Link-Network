<?php
$f = 'operator/header.php';
$c = file_get_contents($f);

// 1. Wrap the text in .nav-link-main with a span so we can hide it.
// e.g., <i class="fa-solid fa-house menu-icon"></i> Home
// becomes <i class="fa-solid fa-house menu-icon"></i> <span class="menu-text">Home</span>
$c = preg_replace('/(<i class="[^"]+ menu-icon[^"]*"><\/i>)\s+([A-Za-z0-np-z &\/]+)(?=\s*<\/a>|\s*<span)/', '$1 <span class="menu-text">$2</span>', $c);

// Also handle the brand text
$c = preg_replace('/(<h5 class="fw-bold mb-0 text-dark"[^>]*>.*<\/h5>)\s*(<div class="text-muted".*Operator Panel<\/div>)/is', '<div class="brand-text" style="white-space: nowrap; overflow: hidden; transition: opacity 0.3s;">$1$2</div>', $c);


// 2. Add the CSS for mini-sidebar
$miniCss = <<<CSS
        /* Mini Sidebar Styles */
        .sidebar { transition: width 0.3s ease, padding 0.3s ease; z-index: 1040; overflow-x: hidden; }
        .main-content, .top-nav { transition: margin-left 0.3s ease; }
        
        .sidebar.mini-sidebar { width: 75px !important; }
        .sidebar.mini-sidebar .menu-text { display: none; }
        .sidebar.mini-sidebar .brand-text { display: none; }
        .sidebar.mini-sidebar .has-submenu::after { display: none; }
        .sidebar.mini-sidebar .badge { display: none !important; }
        .sidebar.mini-sidebar .submenu { display: none !important; }
        .sidebar.mini-sidebar .nav-link-main { padding: 14px 10px; justify-content: center; }
        .sidebar.mini-sidebar .nav-link-main i.menu-icon { width: auto; margin: 0; font-size: 1.3rem; }
        
        .main-content.mini-sidebar-active { margin-left: 75px; }
        .top-nav.mini-sidebar-active { margin-left: 75px; }
CSS;

$c = str_replace('/* Sidebar Styles based on requested design */', $miniCss . "\n        /* Sidebar Styles based on requested design */", $c);

// Also need to change default margins in top-nav and main-content so they match mini by default
$c = str_replace('margin-left: 260px;', 'margin-left: 75px;', $c);

// 3. Add JS to handle click to expand, and click outside to collapse
$js = <<<JS
    <!-- Mini Sidebar Logic -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const topNav = document.querySelector('.top-nav');

        // Set default mini state
        sidebar.classList.add('mini-sidebar');
        
        sidebar.addEventListener('click', function(e) {
            // If it's mini, expand it and prevent the click from doing its normal action
            if (sidebar.classList.contains('mini-sidebar')) {
                sidebar.classList.remove('mini-sidebar');
                if (mainContent) mainContent.style.marginLeft = '260px';
                if (topNav) topNav.style.marginLeft = '260px';
                
                // Prevent default so they don't accidentally click a link when just trying to open the sidebar
                e.preventDefault();
                e.stopPropagation();
            }
        });

        // Click outside to collapse
        document.addEventListener('click', function(e) {
            if (!sidebar.contains(e.target) && !sidebar.classList.contains('mini-sidebar')) {
                sidebar.classList.add('mini-sidebar');
                if (mainContent) mainContent.style.marginLeft = '75px';
                if (topNav) topNav.style.marginLeft = '75px';
                
                // Optional: Close submenus when collapsing
                let openMenus = sidebar.querySelectorAll('.submenu.show');
                openMenus.forEach(menu => {
                    if (typeof bootstrap !== 'undefined') {
                        let bsCollapse = bootstrap.Collapse.getInstance(menu);
                        if (bsCollapse) bsCollapse.hide();
                    } else {
                        menu.classList.remove('show');
                    }
                });
            }
        });
    });
    </script>
JS;

$c = str_replace('</body>', $js . "\n</body>", $c);

file_put_contents($f, $c);
echo "Mini sidebar applied.\n";
?>
