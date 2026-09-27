<?php

function fixFile($filePath) {
    if (!file_exists($filePath)) return;
    $content = file_get_contents($filePath);
    
    // 1. Add class="needs-validation" novalidate to the addUserModal form
    $content = preg_replace(
        '/(<div class="modal fade" id="addUserModal" tabindex="-1">.*?<form method="POST")>/s',
        '$1 class="needs-validation" novalidate>',
        $content
    );

    // 2. Remove accordion structure, keep it flat
    // We'll replace accordion headers and collapse classes
    
    // Account Info Section
    $content = preg_replace(
        '/<div class="accordion-item[^>]*>\s*<h2 class="accordion-header">\s*<button class="accordion-button[^"]*" type="button" data-bs-toggle="collapse"[^>]*>\s*(<i class="[^"]+"><\/i>[^<]+)\s*<\/button>\s*<\/h2>\s*<div id="collapseAccount" class="accordion-collapse collapse[^"]*"[^>]*>\s*<div class="accordion-body[^"]*">/s',
        '<div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">$1</h6>
            ',
        $content
    );
    
    // Service Info Section
    $content = preg_replace(
        '/<\/div>\s*<\/div>\s*<\/div>\s*<!-- Service Info -->\s*<div class="accordion-item[^>]*>\s*<h2 class="accordion-header">\s*<button class="accordion-button[^"]*" type="button" data-bs-toggle="collapse"[^>]*>\s*(<i class="[^"]+"><\/i>[^<]+)\s*<\/button>\s*<\/h2>\s*<div id="collapseService" class="accordion-collapse collapse[^"]*"[^>]*>\s*<div class="accordion-body[^"]*">/s',
        '</div>
        <!-- Service Info -->
        <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">$1</h6>
            ',
        $content
    );

    // Contact Info Section
    $content = preg_replace(
        '/<\/div>\s*<\/div>\s*<\/div>\s*<!-- Contact Info -->\s*<div class="accordion-item[^>]*>\s*<h2 class="accordion-header">\s*<button class="accordion-button[^"]*" type="button" data-bs-toggle="collapse"[^>]*>\s*(<i class="[^"]+"><\/i>[^<]+)\s*<\/button>\s*<\/h2>\s*<div id="collapseContact" class="accordion-collapse collapse[^"]*"[^>]*>\s*<div class="accordion-body[^"]*">/s',
        '</div>
        <!-- Contact Info -->
        <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2">$1</h6>
            ',
        $content
    );

    // End of Contact Info Section and Accordion wrapper
    $content = preg_replace(
        '/<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<div class="modal-footer/s',
        '</div>
        </div>
        <div class="modal-footer',
        $content
    );

    // Also remove the <div class="accordion" id="addUserAccordion"> wrapper
    $content = preg_replace(
        '/<div class="accordion[^"]*" id="addUserAccordion">/s',
        '<div class="add-user-flat-form">',
        $content
    );
    
    // 3. Add JS Validation Script if not present
    if (strpos($content, 'needs-validation') !== false && strpos($content, '.was-validated') === false) {
        $js = "
<script>
document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
});
</script>
";
        // append JS before closing body or at the end
        $content = str_replace('</body>', $js . "\n</body>", $content);
        // If no </body> (e.g. in subscribers.php which might include footer.php), insert at end of file
        if (strpos($content, '</body>') === false) {
            $content .= $js;
        }
    }

    file_put_contents($filePath, $content);
    echo "Processed $filePath\n";
}

fixFile("C:/xampp/htdocs/SB Link Network/operator/dashboard.php");
fixFile("C:/xampp/htdocs/SB Link Network/operator/subscribers.php");

