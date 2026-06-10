<?php
require 'config.php';

$queries = [
    "ALTER TABLE quizzes ADD COLUMN quiz_type ENUM('manual', 'file') DEFAULT 'manual'",
    "ALTER TABLE quizzes ADD COLUMN quiz_file_path VARCHAR(255) NULL",
    "ALTER TABLE quizzes ADD COLUMN total_marks INT(11) NULL",
    "ALTER TABLE quizzes ADD COLUMN start_time DATETIME NULL",
    "ALTER TABLE quizzes ADD COLUMN end_time DATETIME NULL",
    "ALTER TABLE quiz_submissions ADD COLUMN solution_file_path VARCHAR(255) NULL",
    "ALTER TABLE quiz_submissions MODIFY COLUMN score INT(11) NULL DEFAULT NULL",
    "ALTER TABLE quiz_submissions MODIFY COLUMN total INT(11) NULL DEFAULT NULL"
];

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "Success: $q\n";
    } catch (PDOException $e) {
        echo "Error: $q - " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
