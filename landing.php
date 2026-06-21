<?php
// landing.php - Core Dashboard / Landing Page
session_start();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'authority') {
    header('Location: authority_dashboard.php');
    exit();
}

$fullName    = $_SESSION['full_name'];
$userId      = $_SESSION['user_id'];
$email       = $_SESSION['email'];
$department  = $_SESSION['department'];
$role        = $_SESSION['role'];

// Badge class & clearance label
$badgeClass    = '';
$clearanceTitle = '';
if ($role === 'student') {
    $badgeClass    = 'badge-student';
    $clearanceTitle = 'Student Level-I Clearance';
} elseif ($role === 'teacher') {
    $badgeClass    = 'badge-teacher';
    $clearanceTitle = 'Faculty Level-II Clearance';
} else {
    $badgeClass    = 'badge-guest';
    $clearanceTitle = 'Guest Level-0 Restricted';
}

// User initials (two-letter abbreviation)
$nameParts = explode(' ', trim($fullName));
if (count($nameParts) > 1) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $initials = strtoupper(substr($fullName, 0, 2));
}

// Dashboard Stats fetching
$cgpa = 'N/A';
$current_semester = 'N/A';
$joining_semester = 'N/A';

// Fetch user's extra stats from DB (for both students and teachers)
require_once 'config.php';
require_once 'includes/grading.php';
require_once 'includes/notification_system.php';

