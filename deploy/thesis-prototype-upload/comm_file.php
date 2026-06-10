<?php
// comm_file.php — Securely serve course communication attachments
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}
require_once 'config.php';

$msg_id = intval($_GET['id'] ?? 0);
if ($msg_id < 1) {
    die("Invalid ID");
}

$user_db_id = $_SESSION['user_pk'] ?? 0;
if (!$user_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $user_db_id = $row['id'] ?? 0;
}

try {
    $stmt = $pdo->prepare("
        SELECT cc.file_path, cc.file_name, cc.file_mime, cc.section_id
        FROM course_communications cc
        WHERE cc.id = ? AND cc.file_path IS NOT NULL
    ");
    $stmt->execute([$msg_id]);
    $msg = $stmt->fetch();

    if (!$msg) {
        die("File not found");
    }

    $role = $_SESSION['role'];
    $allowed = false;
    if ($role === 'student') {
        $chk = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?");
        $chk->execute([$user_db_id, $msg['section_id']]);
        $allowed = (bool) $chk->fetch();
    } elseif ($role === 'teacher') {
        $chk = $pdo->prepare("
            SELECT cs.id FROM course_sections cs
            JOIN courses c ON cs.course_id = c.id
            WHERE cs.id = ? AND c.teacher_id = ?
        ");
        $chk->execute([$msg['section_id'], $user_db_id]);
        $allowed = (bool) $chk->fetch();
    }

    if (!$allowed) {
        die("Forbidden");
    }

    $filepath = __DIR__ . '/' . $msg['file_path'];
    if (!file_exists($filepath)) {
        die("File is missing from disk");
    }

    $mime = $msg['file_mime'] ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($msg['file_name']) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=86400');
    readfile($filepath);
} catch (PDOException $e) {
    die("Database error");
}
