<?php
// toggle_advising.php - Teacher endpoint to toggle advising portal open/close
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get raw JSON payload
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (isset($data['advising_open'])) {
        $newState = $data['advising_open'] ? '1' : '0';
        try {
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'advising_open'");
            $stmt->execute([$newState]);
            echo json_encode(['success' => true, 'advising_open' => ($newState === '1')]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing advising_open state in request.']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
}
?>
