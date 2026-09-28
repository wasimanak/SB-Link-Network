<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. Delete any existing NAS-IP-Address limits just to be clean
$pdo->exec("DELETE FROM radcheck WHERE attribute = 'NAS-IP-Address'");

// 2. Fetch all subscribers and their operator's NAS IP
$subs = $pdo->query("
    SELECT s.username, n.nasname 
    FROM subscribers s
    JOIN nas n ON s.client_id = n.client_id
")->fetchAll();

$count = 0;
$stmt = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'NAS-IP-Address', '==', ?)");

foreach ($subs as $s) {
    if (!empty($s['nasname'])) {
        $stmt->execute([$s['username'], $s['nasname']]);
        $count++;
    }
}

echo "Retroactively restricted $count users to their respective routers.\n";

// 3. Drop existing triggers if any
$pdo->exec("DROP TRIGGER IF EXISTS restrict_nas_ip_insert");
$pdo->exec("DROP TRIGGER IF EXISTS restrict_nas_ip_update");

// 4. Create AFTER INSERT trigger
$pdo->exec("
CREATE TRIGGER restrict_nas_ip_insert AFTER INSERT ON subscribers
FOR EACH ROW
BEGIN
    DECLARE v_nasname VARCHAR(255);
    SELECT nasname INTO v_nasname FROM nas WHERE client_id = NEW.client_id LIMIT 1;
    
    IF v_nasname IS NOT NULL THEN
        INSERT INTO radcheck (username, attribute, op, value) 
        VALUES (NEW.username, 'NAS-IP-Address', '==', v_nasname);
    END IF;
END;
");

// 5. Create AFTER UPDATE trigger (in case username changes)
$pdo->exec("
CREATE TRIGGER restrict_nas_ip_update AFTER UPDATE ON subscribers
FOR EACH ROW
BEGIN
    IF OLD.username != NEW.username THEN
        UPDATE radcheck SET username = NEW.username 
        WHERE username = OLD.username AND attribute = 'NAS-IP-Address';
    END IF;
END;
");

echo "Database triggers successfully created to enforce router isolation automatically.\n";
?>
