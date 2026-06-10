<?php
// includes/notification_system.php - Core functions for triggering notifications
require_once __DIR__ . '/../config.php';

/**
 * Sends a notification to a specific user, respecting their preferences.
 *
 * @param PDO $pdo The database connection
 * @param int $user_id The recipient's user ID
 * @param string $type The notification type ('announcement', 'theory', 'lab', 'dm', 'material', 'grade', 'deployment')
 * @param string $message The notification text
 * @param string $link_url The URL to redirect to when clicked
 */
function sendNotification($pdo, $user_id, $type, $message, $link_url) {
    // 1. Fetch preferences
    $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $prefs = $stmt->fetch();
    
    // Default to false if no prefs exist
    $mute_announcements = $prefs['mute_announcements'] ?? 0;
    $mute_theory        = $prefs['mute_theory'] ?? 0;
    $mute_lab           = $prefs['mute_lab'] ?? 0;
    $mute_dms           = $prefs['mute_dms'] ?? 0;

    // 2. Check mutes
    if ($type === 'announcement' && $mute_announcements) return;
    if ($type === 'theory' && $mute_theory) return;
    if ($type === 'lab' && $mute_lab) return;
    if ($type === 'dm' && $mute_dms) return;

    // 3. Insert Notification
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link_url) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $type, $message, $link_url]);
}

function markNotificationsRead($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND deleted_from_bell = 0");
    $stmt->execute([$user_id]);
}

/**
 * Gets unread count for UI badge.
 */
function getUnreadCount($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND deleted_from_bell = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}
?>
