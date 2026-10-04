<?php
$files = ['operator/recovery_man.php', 'operator/line_man.php'];
foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        $c = str_replace(
            "echo \"<script>alert('Error: Username might already exist.');</script>\";",
            "echo \"<script>alert('Error: ' + \" . json_encode(\$e->getMessage()) . \"); window.history.back();</script>\";",
            $c
        );
        file_put_contents($f, $c);
        echo "Patched $f\n";
    }
}
?>
