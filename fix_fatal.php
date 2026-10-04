<?php
$files = ['customer/dashboard.php', 'dealer/dashboard.php'];
foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        
        // Fix 1: bank_details
        $c = str_replace(
            "\$bank_details = \$operator_info['bank_details'] ?: \"Bank details not provided. Please contact operator.\";",
            "\$bank_details = (isset(\$operator_info['bank_details']) && \$operator_info['bank_details']) ? \$operator_info['bank_details'] : \"Bank details not provided. Please contact operator.\";",
            $c
        );
        
        // Fix 2: company_name in active method logic (PHP 7/8 compat fix)
        $c = str_replace(
            "\$gateway_account_name = \$gw['account_name'] ?: (\$operator_info['company_name'] ?? 'SB-Link Network');",
            "\$opCompany = isset(\$operator_info['company_name']) ? \$operator_info['company_name'] : 'SB-Link Network';\n    \$gateway_account_name = \$gw['account_name'] ?: \$opCompany;",
            $c
        );
        
        $c = str_replace(
            "\$gateway_account_name = \$operator_info['company_name'] ?? 'SB-Link Network';",
            "\$gateway_account_name = isset(\$operator_info['company_name']) ? \$operator_info['company_name'] : 'SB-Link Network';",
            $c
        );
        
        file_put_contents($f, $c);
        echo "Fixed array access in $f\n";
    }
}
?>
