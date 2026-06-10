<?php
// migrate_totalscore.php – run once to add score_total column
require_once 'config.php';
try {
    // Check if column already exists
    $cols = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'score_total'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN score_total INT DEFAULT NULL AFTER score_lab");
        echo "<p style='color:green;font-family:monospace;'>✔ Migration successful: <b>score_total</b> column added to enrollments.</p>";
    } else {
        echo "<p style='color:orange;font-family:monospace;'>ℹ Column <b>score_total</b> already exists – no changes made.</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color:red;font-family:monospace;'>✘ Migration failed: " . htmlspecialchars($e->getMessage()) . "</p>";
}
