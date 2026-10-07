<?php
$f = 'lineman/dashboard.php';
$c = file_get_contents($f);

// Find the JS function that was injected without script tags
$pattern = '/function testLineQuality\(ip\) \{.*?\n\}/s';
if (preg_match($pattern, $c, $matches)) {
    $raw_js = $matches[0];
    
    // Check if it's already inside a script tag (unlikely given the bug report)
    // We can just replace the raw JS with wrapped JS
    $wrapped_js = "<script>\n" . $raw_js . "\n</script>";
    
    // Replace it in the file
    $c = str_replace($raw_js, $wrapped_js, $c);
    
    file_put_contents($f, $c);
    echo "Fixed: Wrapped testLineQuality in <script> tags.\n";
} else {
    echo "Function not found or already wrapped differently.\n";
}
?>
