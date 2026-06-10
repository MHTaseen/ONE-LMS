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
$allowedFilters = ['messages', 'materials', 'routine', 'features'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'features';
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
    return $activeFilter === $target;
}

function shortenText(?string $text, int $limit = 72): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 3) . '...' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
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

if (allowsFilter($filter, 'features')) {
    foreach ($features as $feature) {
        if (
            strpos(strtolower($feature['title']), $qLower) !== false ||
            strpos(strtolower($feature['meta']), $qLower) !== false
        ) {
            addSearchResult($results, $feature['title'], $feature['type'], $feature['url'], $feature['meta']);
        }
    }
}

try {
    $searchWildcard = '%' . $query . '%';

    if (allowsFilter($filter, 'messages') && $userPk) {
        $directMessageUrl = $role === 'teacher' ? 'teacher_messages.php' : 'student_consult.php';

        $stmt = $pdo->prepare("
            SELECT
                m.message_text,
                c.code,
                COALESCE(other_user.full_name, 'Direct Message') AS other_name
            FROM messages m
            LEFT JOIN course_sections cs ON m.section_id = cs.id
            LEFT JOIN courses c ON cs.course_id = c.id
            LEFT JOIN users other_user ON other_user.id = CASE
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END
            WHERE (m.sender_id = ? OR m.receiver_id = ?)
              AND m.message_text LIKE ?
            ORDER BY m.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userPk, $userPk, $userPk, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
            $title = shortenText($message['message_text']);
            if ($title === '') {
                continue;
            }
            $metaParts = [];
            if (!empty($message['code'])) {
                $metaParts[] = $message['code'];
            }
            if (!empty($message['other_name'])) {
                $metaParts[] = 'Direct message with ' . $message['other_name'];
            }
            addSearchResult($results, $title, 'Message', $directMessageUrl, implode(' • ', $metaParts));
        }

        if ($role === 'teacher') {
            $stmt = $pdo->prepare("
                SELECT cc.message, cc.channel, cs.id AS section_id, c.code
                FROM course_communications cc
                JOIN course_sections cs ON cc.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE c.teacher_id = ?
                  AND cc.message LIKE ?
                ORDER BY cc.created_at DESC
                LIMIT 6
            ");
            $stmt->execute([$userPk, $searchWildcard]);
        } elseif ($role === 'student' && $studentDbId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT cc.message, cc.channel, cs.id AS section_id, c.code
                FROM course_communications cc
                JOIN course_sections cs ON cc.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                JOIN enrollments e ON e.section_id = cs.id
                WHERE e.student_id = ?
                  AND cc.message LIKE ?
                ORDER BY cc.created_at DESC
                LIMIT 6
            ");
            $stmt->execute([$studentDbId, $searchWildcard]);
        } else {
            $stmt = null;
        }

        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
                $title = shortenText($message['message']);
                if ($title === '') {
                    continue;
                }
                addSearchResult(
                    $results,
                    $title,
                    'Message',
                    'communication_media.php?section_id=' . intval($message['section_id']) . '&channel=' . urlencode($message['channel']),
                    implode(' • ', array_filter([$message['code'] ?? '', ucfirst($message['channel'])]))
                );
            }
        }
    }

    if (allowsFilter($filter, 'materials')) {
        if ($role === 'student' && $studentDbId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT cm.id, cm.title, cm.original_filename, cm.category, cm.is_private, c.code, c.title AS course_title
                FROM course_materials cm
                JOIN courses c ON cm.course_id = c.id
                JOIN course_sections cs ON cs.course_id = c.id
                JOIN enrollments e ON e.section_id = cs.id
                WHERE e.student_id = ?
                  AND (
                    cm.title LIKE ?
                    OR cm.original_filename LIKE ?
                    OR cm.category LIKE ?
                    OR c.code LIKE ?
                    OR c.title LIKE ?
                  )
                ORDER BY cm.uploaded_at DESC
                LIMIT 8
            ");
            $stmt->execute([$studentDbId, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
        } else {
            $stmt = $pdo->prepare("
                SELECT cm.id, cm.title, cm.original_filename, cm.category, cm.is_private, c.code, c.title AS course_title
                FROM course_materials cm
                JOIN courses c ON cm.course_id = c.id
                WHERE
                    cm.title LIKE ?
                    OR cm.original_filename LIKE ?
                    OR cm.category LIKE ?
                    OR c.code LIKE ?
                    OR c.title LIKE ?
                ORDER BY cm.uploaded_at DESC
                LIMIT 8
            ");
            $stmt->execute([$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $material) {
            $title = trim($material['title']) !== '' ? $material['title'] : $material['original_filename'];
            addSearchResult(
                $results,
                $title,
                'Course Material',
                'view_material.php?id=' . intval($material['id']),
                implode(' • ', array_filter([$material['code'] ?? '', $material['category'] ?? 'Material']))
            );
        }
    }

    if (allowsFilter($filter, 'routine')) {
        if ($role === 'student' && $studentDbId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    c.code,
                    c.title,
                    cs.section_no,
                    cs.room_no,
                    cs.theory_day_1,
                    cs.theory_day_2,
                    cs.theory_time_slot,
                    cs.lab_day,
                    cs.lab_time_slot
                FROM enrollments e
                JOIN course_sections cs ON e.section_id = cs.id
                JOIN courses c ON cs.course_id = c.id
                WHERE e.student_id = ?
                  AND (
                    c.code LIKE ?
                    OR c.title LIKE ?
                    OR cs.room_no LIKE ?
                    OR cs.theory_day_1 LIKE ?
                    OR cs.theory_day_2 LIKE ?
                    OR cs.lab_day LIKE ?
                    OR cs.theory_time_slot LIKE ?
                    OR cs.lab_time_slot LIKE ?
                  )
                LIMIT 8
            ");
            $stmt->execute([
                $studentDbId,
                $searchWildcard,
                $searchWildcard,
                $searchWildcard,
                $searchWildcard,
                $searchWildcard,
                $searchWildcard,
                $searchWildcard,
                $searchWildcard
            ]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $routine) {
                $metaParts = [];
                if (!empty($routine['theory_day_1']) && !empty($routine['theory_time_slot'])) {
                    $metaParts[] = $routine['theory_day_1'] . ' ' . $routine['theory_time_slot'];
                }
                if (!empty($routine['theory_day_2']) && !empty($routine['theory_time_slot']) && $routine['theory_day_2'] !== $routine['theory_day_1']) {
                    $metaParts[] = $routine['theory_day_2'] . ' ' . $routine['theory_time_slot'];
                }
                if (!empty($routine['lab_day']) && !empty($routine['lab_time_slot'])) {
                    $metaParts[] = 'Lab: ' . $routine['lab_day'] . ' ' . $routine['lab_time_slot'];
                }
                if (!empty($routine['room_no'])) {
                    $metaParts[] = 'Room ' . $routine['room_no'];
                }

                addSearchResult(
                    $results,
                    $routine['code'] . ' - ' . $routine['title'],
                    'Routine',
                    'routine.php',
                    implode(' • ', $metaParts)
                );
            }
        } else {
            if (preg_match('/routine|schedule|class|timetable/i', $query)) {
                addSearchResult($results, 'Routine', 'Feature', 'routine.php', 'Open the weekly class routine page');
            }
        }
    }
} catch (PDOException $e) {
    // Fail softly and return any results collected so far.
}

echo json_encode($results);
?>
