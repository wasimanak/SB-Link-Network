<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

try {
    $pdo->exec("ALTER TABLE `dealer_packages` ADD COLUMN `dealer_price` decimal(10,2) DEFAULT '0.00' AFTER `package_id`");
    echo "Added 'dealer_price' to 'dealer_packages' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

$file1 = 'C:/xampp/htdocs/SB Link Network/operator/api_router_time.php';
if (file_exists($file1)) {
    $content1 = file_get_contents($file1);
    $search = '$client_id = (int)$_SESSION[\'operator_id\'];';
    $replace = $search . "\n" . 'session_write_close(); // Unlock session immediately so it doesn\'t block other pages';
    if (strpos($content1, 'session_write_close') === false) {
        $content1 = str_replace($search, $replace, $content1);
        file_put_contents($file1, $content1);
        echo "Fixed api_router_time.php session lock.\n";
    }
}

$file2 = 'C:/xampp/htdocs/SB Link Network/operator/api_bandwidth.php';
if (file_exists($file2)) {
    $content2 = file_get_contents($file2);
    $search = '$client_id = $_SESSION[\'operator_id\'];';
    $replace = $search . "\n" . 'session_write_close(); // Unlock session';
    if (strpos($content2, 'session_write_close') === false) {
        $content2 = str_replace($search, $replace, $content2);
        file_put_contents($file2, $content2);
        echo "Fixed api_bandwidth.php session lock.\n";
    }
}
?>
