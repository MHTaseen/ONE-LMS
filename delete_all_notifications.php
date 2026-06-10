<?php
session_start();
if (!isset($_SESSION['user_pk'])) exit();
require_once 'config.php';

$stmt = $pdo->prepare("UPDATE notifications SET deleted_from_bell = 1 WHERE user_id = ?");
$stmt->execute([$_SESSION['user_pk']]);

echo "OK";
