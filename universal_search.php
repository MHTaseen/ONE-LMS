<?php
// universal_search.php - Handles live search queries across the platform
session_start();
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter = strtolower(trim($_GET['filter'] ?? 'all'));
$allowedFilters = ['all', 'course', 'teacher', 'feature', 'notifications', 'quiz', 'assignment', 'grades'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

if (strlen($query) === 0) {
    echo json_encode([]);
    exit();
}

$role = $_SESSION['role'] ?? '';
$userPk = $_SESSION['user_pk'] ?? 0;
$studentDbId = $userPk;
$results = [];
$qLower = strtolower($query);

function addSearchResult(array &$results, string $title, string $type, string $url, string $meta = ''): void
{
    $results[] = [
        'title' => $title,
        'type'  => $type,
        'url'   => $url,
        'meta'  => $meta
    ];
}

function allowsFilter(string $activeFilter, string $target): bool
{
    return $activeFilter === 'all' || $activeFilter === $target;
}

if (!$studentDbId && in_array($role, ['student', 'guest'], true)) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $studentDbId = $row['id'] ?? 0;
}

$features = [
    ['title' => 'Advising Portal', 'url' => 'advising.php', 'type' => 'Feature', 'meta' => 'Enrollment planning and section selection'],
    ['title' => 'Student Consult', 'url' => 'student_consult.php', 'type' => 'Feature', 'meta' => 'Talk with teachers'],
    ['title' => 'Course Materials', 'url' => 'student_materials.php', 'type' => 'Feature', 'meta' => 'Browse lecture files and resources'],
    ['title' => 'Central Communication Media', 'url' => 'communication_media.php', 'type' => 'Feature', 'meta' => 'Messages and communication'],
    ['title' => 'Manage Notifications', 'url' => 'manage_notifications.php', 'type' => 'Feature', 'meta' => 'View and manage alerts'],
    ['title' => 'Current Score', 'url' => 'student_scores.php', 'type' => 'Feature', 'meta' => 'See current marks'],
    ['title' => 'Attendance', 'url' => 'student_attendance.php', 'type' => 'Feature', 'meta' => 'Track attendance records'],
    ['title' => 'Dashboard', 'url' => 'landing.php', 'type' => 'Feature', 'meta' => 'Main landing page']
];

if ($role === 'teacher') {
    $features[] = ['title' => 'Submit Current Score', 'url' => 'teacher_grading.php', 'type' => 'Feature', 'meta' => 'Teacher grading and score management'];
    $features[] = ['title' => 'Deploy Quiz', 'url' => 'deploy_quiz.php', 'type' => 'Feature', 'meta' => 'Create and schedule quizzes'];
    $features[] = ['title' => 'Deploy Assignments', 'url' => 'deploy_assignment.php', 'type' => 'Feature', 'meta' => 'Create assignment tasks'];
}

if (allowsFilter($filter, 'feature')) {
    foreach ($features as $feature) {
        if (strpos(strtolower($feature['title']), $qLower) !== false) {
            addSearchResult($results, $feature['title'], $feature['type'], $feature['url'], $feature['meta']);
        }
    }
}

