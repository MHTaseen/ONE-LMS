<?php
// search_materials.php - Global search for course materials
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$role = $_SESSION['role'];
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'my';
if (strlen($query) === 0) {
    echo json_encode([]);
    exit();
}

$results = [];
$searchWildcard = '%' . $query . '%';

$student_db_id = $_SESSION['user_pk'] ?? 0;
if (!$student_db_id && in_array($role, ['student', 'guest'])) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $student_db_id = $row['id'] ?? 0;
}

try {
    $coursesQuery = "";
    $materialsQuery = "";
    
    // Base course query
    if ($view_mode === 'my') {
        if ($role === 'guest') {
            // Guests have no "my" courses
            echo json_encode([]);
            exit();
        }
        $coursesQuery = "
            SELECT DISTINCT c.id, c.code, c.title, t.full_name as teacher_name
            FROM enrollments e
            JOIN course_sections cs ON e.section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            JOIN users t ON c.teacher_id = t.id
            WHERE e.student_id = ? AND (c.code LIKE ? OR c.title LIKE ?)
            LIMIT 5
        ";
        
        $materialsQuery = "
            SELECT cm.id, cm.title, cm.original_filename, cm.category, cm.file_size, cm.is_private, c.code as course_code
            FROM course_materials cm
            JOIN courses c ON cm.course_id = c.id
            JOIN course_sections cs ON cs.course_id = c.id
            JOIN enrollments e ON e.section_id = cs.id
            WHERE e.student_id = ? AND (cm.title LIKE ? OR cm.original_filename LIKE ?)
            LIMIT 10
        ";
        
        // Courses
        $stmt = $pdo->prepare($coursesQuery);
        $stmt->execute([$student_db_id, $searchWildcard, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $results[] = [
                'type' => 'Course',
                'title' => $c['code'] . ' - ' . $c['title'],
                'meta' => $c['teacher_name'],
                'url' => "?view=my&course_id=" . $c['id']
            ];
        }
        
        // Materials
        $stmt = $pdo->prepare($materialsQuery);
        $stmt->execute([$student_db_id, $searchWildcard, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $results[] = [
                'type' => 'Material',
                'title' => $m['title'],
                'meta' => $m['course_code'] . ' - ' . $m['category'],
                'url' => "view_material.php?id=" . $m['id'],
                'is_locked' => false
            ];
        }

    } else {
        // "all" mode (Public Contents)
        $coursesQuery = "
            SELECT c.id, c.code, c.title, t.full_name as teacher_name
            FROM courses c
            JOIN users t ON c.teacher_id = t.id
            WHERE (c.code LIKE ? OR c.title LIKE ?)
            LIMIT 5
        ";
        
        $materialsQuery = "
            SELECT cm.id, cm.title, cm.original_filename, cm.category, cm.file_size, cm.is_private, c.code as course_code
            FROM course_materials cm
            JOIN courses c ON cm.course_id = c.id
            WHERE (cm.title LIKE ? OR cm.original_filename LIKE ?)
            LIMIT 10
        ";
        
        // Courses
        $stmt = $pdo->prepare($coursesQuery);
        $stmt->execute([$searchWildcard, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $results[] = [
                'type' => 'Course',
                'title' => $c['code'] . ' - ' . $c['title'],
                'meta' => $c['teacher_name'],
                'url' => "?view=all&course_id=" . $c['id']
            ];
        }
        
        // Materials
        $stmt = $pdo->prepare($materialsQuery);
        $stmt->execute([$searchWildcard, $searchWildcard]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $is_locked = ($role === 'guest' && $m['is_private']);
            $results[] = [
                'type' => 'Material',
                'title' => $m['title'],
                'meta' => $m['course_code'] . ' - ' . $m['category'],
                'url' => $is_locked ? "#" : "view_material.php?id=" . $m['id'],
                'is_locked' => $is_locked
            ];
        }
    }

} catch (PDOException $e) {
    // Error
}

echo json_encode($results);
?>
