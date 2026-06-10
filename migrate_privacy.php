<?php
require_once 'config.php';

try {
    $pdo->exec("ALTER TABLE course_materials ADD COLUMN is_private TINYINT(1) DEFAULT 0");
    echo "Added is_private column to course_materials.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