function computePublishedStudentCgpa(PDO $pdo, int $studentId): string
{
    $stmt = $pdo->prepare("
        SELECT c.credit, e.score_total, e.score_published
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        WHERE e.student_id = ?
    ");
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCreditPoints = 0.0;
    $totalAttemptedCredits = 0.0;

    foreach ($rows as $row) {
        if (empty($row['score_published']) || $row['score_total'] === null) {
            continue;
        }

        $gradeInfo = getGradeInfo($row['score_total']);
        if ($gradeInfo['point'] === null) {
            continue;
        }

        $credit = (float) $row['credit'];
        $totalAttemptedCredits += $credit;
        $totalCreditPoints += $credit * (float) $gradeInfo['point'];
    }

    return $totalAttemptedCredits > 0
        ? number_format($totalCreditPoints / $totalAttemptedCredits, 2)
        : 'N/A';
}

function parseNotificationDeadlineTimestamp(string $message): ?int
{
    if (preg_match('/^Deadline:\s*(.+)$/mi', $message, $matches) !== 1) {
        return null;
    }

    $timestamp = strtotime(trim($matches[1]));
    return $timestamp !== false ? $timestamp : null;
}

function resolveNotificationExpiryTimestamp(PDO $pdo, array $notification): ?int
{
    $message = trim(str_replace("\r", '', (string) ($notification['message'] ?? '')));
    $firstLine = strtok($message, "\n") ?: $message;

    if (preg_match('/^New Quiz Scheduled in\s+([A-Z0-9]+)\s+\(Sec\s+(\d+)\):\s+(.+?)\s+on\s+[A-Z][a-z]{2}\s+\d{1,2},\s+\d{4}\s+\d{1,2}:\d{2}\s+[AP]M$/', $firstLine, $matches) === 1) {
        $courseCode = trim($matches[1]);
        $sectionNo = (int) $matches[2];
        $quizName = trim($matches[3]);

        $stmt = $pdo->prepare("
            SELECT q.end_time
            FROM quizzes q
            JOIN course_sections cs ON q.section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            WHERE c.code = ? AND cs.section_no = ? AND q.quiz_name = ?
            ORDER BY q.id DESC
            LIMIT 1
        ");
        $stmt->execute([$courseCode, $sectionNo, $quizName]);
        $endTime = $stmt->fetchColumn();

        if (!empty($endTime)) {
            $timestamp = strtotime((string) $endTime);
            return $timestamp !== false ? $timestamp : null;
        }
    }

    if (preg_match('/^New Assignment Deployed in\s+([A-Z0-9]+)\s+\(Sec\s+(\d+)\):\s+(.+)$/', $firstLine, $matches) === 1) {
        $courseCode = trim($matches[1]);
        $sectionNo = (int) $matches[2];
        $assignmentName = trim($matches[3]);

        $stmt = $pdo->prepare("
            SELECT a.deadline
            FROM assignments a
            JOIN course_sections cs ON a.section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            WHERE c.code = ? AND cs.section_no = ? AND a.assignment_name = ?
            ORDER BY a.id DESC
            LIMIT 1
        ");
        $stmt->execute([$courseCode, $sectionNo, $assignmentName]);
        $deadline = $stmt->fetchColumn();

        if (!empty($deadline)) {
            $timestamp = strtotime((string) $deadline);
            return $timestamp !== false ? $timestamp : null;
        }
    }

    return parseNotificationDeadlineTimestamp($message);
}

function shouldHideImportantNotificationCard(PDO $pdo, array $notification): bool
{
    $expiryTimestamp = resolveNotificationExpiryTimestamp($pdo, $notification);
    return $expiryTimestamp !== null && $expiryTimestamp < time();
}

// Fetch Notifications
$unreadCount = getUnreadCount($pdo, $_SESSION['user_pk']);
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND deleted_from_bell = 0 ORDER BY created_at DESC LIMIT 10");
$stmt->execute([$_SESSION['user_pk']]);
$recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT current_semester, joining_semester FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_pk']]);
$userStats = $stmt->fetch();
if ($userStats) {
    $current_semester = $userStats['current_semester'] ?? 'N/A';
    $joining_semester = $userStats['joining_semester'] ?? 'N/A';
}

if ($role === 'student') {
    $cgpa = computePublishedStudentCgpa($pdo, (int) $_SESSION['user_pk']);
    
    $paymentWarn = false;
    $paymentDeadlineStr = '';
    if (isset($activeSemester['id'])) {
        $stmtPay = $pdo->prepare("SELECT status FROM semester_payments WHERE student_id = ? AND semester_id = ? LIMIT 1");
        $stmtPay->execute([$_SESSION['user_pk'], $activeSemester['id']]);
        $paymentStatus = $stmtPay->fetchColumn() ?: 'unpaid';
        
        // Let's also check if they are actually enrolled in any courses in the active semester
        $stmtCheckEnroll = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND semester_id = ?");
        $stmtCheckEnroll->execute([$_SESSION['user_pk'], $activeSemester['id']]);
        $hasEnrolledCourses = $stmtCheckEnroll->fetchColumn() > 0;
        
        if ($hasEnrolledCourses && $paymentStatus === 'unpaid' && !empty($activeSemester['payment_deadline'])) {
            $paymentWarn = true;
            $paymentDeadlineStr = date('F j, Y', strtotime($activeSemester['payment_deadline']));
        }
    }
}

$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'advising_open'");
$advisingOpenRow = $stmt->fetch();
$advisingOpen = $advisingOpenRow ? ($advisingOpenRow['setting_value'] === '1') : false;

if ($role === 'student' || $role === 'guest') {
    $completed_credits = 0;
    $required_credits = 130; // Default

    // Compute completed credits (sum of enrolled course credits)
    $stmt = $pdo->prepare("
        SELECT SUM(c.credit) as total_credits
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        WHERE e.student_id = ?
    ");
    $stmt->execute([$_SESSION['user_pk']]);
    $creditRes = $stmt->fetch();
    if ($creditRes && $creditRes['total_credits'] !== null) {
        $completed_credits = floatval($creditRes['total_credits']);
    }

    // Set required credits based on department
    $dept = strtoupper(trim($department));
    if ($dept === 'CSE') {
        $required_credits = 136;
    } elseif ($dept === 'CS') {
        $required_credits = 124;
    } elseif ($dept === 'BBA') {
        $required_credits = 130;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ONE LMS – Thesis Prototype</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        /* ── Body reset for navbar layout ── */
        body {
            justify-content: flex-start;
            align-items: stretch;
            padding-top: 0;
        }

        /* ════════════════════════════════════
           TOP NAVIGATION BAR
        ════════════════════════════════════ */
        .top-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            min-height: 64px;
            z-index: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.25);
        }

        /* Left: brand + initials */
        .navbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .navbar-brand {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.15rem;
            font-weight: 700;
            background: var(--gradient-accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            user-select: none;
        }

        .nav-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-accent);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            box-shadow: var(--glow-shadow);
            cursor: default;
            flex-shrink: 0;
            letter-spacing: 0.5px;
            font-family: 'Space Grotesque', sans-serif;
        }

        /* Right: theme toggle inside navbar */
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Notification Bell */
        .notif-wrapper { position: relative; display: flex; align-items: center; }
        .notif-btn { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; position: relative; padding: 4px; transition: color 0.2s; }
        .notif-btn:hover { color: var(--text-primary); }
        .notif-badge { position: absolute; top: -2px; right: -2px; background: #ef4444; color: #fff; font-size: 0.65rem; font-weight: 700; width: 16px; height: 16px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid var(--bg-secondary); }
        .notif-dropdown { position: absolute; top: 40px; right: 0; width: 320px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: var(--glow-shadow); display: none; flex-direction: column; overflow: hidden; z-index: 1000; }
        .notif-dropdown.show { display: flex; }
        .notif-header { padding: 12px 16px; font-weight: 600; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .notif-list { max-height: 300px; overflow-y: auto; }
        .notif-item { padding: 12px 16px; border-bottom: 1px solid var(--border-color); text-decoration: none; color: var(--text-primary); display: flex; flex-direction: column; gap: 4px; transition: 0.2s; }
        .notif-item:hover { background: rgba(168,85,247,0.1); }
        .notif-item.unread { background: rgba(168,85,247,0.05); border-left: 3px solid var(--accent-primary); }
        .notif-time { font-size: 0.75rem; color: var(--text-secondary); }

        /* Override theme-switch-container positioning – now lives inside nav */
        .theme-switch-container {
            position: static;
        }

        /* ════════════════════════════════════
           DRAWER TRIGGER BUTTON
        ════════════════════════════════════ */
        .drawer-section {
            position: fixed;
            top: var(--landing-top-nav-offset, 64px);   /* sits flush under the navbar */
            left: 0;
            z-index: 800;
        }

        .drawer-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 14px 0 0 20px;
            padding: 9px 18px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 12px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: var(--card-glow);
            transition: border-color 0.25s, box-shadow 0.25s, transform 0.2s;
        }

        .drawer-btn:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--glow-shadow);
            transform: translateY(-2px);
        }

        /* Hamburger icon lines */
        .hamburger-icon {
            display: flex;
            flex-direction: column;
            gap: 5px;
            width: 20px;
        }

        .hamburger-icon span {
            display: block;
            height: 2px;
            border-radius: 2px;
            background: var(--text-primary);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: center;
        }

        /* Animate hamburger → X when active */
        .drawer-btn.open .hamburger-icon span:nth-child(1) {
            transform: translateY(7px) rotate(45deg);
        }
        .drawer-btn.open .hamburger-icon span:nth-child(2) {
            opacity: 0;
            transform: scaleX(0);
        }
        .drawer-btn.open .hamburger-icon span:nth-child(3) {
            transform: translateY(-7px) rotate(-45deg);
        }

        /* ════════════════════════════════════
           DROPDOWN MENU
        ════════════════════════════════════ */
        .drawer-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 20px;
            width: 270px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 8px;
            /* Extra space so the last item (Log Out) is fully visible when scrolled */
            padding-bottom: 22px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35), var(--glow-shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            /* Scrollable drawer */
            max-height: calc(100vh - 140px);
            overflow-y: auto;
            overflow-x: hidden;
            /* Hidden state */
            opacity: 0;
            transform: translateY(-12px) scale(0.97);
            pointer-events: none;
            transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1),
                        transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Custom scrollbar for drawer */
        .drawer-dropdown::-webkit-scrollbar { width: 5px; }
        .drawer-dropdown::-webkit-scrollbar-track { background: transparent; }
        .drawer-dropdown::-webkit-scrollbar-thumb { background: var(--accent-primary); border-radius: 99px; opacity: 0.5; }

        .drawer-dropdown.visible {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: all;
        }

        .drawer-dropdown::before {
            content: '';
            position: absolute;
            top: -1px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-accent);
            border-radius: 16px 16px 0 0;
        }

        .drawer-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            cursor: pointer;
            color: var(--text-primary);
            font-size: 0.92rem;
            font-weight: 500;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            transition: background 0.2s, color 0.2s, transform 0.15s;
        }

        .drawer-item:hover {
            background: rgba(168, 85, 247, 0.1);
            color: var(--accent-primary);
            transform: translateX(4px);
        }

        .drawer-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            color: var(--accent-secondary);
            transition: color 0.2s;
        }

        .drawer-item:hover svg {
            color: var(--accent-primary);
        }

        .drawer-divider {
            height: 1px;
            background: var(--border-color);
            margin: 6px 8px;
        }

        .drawer-section-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            padding: 10px 14px 4px;
            display: block;
        }

        /* ════════════════════════════════════
           PROFILE MODAL OVERLAY
        ════════════════════════════════════ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            justify-content: center;
            align-items: center;
            background: rgba(7, 11, 20, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            /* Hidden state */
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modal-overlay.visible {
            opacity: 1;
            pointer-events: all;
        }

        .profile-modal {
            position: relative;
            width: 100%;
            max-width: 460px;
            margin: 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 36px 36px 32px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5), var(--glow-shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            overflow: hidden;
            /* Entry animation */
            transform: scale(0.88) translateY(24px);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),
                        opacity 0.35s ease;
            opacity: 0;
        }

        .modal-overlay.visible .profile-modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        /* Gradient top accent bar */
        .profile-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-accent);
        }

        /* Close button */
        .modal-close-btn {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            transition: background 0.2s, color 0.2s, border-color 0.2s,
                        transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .modal-close-btn:hover {
            background: rgba(239, 68, 68, 0.15);
            border-color: var(--error-color);
            color: var(--error-color);
            transform: rotate(90deg) scale(1.1);
        }

        .modal-close-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Modal header */
        .modal-avatar-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .modal-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: var(--gradient-accent);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fff;
            font-size: 1.8rem;
            font-weight: 700;
            font-family: 'Space Grotesque', sans-serif;
            box-shadow: var(--glow-shadow);
            letter-spacing: 1px;
        }

        .modal-full-name {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Info rows */
        .profile-info-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .profile-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 13px 16px;
            border-radius: 12px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            transition: border-color 0.2s;
        }

        .profile-info-row:hover {
            border-color: var(--accent-primary);
        }

        .profile-info-label {
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-secondary);
        }

        .profile-info-val {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
            max-width: 60%;
            word-break: break-word;
        }

        .profile-info-val.verified {
            color: var(--success-color);
        }

        .profile-info-val.unverified {
            color: var(--error-color);
        }

        /* Page content area (push below fixed navbar) */
        .page-content {
            padding-top: var(--landing-top-nav-offset, 64px);
            min-height: 100vh;
        }
        .page-content > .dashboard-container:first-of-type {
            padding-top: 20px;
        }

        /* ── Student Dashboard Grid ── */
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 28px;
        }
        
        .welcome-header {
            margin-bottom: 32px;
        }
        .welcome-title-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 8px;
        }
        .welcome-header h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2.4rem;
            color: var(--text-primary);
            margin-bottom: 0;
        }
        .session-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.28);
            color: #34d399;
            font-size: 0.85rem;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }
        .session-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.16);
            flex-shrink: 0;
        }
        .welcome-header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
        }
        
        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 28px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            border-color: rgba(168,85,247,0.3);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--gradient-accent);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .stat-card:hover::before {
            opacity: 1;
        }
        
        .stat-card .icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(168,85,247,0.1);
            color: var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        
        .stat-card .icon-wrap svg {
            width: 24px;
            height: 24px;
        }

        .stat-card h3 {
            color: var(--text-secondary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-card .val {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-primary);
            font-family: 'Space Grotesque', sans-serif;
            display: flex;
            align-items: baseline;
            gap: 8px;
        }
        
        .stat-card .val .sub-val {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        /* Specific card colors */
        .card-cgpa .icon-wrap { background: rgba(16,185,129,0.1); color: #10b981; }
        .card-cgpa:hover::before { background: #10b981; }
        
        .card-sem .icon-wrap { background: rgba(59,130,246,0.1); color: #3b82f6; }
        .card-sem:hover::before { background: #3b82f6; }

        /* Universal Search Bar */
        .universal-search-container {
            position: relative;
            flex: 1;
            max-width: 400px;
            margin: 0 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .universal-search-field {
            position: relative;
            width: 100%;
        }
        .universal-search-input {
            width: 100%;
            padding: 10px 36px 10px 40px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s ease;
        }
        .universal-search-input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.2);
        }
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: var(--text-secondary);
            pointer-events: none;
        }
        .search-filter-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            padding: 0;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, transform 0.2s;
        }
        .search-filter-toggle:hover,
        .search-filter-toggle.active {
            background: rgba(168, 85, 247, 0.15);
            color: var(--accent-primary);
        }
        .search-filter-toggle.active {
            transform: translateY(-50%);
        }
        .search-filter-toggle svg {
            width: 12px;
            height: 12px;
            flex-shrink: 0;
            transition: transform 0.2s ease;
        }
        .search-filter-toggle.active svg {
            transform: rotate(180deg);
        }
        .search-filter-panel {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            width: 100%;
            display: none;
            padding: 12px;
            background: #0d1629;
            border: 1px solid rgba(168, 85, 247, 0.25);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4), 0 0 15px rgba(168, 85, 247, 0.15);
            z-index: 1001;
        }
        .light-theme .search-filter-panel {
            background: #ffffff;
            border: 1px solid rgba(99, 102, 241, 0.3);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08), 0 0 15px rgba(99, 102, 241, 0.08);
        }
        .search-filter-panel.active {
            display: block;
        }
        .search-filter-title {
            color: var(--text-secondary);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 10px;
        }
        .search-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .search-chip {
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-secondary);
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, border-color 0.2s, box-shadow 0.2s;
        }
        .search-chip:hover,
        .search-chip.active {
            background: rgba(168, 85, 247, 0.12);
            color: var(--accent-primary);
            border-color: rgba(168, 85, 247, 0.26);
            box-shadow: 0 0 0 2px rgba(168, 85, 247, 0.08);
        }
        .search-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            width: 100%;
            background: #0d1629;
            border: 1px solid rgba(168, 85, 247, 0.25);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4), 0 0 15px rgba(168, 85, 247, 0.15);
            max-height: 360px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .light-theme .search-dropdown {
            background: #ffffff;
            border: 1px solid rgba(99, 102, 241, 0.3);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08), 0 0 15px rgba(99, 102, 241, 0.08);
        }
        .search-dropdown.active {
            display: block;
        }
        .search-result-item {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            text-decoration: none;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s;
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
        .search-result-item:hover {
            background: rgba(168, 85, 247, 0.1);
        }
        .search-result-icon {
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--accent-primary);
        }
        .search-result-text {
            display: flex;
            flex-direction: column;
        }
        .search-result-title {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.9rem;
        }
        .search-result-type {
            color: var(--text-secondary);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .search-result-meta {
            color: var(--text-secondary);
            font-size: 0.78rem;
            margin-top: 2px;
        }
        .search-loading {
            padding: 16px;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* ══════════════════════════════════
           LANDING PAGE RESPONSIVE RULES
        ══════════════════════════════════ */

        /* Page content below the fixed navbar */
        .page-content {
            padding-top: var(--landing-top-nav-offset, 64px); /* offset for fixed navbar */
        }
        .dashboard-container {
            padding: 40px 28px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .welcome-header {
            margin-bottom: 30px;
        }
        .welcome-title-row {
            margin-bottom: 6px;
        }
        .welcome-header h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2rem;
            margin-bottom: 0;
        }
        .welcome-header p {
            color: var(--text-secondary);
            font-size: 1rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        /* ── Tablets ≤ 900px ── */
        @media (max-width: 900px) {
            .dashboard-container {
                padding: 28px 18px;
            }
            .universal-search-container {
                max-width: 220px;
                margin: 0 10px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
        }

        /* ── Mobile & Small Tablets ≤ 768px ── */
        @media (max-width: 768px) {
            .top-navbar {
                padding: 0 12px;
                gap: 8px;
            }
            .navbar-brand {
                display: none;
            }
            .universal-search-container {
                max-width: 180px;
                margin: 0 6px;
            }
            .universal-search-input {
                padding: 8px 32px 8px 34px;
                font-size: 0.85rem;
            }
            .notif-dropdown {
                position: fixed;
                top: 68px;
                left: 8px;
                right: 8px;
                width: auto;
            }
            .drawer-dropdown {
                width: calc(100vw - 28px);
                max-width: 300px;
            }
            .dashboard-container {
                padding: 20px 12px;
            }
            .welcome-header h1 {
                font-size: 1.6rem;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .stat-card {
                padding: 18px;
            }
            .stat-card .val {
                font-size: 1.8rem;
            }
            .profile-modal {
                width: calc(100% - 32px);
                max-width: 440px;
                margin: 16px;
                border-radius: 18px;
                max-height: calc(100vh - 32px);
                overflow-y: auto;
            }
        }

        /* ── Small Mobile ≤ 600px ── */
        @media (max-width: 600px) {
            .global-search-nav {
                align-items: flex-start;
                padding: 8px 12px 10px;
                min-height: 64px;
                height: auto;
            }
            .global-search-nav .navbar-left,
            .global-search-nav .navbar-right {
                min-height: 44px;
                align-items: center;
            }
            .global-search-nav .universal-search-container {
                display: block;
                order: 3;
                flex: 0 0 100%;
                width: 100%;
                max-width: none;
                margin: 0;
            }
            .global-search-nav .universal-search-input {
                padding-right: 126px;
            }
            .nav-avatar {
                width: 34px;
                height: 34px;
                font-size: 0.8rem;
            }
            .navbar-right {
                gap: 6px;
            }
            .theme-btn span { display: none; }
            .theme-btn { padding: 8px 10px; }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .stat-card {
                padding: 16px;
            }
            .stat-card .val {
                font-size: 1.6rem;
            }
            .dashboard-container {
                padding: 14px 10px;
            }
            .page-content {
                padding-top: var(--landing-top-nav-offset, 122px);
            }
            .welcome-header h1 {
                font-size: 1.4rem;
            }
            .welcome-header p {
                font-size: 0.9rem;
            }
        }

        /* ════════════════════════════════════
           IMPORTANT NOTIFICATIONS SECTION
        ════════════════════════════════════ */
        .important-notifs-section {
            margin-top: 40px;
        }
        .notifs-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .notifs-section-title {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notifs-section-title svg {
            width: 22px; height: 22px;
            color: var(--accent-primary);
        }
        .notif-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }
        .imp-notif-card {
            position: relative;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 18px 20px;
            padding-right: 44px;
            box-shadow: var(--card-glow);
            backdrop-filter: blur(12px);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
            text-decoration: none;
            display: block;
            animation: slideInCard 0.3s ease;
        }
        @keyframes slideInCard {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .imp-notif-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.3);
        }
        .imp-notif-card.priority-high   { border-left: 4px solid #ef4444; }
        .imp-notif-card.priority-medium { border-left: 4px solid #f59e0b; }
        .imp-notif-card.priority-low    { border-left: 4px solid #06b6d4; }
        .notif-badge-priority {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 20px;
            margin-bottom: 8px;
        }
        .notif-badge-priority.high   { background: rgba(239,68,68,0.15);  color: #ef4444; }
        .notif-badge-priority.medium { background: rgba(245,158,11,0.15); color: #f59e0b; }
        .notif-badge-priority.low    { background: rgba(6,182,212,0.15);  color: #06b6d4; }
        .imp-notif-title {
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 5px;
            line-height: 1.3;
        }
        .imp-notif-body {
            font-size: 0.875rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        .imp-notif-time {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .imp-notif-dismiss {
            position: absolute;
            top: 12px;
            right: 12px;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            width: 24px; height: 24px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            transition: background 0.2s, color 0.2s;
            line-height: 1;
        }
        .imp-notif-dismiss:hover {
            background: rgba(239,68,68,0.2);
            color: #ef4444;
        }
        .no-imp-notifs {
            grid-column: 1 / -1;
            padding: 30px;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.95rem;
            background: var(--bg-secondary);
            border: 1px dashed var(--border-color);
            border-radius: 16px;
        }
        @media (max-width: 600px) {
            .notif-cards-grid { grid-template-columns: 1fr; }
        }

        /* ── Routine widget ── */
        .routine-scroll-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: rgba(0,0,0,0.12);
        }
        .routine-scroll-hint {
            display: none;
            font-size: 0.72rem;
            color: var(--text-secondary);
            text-align: center;
            padding: 6px 8px 0;
        }
        .routine-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; min-width: 560px; }
        .routine-table th,
        .routine-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .routine-table th {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            background: rgba(168,85,247,0.04);
        }
        .routine-mobile-cards { display: none; }
        .routine-day-label {
            font-weight: 700;
            color: var(--accent-secondary);
            font-size: 0.85rem;
            margin: 12px 0 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border-color);
        }
        .routine-day-label:first-child { margin-top: 0; }
        .routine-card {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 10px;
        }
        .routine-card-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 4px 0;
            font-size: 0.84rem;
        }
        .routine-card-row span:first-child {
            color: var(--text-secondary);
            flex-shrink: 0;
        }
        .routine-card-row span:last-child {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }
        .routine-type-pill {
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .routine-type-pill.theory { background: rgba(16,185,129,0.12); color: #10b981; }
        .routine-type-pill.lab { background: rgba(168,85,247,0.12); color: var(--accent-primary); }

        /* ── Mobile drawer overlay ── */
        .drawer-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            top: var(--landing-top-nav-offset, 64px);
            background: rgba(7, 11, 20, 0.65);
            backdrop-filter: blur(2px);
            /* Keep the backdrop BELOW the drawer so menu items remain clickable */
            z-index: 790;
        }
        .drawer-backdrop.visible { display: block; }

        .page-content {
            width: 100%;
            max-width: 100vw;
            overflow-x: clip;
        }
        .dashboard-container,
        .important-notifs-section {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            .routine-scroll-hint { display: block; }

            /* Menu button lives inside the top navbar — never covers welcome text */
            .top-navbar {
                z-index: 920;
            }
            .drawer-section {
                position: fixed;
                top: 10px;
                left: 58px;
                z-index: 925;
                margin: 0;
            }
            .drawer-btn {
                margin: 0 !important;
                padding: 8px 12px;
                font-size: 0.85rem;
                box-shadow: 0 2px 14px rgba(0, 0, 0, 0.35);
            }
            .drawer-btn.open {
                border-color: var(--accent-primary);
                box-shadow: var(--glow-shadow);
            }

            .drawer-backdrop {
                top: 64px;
                z-index: 905;
            }
            .drawer-dropdown {
                position: fixed !important;
                top: 64px !important;
                left: 0 !important;
                right: auto !important;
                bottom: 0 !important;
                width: min(300px, 88vw) !important;
                max-height: none !important;
                height: calc(100dvh - 64px) !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                border-radius: 0 16px 16px 0 !important;
                z-index: 910 !important;
                transform: translateX(-105%) !important;
                opacity: 1 !important;
                pointer-events: none;
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .drawer-dropdown.visible {
                transform: translateX(0) !important;
                pointer-events: all;
            }

            .page-content {
                padding-top: var(--landing-top-nav-offset, 64px);
            }
            .page-content > .dashboard-container:first-of-type {
                padding-top: 18px;
            }
            .welcome-header {
                margin-bottom: 20px;
            }
            .welcome-header h1 { font-size: 1.35rem; word-break: break-word; }
            .welcome-title-row { gap: 8px; }
            .session-status { padding: 6px 10px; font-size: 0.75rem; }
            .stat-card .val { font-size: 1.5rem; flex-wrap: wrap; }
            .notifs-section-header { flex-wrap: wrap; gap: 8px; }
            .notifs-section-title { font-size: 1.1rem; }
        }

        @media (max-width: 600px) {
            .routine-desktop { display: none !important; }
            .routine-mobile-cards { display: block !important; }
            .routine-scroll-hint { display: none; }
            .drawer-section {
                top: 9px;
                left: 50px;
            }
            .drawer-btn {
                padding: 7px 10px;
                font-size: 0.8rem;
            }
            .drawer-dropdown {
                width: min(320px, 92vw) !important;
            }
            .drawer-item {
                font-size: 0.88rem;
                padding: 11px 12px;
            }
            .drawer-section-label {
                font-size: 0.65rem;
                padding: 8px 12px 4px;
            }
        }
    </style>
    <link rel="stylesheet" href="responsive.css">
</head>
<body class="landing-page">

    <!-- ░░ Ambient Background ░░ -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- ════════════════════════════════════
         TOP NAVIGATION BAR
    ════════════════════════════════════ -->
    <?php include 'includes/global_nav.php'; ?>

    <!-- Drawer backdrop (mobile) -->
    <div class="drawer-backdrop" id="drawerBackdrop" aria-hidden="true"></div>

    <!-- ════════════════════════════════════
         DRAWER BUTTON + DROPDOWN
    ════════════════════════════════════ -->
    <div class="drawer-section" id="drawerSection">

        <!-- Hamburger trigger -->
        <button class="drawer-btn" id="drawerToggleBtn" aria-label="Open navigation menu" aria-expanded="false">
            <div class="hamburger-icon" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <span class="drawer-btn-label">Menu</span>
        </button>

        <!-- Dropdown list -->
        <div class="drawer-dropdown" id="drawerDropdown" role="menu">
            <span class="drawer-section-label">Account</span>

            <!-- My Profile – visible to all roles -->
            <button class="drawer-item" id="myProfileBtn" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                My Profile
            </button>

            <div class="drawer-divider"></div>

            <?php if ($role === 'student' || $role === 'guest'): ?>
            <!-- ── STUDENT/GUEST SECTION ── -->
            <span class="drawer-section-label">Academic</span>
            <a href="student_quiz.php" class="drawer-item student-section" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4m0 0L8 8m4-4l4 4"/><path d="M12 16c-3.5 0-6 2-6 4v2h12v-2c0-2-2.5-4-6-4z"/></svg>
                My Quiz
            </a>
            <a href="grade_sheet.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                Grade Sheet
            </a>

            <a href="routine.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                Routine
            </a>

            <?php if ($role !== 'guest'): ?>
            <a href="advising.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Advising
            </a>

            <a href="student_consult.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Consult with Teacher
            </a>
            <?php endif; ?>

            <a href="student_assignments.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                My Assignments
            </a>

            <a href="student_materials.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                Course Materials
            </a>

            <?php if ($role !== 'guest'): ?>
            <a href="communication_media.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Central Communication Media
            </a>

            <a href="manage_notifications.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                Manage Notifications
            </a>
            <?php endif; ?>

            <a href="student_scores.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Current Score
            </a>

            <a href="student_attendance.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                    <path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>
                </svg>
                Attendance
            </a>

            <a href="app_support.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="10 8 16 12 10 16 10 8"></polygon>
                </svg>
                App Support
            </a>

            <?php elseif ($role === 'teacher'): ?>
            <!-- ── TEACHER-ONLY SECTION ── -->
            <span class="drawer-section-label">Faculty Panel</span>

            <a href="add_course.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Add Course
            </a>

            <a href="teacher_messages.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Student messages
            </a>

            <a href="teacher_materials.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                    <polyline points="13 2 13 9 20 9"/>
                    <line x1="12" y1="11" x2="12" y2="17"/>
                    <line x1="9" y1="14" x2="15" y2="14"/>
                </svg>
                Add Course Materials
            </a>

            <a href="communication_media.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Central Communication Media
            </a>

            <a href="manage_notifications.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                Manage Notifications
            </a>

            <a href="teacher_grading.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Submit Current Score
            </a>

            <a href="teacher_attendance.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Attendance
            </a>

            <a href="teacher_courses.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                Courses
            </a>

            <a href="teacher_status.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Course Status
            </a>

            <a href="deploy_assignment.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Deploy Assignments
            </a>

            <a href="deploy_quiz.php" class="drawer-item teacher-section" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Deploy Quiz
            </a>

            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-primary); width: 18px; height: 18px;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span>Open Advising Portal</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="advisingToggleBtn" <?= $advisingOpen ? 'checked' : '' ?>>
                    <span class="switch-slider"></span>
                </label>
            </div>

            <a href="app_support.php" class="drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="10 8 16 12 10 16 10 8"></polygon>
                </svg>
                App Support
            </a>

            <?php endif; ?>

            <div class="drawer-divider"></div>
            <span class="drawer-section-label">Accessibility</span>

            <!-- Accessibility display toggles -->
            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-primary); width: 18px; height: 18px;">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M7 12h10"/>
                        <path d="M12 7v10"/>
                    </svg>
                    <span>High Contrast Mode</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="highContrastToggleBtn">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-secondary); width: 18px; height: 18px;">
                        <path d="M5 12h14"/>
                        <path d="M12 5l7 7-7 7"/>
                    </svg>
                    <span>Reduce Motion</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="reduceMotionToggleBtn">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-primary); width: 18px; height: 18px;">
                        <rect x="4" y="4" width="16" height="16" rx="2"/>
                        <path d="M9 9h6v6H9z"/>
                    </svg>
                    <span>Keyboard Focus States</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="keyboardFocusToggleBtn">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-secondary); width: 18px; height: 18px;">
                        <path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        <path d="M12 19v2"/>
                    </svg>
                    <span>Text to speech</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="textToSpeechToggleBtn">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <!-- Language Toggle Switch -->
            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-secondary); width: 18px; height: 18px;">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <span>English/Bangla</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="langToggleBtn">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <!-- Dyslexia Font Toggle Switch -->
            <div class="drawer-item preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-primary); width: 18px; height: 18px;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span>Dyslexia-friendly Font</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="dyslexiaToggleBtn">
                    <span class="switch-slider"></span>
                </label>
            </div>

            <div class="drawer-divider"></div>
            <a href="logout.php" class="drawer-item" role="menuitem" style="color: #ef4444;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Log Out
            </a>

        </div>
    </div>

    <!-- ════════════════════════════════════
         PROFILE MODAL
    ════════════════════════════════════ -->
    <div class="modal-overlay" id="profileModalOverlay" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
        <div class="profile-modal" id="profileModal">

            <!-- Close button -->
            <button class="modal-close-btn" id="closeProfileModal" aria-label="Close profile">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>

            <!-- Avatar + name header -->
            <div class="modal-avatar-wrap">
                <div class="modal-avatar"><?= $initials ?></div>
                <div class="modal-full-name" id="profileModalTitle"><?= htmlspecialchars($fullName) ?></div>
                <span class="modal-badge badge-role <?= $badgeClass ?>"><?= strtoupper($role) ?></span>
            </div>

            <!-- Profile info rows -->
            <ul class="profile-info-list" aria-label="Profile details">
                <li class="profile-info-row">
                    <span class="profile-info-label">Registrant ID</span>
                    <span class="profile-info-val"><?= htmlspecialchars($userId) ?></span>
                </li>
                <li class="profile-info-row">
                    <span class="profile-info-label">Full Name</span>
                    <span class="profile-info-val"><?= htmlspecialchars($fullName) ?></span>
                </li>
                <li class="profile-info-row">
                    <span class="profile-info-label">Role</span>
                    <span class="profile-info-val"><?= htmlspecialchars(ucfirst($role)) ?></span>
                </li>
                <li class="profile-info-row">
                    <span class="profile-info-label">Department</span>
                    <span class="profile-info-val"><?= htmlspecialchars($department) ?></span>
                </li>
                <li class="profile-info-row">
                    <span class="profile-info-label">Email</span>
                    <span class="profile-info-val" style="font-size:0.8rem;"><?= htmlspecialchars($email) ?></span>
                </li>
                <li class="profile-info-row">
                    <span class="profile-info-label">Verification</span>
                    <span class="profile-info-val <?= ($role === 'guest') ? 'unverified' : 'verified' ?>">
                        <?= ($role === 'guest') ? 'Unverified Guest' : 'Verified Domain' ?>
                    </span>
                </li>
                <li class="profile-info-row">
                    <span class="profile-info-label">Clearance</span>
                    <span class="profile-info-val" style="font-size:0.8rem;"><?= htmlspecialchars($clearanceTitle) ?></span>
                </li>
            </ul>

            <div style="margin-top: 24px;">
                <a href="logout.php" style="display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px; background: rgba(239,68,68,0.1); color: #ef4444; border-radius: 12px; text-decoration: none; font-weight: 600; transition: background 0.2s;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    Log Out
                </a>
            </div>

        </div>
    </div>

    <!-- ════════════════════════════════════
         PAGE CONTENT
    ════════════════════════════════════ -->
    <div class="page-content">
        
        <?php if ($role === 'student' || $role === 'guest'): ?>
        <div class="dashboard-container">
            <div class="welcome-header">
                <div class="welcome-title-row">
                    <h1>Welcome back, <?= htmlspecialchars(explode(' ', trim($fullName))[0]) ?>!</h1>
                    <span class="session-status"><span class="session-status-dot"></span>Active</span>
                </div>
                <p>Here's an overview of your academic progress.</p>
            </div>
            
            <?php if (isset($paymentWarn) && $paymentWarn): ?>
                <div class="alert-box alert-error" style="margin-bottom: 24px; border: 1px solid rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.08); display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-radius: 16px; color: #fff;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" style="flex-shrink:0;">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <div style="flex-grow: 1; font-size: 0.92rem; line-height: 1.4;">
                        <strong style="color: #ef4444;">Payment Due:</strong> You have unpaid course fees for the <strong><?= htmlspecialchars($activeSemester['label']) ?></strong> semester.
                        Please download your payment slip and clear the payment before <strong style="color: #ef4444;"><?= $paymentDeadlineStr ?></strong> to avoid account freeze.
                    </div>
                    <a href="student_payment.php" class="btn-primary" style="padding: 8px 16px; font-size: 0.85rem; text-decoration: none; border-radius: 10px; background: linear-gradient(135deg, #a855f7, #ec4899); box-shadow: 0 4px 12px rgba(168, 85, 247, 0.3); border:none; display:inline-block; font-weight:700; color:#fff; white-space:nowrap; transition:all 0.2s;">
                        Pay Now / Slip
                    </a>
                </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                
                <!-- Credits Completed -->
                <div class="stat-card">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                    <h3 class="translate" data-key="Credits Completed">Credits Completed</h3>
                    <div class="val">
                        <?= $completed_credits ?> <span class="sub-val">/ <?= $required_credits ?></span>
                    </div>
                </div>

                <!-- Current CGPA -->
                <div class="stat-card card-cgpa">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                    </div>
                    <h3 class="translate" data-key="Current CGPA">Current CGPA</h3>
                    <div class="val"><?= htmlspecialchars($cgpa) ?></div>
                </div>

                <!-- Current Semester -->
                <div class="stat-card card-sem">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <h3 class="translate" data-key="Current Semester">Current Semester</h3>
                    <div class="val" style="font-size: 1.6rem;"><?= htmlspecialchars($current_semester) ?></div>
                </div>

                <!-- Joining Semester -->
                <div class="stat-card card-sem">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <h3 class="translate" data-key="Joining Semester">Joining Semester</h3>
                    <div class="val" style="font-size: 1.6rem;"><?= htmlspecialchars($joining_semester) ?></div>
                </div>

            </div>
        </div>

        <?php
        // Fetch important notifications for student (Quiz, Assignment, Deadline)
        // NOTE: filter on deleted_from_dashboard only – bell-deletes do NOT remove these cards.
        $importantNotifs = [];
        $routineSchedule  = [];
        $daysLanding      = ["Sunday","Monday","Tuesday","Wednesday","Thursday","Saturday"];
        $hasRoutineData   = false;
        if ($role === 'student') {
            // Priority-sorted: quiz(1) > deadline(2) > assignment(3)
            $stmtImp = $pdo->prepare("
                SELECT *,
                  CASE
                    WHEN type LIKE '%quiz%'     OR message LIKE '%quiz%'     THEN 1
                    WHEN type LIKE '%deadline%' OR message LIKE '%deadline%' THEN 2
                    ELSE 3
                  END AS priority_order
                FROM notifications
                WHERE user_id = ?
                  AND deleted_from_dashboard = 0
                  AND (
                    type LIKE '%quiz%'       OR type LIKE '%assignment%'    OR type LIKE '%deadline%'
                    OR message LIKE '%quiz%' OR message LIKE '%assignment%' OR message LIKE '%deadline%'
                  )
                ORDER BY priority_order ASC, created_at DESC
                LIMIT 20
            ");
            $stmtImp->execute([$_SESSION['user_pk']]);
            $importantNotifs = $stmtImp->fetchAll(PDO::FETCH_ASSOC);
            $importantNotifs = array_values(array_filter(
                $importantNotifs,
                fn ($notification) => !shouldHideImportantNotificationCard($pdo, $notification)
            ));

            // ── Routine inline widget data ──
            try {
                $stmtSec = $pdo->prepare("
                    SELECT cs.section_no, cs.room_no, cs.theory_day_1, cs.theory_day_2,
                           cs.theory_time_slot, cs.lab_day, cs.lab_time_slot, c.code
                    FROM enrollments e
                    JOIN course_sections cs ON e.section_id = cs.id
                    JOIN courses c ON cs.course_id = c.id
                    WHERE e.student_id = ?
                ");
                $stmtSec->execute([$_SESSION['user_pk']]);
                $enrolledSecLanding = $stmtSec->fetchAll(PDO::FETCH_ASSOC);
                foreach ($daysLanding as $d) $routineSchedule[$d] = [];
                foreach ($enrolledSecLanding as $sec) {
                    $ci = [
                        'code'       => $sec['code'],
                        'section_no' => str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT),
                        'room'       => $sec['room_no'] ?? '–',
                        'type'       => 'Theory'
                    ];
                    if (!empty($sec['theory_day_1']) && isset($routineSchedule[$sec['theory_day_1']]))
                        $routineSchedule[$sec['theory_day_1']][] = array_merge($ci, ['time' => $sec['theory_time_slot'] ?? '']);
                    if (!empty($sec['theory_day_2']) && isset($routineSchedule[$sec['theory_day_2']]))
                        $routineSchedule[$sec['theory_day_2']][] = array_merge($ci, ['time' => $sec['theory_time_slot'] ?? '']);
                    if (!empty($sec['lab_day']) && isset($routineSchedule[$sec['lab_day']])) {
                        $li = $ci; $li['type'] = 'Lab'; $li['time'] = $sec['lab_time_slot'] ?? '';
                        $routineSchedule[$sec['lab_day']][] = $li;
                    }
                }
                foreach ($daysLanding as $d) {
                    usort($routineSchedule[$d], function($a, $b) {
                        $ta = strtotime(explode('-', $a['time'])[0] ?? '0');
                        $tb = strtotime(explode('-', $b['time'])[0] ?? '0');
                        return ($ta ?: 0) - ($tb ?: 0);
                    });
                }
                foreach ($daysLanding as $d) if (!empty($routineSchedule[$d])) { $hasRoutineData = true; break; }
            } catch (Exception $ex) {
                $routineSchedule = []; $hasRoutineData = false;
            }
        }
        ?>

        <?php if ($role === 'student' && !empty($importantNotifs)): ?>
        <div class="dashboard-container">
            <div class="important-notifs-section">
                <div class="notifs-section-header">
                    <div class="notifs-section-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        Important Notifications
                    </div>
                </div>
                <div class="notif-cards-grid" id="impNotifGrid">
                    <?php foreach ($importantNotifs as $n):
                        $type = strtolower($n['type'] ?? '');
                        $msg  = $n['message'] ?? '';

                        // Determine priority and label
                        if (strpos($type, 'quiz') !== false || stripos($msg, 'quiz') !== false) {
                            $pClass = 'high';   $pLabel = '⚡ High Priority — Quiz';
                        } elseif (strpos($type, 'deadline') !== false || stripos($msg, 'deadline') !== false) {
                            $pClass = 'medium'; $pLabel = '⏰ Medium Priority — Deadline';
                        } else {
                            $pClass = 'low';    $pLabel = '📋 Low Priority — Assignment';
                        }

                        // Title from message first line
                        $lines = explode("\n", trim($msg));
                        $title = mb_strimwidth($lines[0], 0, 80, '...');
                        $body  = isset($lines[1]) ? mb_strimwidth(implode(' ', array_slice($lines, 1)), 0, 120, '...') : '';
                        $timeAgo = date('M j, g:i A', strtotime($n['created_at']));
                        $linkUrl = $n['link_url'] ?? '#';
                    ?>
                    <div class="imp-notif-card priority-<?= $pClass ?>" id="impNotif_<?= $n['id'] ?>" onclick="window.location.href='<?= htmlspecialchars($linkUrl) ?>'" style="cursor: pointer;">
                        <button class="imp-notif-dismiss" onclick="event.stopPropagation(); dismissNotif(event, <?= $n['id'] ?>)" title="Dismiss">✕</button>
                        <span class="notif-badge-priority <?= $pClass ?>"><?= $pLabel ?></span>
                        <div class="imp-notif-title"><?= htmlspecialchars($title) ?></div>
                        <?php if ($body): ?>
                        <div class="imp-notif-body"><?= htmlspecialchars($body) ?></div>
                        <?php endif; ?>
                        <div class="imp-notif-time">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?= $timeAgo ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($role === 'student'): ?>
        <!-- ─── ROUTINE WIDGET ─── -->
        <div class="dashboard-container" style="margin-top:0;">
            <div class="important-notifs-section">
                <div class="notifs-section-header">
                    <div class="notifs-section-title">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Routine
                    </div>
                    <a href="routine.php"
                       style="font-size:0.82rem; color:var(--accent-primary); text-decoration:none; font-weight:600; opacity:0.85; transition:opacity .2s;"
                       onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.85'">View Full →</a>
                </div>
                <?php if (!$hasRoutineData): ?>
                <div style="padding:24px; text-align:center; color:var(--text-secondary); font-size:0.9rem; font-style:italic;">
                    No courses enrolled yet. Visit <a href="advising.php" style="color:var(--accent-primary); text-decoration:none;">Advising</a> to register for classes.
                </div>
                <?php else: ?>
                <!-- Tablet / desktop: scrollable table -->
                <div class="routine-desktop">
                    <div class="routine-scroll-wrap scroll-x-touch">
                        <table class="routine-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Time</th>
                                    <th>Course</th>
                                    <th>Sec</th>
                                    <th>Room</th>
                                    <th>Type</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            foreach ($daysLanding as $rDay):
                                if (empty($routineSchedule[$rDay])) continue;
                                $rFirst = true;
                                foreach ($routineSchedule[$rDay] as $rCls):
                                    $rIsLab = ($rCls['type'] === 'Lab');
                            ?>
                                <tr>
                                    <td style="font-weight:700; color:var(--accent-secondary);"><?= $rFirst ? htmlspecialchars($rDay) : '' ?></td>
                                    <td style="color:var(--text-secondary); font-size:0.82rem; white-space:nowrap;"><?= htmlspecialchars($rCls['time']) ?></td>
                                    <td style="font-weight:600;"><?= htmlspecialchars($rCls['code']) ?></td>
                                    <td style="color:var(--text-secondary);"><?= htmlspecialchars($rCls['section_no']) ?></td>
                                    <td style="color:var(--text-secondary);"><?= htmlspecialchars($rCls['room']) ?></td>
                                    <td>
                                        <span class="routine-type-pill <?= $rIsLab ? 'lab' : 'theory' ?>"><?= $rCls['type'] ?></span>
                                    </td>
                                </tr>
                            <?php $rFirst = false; endforeach; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="routine-scroll-hint">← Swipe left/right to see all columns →</p>
                </div>

                <!-- Phone: stacked cards (no horizontal scroll needed) -->
                <div class="routine-mobile-cards">
                    <?php foreach ($daysLanding as $rDay):
                        if (empty($routineSchedule[$rDay])) continue;
                    ?>
                        <div class="routine-day-label"><?= htmlspecialchars($rDay) ?></div>
                        <?php foreach ($routineSchedule[$rDay] as $rCls):
                            $rIsLab = ($rCls['type'] === 'Lab');
                        ?>
                        <div class="routine-card">
                            <div class="routine-card-row"><span>Time</span><span><?= htmlspecialchars($rCls['time']) ?></span></div>
                            <div class="routine-card-row"><span>Course</span><span><?= htmlspecialchars($rCls['code']) ?></span></div>
                            <div class="routine-card-row"><span>Section</span><span><?= htmlspecialchars($rCls['section_no']) ?></span></div>
                            <div class="routine-card-row"><span>Room</span><span><?= htmlspecialchars($rCls['room']) ?></span></div>
                            <div class="routine-card-row"><span>Type</span>
                                <span class="routine-type-pill <?= $rIsLab ? 'lab' : 'theory' ?>"><?= $rCls['type'] ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php elseif ($role === 'teacher'): ?>
        <div class="dashboard-container">
            <div class="welcome-header">
                <div class="welcome-title-row">
                    <h1>Welcome back, <?= htmlspecialchars(explode(' ', trim($fullName))[0]) ?>!</h1>
                    <span class="session-status"><span class="session-status-dot"></span>Active</span>
                </div>
                <p>Here's an overview of your current academic term.</p>
            </div>
            
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
                
                <!-- Current Semester -->
                <div class="stat-card card-sem">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <h3 class="translate" data-key="Current Semester">Current Semester</h3>
                    <div class="val" style="font-size: 2rem;"><?= htmlspecialchars($current_semester) ?></div>
                </div>

                <!-- Joining Semester -->
                <div class="stat-card card-sem">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <h3 class="translate" data-key="Joining Semester">Joining Semester</h3>
                    <div class="val" style="font-size: 2rem;"><?= htmlspecialchars($joining_semester) ?></div>
                </div>

            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ════════════════════════════════════
         JAVASCRIPT
    ════════════════════════════════════ -->
    <script>
    function deleteNotif(e, id) {
        e.preventDefault();
        e.stopPropagation();
        fetch('delete_notification.php?id=' + id)
        .then(res => res.text())
        .then(() => {
            const el = document.getElementById('notif_' + id);
            if(el) el.remove();
        });
    }

    function deleteAllNotifs(e) {
        e.preventDefault();
        e.stopPropagation();
        fetch('delete_all_notifications.php')
        .then(res => res.text())
        .then(() => {
            document.getElementById('notifList').innerHTML = '<div style="padding:16px; text-align:center; color:var(--text-secondary); font-size:0.9rem;">No notifications.</div>';
        });
    }

    // Dismiss Important Notification Card from Dashboard
    function dismissNotif(e, id) {
        e.preventDefault();
        e.stopPropagation();
        const card = document.getElementById('impNotif_' + id);
        if (card) {
            card.style.transition = 'opacity 0.25s, transform 0.25s';
            card.style.opacity = '0';
            card.style.transform = 'scale(0.95)';
            setTimeout(() => {
                card.remove();
                // If no cards remain, hide the whole section
                const grid = document.getElementById('impNotifGrid');
                if (grid && grid.children.length === 0) {
                    const section = grid.closest('.dashboard-container');
                    if (section) section.remove();
                }
            }, 260);
        }
        // Also delete from DB via backend
        // source=dashboard: only marks deleted_from_dashboard, bell entry survives
        fetch('delete_notification.php?id=' + id + '&source=dashboard').catch(() => {});
    }

    // Notifications Dropdown
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    if(notifBtn) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
            // Check if we need to mark them as read visually
            const badge = notifBtn.querySelector('.notif-badge');
            if(badge && notifDropdown.classList.contains('show')) {
                // Call an endpoint to mark as read
                fetch('mark_notifications_read.php', {method: 'POST'})
                .then(res => res.text())
                .then(() => { badge.style.display = 'none'; });
            }
        });
        document.addEventListener('click', (e) => {
            if(!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('show');
            }
        });
    }

    // Drawer Logic
    document.addEventListener('DOMContentLoaded', () => {
        const topNavbar = document.querySelector('.top-navbar');
        const syncTopNavbarOffset = () => {
            if (!topNavbar) return;
            document.documentElement.style.setProperty('--landing-top-nav-offset', `${Math.ceil(topNavbar.getBoundingClientRect().height)}px`);
        };

        syncTopNavbarOffset();

        if (topNavbar && typeof ResizeObserver !== 'undefined') {
            const topNavbarResizeObserver = new ResizeObserver(syncTopNavbarOffset);
            topNavbarResizeObserver.observe(topNavbar);
        } else {
            window.addEventListener('resize', syncTopNavbarOffset);
        }

        /* ── Drawer toggle ── */
        const drawerBtn      = document.getElementById('drawerToggleBtn');
        const drawerDropdown = document.getElementById('drawerDropdown');
        const drawerBackdrop = document.getElementById('drawerBackdrop');
        const isMobileDrawer = window.matchMedia('(max-width: 768px)');

        const drawerLabel = drawerBtn.querySelector('.drawer-btn-label');

        function openDrawer() {
            drawerDropdown.classList.add('visible');
            drawerBtn.classList.add('open');
            drawerBtn.setAttribute('aria-expanded', 'true');
            drawerBtn.setAttribute('aria-label', 'Close navigation menu');
            if (drawerLabel) drawerLabel.textContent = 'Close';
            // Backdrop is intended for MOBILE only. On desktop it can block clicks on the drawer.
            if (drawerBackdrop && isMobileDrawer.matches) drawerBackdrop.classList.add('visible');
            document.body.classList.add('drawer-open');
        }

        function closeDrawer() {
            drawerDropdown.classList.remove('visible');
            drawerBtn.classList.remove('open');
            drawerBtn.setAttribute('aria-expanded', 'false');
            drawerBtn.setAttribute('aria-label', 'Open navigation menu');
            if (drawerLabel) drawerLabel.textContent = 'Menu';
            if (drawerBackdrop) drawerBackdrop.classList.remove('visible');
            document.body.classList.remove('drawer-open');
        }

        drawerBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            drawerDropdown.classList.contains('visible') ? closeDrawer() : openDrawer();
        });

        if (drawerBackdrop) {
            drawerBackdrop.addEventListener('click', closeDrawer);
        }

        // If the viewport grows (mobile → desktop) while the drawer is open, ensure backdrop is removed.
        if (isMobileDrawer && isMobileDrawer.addEventListener) {
            isMobileDrawer.addEventListener('change', () => {
                if (!isMobileDrawer.matches && drawerBackdrop) drawerBackdrop.classList.remove('visible');
            });
        }

        drawerDropdown.querySelectorAll('a.drawer-item').forEach((link) => {
            link.addEventListener('click', closeDrawer);
        });

        // Close drawer when clicking anywhere outside
        document.addEventListener('click', (e) => {
            const section = document.getElementById('drawerSection');
            if (section && !section.contains(e.target) && !drawerBackdrop?.contains(e.target)) {
                closeDrawer();
            }
        });

        // Close drawer on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDrawer();
                closeProfileModal();
            }
        });

        /* ── Profile modal ── */
        const overlay          = document.getElementById('profileModalOverlay');
        const myProfileBtn     = document.getElementById('myProfileBtn');
        const closeProfileBtn  = document.getElementById('closeProfileModal');

        function openProfileModal() {
            closeDrawer();
            overlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
            // Delay focus for accessibility after animation
            setTimeout(() => closeProfileBtn.focus(), 100);
        }

        function closeProfileModal() {
            overlay.classList.remove('visible');
            document.body.style.overflow = '';
        }

        myProfileBtn.addEventListener('click', openProfileModal);
        closeProfileBtn.addEventListener('click', closeProfileModal);

        // Click overlay background to close
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeProfileModal();
        });

        // Prevent preference toggle clicks from closing the drawer
        document.querySelectorAll('.preference-item').forEach(item => {
            item.addEventListener('click', (e) => e.stopPropagation());
        });

        // Advising Portal Toggle
        const advisingToggleBtn = document.getElementById('advisingToggleBtn');
        if (advisingToggleBtn) {
            advisingToggleBtn.addEventListener('change', function() {
                const isOpen = this.checked;
                fetch('toggle_advising.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ advising_open: isOpen })
                })
                .then(response => response.json())
                .then(data => {
                    if(!data.success) {
                        alert(data.message || 'Failed to toggle advising state.');
                        this.checked = !isOpen; // Revert
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred.');
                    this.checked = !isOpen; // Revert
                });
            });
        }

        // Universal Search Logic
        const searchInput = document.getElementById('universalSearchInput');
        const searchDropdown = document.getElementById('universalSearchDropdown');
        const searchFilterToggle = document.getElementById('searchFilterToggle');
        const searchFilterPanel = document.getElementById('searchFilterPanel');
        const searchFilterChips = document.querySelectorAll('.search-chip');
        let searchTimeout = null;
        let selectedSearchFilter = 'features';

        if (searchInput && searchDropdown) {
            const searchFilterLabels = {
                messages: 'Messages',
                materials: 'Course Materials',
                routine: 'Routine',
                features: 'App Features'
            };

            const getSearchIcon = (type) => {
                if (type === 'Feature') {
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
                }
                if (type === 'Message') {
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
                }
                if (type === 'Course Material') {
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                }
                if (type === 'Routine') {
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
                }
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/></svg>';
            };

            const renderSearchResults = (data) => {
                if (data.length === 0) {
                    searchDropdown.innerHTML = '<div class="search-loading">No results found.</div>';
                    return;
                }

                searchDropdown.innerHTML = '';
                data.forEach(item => {
                    const a = document.createElement('a');
                    a.href = item.url;
                    a.className = 'search-result-item';
                    a.innerHTML = `
                        <div class="search-result-icon">${getSearchIcon(item.type)}</div>
                        <div class="search-result-text">
                            <span class="search-result-title">${item.title}</span>
                            <span class="search-result-type">${item.type}</span>
                            ${item.meta ? `<span class="search-result-meta">${item.meta}</span>` : ''}
                        </div>
                    `;
                    searchDropdown.appendChild(a);
                });
            };

            const runSearch = () => {
                const query = searchInput.value.trim();

                clearTimeout(searchTimeout);

                if (query.length === 0) {
                    searchDropdown.classList.remove('active');
                    searchDropdown.innerHTML = '';
                    return;
                }

                searchDropdown.classList.add('active');
                searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';

                searchTimeout = setTimeout(() => {
                    fetch(`universal_search.php?q=${encodeURIComponent(query)}&filter=${encodeURIComponent(selectedSearchFilter)}`)
                        .then(response => response.json())
                        .then(renderSearchResults)
                        .catch(() => {
                            searchDropdown.innerHTML = '<div class="search-loading">Error fetching results.</div>';
                        });
                }, 200);
            };

            searchInput.addEventListener('input', function() {
                runSearch();
            });

            if (searchFilterToggle && searchFilterPanel) {
                searchFilterToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isOpen = searchFilterPanel.classList.toggle('active');
                    searchFilterToggle.classList.toggle('active', isOpen);
                    searchFilterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
            }

            searchFilterChips.forEach(chip => {
                chip.addEventListener('click', function(e) {
                    e.stopPropagation();
                    selectedSearchFilter = this.dataset.filter;
                    searchFilterToggle?.setAttribute('aria-label', `Search type: ${searchFilterLabels[selectedSearchFilter] || 'App Features'}`);
                    searchFilterChips.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    searchFilterPanel.classList.remove('active');
                    searchFilterToggle.classList.remove('active');
                    searchFilterToggle.setAttribute('aria-expanded', 'false');
                    if (searchInput.value.trim().length > 0) {
                        runSearch();
                    }
                });
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target) && !searchFilterPanel?.contains(e.target) && !searchFilterToggle?.contains(e.target)) {
                    searchDropdown.classList.remove('active');
                    searchFilterPanel?.classList.remove('active');
                    searchFilterToggle?.classList.remove('active');
                    searchFilterToggle?.setAttribute('aria-expanded', 'false');
                }
            });
            
            // Show dropdown again if focused and has value
            searchInput.addEventListener('focus', function() {
                if (this.value.trim().length > 0 && searchDropdown.innerHTML !== '') {
                    searchDropdown.classList.add('active');
                }
            });
        }

    });
    </script>

</body>
</html>
