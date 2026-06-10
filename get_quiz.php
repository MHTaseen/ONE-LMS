<?php
// get_quiz.php – Returns quiz + questions as JSON (students only)
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['error' => 'Unauthorized']); exit();
}
require_once 'config.php';
header('Content-Type: application/json');

$quiz_id = intval($_GET['id'] ?? 0);
if ($quiz_id < 1) { echo json_encode(['error' => 'Invalid quiz ID.']); exit(); }

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Verify student is enrolled in this quiz's section
    $stmt = $pdo->prepare("SELECT q.*, cs.section_id as section_id FROM quizzes q JOIN course_sections cs ON q.section_id = cs.id WHERE q.id = ?");
    $stmt->execute([$quiz_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) { echo json_encode(['error' => 'Quiz not found.']); exit(); }

    $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?");
    $stmt->execute([$student_db_id, $quiz['section_id']]);
    if (!$stmt->fetch()) { echo json_encode(['error' => 'Not enrolled in this course.']); exit(); }

    // Check not already submitted
    $stmt = $pdo->prepare("SELECT id FROM quiz_submissions WHERE quiz_id = ? AND student_id = ?");
    $stmt->execute([$quiz_id, $student_db_id]);
    if ($stmt->fetch()) { echo json_encode(['error' => 'Quiz already submitted.']); exit(); }

    // Get questions (do NOT send correct_option to client)
    $stmt = $pdo->prepare("SELECT id, question_text, option_a, option_b, option_c, option_d FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
    $stmt->execute([$quiz_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'quiz_name'   => $quiz['quiz_name'],
        'quiz_number' => $quiz['quiz_number'],
        'time_limit'  => $quiz['time_limit'],
        'questions'   => $questions,
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
