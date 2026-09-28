<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. Delete existing Unlimited
$pdo->exec("DELETE FROM radreply WHERE attribute='Mikrotik-Rate-Limit' AND value LIKE '%Unlimited%'");
$pdo->exec("DELETE FROM radgroupreply WHERE attribute='Mikrotik-Rate-Limit' AND value LIKE '%Unlimited%'");

// 2. Drop triggers if they exist
$pdo->exec("DROP TRIGGER IF EXISTS prevent_unlimited_radreply_insert");
$pdo->exec("DROP TRIGGER IF EXISTS prevent_unlimited_radreply_update");
$pdo->exec("DROP TRIGGER IF EXISTS prevent_unlimited_radgroupreply_insert");
$pdo->exec("DROP TRIGGER IF EXISTS prevent_unlimited_radgroupreply_update");

// 3. Create Trigger for radreply INSERT
$pdo->exec("
CREATE TRIGGER prevent_unlimited_radreply_insert BEFORE INSERT ON radreply
FOR EACH ROW
BEGIN
    IF NEW.attribute = 'Mikrotik-Rate-Limit' AND LOWER(NEW.value) LIKE '%unlimited%' THEN
        SET NEW.attribute = 'Reply-Message';
    END IF;
END;
");

// 4. Create Trigger for radreply UPDATE
$pdo->exec("
CREATE TRIGGER prevent_unlimited_radreply_update BEFORE UPDATE ON radreply
FOR EACH ROW
BEGIN
    IF NEW.attribute = 'Mikrotik-Rate-Limit' AND LOWER(NEW.value) LIKE '%unlimited%' THEN
        SET NEW.attribute = 'Reply-Message';
    END IF;
END;
");

// 5. Create Trigger for radgroupreply INSERT
$pdo->exec("
CREATE TRIGGER prevent_unlimited_radgroupreply_insert BEFORE INSERT ON radgroupreply
FOR EACH ROW
BEGIN
    IF NEW.attribute = 'Mikrotik-Rate-Limit' AND LOWER(NEW.value) LIKE '%unlimited%' THEN
        SET NEW.attribute = 'Reply-Message';
    END IF;
END;
");

// 6. Create Trigger for radgroupreply UPDATE
$pdo->exec("
CREATE TRIGGER prevent_unlimited_radgroupreply_update BEFORE UPDATE ON radgroupreply
FOR EACH ROW
BEGIN
    IF NEW.attribute = 'Mikrotik-Rate-Limit' AND LOWER(NEW.value) LIKE '%unlimited%' THEN
        SET NEW.attribute = 'Reply-Message';
    END IF;
END;
");

echo "Database triggers successfully added to permanently prevent 'Unlimited' rate limit bugs!\n";
?>
