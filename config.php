<?php
// config.php - Database connection (local XAMPP or live server)

date_default_timezone_set('Asia/Dhaka');

$productionConfig = __DIR__ . '/config.production.php';
if (file_exists($productionConfig)) {
    require $productionConfig;
} else {
    // Local XAMPP defaults
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'bracu_thesis');
}

$dbPort = defined('DB_PORT') ? DB_PORT : '3306';
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
if ($dbPort !== '' && $dbPort !== '3306') {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $dbPort . ';dbname=' . DB_NAME . ';charset=utf8mb4';
}

try {
    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(255)
    )");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('advising_open', '0')");

    $pubCol = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'score_published'")->fetchAll();
    if (empty($pubCol)) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN score_published TINYINT(1) NOT NULL DEFAULT 0");
    }

    try {
        $commFileCol = $pdo->query("SHOW COLUMNS FROM course_communications LIKE 'file_path'")->fetchAll();
        if (empty($commFileCol)) {
            $pdo->exec("ALTER TABLE course_communications
                MODIFY message TEXT NULL,
                ADD COLUMN file_path VARCHAR(255) DEFAULT NULL AFTER message,
                ADD COLUMN file_name VARCHAR(255) DEFAULT NULL AFTER file_path,
                ADD COLUMN file_mime VARCHAR(100) DEFAULT NULL AFTER file_name");
        }
    } catch (PDOException $e) {
        // Table does not exist yet; skip
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS developer_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        developer_name VARCHAR(100) NOT NULL,
        message_text TEXT NOT NULL,
        sender_type VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
require_once __DIR__ . '/semester_helper.php';

// Check if student is frozen
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'student' && isset($_SESSION['user_pk'])) {
    try {
        $stmtF = $pdo->prepare("SELECT is_frozen FROM users WHERE id = ?");
        $stmtF->execute([$_SESSION['user_pk']]);
        $isFrozen = $stmtF->fetchColumn();
        if ($isFrozen) {
            $currentPage = basename($_SERVER['SCRIPT_NAME']);
            $allowedPages = ['student_payment.php', 'logout.php', 'app_support.php'];
            if (!in_array($currentPage, $allowedPages)) {
                header('Location: student_payment.php');
                exit();
            }
        }
    } catch (PDOException $e) {
        // Silently ignore DB errors during config load
    }
}
?>
