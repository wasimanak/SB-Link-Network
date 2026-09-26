<?php
try {
    $p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin','syncuser','admin123');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Add missing IPv6 columns to radacct table
    $query = "ALTER TABLE radacct 
              ADD COLUMN framedipv6address varchar(45) NOT NULL default '' AFTER framedipaddress,
              ADD COLUMN framedipv6prefix varchar(45) NOT NULL default '' AFTER framedipv6address,
              ADD COLUMN framedinterfaceid varchar(44) NOT NULL default '' AFTER framedipv6prefix,
              ADD COLUMN delegatedipv6prefix varchar(45) NOT NULL default '' AFTER framedinterfaceid";
              
    $p->exec($query);
    echo "Successfully fixed radacct table!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
