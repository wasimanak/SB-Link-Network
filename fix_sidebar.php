<?php
$f = 'operator/header.php';
$c = file_get_contents($f);

// 1. Hardcode mini-sidebar class into the HTML so it's there instantly
$c = str_replace('<div class="sidebar">', '<div class="sidebar mini-sidebar">', $c);

// 2. Add the JS logic to the bottom of the file (since there is no </body> in header.php)
$js = <<<JS
<!-- Mini Sidebar Logic -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    const topNav = document.querySelector('.top-nav');
    
    if(!sidebar) return;

    sidebar.addEventListener('click', function(e) {
        if (sidebar.classList.contains('mini-sidebar')) {
            sidebar.classList.remove('mini-sidebar');
            if (mainContent) mainContent.style.marginLeft = '260px';
            if (topNav) topNav.style.marginLeft = '260px';
            
            e.preventDefault();
            e.stopPropagation();
        }
    });

    document.addEventListener('click', function(e) {
        if (!sidebar.contains(e.target) && !sidebar.classList.contains('mini-sidebar')) {
            sidebar.classList.add('mini-sidebar');
            if (mainContent) mainContent.style.marginLeft = '75px';
            if (topNav) topNav.style.marginLeft = '75px';
            
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

// Append if not already there
if (strpos($c, '<!-- Mini Sidebar Logic -->') === false) {
    $c .= "\n" . $js . "\n";
}

file_put_contents($f, $c);
echo "Fixed mini sidebar logic and HTML.\n";
?>
