<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['operator_id'])) {
    echo json_encode([]);
    exit;
}

$client_id = $_SESSION['operator_id'];
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($q)) {
    echo json_encode([]);
    exit;
}

$results = [];

// 1. Static Pages & Functions
$pages = [
    ['title' => 'Dashboard / Home', 'url' => 'dashboard.php', 'icon' => 'fa-house'],
    ['title' => 'All Users / Subscribers', 'url' => 'subscribers.php', 'icon' => 'fa-users'],
    ['title' => 'Add New User', 'url' => 'subscribers.php', 'icon' => 'fa-user-plus'],
    ['title' => 'Online Users / Live Sessions', 'url' => 'live_sessions.php', 'icon' => 'fa-wifi'],
    ['title' => 'Packages & Plans', 'url' => 'packages.php', 'icon' => 'fa-box'],
    ['title' => 'Activity Logs', 'url' => 'activity_logs.php', 'icon' => 'fa-list'],
    ['title' => 'MikroTik Settings', 'url' => 'mikrotik_connect.php', 'icon' => 'fa-router'],
    ['title' => 'My Profile', 'url' => 'profile.php', 'icon' => 'fa-user'],
];

foreach ($pages as $page) {
    if (stripos($page['title'], $q) !== false) {
        $results[] = [
            'type' => 'Page / Action',
            'title' => $page['title'],
            'url' => $page['url'],
            'icon' => $page['icon']
        ];
    }
}

// 2. Search Users
$uStmt = $pdo->prepare("SELECT id, username, full_name, phone FROM subscribers WHERE client_id = ? AND (username LIKE ? OR full_name LIKE ? OR phone LIKE ?) LIMIT 5");
$like = "%$q%";
$uStmt->execute([$client_id, $like, $like, $like]);
while ($row = $uStmt->fetch()) {
    $title = htmlspecialchars($row['username']);
    if (!empty($row['full_name'])) $title .= ' (' . htmlspecialchars($row['full_name']) . ')';
    $results[] = [
        'type' => 'Subscriber',
        'title' => $title,
        'url' => "subscriber_view.php?id=" . $row['id'],
        'icon' => 'fa-user-circle'
    ];
}

// 3. Search Packages
$pStmt = $pdo->prepare("SELECT id, name FROM packages WHERE client_id = ? AND name LIKE ? LIMIT 3");
$pStmt->execute([$client_id, $like]);
while ($row = $pStmt->fetch()) {
    $results[] = [
        'type' => 'Package',
        'title' => htmlspecialchars($row['name']),
        'url' => "packages.php", // Highlight or filter in the future
        'icon' => 'fa-box-open'
    ];
}

echo json_encode($results);
