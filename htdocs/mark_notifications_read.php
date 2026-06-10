<?php
session_start();
if (!isset($_SESSION['user_id'])) exit();
require_once 'config.php';
require_once 'includes/notification_system.php';

$stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$row = $stmt->fetch();
if ($row) {
    markNotificationsRead($pdo, $row['id']);
}
echo "OK";
