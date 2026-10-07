<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

$replacements = [
    // Users -> subscribers.php
    '<div class="stat-box bg-blue">' => '<div class="stat-box bg-blue cursor-pointer" onclick="window.location.href=\'subscribers.php\'">',
    
    // Active -> subscribers.php
    '<div class="stat-box bg-green">' => '<div class="stat-box bg-green cursor-pointer" onclick="window.location.href=\'subscribers.php\'">',
    
    // Online -> live_sessions.php
    '<div class="stat-box bg-green" style="opacity: 0.8;">' => '<div class="stat-box bg-green cursor-pointer" style="opacity: 0.8;" onclick="window.location.href=\'live_sessions.php\'">',
    
    // Offline -> subscribers.php
    '<div class="stat-box bg-grey">' => '<div class="stat-box bg-grey cursor-pointer" onclick="window.location.href=\'subscribers.php\'">',
    
    // Disable -> ?report=disabled#reportsSection
    '<div class="title"><i class="fa-solid fa-user-slash"></i> Disable</div>' => '<div class="title"><i class="fa-solid fa-user-slash"></i> Disable</div>', // Need a better target for Disable
    
    // Expired -> ?report=expired#reportsSection
    '<div class="stat-box bg-red">' => '<div class="stat-box bg-red cursor-pointer" onclick="window.location.href=\'?report=expired#reportsSection\'">',
    
    // Expiring (1 Day)
    '<div class="stat-box bg-yellow">' => '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'?report=expiring_1#reportsSection\'">',
];

// We need to apply this carefully.
// Instead of simple str_replace which might catch multiple bg-grey, let's use regex for specific titles.

$regex_replacements = [
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-users"><\/i> Users<\/div>)/i' 
        => '<div class="stat-box bg-blue cursor-pointer" onclick="window.location.href=\'subscribers.php\'" title="View all subscribers">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-circle-check"><\/i> Active<\/div>)/i' 
        => '<div class="stat-box bg-green cursor-pointer" onclick="window.location.href=\'subscribers.php\'" title="View all subscribers">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-wifi"><\/i> Online<\/div>)/i' 
        => '<div class="stat-box bg-green cursor-pointer" style="opacity: 0.8;" onclick="window.location.href=\'live_sessions.php\'" title="View live sessions">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-large-slash"><\/i> Offline<\/div>)/i' 
        => '<div class="stat-box bg-grey cursor-pointer" onclick="window.location.href=\'subscribers.php\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-plus"><\/i> Registered<\/div>)/i' 
        => '<div class="stat-box bg-grey cursor-pointer" onclick="window.location.href=\'subscribers.php\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-slash"><\/i> Disable<\/div>)/i' 
        => '<div class="stat-box bg-grey cursor-pointer" onclick="window.location.href=\'?report=disabled#reportsSection\'" title="View disabled users report">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-xmark"><\/i> Expired<\/div>)/i' 
        => '<div class="stat-box bg-red cursor-pointer" onclick="window.location.href=\'?report=expired#reportsSection\'" title="View expired users report">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-network-wired"><\/i> PPPoE<\/div>)/i' 
        => '<div class="stat-box bg-grey cursor-pointer" onclick="window.location.href=\'subscribers.php\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-wifi"><\/i> Hotspot<\/div>)/i' 
        => '<div class="stat-box bg-grey cursor-pointer" onclick="window.location.href=\'subscribers.php\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-hourglass-end"><\/i> Expiring \(1 Day\)<\/div>)/i' 
        => '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'?report=expiring_1#reportsSection\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-hourglass-half"><\/i> Expiring \(3 Days\)<\/div>)/i' 
        => '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'?report=expiring_3#reportsSection\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-calendar-week"><\/i> Expiring \(1 Week\)<\/div>)/i' 
        => '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'?report=expiring_1w#reportsSection\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-calendar-days"><\/i> Expiring \(2 Weeks\)<\/div>)/i' 
        => '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'?report=expiring_2w#reportsSection\'">'."\n".'$2',
        
    '/(<div class="stat-box[^>]*>)\s*(<div class="title"><i class="fa-solid fa-user-clock"><\/i> Expired Online<\/div>)/i' 
        => '<div class="stat-box bg-yellow cursor-pointer" onclick="window.location.href=\'live_sessions.php\'">'."\n".'$2',
];

foreach ($regex_replacements as $pattern => $replacement) {
    $c = preg_replace($pattern, $replacement, $c);
}

// Add CSS to make the cards look clickable on hover if not already
if (strpos($c, '.cursor-pointer') === false) {
    $c = str_replace('<style>', "<style>\n    .cursor-pointer { cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }\n    .cursor-pointer:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }", $c);
}

file_put_contents($f, $c);
echo "Updated dashboard stat boxes to be clickable.\n";
?>
