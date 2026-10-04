<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

// 1. Replace the image block to make it clickable
$old_img = '<img src="<?= htmlspecialchars($dealer[\'photo\']) ?>" alt="Avatar">';
$new_img = '<img src="<?= htmlspecialchars($dealer[\'photo\']) ?>" alt="Avatar" style="cursor:pointer;" data-bs-toggle="modal" data-bs-target="#photoViewModal" title="Click to view">';

if (strpos($c, 'data-bs-target="#photoViewModal"') === false) {
    $c = str_replace($old_img, $new_img, $c);
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
                <?php if (!empty(\$dealer['photo'])): ?>
                    <img src=\"<?= htmlspecialchars(\$dealer['photo']) ?>\" class=\"img-fluid rounded shadow-lg\" oncontextmenu=\"return false;\" style=\"max-height: 80vh; pointer-events: none; border: 4px solid white;\" alt=\"Profile View\">
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
echo "Image view functionality integrated for Dealer.\n";
?>
