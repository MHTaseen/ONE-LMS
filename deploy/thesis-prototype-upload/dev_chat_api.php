<?php
// dev_chat_api.php - Handles fetching and sending messages between users and developers
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Allowed developers
$validDevelopers = ['SM Shuraim', 'Jannat Zarin', 'Afif Atanu', 'Mahmudul Hassan Taseen'];

if ($method === 'GET') {
    // Fetch chat history for a specific developer
    $developer = isset($_GET['developer']) ? trim($_GET['developer']) : '';
    
    if (!in_array($developer, $validDevelopers)) {
        echo json_encode(['success' => false, 'message' => 'Invalid developer selected']);
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("SELECT message_text, sender_type, created_at FROM developer_messages WHERE user_id = ? AND developer_name = ? ORDER BY created_at ASC");
        $stmt->execute([$userId, $developer]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'messages' => $messages]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} elseif ($method === 'POST') {
    // Send a new message
    $input = json_decode(file_get_contents('php://input'), true);
    $developer = isset($input['developer']) ? trim($input['developer']) : '';
    $messageText = isset($input['message']) ? trim($input['message']) : '';
    
    if (!in_array($developer, $validDevelopers) || empty($messageText)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit();
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO developer_messages (user_id, developer_name, message_text, sender_type) VALUES (?, ?, ?, 'user')");
        $stmt->execute([$userId, $developer, $messageText]);
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}
?>