try {
    $searchWildcard = '%' . $query . '%';

    if (allowsFilter($filter, 'teacher')) {
        $stmt = $pdo->prepare("SELECT full_name, user_id FROM users WHERE role = 'teacher' AND (full_name LIKE ? OR user_id LIKE ?) LIMIT 8");
        $stmt->execute([$searchWildcard, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $teacher) {
            addSearchResult($results, $teacher['full_name'], 'Teacher', 'student_consult.php', $teacher['user_id']);
        }
    }

    if (allowsFilter($filter, 'course')) {
        $stmt = $pdo->prepare("SELECT title, code FROM courses WHERE title LIKE ? OR code LIKE ? LIMIT 8");
        $stmt->execute([$searchWildcard, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $course) {
            addSearchResult($results, $course['code'] . ' - ' . $course['title'], 'Course', 'advising.php', 'Course catalog result');
        }
    }

    if (allowsFilter($filter, 'notifications') && $userPk) {
        $stmt = $pdo->prepare("
            SELECT message, link_url, created_at
            FROM notifications
            WHERE user_id = ? AND message LIKE ?
            ORDER BY created_at DESC
            LIMIT 8
        ");
        $stmt->execute([$userPk, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $notification) {
            $meta = date('M j, g:i a', strtotime($notification['created_at']));
            addSearchResult(
                $results,
                $notification['message'],
                'Notification',
                $notification['link_url'] ?: 'manage_notifications.php',
                $meta
            );
        }
    }

    if (allowsFilter($filter, 'quiz')) {
        if ($role === 'teacher') {
            $stmt = $pdo->prepare("
                SELECT q.id, q.quiz_name, c.code, cs.section_no
                FROM quizzes q
                JOIN course_sections cs ON q.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE c.teacher_id = ? AND (q.quiz_name LIKE ? OR c.code LIKE ? OR c.title LIKE ?)
                ORDER BY q.start_time DESC
                LIMIT 8
            ");
            $stmt->execute([$userPk, $searchWildcard, $searchWildcard, $searchWildcard]);
        } elseif ($role === 'student' && $studentDbId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT q.id, q.quiz_name, c.code, cs.section_no
                FROM quizzes q
                JOIN course_sections cs ON q.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                JOIN enrollments e ON e.section_id = cs.id
                WHERE e.student_id = ? AND (q.quiz_name LIKE ? OR c.code LIKE ? OR c.title LIKE ?)
                ORDER BY q.start_time DESC
                LIMIT 8
            ");
            $stmt->execute([$studentDbId, $searchWildcard, $searchWildcard, $searchWildcard]);
        } else {
            $stmt = $pdo->prepare("
                SELECT q.id, q.quiz_name, c.code, cs.section_no
                FROM quizzes q
                JOIN course_sections cs ON q.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE q.quiz_name LIKE ? OR c.code LIKE ? OR c.title LIKE ?
                ORDER BY q.start_time DESC
                LIMIT 8
            ");
            $stmt->execute([$searchWildcard, $searchWildcard, $searchWildcard]);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $quiz) {
            addSearchResult($results, $quiz['quiz_name'], 'Quiz', 'student_quiz.php', $quiz['code'] . ' • Sec ' . str_pad($quiz['section_no'], 2, '0', STR_PAD_LEFT));
        }
    }

    if (allowsFilter($filter, 'assignment')) {
        if ($role === 'teacher') {
            $stmt = $pdo->prepare("
                SELECT a.assignment_name, c.code, cs.section_no
                FROM assignments a
                JOIN course_sections cs ON a.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE c.teacher_id = ? AND (a.assignment_name LIKE ? OR c.code LIKE ? OR c.title LIKE ?)
                ORDER BY a.deadline DESC
                LIMIT 8
            ");
            $stmt->execute([$userPk, $searchWildcard, $searchWildcard, $searchWildcard]);
        } elseif ($role === 'student' && $studentDbId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT a.assignment_name, c.code, cs.section_no
                FROM assignments a
                JOIN course_sections cs ON a.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                JOIN enrollments e ON e.section_id = cs.id
                WHERE e.student_id = ? AND (a.assignment_name LIKE ? OR c.code LIKE ? OR c.title LIKE ?)
                ORDER BY a.deadline DESC
                LIMIT 8
            ");
            $stmt->execute([$studentDbId, $searchWildcard, $searchWildcard, $searchWildcard]);
        } else {
            $stmt = $pdo->prepare("
                SELECT a.assignment_name, c.code, cs.section_no
                FROM assignments a
                JOIN course_sections cs ON a.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE a.assignment_name LIKE ? OR c.code LIKE ? OR c.title LIKE ?
                ORDER BY a.deadline DESC
                LIMIT 8
            ");
            $stmt->execute([$searchWildcard, $searchWildcard, $searchWildcard]);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
            addSearchResult($results, $assignment['assignment_name'], 'Assignment', 'student_assignments.php', $assignment['code'] . ' • Sec ' . str_pad($assignment['section_no'], 2, '0', STR_PAD_LEFT));
        }
    }

    if (allowsFilter($filter, 'grades')) {
        if ($role === 'teacher') {
            $stmt = $pdo->prepare("
                SELECT cs.id AS section_id, c.code, c.title, cs.section_no
                FROM course_sections cs
                JOIN courses c ON cs.course_id = c.id
                WHERE c.teacher_id = ? AND (c.code LIKE ? OR c.title LIKE ?)
                LIMIT 8
            ");
            $stmt->execute([$userPk, $searchWildcard, $searchWildcard]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $gradeItem) {
                addSearchResult(
                    $results,
                    $gradeItem['code'] . ' - ' . $gradeItem['title'],
                    'Grade',
                    'teacher_grading.php?section_id=' . $gradeItem['section_id'],
                    'Section ' . str_pad($gradeItem['section_no'], 2, '0', STR_PAD_LEFT)
                );
            }
        } elseif ($role === 'student' && $studentDbId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.code, c.title, e.score_total, e.score_published
                FROM enrollments e
                JOIN course_sections cs ON e.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE e.student_id = ? AND (c.code LIKE ? OR c.title LIKE ?)
                LIMIT 8
            ");
            $stmt->execute([$studentDbId, $searchWildcard, $searchWildcard]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $gradeItem) {
                $meta = !empty($gradeItem['score_published']) && $gradeItem['score_total'] !== null
                    ? 'Published score: ' . intval($gradeItem['score_total'])
                    : 'Grade sheet entry';
                addSearchResult($results, $gradeItem['code'] . ' - ' . $gradeItem['title'], 'Grade', 'grade_sheet.php', $meta);
            }
        }
    }
} catch (PDOException $e) {
    // Fail softly and return any results collected so far.
}

echo json_encode($results);
?>
