<?php
// download_assignment.php – Secure file delivery
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit();
}
require_once 'config.php';

$id = intval($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(400); die("Invalid request."); }

try {
    $stmt = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    $asgn = $stmt->fetch();

    if (!$asgn) { http_response_code(404); die("Assignment not found."); }

    // If student: verify they are enrolled in this section
    if ($_SESSION['role'] === 'student') {
        $stmt2 = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt2->execute([$_SESSION['user_id']]);
        $student_db_id = $stmt2->fetch()['id'];

        $stmt3 = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?");
        $stmt3->execute([$student_db_id, $asgn['section_id']]);
        if (!$stmt3->fetch()) { http_response_code(403); die("Access denied."); }
    }

    $filePath = __DIR__ . '/' . $asgn['file_path'];
    if (!file_exists($filePath)) { http_response_code(404); die("File not found on server."); }

    // Serve the file with its original name
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($asgn['original_filename']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Pragma: no-cache');
    header('Cache-Control: must-revalidate');
    ob_clean(); flush();
    readfile($filePath);
    exit();

} catch (PDOException $e) {
    http_response_code(500);
    die("Server error: " . $e->getMessage());
}
