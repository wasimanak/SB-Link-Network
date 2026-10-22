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
    ['title' => 'Dashboard / Home', 'url' => 'dashboard', 'icon' => 'fa-house'],
    ['title' => 'All Users / Subscribers', 'url' => 'subscribers', 'icon' => 'fa-users'],
    ['title' => 'Add New User', 'url' => 'subscribers', 'icon' => 'fa-user-plus'],
    ['title' => 'Online Users / Live Sessions', 'url' => 'live_sessions', 'icon' => 'fa-wifi'],
    ['title' => 'Packages & Plans', 'url' => 'packages', 'icon' => 'fa-box'],
    ['title' => 'Activity Logs', 'url' => 'activity_logs', 'icon' => 'fa-list'],
    ['title' => 'MikroTik Settings', 'url' => 'mikrotik_connect', 'icon' => 'fa-router'],
    ['title' => 'My Profile', 'url' => 'profile', 'icon' => 'fa-user'],
];

if (empty($_SESSION['is_manager'])) {
    $pages[] = ['title' => 'Team Managers', 'url' => 'managers', 'icon' => 'fa-user-shield'];
}

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
        'url' => "subscriber_view?id=" . $row['id'],
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
        'url' => "packages", // Highlight or filter in the future
        'icon' => 'fa-box-open'
    ];
}

// 4. Search Managers (Operator Only)
if (empty($_SESSION['is_manager'])) {
    $mStmt = $pdo->prepare("SELECT id, username, full_name FROM managers WHERE client_id = ? AND (username LIKE ? OR full_name LIKE ?) LIMIT 3");
    $mStmt->execute([$client_id, $like, $like]);
    while ($row = $mStmt->fetch()) {
        $results[] = [
            'type' => 'Manager',
            'title' => htmlspecialchars($row['full_name']) . ' (' . htmlspecialchars($row['username']) . ')',
            'url' => "manager_view?id=" . $row['id'],
            'icon' => 'fa-user-shield'
        ];
    }
}

echo json_encode($results);
