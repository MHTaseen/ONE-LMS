<?php
// get_messages.php — Fetch new messages and mark received as read
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']); exit();
}
require_once 'config.php';
header('Content-Type: application/json');

$my_id = $_SESSION['user_pk'] ?? null;
if (!$my_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $my_id = $row['id'] ?? 0;
}

$other_id   = intval($_GET['other_id'] ?? 0);
$section_id = intval($_GET['section_id'] ?? 0);
$after_id   = intval($_GET['after_id'] ?? 0);

if ($other_id < 1 || $section_id < 1) {
    echo json_encode([]); exit();
}

try {
    // 1. Fetch messages exchanged between my_id and other_id for this section_id after after_id
    $stmt = $pdo->prepare("
        SELECT id, sender_id, receiver_id, message_text, attach_path, attach_name, attach_mime, created_at, is_read
        FROM messages
        WHERE section_id = ? 
          AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
          AND id > ?
        ORDER BY id ASC
    ");
    $stmt->execute([$section_id, $my_id, $other_id, $other_id, $my_id, $after_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Mark any unread messages from other_id as read
    $unreadIds = [];
    foreach ($messages as $msg) {
        if ($msg['receiver_id'] == $my_id && $msg['is_read'] == 0) {
            $unreadIds[] = $msg['id'];
        }
    }
    if (!empty($unreadIds)) {
        $in = str_repeat('?,', count($unreadIds) - 1) . '?';
        $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id IN ($in)")->execute($unreadIds);
    }

    echo json_encode($messages);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
