<?php
// send_message.php — Handles posting a new message (text + optional attachment)
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';
header('Content-Type: application/json');

$sender_db_id  = $_SESSION['user_pk'] ?? null;
if (!$sender_db_id) {
    // fallback lookup
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['error' => 'User not found']); exit(); }
    $sender_db_id = $row['id'];
}

$receiver_id = intval($_POST['receiver_id'] ?? 0);
$section_id  = intval($_POST['section_id']  ?? 0);
$message_text = trim($_POST['message_text'] ?? '');

if ($receiver_id < 1 || $section_id < 1) {
    echo json_encode(['error' => 'Invalid parameters']); exit();
}
if ($message_text === '' && empty($_FILES['attachment'])) {
    echo json_encode(['error' => 'Message cannot be empty']); exit();
}

// Verify sender is either a student enrolled in this section OR the teacher of this section
$role = $_SESSION['role'];
try {
    if ($role === 'student') {
        $stmt = $pdo->prepare("SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?");
        $stmt->execute([$sender_db_id, $section_id]);
        if (!$stmt->fetch()) { echo json_encode(['error' => 'Not enrolled in this section']); exit(); }
    } elseif ($role === 'teacher') {
        $stmt = $pdo->prepare("SELECT cs.id FROM course_sections cs JOIN courses c ON cs.course_id = c.id WHERE cs.id = ? AND c.teacher_id = ?");
        $stmt->execute([$section_id, $sender_db_id]);
        if (!$stmt->fetch()) { echo json_encode(['error' => 'Not teaching this section']); exit(); }
    } else {
        echo json_encode(['error' => 'Guests cannot send messages']); exit();
    }
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]); exit();
}

// Handle file upload
$attach_path = null;
$attach_name = null;
$attach_mime = null;

if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $maxSize = 20 * 1024 * 1024; // 20 MB
    if ($_FILES['attachment']['size'] > $maxSize) {
        echo json_encode(['error' => 'File exceeds 20 MB limit']); exit();
    }

    $allowedMimes = [
        'image/jpeg','image/png','image/gif','image/webp','image/svg+xml',
        'audio/mpeg','audio/ogg','audio/wav','audio/webm','audio/mp4',
        'video/mp4','video/webm','video/ogg','video/quicktime','video/x-msvideo',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($_FILES['attachment']['tmp_name']);
    if (!in_array($detectedMime, $allowedMimes)) {
        echo json_encode(['error' => 'File type not allowed. Only images, audio, and video are permitted.']); exit();
    }

    $uploadDir = __DIR__ . '/uploads/messages/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $dest = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
        echo json_encode(['error' => 'File upload failed']); exit();
    }

    $attach_path = 'uploads/messages/' . $filename;
    $attach_name = $_FILES['attachment']['name'];
    $attach_mime = $detectedMime;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, section_id, message_text, attach_path, attach_name, attach_mime)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$sender_db_id, $receiver_id, $section_id,
        $message_text ?: null, $attach_path, $attach_name, $attach_mime]);
    $msgId = $pdo->lastInsertId();

    // Send Notification
    $msgContent = $_SESSION['full_name'] . " sent you a message.";
    if ($role === 'teacher') {
        $link = "teacher_messages.php?student_id=" . $sender_db_id;
    } else {
        $link = "student_consult.php?teacher_id=" . $receiver_id;
    }
    sendNotification($pdo, $receiver_id, 'dm', $msgContent, $link);

    echo json_encode([
        'success'    => true,
        'id'         => (int)$msgId,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
