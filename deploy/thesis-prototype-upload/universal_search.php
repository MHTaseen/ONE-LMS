<?php
// universal_search.php - Handles live search queries across the platform
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($query) === 0) {
    echo json_encode([]);
    exit();
}

$results = [];
$qLower = strtolower($query);

// 1. Static Features List
$features = [
    ['title' => 'Advising Portal', 'url' => 'advising.php', 'type' => 'Feature'],
    ['title' => 'Student Consult', 'url' => 'student_consult.php', 'type' => 'Feature'],
    ['title' => 'Course Materials', 'url' => 'student_courses.php', 'type' => 'Feature'],
    ['title' => 'Central Communication Media', 'url' => 'communication_media.php', 'type' => 'Feature'],
    ['title' => 'Manage Notifications', 'url' => 'manage_notifications.php', 'type' => 'Feature'],
    ['title' => 'Current Score', 'url' => 'student_scores.php', 'type' => 'Feature'],
    ['title' => 'Attendance', 'url' => 'student_attendance.php', 'type' => 'Feature'],
    ['title' => 'Dashboard', 'url' => 'landing.php', 'type' => 'Feature']
];

foreach ($features as $f) {
    if (strpos(strtolower($f['title']), $qLower) !== false) {
        $results[] = [
            'title' => $f['title'],
            'type'  => $f['type'],
            'url'   => $f['url']
        ];
    }
}

try {
    $searchWildcard = '%' . $query . '%';

    // 2. Search Teachers
    $stmt = $pdo->prepare("SELECT full_name, user_id FROM users WHERE role = 'teacher' AND (full_name LIKE ? OR user_id LIKE ?) LIMIT 5");
    $stmt->execute([$searchWildcard, $searchWildcard]);
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($teachers as $t) {
        $results[] = [
            'title' => $t['full_name'],
            'type'  => 'Teacher',
            'url'   => 'student_consult.php'
        ];
    }

    // 3. Search Courses
    $stmt = $pdo->prepare("SELECT title, code FROM courses WHERE title LIKE ? OR code LIKE ? LIMIT 5");
    $stmt->execute([$searchWildcard, $searchWildcard]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($courses as $c) {
        $results[] = [
            'title' => $c['code'] . ' - ' . $c['title'],
            'type'  => 'Course',
            'url'   => 'advising.php'
        ];
    }

} catch (PDOException $e) {
    // If DB fails, just return whatever we have (features)
}

echo json_encode($results);
?>
