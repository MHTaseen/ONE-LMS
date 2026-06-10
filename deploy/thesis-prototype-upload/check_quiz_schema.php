<?php
require 'config.php';
$tables = ['quizzes', 'quiz_questions', 'quiz_submissions', 'student_quiz_answers'];
foreach ($tables as $t) {
    echo "--- $t ---\n";
    try {
        $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as $c) {
            echo $c['Field'].' '.$c['Type'].' '.$c['Null']."\n";
        }
    } catch(Exception $e) {
        echo "Table does not exist or error.\n";
    }
}
