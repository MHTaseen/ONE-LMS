<?php
require_once 'config.php';
$stmt = $pdo->query("SHOW CREATE TABLE assignments");
print_r($stmt->fetch());
