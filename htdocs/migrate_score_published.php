<?php
// migrate_score_published.php – run once to add score_published column
require_once 'config.php';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'score_published'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN score_published TINYINT(1) NOT NULL DEFAULT 0 AFTER score_total");
        echo "<p style='color:green;font-family:monospace;'>Migration successful: <b>score_published</b> column added to enrollments.</p>";
    } else {
        echo "<p style='color:orange;font-family:monospace;'>Column <b>score_published</b> already exists – no changes made.</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color:red;font-family:monospace;'>Migration failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}
