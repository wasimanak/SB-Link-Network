<?php
/**
 * =========================================================================================
 * NETBEE - CENTRALIZED BRANDING & TEXTS CONFIGURATION FILE
 * =========================================================================================
 * 
 * Is aik file se aap poori website ka Naam, Logo Text, Tagline, Phone, Email, 
 * Copyright aur tamam Portals ke Titles aik hi jagah se badal saktay hain!
 * 
 * Jab bhi aap yahan koi text change karenge, wo foran:
 * 1. Customer Portal
 * 2. Operator Portal
 * 3. Dealer Portal
 * 4. Line Man Portal
 * 5. Recovery Man Portal
 * 6. Super Admin Portal
 * 7. Unified Login Screen
 * me khud-ba-khud update ho jayega!
 * =========================================================================================
 */

$BRAND_CONFIG = [
    // 1. Main System & Brand Name
    'app_name'          => 'LASANI NETWORK (Pvt) Ltd',                         // Main brand name (e.g. NetBee)
    'app_title'         => 'LASANI NETWORK (Pvt) Ltd',                 // Full company/network title
    'app_short'         => 'LASANI NETWORK (Pvt) Ltd',                         // Short display name
    'logo_abbr'         => 'LN',                             // 2-character abbreviation for round avatar logos
    
    // 2. Tagline & Slogans
    'tagline'           => 'Next-Gen High-Speed Internet & RADIUS Management System',
    'customer_slogan'   => 'Providing ultra-fast, reliable, and unlimited internet connectivity for your home and business.',
    
    // 3. Powered By & Copyright (Centrally controlled for all portals & pages)
    'powered_by'        => 'Alpha4Technologies',
    'powered_by_email'  => 'businessoperation7616@gmail.com',
    'powered_by_link'   => 'mailto:businessoperation7616@gmail.com',
    'copyright_holder'  => 'LASANI NETWORK (Pvt) Ltd',
    
    // 4. Portal Titles (Browser Tab Titles & Topbars)
    'title_customer'    => 'Customer Portal - Lasani',
    'title_operator'    => 'Operator Portal - Lasani',
    'title_dealer'      => 'Dealer Dashboard - Lasani',
    'title_lineman'     => 'Line Man Portal - Lasani',
    'title_recoveryman' => 'Recovery Portal - Lasani',
    'title_superadmin'  => 'Super Admin - Lasani',
    'title_login'       => 'Login - LASANI NETWORK (Pvt) Ltd',

    // 5. Contact, Support & WhatsApp Details (Website Landing Page & All Portals)
    'support_phone'        => '0301-6542060',                   // Official Helpline / Mobile Phone Number
    'support_whatsapp'     => '0301-6542060',                    // Official WhatsApp Number for Orders & Helpline (e.g. 03036542000)
    'support_email'        => 'malikmobiles4004@gmail.com',            // Official Support & Inquiries Email
    'support_address'      => 'Al Munir Mobile Shop Nearr MCB Bank, Piplan, District Mianwali, Pakistan', // Office / NOC Location
    'website_url'          => 'https://lasani.tech',            // Official Website URL
    'customer_portal_url'  => '/customer/login',                // Customer Self-Care Login URL
    'login_url'            => '/login',                         // Unified Multi-Role Login URL

    // 6. WhatsApp Reminders Default Signature
    'whatsapp_sender'      => 'LASANI NETWORK (Pvt) Ltd',
];

// -----------------------------------------------------------------------------------------
// GLOBAL CONSTANTS & HELPER FUNCTIONS (DO NOT REMOVE)
// -----------------------------------------------------------------------------------------
if (!defined('BRAND_NAME'))                define('BRAND_NAME',                $BRAND_CONFIG['app_name']);
if (!defined('BRAND_TITLE'))               define('BRAND_TITLE',               $BRAND_CONFIG['app_title']);
if (!defined('BRAND_SHORT_NAME'))          define('BRAND_SHORT_NAME',          $BRAND_CONFIG['app_short']);
if (!defined('BRAND_LOGO_ABBR'))           define('BRAND_LOGO_ABBR',           $BRAND_CONFIG['logo_abbr']);
if (!defined('BRAND_TAGLINE'))             define('BRAND_TAGLINE',             $BRAND_CONFIG['tagline']);
if (!defined('BRAND_POWERED_BY'))          define('BRAND_POWERED_BY',          $BRAND_CONFIG['powered_by']);
if (!defined('BRAND_POWERED_BY_EMAIL'))    define('BRAND_POWERED_BY_EMAIL',    $BRAND_CONFIG['powered_by_email'] ?? 'businessoperation7616@gmail.com');
if (!defined('BRAND_POWERED_BY_LINK'))     define('BRAND_POWERED_BY_LINK',     $BRAND_CONFIG['powered_by_link'] ?? ('mailto:' . ($BRAND_CONFIG['powered_by_email'] ?? 'businessoperation7616@gmail.com')));
if (!defined('BRAND_SUPPORT_EMAIL'))       define('BRAND_SUPPORT_EMAIL',       $BRAND_CONFIG['support_email']);
if (!defined('BRAND_SUPPORT_PHONE'))       define('BRAND_SUPPORT_PHONE',       $BRAND_CONFIG['support_phone']);
if (!defined('BRAND_SUPPORT_WHATSAPP'))    define('BRAND_SUPPORT_WHATSAPP',    $BRAND_CONFIG['support_whatsapp']);
if (!defined('BRAND_SUPPORT_ADDRESS'))     define('BRAND_SUPPORT_ADDRESS',     $BRAND_CONFIG['support_address']);
if (!defined('BRAND_WEBSITE_URL'))         define('BRAND_WEBSITE_URL',         $BRAND_CONFIG['website_url']);
if (!defined('BRAND_CUSTOMER_PORTAL_URL')) define('BRAND_CUSTOMER_PORTAL_URL', $BRAND_CONFIG['customer_portal_url']);

$GLOBALS['site_brand'] = $BRAND_CONFIG;

if (!function_exists('app_brand')) {
    /**
     * Get any branding text easily: app_brand('app_name') or app_brand('support_phone')
     */
    function app_brand($key = null, $default = '') {
        global $BRAND_CONFIG;
        if ($key === null) return $BRAND_CONFIG;
        return $BRAND_CONFIG[$key] ?? $default;
    }
}

if (!function_exists('brand_whatsapp_number')) {
    /**
     * Get clean international WhatsApp number formatted for wa.me links
     */
    function brand_whatsapp_number() {
        $raw = app_brand('support_whatsapp', '03016542060');
        $clean = preg_replace('/[^0-9]/', '', $raw);
        if (strpos($clean, '03') === 0) {
            $clean = '92' . substr($clean, 1);
        }
        return $clean;
    }
}

if (!function_exists('render_powered_by_html')) {
    /**
     * Centralized Powered By HTML with mailto link.
     * Controlled directly by $BRAND_CONFIG['powered_by'] and $BRAND_CONFIG['powered_by_email'].
     */
    function render_powered_by_html($extraClass = 'text-primary') {
        $name = defined('BRAND_POWERED_BY') ? BRAND_POWERED_BY : 'Alpha4Technologies';
        $link = defined('BRAND_POWERED_BY_LINK') ? BRAND_POWERED_BY_LINK : 'mailto:businessoperation7616@gmail.com';
        return 'Powered by <a href="' . htmlspecialchars($link) . '" class="text-decoration-none fw-semibold ' . htmlspecialchars($extraClass) . '" title="Contact ' . htmlspecialchars($name) . '">' . htmlspecialchars($name) . '</a>';
    }
}

