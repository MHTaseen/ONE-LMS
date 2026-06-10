<?php
// message_file.php — Securely serve message attachments
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}
require_once 'config.php';

$msg_id = intval($_GET['id'] ?? 0);
if ($msg_id < 1) die("Invalid ID");

$my_id = $_SESSION['user_pk'] ?? null;
if (!$my_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $my_id = $row['id'] ?? 0;
}

try {
    $stmt = $pdo->prepare("SELECT sender_id, receiver_id, attach_path, attach_name, attach_mime FROM messages WHERE id = ?");
    $stmt->execute([$msg_id]);
    $msg = $stmt->fetch();

    if (!$msg) die("File not found");
    // Ensure the user requesting the file is part of the conversation
    if ($msg['sender_id'] != $my_id && $msg['receiver_id'] != $my_id) {
        die("Forbidden");
    }

    $filepath = __DIR__ . '/' . $msg['attach_path'];
    if (!file_exists($filepath)) {
        die("File is missing from disk");
    }

    header('Content-Type: ' . $msg['attach_mime']);
    header('Content-Disposition: inline; filename="' . basename($msg['attach_name']) . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: private, max-age=86400'); // Cache for 1 day
    
    // Output the file
    readfile($filepath);
} catch (PDOException $e) {
    die("Database error");
}
