<?php
session_start();
if (!isset($_SESSION['user_pk'])) exit();
require_once 'config.php';

$id = intval($_GET['id'] ?? 0);
$source = $_GET['source'] ?? 'bell';

if ($id > 0) {
    if ($source === 'dashboard') {
        $stmt = $pdo->prepare("UPDATE notifications SET deleted_from_dashboard = 1 WHERE id = ? AND user_id = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE notifications SET deleted_from_bell = 1 WHERE id = ? AND user_id = ?");
    }
    $stmt->execute([$id, $_SESSION['user_pk']]);
}
echo "OK";
