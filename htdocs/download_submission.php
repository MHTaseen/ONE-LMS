<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php'); exit();
}
require_once 'config.php';

$sub_id = intval($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'assignment'; // 'assignment' or 'quiz'

if ($sub_id < 1) { http_response_code(400); die("Invalid request."); }

try {
    if ($type === 'quiz') {
        $stmt = $pdo->prepare("SELECT solution_file_path as file_path FROM quiz_submissions WHERE id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT file_path, original_filename FROM assignment_submissions WHERE id = ?");
    }
    $stmt->execute([$sub_id]);
    $sub = $stmt->fetch();

    if (!$sub || empty($sub['file_path'])) { http_response_code(404); die("Submission file not found."); }

    $filePath = __DIR__ . '/' . ltrim($sub['file_path'], '/');
    if (!file_exists($filePath)) { http_response_code(404); die("File not found on server."); }

    $filename = $type === 'quiz' ? basename($filePath) : $sub['original_filename'];

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
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
