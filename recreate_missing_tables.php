<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. dealers
$pdo->exec("CREATE TABLE IF NOT EXISTS dealers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    full_name VARCHAR(150),
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    balance DECIMAL(10,2) DEFAULT 0.00,
    perm_create_user TINYINT(1) DEFAULT 1,
    perm_delete_user TINYINT(1) DEFAULT 0,
    perm_custom_expiry TINYINT(1) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 2. dealer_notes (ledger)
$pdo->exec("CREATE TABLE IF NOT EXISTS dealer_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 3. linemen
$pdo->exec("CREATE TABLE IF NOT EXISTS linemen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150),
    phone VARCHAR(20),
    address VARCHAR(255),
    city VARCHAR(100),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 4. recovery_men
$pdo->exec("CREATE TABLE IF NOT EXISTS recovery_men (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150),
    phone VARCHAR(20),
    address VARCHAR(255),
    city VARCHAR(100),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 5. user_ledger
$pdo->exec("CREATE TABLE IF NOT EXISTS user_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    username VARCHAR(100),
    type ENUM('credit','debit'),
    amount DECIMAL(10,2),
    balance_after DECIMAL(10,2),
    description VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 6. fund_requests
$pdo->exec("CREATE TABLE IF NOT EXISTS fund_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    subscriber_id INT NULL,
    dealer_id INT NULL,
    amount DECIMAL(10,2) NOT NULL,
    method VARCHAR(50) NOT NULL,
    receipt_path VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Add missing columns to activity_logs
try { $pdo->exec("ALTER TABLE activity_logs ADD COLUMN dealer_id INT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE activity_logs ADD COLUMN by_role VARCHAR(50) DEFAULT 'Admin'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE activity_logs ADD COLUMN against_role VARCHAR(50) DEFAULT 'User'"); } catch(Exception $e){}

// Add missing columns to subscribers
try { $pdo->exec("ALTER TABLE subscribers ADD COLUMN dealer_id INT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE subscribers ADD COLUMN lineman_id INT DEFAULT NULL"); } catch(Exception $e){}

echo "Missing tables recreated!\n";
?>
