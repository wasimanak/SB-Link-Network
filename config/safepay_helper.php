<?php
/**
 * Safepay Payment Gateway Centralized Helper
 * Supports Debit/Credit Cards (Any Bank), EasyPaisa, JazzCash, Raast & 1Link Bank Accounts
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/radius_kick_helper.php';

/**
 * Ensure online_payments table exists
 */
function ensure_online_payments_table($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `online_payments` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `client_id` INT NOT NULL,
              `subscriber_id` INT NOT NULL,
              `order_id` VARCHAR(100) NOT NULL UNIQUE,
              `tracker_token` VARCHAR(255) NULL,
              `amount` DECIMAL(10,2) NOT NULL,
              `currency` VARCHAR(10) DEFAULT 'PKR',
              `purpose` ENUM('recharge', 'package_renew') DEFAULT 'recharge',
              `package_id` INT NULL,
              `gateway` VARCHAR(50) DEFAULT 'Safepay',
              `status` ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
              `payment_method_used` VARCHAR(100) NULL,
              `raw_response` TEXT NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              `completed_at` DATETIME NULL,
              INDEX (`order_id`),
              INDEX (`tracker_token`),
              INDEX (`subscriber_id`),
              INDEX (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {}
}

/**
 * Fetch active Safepay gateway credentials for an operator
 */
function get_safepay_config($pdo, $client_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE client_id = ? AND gateway_name = 'Safepay' AND status = 'active' LIMIT 1");
        $stmt->execute([$client_id]);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cfg) return $cfg;

        // Fallback: Check if ANY active Safepay gateway exists in the system
        $stmt2 = $pdo->prepare("SELECT * FROM payment_gateways WHERE gateway_name = 'Safepay' AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt2->execute();
        $cfg2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($cfg2) return $cfg2;
        
        // Fallback: Check if any gateway exists with name Safepay even if not active
        $stmt3 = $pdo->prepare("SELECT * FROM payment_gateways WHERE gateway_name = 'Safepay' ORDER BY id DESC LIMIT 1");
        $stmt3->execute();
        return $stmt3->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Initialize a payment tracker with Safepay API
 *
 * @param string $api_key (Public Key, e.g. sec_...)
 * @param string $api_secret (Secret Key)
 * @param float $amount (Amount in PKR)
 * @param string $mode ('sandbox' or 'live')
 * @return array ['success' => bool, 'token' => string|null, 'error' => string|null]
 */
function create_safepay_tracker($api_key, $api_secret, $amount, $mode = 'sandbox') {
    $baseUrl = ($mode === 'live') ? 'https://api.getsafepay.com' : 'https://sandbox.api.getsafepay.com';
    $url = $baseUrl . '/order/v1/init';

    $payload = json_encode([
        'client'      => $api_key,
        'amount'      => (float)$amount,
        'currency'    => 'PKR',
        'environment' => ($mode === 'live') ? 'production' : 'sandbox'
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-SFPY-MERCHANT-SECRET: ' . trim($api_secret)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'token' => null, 'error' => "Curl Error: " . $curlErr];
    }

    $res = json_decode($response, true);
    if ($httpCode === 200 && !empty($res['data']['token'])) {
        return ['success' => true, 'token' => $res['data']['token'], 'raw' => $res];
    }

    $errMsg = !empty($res['status']['message']) ? $res['status']['message'] : ("HTTP Code: " . $httpCode);
    if (!empty($res['status']['errors'])) {
        $errMsg .= " - " . implode(", ", (array)$res['status']['errors']);
    }

    return ['success' => false, 'token' => null, 'error' => $errMsg];
}

/**
 * Build Safepay Hosted Checkout Redirection URL
 */
function get_safepay_checkout_url($tracker_token, $order_id, $redirect_url, $cancel_url, $mode = 'sandbox') {
    $checkoutHost = ($mode === 'live') 
        ? 'https://getsafepay.com/checkout/pay' 
        : 'https://sandbox.api.getsafepay.com/checkout/pay';

    $env_name = ($mode === 'live') ? 'production' : 'sandbox';

    $params = [
        'beacon'       => $tracker_token,
        'env'          => $env_name,
        'environment'  => $env_name,
        'order_id'     => $order_id,
        'redirect_url' => $redirect_url,
        'cancel_url'   => $cancel_url,
        'source'       => 'custom'
    ];

    return $checkoutHost . '?' . http_build_query($params);
}

/**
 * Verify a payment with Safepay using HMAC signature or direct API verification
 */
function verify_safepay_payment($tracker, $sig, $api_secret, $mode = 'sandbox') {
    // 1. Signature check if provided
    $sig_valid = false;
    if (!empty($sig) && !empty($tracker) && !empty($api_secret)) {
        $expected_sig = hash_hmac('sha256', $tracker, $api_secret);
        if (hash_equals($expected_sig, $sig)) {
            $sig_valid = true;
        }
    }

    // 2. Direct API status verification with Safepay order details endpoint
    $baseUrl = ($mode === 'live') ? 'https://api.getsafepay.com' : 'https://sandbox.api.getsafepay.com';
    $url = $baseUrl . '/order/v1/details?tracker=' . urlencode($tracker);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-SFPY-MERCHANT-SECRET: ' . trim($api_secret)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res = json_decode($response, true);
    if ($httpCode === 200 && !empty($res['data'])) {
        $state = strtoupper($res['data']['state'] ?? '');
        // Valid successful states in Safepay: PAID, COMPLETED, TRACKER_ENDED
        if (in_array($state, ['PAID', 'COMPLETED', 'TRACKER_ENDED'])) {
            return [
                'verified' => true,
                'state'    => $state,
                'data'     => $res['data']
            ];
        }
    }

    // If signature was valid, consider verified even if details call had a minor timeout
    if ($sig_valid) {
        return [
            'verified' => true,
            'state'    => 'PAID_SIG_VERIFIED',
            'data'     => null
        ];
    }

    return ['verified' => false, 'state' => 'UNVERIFIED', 'raw' => $response];
}

/**
 * Process a successful online payment:
 * 1. Mark online_payments record as completed (Idempotency safe).
 * 2. Credit subscriber wallet balance OR Renew internet package.
 * 3. Update FreeRADIUS and kick session on MikroTik.
 * 4. Record ledger and activity log entries.
 */
function process_successful_payment($order_ref, $pdo, $method_label = 'Safepay') {
    ensure_online_payments_table($pdo);

    // Find order by order_id or tracker_token or id
    $stmt = $pdo->prepare("SELECT * FROM online_payments WHERE order_id = ? OR tracker_token = ? OR id = ? LIMIT 1");
    $stmt->execute([$order_ref, $order_ref, is_numeric($order_ref) ? (int)$order_ref : 0]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'message' => "Order not found: {$order_ref}"];
    }

    // Check if already processed (Idempotency protection)
    if ($order['status'] === 'completed') {
        return ['success' => true, 'already_completed' => true, 'order' => $order];
    }

    $client_id = (int)$order['client_id'];
    $sub_id = (int)$order['subscriber_id'];
    $amount = (float)$order['amount'];
    $purpose = $order['purpose'];
    $package_id = (int)($order['package_id'] ?? 0);

    // Fetch subscriber details
    $subStmt = $pdo->prepare("SELECT * FROM subscribers WHERE id = ?");
    $subStmt->execute([$sub_id]);
    $sub = $subStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sub) {
        return ['success' => false, 'message' => "Subscriber #{$sub_id} not found."];
    }

    $username = $sub['username'];
    $current_bal = (float)$sub['balance'];

    $pdo->beginTransaction();
    try {
        // Mark payment as completed
        $updOrder = $pdo->prepare("UPDATE online_payments SET status = 'completed', completed_at = NOW(), payment_method_used = ? WHERE id = ?");
        $updOrder->execute([$method_label, $order['id']]);

        if ($purpose === 'recharge' || empty($package_id)) {
            // Case 1: Wallet Recharge
            $new_bal = $current_bal + $amount;
            $pdo->prepare("UPDATE subscribers SET balance = ? WHERE id = ?")->execute([$new_bal, $sub_id]);

            // User Ledger
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $username, $amount, $new_bal, "Online Wallet Recharge via {$method_label} (Order #{$order['order_id']})"]);

            // Activity Log
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'System', ?, 'User', ?)")
                ->execute([$client_id, $username, "Online Wallet Recharge: Rs. " . number_format($amount, 2) . " via {$method_label}"]);

        } elseif ($purpose === 'package_renew') {
            // Case 2: Direct Package Renewal
            $pkgStmt = $pdo->prepare("SELECT p.name, p.validity_days, p.rate_limit, p.price, p.wan_provider, p.pool_id, pl.pool_name 
                                      FROM packages p 
                                      LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                                      WHERE p.id = ?");
            $pkgStmt->execute([$package_id]);
            $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

            if (!$pkg) {
                // If package not found, fallback to crediting wallet
                $new_bal = $current_bal + $amount;
                $pdo->prepare("UPDATE subscribers SET balance = ? WHERE id = ?")->execute([$new_bal, $sub_id]);
            } else {
                $days = (int)$pkg['validity_days'];
                $current_expiry = strtotime($sub['expiry_date'] ?? '1970-01-01');
                $now = time();

                if ($current_expiry > $now) {
                    $new_expiry = date('Y-m-d H:i:s', strtotime("+{$days} days", $current_expiry));
                } else {
                    $new_expiry = date('Y-m-d H:i:s', strtotime("+{$days} days", $now));
                }

                // Update subscriber record
                $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")
                    ->execute([$package_id, $new_expiry, $sub_id]);

                // Update FreeRADIUS radreply
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$username]);
                if (!empty($pkg['rate_limit']) && !in_array($pkg['rate_limit'], ['No Limit', 'Unlimited'])) {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
                }

                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$username]);

                // Profile name (e.g. 5Mbps)
                $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                    if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                        $target_profile = $m_prof[1] . 'Mbps';
                    }
                }
                if (!empty($target_profile)) {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$username, $target_profile]);
                }

                // Update FreeRADIUS radcheck Expiration
                $formatted_expiry = date('d M Y H:i:s', strtotime($new_expiry));
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);

                // Logs
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'System', ?, 'User', ?)")
                    ->execute([$client_id, $username, "Online Package Renewal ({$pkg['name']}) via {$method_label}"]);

                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                    ->execute([$client_id, $username, $amount, $current_bal, "Online Payment via {$method_label} (Order #{$order['order_id']})"]);

                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                    ->execute([$client_id, $username, $amount, $current_bal, "Auto-Renewed Package: {$pkg['name']}"]);
            }
        }

        $pdo->commit();

        // Kick session on MikroTik so subscriber reconnects immediately with renewed speed/status
        try {
            kick_subscriber_session($pdo, $username, $client_id);
        } catch (Exception $e) {}

        return ['success' => true, 'already_completed' => false, 'order' => $order];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => "DB Error: " . $e->getMessage()];
    }
}
