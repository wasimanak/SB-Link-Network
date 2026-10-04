<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// 1. Replace the image block
$old_img_block = "<?php if (!empty(\$user['photo']) && file_exists(\"../uploads/profiles/\" . \$user['photo'])): ?>
                    <div style=\"width: 60px; height: 60px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background-color: #e2e8f0; flex-shrink: 0;\">
                        <img src=\"../uploads/profiles/<?= htmlspecialchars(\$user['photo']) ?>\" alt=\"Profile\" style=\"width: 100%; height: 100%; object-fit: cover;\">
                    </div>";

$new_img_block = "<?php if (!empty(\$user['photo']) && file_exists(\"../uploads/profiles/\" . \$user['photo'])): ?>
                    <div style=\"width: 60px; height: 60px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background-color: #e2e8f0; flex-shrink: 0; cursor: pointer;\" data-bs-toggle=\"modal\" data-bs-target=\"#photoViewModal\" title=\"Click to view\">
                        <img src=\"../uploads/profiles/<?= htmlspecialchars(\$user['photo']) ?>\" alt=\"Profile\" style=\"width: 100%; height: 100%; object-fit: cover;\">
                    </div>";

if (strpos($c, 'data-bs-target="#photoViewModal"') === false) {
    $c = str_replace($old_img_block, $new_img_block, $c);
}

// 2. Append the modal before </body> or inside the modals area
$modal_html = "
<!-- View Photo Modal -->
<div class=\"modal fade\" id=\"photoViewModal\" tabindex=\"-1\">
    <div class=\"modal-dialog modal-dialog-centered\">
        <div class=\"modal-content bg-transparent border-0\">
            <div class=\"modal-header border-0 pb-0 justify-content-end\">
                <button type=\"button\" class=\"btn-close bg-white\" data-bs-dismiss=\"modal\" aria-label=\"Close\"></button>
            </div>
            <div class=\"modal-body text-center pt-0\">
                <?php if (!empty(\$user['photo'])): ?>
                    <img src=\"../uploads/profiles/<?= htmlspecialchars(\$user['photo']) ?>\" class=\"img-fluid rounded shadow-lg\" oncontextmenu=\"return false;\" style=\"max-height: 80vh; pointer-events: none; border: 4px solid white;\" alt=\"Profile View\">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
";

if (strpos($c, 'id="photoViewModal"') === false) {
    $c = str_replace('<?php require_once \'footer.php\'; ?>', $modal_html . "\n<?php require_once 'footer.php'; ?>", $c);
}

file_put_contents($f, $c);
echo "Image view functionality integrated.\n";
?>
