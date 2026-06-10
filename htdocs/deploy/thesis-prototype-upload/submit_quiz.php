<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['error' => 'Unauthorized']); exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';
header('Content-Type: application/json');

$quiz_id = intval($_POST['quiz_id'] ?? 0);
if ($quiz_id < 1) { echo json_encode(['error' => 'Invalid quiz ID']); exit(); }

try {
    // Get student DB id
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['error' => 'Student not found']); exit(); }
    $student_db_id = $row['id'];

    // Check time window and quiz type
    $stmt = $pdo->prepare("SELECT quiz_type, start_time, end_time, total_marks FROM quizzes WHERE id = ?");
    $stmt->execute([$quiz_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) { echo json_encode(['error' => 'Quiz not found']); exit(); }

    $now = new DateTime();
    $start = new DateTime($quiz['start_time']);
    $end = new DateTime($quiz['end_time']);
    if ($now < $start || $now > $end) {
        echo json_encode(['error' => 'Quiz is not active at this time']); exit();
    }

    // Already submitted?
    $stmt = $pdo->prepare("SELECT id FROM quiz_submissions WHERE quiz_id = ? AND student_id = ?");
    $stmt->execute([$quiz_id, $student_db_id]);
    if ($stmt->fetch()) { echo json_encode(['error' => 'Quiz already submitted']); exit(); }

    if ($quiz['quiz_type'] === 'file') {
        if (!isset($_FILES['solution_file']) || $_FILES['solution_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'Missing solution file']); exit();
        }
        $filename = time() . '_' . basename($_FILES['solution_file']['name']);
        $target_dir = 'uploads/quiz_submissions/';
        $solution_file_path = $target_dir . $filename;
        if (!move_uploaded_file($_FILES['solution_file']['tmp_name'], $solution_file_path)) {
            echo json_encode(['error' => 'Failed to upload solution file']); exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO quiz_submissions (quiz_id, student_id, solution_file_path, total, submitted_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$quiz_id, $student_db_id, $solution_file_path, $quiz['total_marks']]);
        $score = null;
        $total = $quiz['total_marks'];

    } else {
        // Collect submitted answers: q{question_id} => A/B/C/D
        $answers = [];
        foreach ($_POST as $key => $value) {
            if (preg_match('/^q(\d+)$/', $key, $m)) {
                $answers[intval($m[1])] = strtoupper(trim($value));
            }
        }
        if (empty($answers)) { echo json_encode(['error' => 'No answers submitted']); exit(); }

        // Load correct answers from DB
        $stmt = $pdo->prepare("SELECT id, correct_option FROM quiz_questions WHERE quiz_id = ?");
        $stmt->execute([$quiz_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($questions)) { echo json_encode(['error' => 'Quiz has no questions']); exit(); }

        // Calculate score
        $score = 0;
        $total = count($questions);
        foreach ($questions as $q) {
            $qid = $q['id'];
            if (isset($answers[$qid]) && $answers[$qid] === strtoupper($q['correct_option'])) {
                $score++;
            }
        }

        $answers_json = json_encode($answers);

        // Save submission with score
        $stmt = $pdo->prepare("
            INSERT INTO quiz_submissions (quiz_id, student_id, answers_json, score, total, submitted_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$quiz_id, $student_db_id, $answers_json, $score, $total]);
    }

    // Notify Teacher
    $stmt = $pdo->prepare("SELECT c.teacher_id, c.code, q.quiz_name FROM quizzes q JOIN course_sections cs ON q.section_id = cs.id JOIN courses c ON cs.course_id = c.id WHERE q.id = ?");
    $stmt->execute([$quiz_id]);
    $info = $stmt->fetch();
    if ($info) {
        $msg = $_SESSION['full_name'] . " submitted {$info['quiz_name']} for {$info['code']}";
        sendNotification($pdo, $info['teacher_id'], 'deployment', $msg, 'teacher_grading.php');
    }

    echo json_encode(['success' => true, 'score' => $score, 'total' => $total]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
