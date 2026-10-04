<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// Add CSS link after the first PHP block closes
if (strpos($c, 'select2.min.css') === false) {
    $css = "\n<!-- Select2 CSS -->\n<link href=\"https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css\" rel=\"stylesheet\" />\n<style>.select2-container .select2-selection--single { height: 31px; border: 1px solid #dee2e6; } .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 29px; color: #475569; font-size: 0.875rem; } .select2-container--default .select2-selection--single .select2-selection__arrow { height: 29px; } .select2-dropdown { border: 1px solid #dee2e6; }</style>\n";
    $c = preg_replace('/\?>/', "?>\n" . $css, $c, 1); // Only replace the first one
}

// Add JS link and init script at the end before </body> or inside the existing script tags
if (strpos($c, 'select2.min.js') === false) {
    $js = "<script src=\"https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js\"></script>\n<script>\n$(document).ready(function() {\n    $('#renew_user_id').select2({\n        dropdownParent: $('#renewUserModal'),\n        width: '100%',\n        placeholder: '-- Choose User --'\n    });\n});\n</script>\n";
    $c = str_replace("<?php require_once 'footer.php'; ?>", $js . "<?php require_once 'footer.php'; ?>", $c);
}

file_put_contents($f, $c);
echo "Select2 injected successfully.";
?>
