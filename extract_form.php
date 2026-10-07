<?php
$content = file_get_contents('operator/dealer_view.php');
if (preg_match('/<input type="hidden" name="action" value="edit_profile">.*?<\/form>/is', $content, $matches)) {
    echo $matches[0];
} else {
    echo "Form not found!";
}
?>
