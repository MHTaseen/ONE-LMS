<?php
// submit_assignment.php – Handle student assignment file submission
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';

header('Content-Type: application/json');

$assignment_id = intval($_POST['assignment_id'] ?? 0);

if ($assignment_id < 1 || empty($_FILES['submission_file']['name']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Invalid submission.']); exit();
}

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Verify student is enrolled in the course section this assignment belongs to
    $stmt = $pdo->prepare("SELECT a.section_id FROM assignments a WHERE a.id = ?");
    $stmt->execute([$assignment_id]);
    $asgn = $stmt->fetch();
    if (!$asgn) { echo json_encode(['error' => 'Assignment not found.']); exit(); }

    $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?");
    $stmt->execute([$student_db_id, $asgn['section_id']]);
    if (!$stmt->fetch()) { echo json_encode(['error' => 'Not enrolled.']); exit(); }

    // Check if already submitted
    $stmt = $pdo->prepare("SELECT id FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
    $stmt->execute([$assignment_id, $student_db_id]);
    if ($stmt->fetch()) { echo json_encode(['error' => 'Already submitted.']); exit(); }

    $originalName = basename($_FILES['submission_file']['name']);
    $storedName   = time() . '_sub_' . $student_db_id . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $uploadDir    = __DIR__ . '/uploads/submissions/';
    $filePath     = $uploadDir . $storedName;

    if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $filePath)) {
        $stmt = $pdo->prepare("INSERT INTO assignment_submissions (assignment_id, student_id, original_filename, file_path) VALUES (?,?,?,?)");
        $stmt->execute([$assignment_id, $student_db_id, $originalName, 'uploads/submissions/' . $storedName]);
        
        // Notify Teacher
        $stmt = $pdo->prepare("SELECT c.teacher_id, c.code, a.assignment_name FROM assignments a JOIN course_sections cs ON a.section_id = cs.id JOIN courses c ON cs.course_id = c.id WHERE a.id = ?");
        $stmt->execute([$assignment_id]);
        $info = $stmt->fetch();
        if ($info) {
            $msg = $_SESSION['full_name'] . " submitted {$info['assignment_name']} for {$info['code']}";
            sendNotification($pdo, $info['teacher_id'], 'deployment', $msg, 'teacher_grading.php');
        }

        echo json_encode(['success' => true, 'filename' => $originalName]);
    } else {
        echo json_encode(['error' => 'File upload failed.']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
}
