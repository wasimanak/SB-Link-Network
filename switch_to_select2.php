<?php
// Patching operator/dealer_view.php to use Select2 instead of Choices.js
$f1 = 'operator/dealer_view.php';
$c1 = file_get_contents($f1);

// Remove Choices.js code
$c1 = preg_replace('/<!-- Premium Dropdown UI \(Choices\.js\).*?<\/script>/is', '', $c1);

$select2_code = '
<!-- Premium Dropdown UI (Select2) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
.select2-container--default .select2-selection--multiple {
    border-radius: 8px;
    border: 1px solid #dee2e6;
    padding: 4px;
    min-height: 45px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #0d6efd;
    border: none;
    color: white;
    border-radius: 5px;
    padding: 5px 10px;
    margin-top: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: white;
    margin-right: 8px;
    border-right: 1px solid rgba(255,255,255,0.3);
    padding-right: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #f8d7da;
    background: transparent;
}
.select2-dropdown {
    border-radius: 8px;
    border: 1px solid #dee2e6;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
</style>
<script>
$(document).ready(function() {
    $("#edit_routers_select").select2({
        placeholder: "Select one or more routers",
        allowClear: true,
        width: "100%"
    });
});
</script>
';

if (strpos($c1, '</body>') !== false) {
    $c1 = str_replace('</body>', $select2_code . '</body>', $c1);
} else {
    $c1 .= $select2_code;
}
file_put_contents($f1, $c1);
echo "dealer_view.php patched to use Select2.\n";

// Patching operator/dealers.php to use Select2
$f2 = 'operator/dealers.php';
$c2 = file_get_contents($f2);

// Remove Choices.js code
$c2 = preg_replace('/<!-- Premium Dropdown UI \(Choices\.js\).*?<\/script>/is', '', $c2);

$select2_code2 = '
<!-- Premium Dropdown UI (Select2) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
.select2-container--default .select2-selection--multiple {
    border-radius: 8px;
    border: 1px solid #dee2e6;
    padding: 4px;
    min-height: 45px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #0d6efd;
    border: none;
    color: white;
    border-radius: 5px;
    padding: 5px 10px;
    margin-top: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: white;
    margin-right: 8px;
    border-right: 1px solid rgba(255,255,255,0.3);
    padding-right: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #f8d7da;
    background: transparent;
}
.select2-dropdown {
    border-radius: 8px;
    border: 1px solid #dee2e6;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
</style>
<script>
$(document).ready(function() {
    $("#add_routers_select").select2({
        placeholder: "Select one or more routers",
        allowClear: true,
        width: "100%"
    });
});
</script>
';

if (strpos($c2, '</body>') !== false) {
    $c2 = str_replace('</body>', $select2_code2 . '</body>', $c2);
} else {
    $c2 .= $select2_code2;
}
file_put_contents($f2, $c2);
echo "dealers.php patched to use Select2.\n";
?>
