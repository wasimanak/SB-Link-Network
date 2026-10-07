<?php
// Patching operator/dealer_view.php
$f1 = 'operator/dealer_view.php';
$c1 = file_get_contents($f1);

// 1. Add ID and remove inline style from the select
$c1 = preg_replace('/<select name="assigned_routers\[\]" class="form-select" multiple.*?>/i', '<select id="edit_routers_select" name="assigned_routers[]" class="form-select" multiple required>', $c1);

// 2. Remove the old help text "Hold CTRL..." since Choices.js makes it intuitive
$c1 = preg_replace('/<div class="form-text text-muted">Hold CTRL.*?<\/div>/is', '', $c1);

// 3. Inject Choices.js CDN and init script at the bottom (before </body> or at the end of the file)
$choices_code = '
<!-- Premium Dropdown UI (Choices.js) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
<style>
.choices__inner { border-radius: 8px; border: 1px solid #dee2e6; background-color: #fff; padding: 5px 10px; }
.choices__list--multiple .choices__item { background-color: #0d6efd; border: none; border-radius: 5px; }
.choices[data-type*="select-multiple"] .choices__button { border-left: 1px solid rgba(255,255,255,0.3); }
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var editSelect = document.getElementById("edit_routers_select");
    if(editSelect) {
        new Choices(editSelect, {
            removeItemButton: true,
            searchPlaceholderValue: "Search routers...",
            itemSelectText: "",
            placeholderValue: "Select routers..."
        });
    }
});
</script>
';

if (strpos($c1, 'Choices.js') === false) {
    if (strpos($c1, '</body>') !== false) {
        $c1 = str_replace('</body>', $choices_code . '</body>', $c1);
    } else {
        $c1 .= $choices_code;
    }
    file_put_contents($f1, $c1);
    echo "operator/dealer_view.php patched with Choices.js\n";
}

// Patching operator/dealers.php
$f2 = 'operator/dealers.php';
$c2 = file_get_contents($f2);

$c2 = preg_replace('/<select name="assigned_routers\[\]" class="form-select" multiple.*?>/i', '<select id="add_routers_select" name="assigned_routers[]" class="form-select" multiple required>', $c2);
$c2 = preg_replace('/<div class="form-text text-muted">Hold CTRL.*?<\/div>/is', '', $c2);

$choices_code2 = '
<!-- Premium Dropdown UI (Choices.js) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
<style>
.choices__inner { border-radius: 8px; border: 1px solid #dee2e6; background-color: #fff; padding: 5px 10px; }
.choices__list--multiple .choices__item { background-color: #0d6efd; border: none; border-radius: 5px; }
.choices[data-type*="select-multiple"] .choices__button { border-left: 1px solid rgba(255,255,255,0.3); }
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var addSelect = document.getElementById("add_routers_select");
    if(addSelect) {
        new Choices(addSelect, {
            removeItemButton: true,
            searchPlaceholderValue: "Search routers...",
            itemSelectText: "",
            placeholderValue: "Select routers..."
        });
    }
});
</script>
';

if (strpos($c2, 'Choices.js') === false) {
    if (strpos($c2, '</body>') !== false) {
        $c2 = str_replace('</body>', $choices_code2 . '</body>', $c2);
    } else {
        $c2 .= $choices_code2;
    }
    file_put_contents($f2, $c2);
    echo "operator/dealers.php patched with Choices.js\n";
}
?>
