<?php
$f = 'operator/profile.php';
$c = file_get_contents($f);

// Inject silent restart logic after NAS insert
$inject = <<<PHP
->execute([\$client_id, \$nasname, \$shortname, \$secret, \$api_user, \$api_pass]);
            
            // Silently restart FreeRADIUS so the new router is active immediately
            exec("sudo systemctl restart freeradius 2>&1");
            exec("systemctl restart freeradius 2>&1");
PHP;

if (strpos($c, 'Silently restart FreeRADIUS') === false) {
    $c = str_replace("->execute([\$client_id, \$nasname, \$shortname, \$secret, \$api_user, \$api_pass]);", $inject, $c);
    file_put_contents($f, $c);
    echo "Added auto-restart to operator/profile.php\n";
} else {
    echo "Already added.\n";
}
?>
