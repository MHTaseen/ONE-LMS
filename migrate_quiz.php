<?php
/**
 * migrate_quiz.php — ONE-TIME migration script
 * Visit this page ONCE in the browser while logged into XAMPP.
 * DELETE this file after running it.
 */
require_once 'config.php';

$results = [];

$tables = [
    'quizzes' => "CREATE TABLE IF NOT EXISTS quizzes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_id INT NOT NULL,
        quiz_name VARCHAR(255) NOT NULL,
        quiz_number INT NOT NULL DEFAULT 1,
        time_limit INT NOT NULL DEFAULT 20,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (section_id) REFERENCES course_sections(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'quiz_questions' => "CREATE TABLE IF NOT EXISTS quiz_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        question_text TEXT NOT NULL,
        option_a VARCHAR(500) NOT NULL,
        option_b VARCHAR(500) NOT NULL,
        option_c VARCHAR(500) NOT NULL,
        option_d VARCHAR(500) NOT NULL,
        correct_option CHAR(1) NOT NULL,
        FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'quiz_submissions' => "CREATE TABLE IF NOT EXISTS quiz_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        student_id INT NOT NULL,
        answers_json TEXT NOT NULL,
        score INT NOT NULL DEFAULT 0,
        total INT NOT NULL DEFAULT 0,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_submission (quiz_id, student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['table' => $name, 'status' => 'OK ✅', 'ok' => true];
    } catch (PDOException $e) {
        $results[] = ['table' => $name, 'status' => 'ERROR: ' . $e->getMessage(), 'ok' => false];
    }
}

// Verify tables exist
$stmt = $pdo->query("SHOW TABLES");
$existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quiz Migration</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { justify-content: center; align-items: center; }
        .card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 20px; padding: 36px; max-width: 560px; width: 100%; box-shadow: var(--card-glow); backdrop-filter: blur(16px); }
        h2 { font-family: 'Space Grotesque', sans-serif; font-size: 1.5rem; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 24px; }
        .row { display: flex; justify-content: space-between; padding: 12px 16px; border-radius: 10px; margin-bottom: 10px; border: 1px solid var(--border-color); background: var(--input-bg); }
        .ok   { border-color: var(--success-color); }
        .err  { border-color: var(--error-color); }
        .status-ok  { color: var(--success-color); font-weight: 700; }
        .status-err { color: var(--error-color);   font-weight: 700; font-size: 0.82rem; }
        .tbl-name { font-weight: 600; color: var(--text-primary); }
        .divider { height: 1px; background: var(--border-color); margin: 20px 0; }
        .table-list { font-size: 0.85rem; color: var(--text-secondary); }
        .table-list span { display: inline-block; background: rgba(168,85,247,0.1); border: 1px solid rgba(168,85,247,0.2); color: var(--accent-primary); padding: 3px 10px; border-radius: 8px; margin: 3px; }
        .btn-back { display: inline-flex; align-items: center; gap: 8px; margin-top: 20px; padding: 12px 24px; background: var(--gradient-accent); border: none; border-radius: 12px; color: #fff; font-weight: 700; text-decoration: none; font-size: 0.95rem; }
        .warning-box { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); border-radius: 12px; padding: 12px 16px; color: var(--error-color); font-size: 0.85rem; margin-top: 16px; }
    </style>
</head>
<body>
<div class="ambient-glow-1"></div>
<div class="ambient-glow-2"></div>
<div class="card">
    <h2>🗄️ Quiz Table Migration</h2>
    <?php foreach ($results as $r): ?>
    <div class="row <?= $r['ok'] ? 'ok' : 'err' ?>">
        <span class="tbl-name"><?= $r['table'] ?></span>
        <span class="<?= $r['ok'] ? 'status-ok' : 'status-err' ?>"><?= $r['status'] ?></span>
    </div>
    <?php endforeach; ?>

    <div class="divider"></div>
    <div class="table-list">
        <strong style="color:var(--text-primary);display:block;margin-bottom:8px;">All tables in <code>bracu_thesis</code>:</strong>
        <?php foreach ($existing as $t): ?>
        <span><?= htmlspecialchars($t) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="warning-box">
        ⚠️ <strong>Delete this file</strong> after migration: <code>migrate_quiz.php</code>
    </div>

    <a href="landing.php" class="btn-back">← Back to Dashboard</a>
</div>
</body>
</html>
