<?php
require 'config.php';
$stmt = $pdo->query("DESCRIBE course_sections");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . "\n";
}
