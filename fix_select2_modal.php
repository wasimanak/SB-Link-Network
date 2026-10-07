<?php
// Patching operator/dealers.php
$f1 = 'operator/dealers.php';
$c1 = file_get_contents($f1);
$c1 = str_replace('width: "100%"', 'width: "100%", dropdownParent: $("#addDealerModal")', $c1);
file_put_contents($f1, $c1);
echo "operator/dealers.php dropdownParent fixed.\n";

// Patching operator/dealer_view.php
$f2 = 'operator/dealer_view.php';
$c2 = file_get_contents($f2);

// Check if editProfileModal exists
if (strpos($c2, 'id="editProfileModal"') !== false) {
    $c2 = str_replace('width: "100%"', 'width: "100%", dropdownParent: $("#editProfileModal")', $c2);
} else {
    // Maybe the modal is just #editModal or something. Let's find it.
    preg_match('/<div class="modal fade" id="(.*?)"/i', $c2, $m);
    if (!empty($m[1])) {
        $c2 = str_replace('width: "100%"', 'width: "100%", dropdownParent: $("#' . $m[1] . '")', $c2);
    }
}
file_put_contents($f2, $c2);
echo "operator/dealer_view.php dropdownParent fixed.\n";
?>
