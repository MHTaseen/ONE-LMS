<?php
require_once 'config.php';

try {
    $pdo->exec("ALTER TABLE assignments ADD COLUMN deadline DATETIME DEFAULT NULL");
    echo "Added deadline column to assignments.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
