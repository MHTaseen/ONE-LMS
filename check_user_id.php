<?php
// check_user_id.php – AJAX endpoint: check if a user_id or email already exists
// Used by authority_dashboard.php for live validation
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'authority') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
require_once 'config.php';
header('Content-Type: application/json');

$field = $_GET['field'] ?? '';
$value = trim($_GET['value'] ?? '');

if (empty($value) || !in_array($field, ['user_id', 'email'])) {
    echo json_encode(['exists' => false]);
    exit();
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE $field = ? LIMIT 1");
$stmt->execute([$value]);
$exists = (bool) $stmt->fetch();
echo json_encode(['exists' => $exists]);
