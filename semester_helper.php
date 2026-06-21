<?php
/**
 * semester_helper.php
 * Shared include: runs DB migrations for semesters, exposes helper
 * functions and the $activeSemester variable.
 *
 * Usage:  require_once 'semester_helper.php';
 * After including, $activeSemester contains the active semester row
 * (or null if none has been set yet).
 */

// ── 1. Ensure semesters table exists ─────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS semesters (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        label     VARCHAR(30)                          NOT NULL,
        season    ENUM('Summer','Fall','Spring')       NOT NULL,
        year      INT                                  NOT NULL,
        is_active TINYINT(1)  DEFAULT 0,
        created_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_season_year (season, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 2. Ensure semester_id column on enrollments ───────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM enrollments LIKE 'semester_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE enrollments ADD COLUMN semester_id INT NULL DEFAULT NULL");
}

// ── 3. Ensure semester_id column on course_sections ──────────────────────────
$cols2 = $pdo->query("SHOW COLUMNS FROM course_sections LIKE 'semester_id'")->fetchAll();
if (empty($cols2)) {
    $pdo->exec("ALTER TABLE course_sections ADD COLUMN semester_id INT NULL DEFAULT NULL");
}

// ── 3b. Ensure additional finance/freeze columns and tables exist ─────────────
$cols_deadline = $pdo->query("SHOW COLUMNS FROM semesters LIKE 'payment_deadline'")->fetchAll();
if (empty($cols_deadline)) {
    $pdo->exec("ALTER TABLE semesters ADD COLUMN payment_deadline DATE NULL DEFAULT NULL");
}

$cols_fee = $pdo->query("SHOW COLUMNS FROM courses LIKE 'course_fee'")->fetchAll();
if (empty($cols_fee)) {
    $pdo->exec("ALTER TABLE courses ADD COLUMN course_fee DECIMAL(10, 2) NOT NULL DEFAULT 5000.00");
}

$cols_frozen = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_frozen'")->fetchAll();
if (empty($cols_frozen)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_frozen TINYINT(1) NOT NULL DEFAULT 0");
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS semester_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        semester_id INT NOT NULL,
        status ENUM('unpaid', 'paid') DEFAULT 'unpaid',
        paid_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_student_semester (student_id, semester_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$cols_unfrozen = $pdo->query("SHOW COLUMNS FROM semester_payments LIKE 'unfrozen_by_authority'")->fetchAll();
if (empty($cols_unfrozen)) {
    $pdo->exec("ALTER TABLE semester_payments ADD COLUMN unfrozen_by_authority TINYINT(1) NOT NULL DEFAULT 0");
}

// Run automatic freeze logic if an active semester has a deadline
$activeSemesterId = getCurrentSemesterId($pdo);
if ($activeSemesterId) {
    $activeSemRow = $pdo->query("SELECT * FROM semesters WHERE id = $activeSemesterId LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($activeSemRow && !empty($activeSemRow['payment_deadline'])) {
        $today = date('Y-m-d');
        if ($today > $activeSemRow['payment_deadline']) {
            try {
                // Ensure entries exist in semester_payments for all enrolled students
                $pdo->exec("
                    INSERT IGNORE INTO semester_payments (student_id, semester_id, status)
                    SELECT DISTINCT e.student_id, $activeSemesterId, 'unpaid'
                    FROM enrollments e
                    WHERE e.semester_id = $activeSemesterId
                ");

                // Now, freeze students who are unpaid, not manually unfrozen, and the deadline has passed
                $pdo->exec("
                    UPDATE users u
                    JOIN semester_payments sp ON u.id = sp.student_id
                    SET u.is_frozen = 1
                    WHERE sp.semester_id = $activeSemesterId
                      AND sp.status = 'unpaid'
                      AND sp.unfrozen_by_authority = 0
                      AND u.role = 'student'
                      AND u.is_frozen = 0
                ");
            } catch (PDOException $e) {
                // Silently continue if tables are not fully ready yet
            }
        }
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS teacher_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        month_year VARCHAR(20) NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_teacher_month (teacher_id, month_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── 4. Seed a default active semester if none exists ─────────────────────────
$count = $pdo->query("SELECT COUNT(*) FROM semesters")->fetchColumn();
if ($count == 0) {
    $currentYear = (int)date('Y');
    $pdo->exec("
        INSERT IGNORE INTO semesters (label, season, year, is_active)
        VALUES ('Summer $currentYear', 'Summer', $currentYear, 1)
    ");
}

// Ensure at least one semester is active (edge-case guard)
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM semesters WHERE is_active = 1")->fetchColumn();
if ($activeCount === 0) {
    // Activate the most recently created semester
    $pdo->exec("UPDATE semesters SET is_active = 1 ORDER BY year DESC, FIELD(season,'Spring','Summer','Fall') DESC LIMIT 1");
}

// ── 5. Back-fill existing enrollments/sections with the active semester ───────
// Only runs once: rows that still have NULL semester_id get the current active ID.
$activeSemId = $pdo->query("SELECT id FROM semesters WHERE is_active = 1 LIMIT 1")->fetchColumn();
if ($activeSemId) {
    $pdo->exec("UPDATE enrollments    SET semester_id = $activeSemId WHERE semester_id IS NULL");
    $pdo->exec("UPDATE course_sections SET semester_id = $activeSemId WHERE semester_id IS NULL");
}

// ── 6. Load the active semester row ──────────────────────────────────────────
$activeSemester = $pdo->query("SELECT * FROM semesters WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ── 7. Helper functions ───────────────────────────────────────────────────────

/**
 * Returns the ID of the currently active semester (int|false).
 */
function getCurrentSemesterId(PDO $pdo): int|false
{
    $id = $pdo->query("SELECT id FROM semesters WHERE is_active = 1 LIMIT 1")->fetchColumn();
    return $id !== false ? (int)$id : false;
}

/**
 * Returns the human-readable label of any semester by its ID.
 */
function getSemesterLabel(PDO $pdo, int $id): string
{
    $label = $pdo->prepare("SELECT label FROM semesters WHERE id = ? LIMIT 1");
    $label->execute([$id]);
    return $label->fetchColumn() ?: "Unknown Semester";
}

/**
 * Returns all semesters ordered newest-first.
 */
function getAllSemesters(PDO $pdo): array
{
    return $pdo->query("
        SELECT * FROM semesters
        ORDER BY year DESC, FIELD(season,'Fall','Summer','Spring') ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns a semester label string like "Fall 2025".
 */
function makeSemesterLabel(string $season, int $year): string
{
    return "$season $year";
}
