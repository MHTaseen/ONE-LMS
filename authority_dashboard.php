<?php
// authority_dashboard.php - Control panel for administrative authorities
session_start();
require_once 'config.php';
require_once 'includes/notification_system.php';

// Auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'authority') {
    header('Location: authority_portal.php');
    exit();
}

$fullName = $_SESSION['full_name'];
$email = $_SESSION['email'];

$error = '';
$success = '';

$activeTab = $_GET['tab'] ?? 'accounts';

// Student Payment Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $student_id = intval($_POST['student_id']);
    $semester_id = intval($_POST['semester_id']);
    
    $chk = $pdo->prepare("SELECT id FROM semester_payments WHERE student_id = ? AND semester_id = ?");
    $chk->execute([$student_id, $semester_id]);
    if ($chk->fetch()) {
        $stmt = $pdo->prepare("UPDATE semester_payments SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE student_id = ? AND semester_id = ?");
        $stmt->execute([$student_id, $semester_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO semester_payments (student_id, semester_id, status, paid_at) VALUES (?, ?, 'paid', CURRENT_TIMESTAMP)");
        $stmt->execute([$student_id, $semester_id]);
    }
    
    // Unfreeze user on payment
    $upd = $pdo->prepare("UPDATE users SET is_frozen = 0 WHERE id = ?");
    $upd->execute([$student_id]);
    
    // Send confirmation notification to the student
    $semStmt = $pdo->prepare("SELECT label FROM semesters WHERE id = ?");
    $semStmt->execute([$semester_id]);
    $semesterLabel = $semStmt->fetchColumn() ?: "the semester";
    
    $notifMsg = "Your payment for $semesterLabel has been confirmed.";
    sendNotification($pdo, $student_id, 'payment', $notifMsg, 'student_payment.php');
    
    $success = "Student payment recorded and account unfrozen.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_unpaid'])) {
    $student_id = intval($_POST['student_id']);
    $semester_id = intval($_POST['semester_id']);
    
    $stmt = $pdo->prepare("UPDATE semester_payments SET status = 'unpaid', paid_at = NULL, unfrozen_by_authority = 0 WHERE student_id = ? AND semester_id = ?");
    $stmt->execute([$student_id, $semester_id]);
    
    $success = "Student status set to Unpaid.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_freeze'])) {
    $student_id = intval($_POST['student_id']);
    $semester_id = intval($_POST['semester_id']);
    
    $stmtF = $pdo->prepare("SELECT is_frozen FROM users WHERE id = ?");
    $stmtF->execute([$student_id]);
    $isFrozenCurrent = $stmtF->fetchColumn();
    $newFreeze = $isFrozenCurrent ? 0 : 1;
    
    $upd = $pdo->prepare("UPDATE users SET is_frozen = ? WHERE id = ?");
    $upd->execute([$newFreeze, $student_id]);
    
    if ($newFreeze == 0) {
        $chk = $pdo->prepare("SELECT id FROM semester_payments WHERE student_id = ? AND semester_id = ?");
        $chk->execute([$student_id, $semester_id]);
        if ($chk->fetch()) {
            $updSp = $pdo->prepare("UPDATE semester_payments SET unfrozen_by_authority = 1 WHERE student_id = ? AND semester_id = ?");
            $updSp->execute([$student_id, $semester_id]);
        } else {
            $insSp = $pdo->prepare("INSERT INTO semester_payments (student_id, semester_id, status, unfrozen_by_authority) VALUES (?, ?, 'unpaid', 1)");
            $insSp->execute([$student_id, $semester_id]);
        }
        $success = "Student account manually unfrozen.";
    } else {
        $updSp = $pdo->prepare("UPDATE semester_payments SET unfrozen_by_authority = 0 WHERE student_id = ? AND semester_id = ?");
        $updSp->execute([$student_id, $semester_id]);
        $success = "Student account manually frozen.";
    }
}

// Teacher Payment Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_teacher'])) {
    $teacher_id = intval($_POST['teacher_id']);
    $month_year = trim($_POST['month_year']);
    $amount = floatval($_POST['amount']);
    
    if ($teacher_id <= 0 || empty($month_year) || $amount <= 0) {
        $error = "All fields (teacher, month/year, and amount) are required.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO teacher_payments (teacher_id, month_year, amount)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), paid_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$teacher_id, $month_year, $amount]);
            $success = "Recorded payment of **" . number_format($amount, 2) . " TK** for the month of **" . htmlspecialchars($month_year) . "**.";
        } catch (PDOException $e) {
            $error = "Database Error recording payment: " . $e->getMessage();
        }
    }
}

// Handle Account Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_account'])) {
    $newFullName  = trim($_POST['full_name'] ?? '');
    $newEmail     = strtolower(trim($_POST['email'] ?? ''));
    $newUserId    = trim($_POST['user_id'] ?? '');
    $newPassword  = $_POST['password'] ?? '';
    $newDept      = $_POST['department'] ?? '';
    $newRole      = $_POST['role'] ?? '';

    // Validation
    if (empty($newFullName) || empty($newEmail) || empty($newUserId) || empty($newPassword) || empty($newDept) || empty($newRole)) {
        $error = 'All fields are required to create an account.';
    } elseif ($newRole === 'student' && !preg_match('/^[a-zA-Z0-9._%+-]+@g\.bracu\.ac\.bd$/i', $newEmail)) {
        $error = 'Invalid Domain: Student accounts must have a @g.bracu.ac.bd email address.';
    } elseif ($newRole === 'teacher' && !preg_match('/^[a-zA-Z0-9._%+-]+@bracu\.ac\.bd$/i', $newEmail)) {
        $error = 'Invalid Domain: Teacher accounts must have a @bracu.ac.bd email address.';
    } else {
        try {
            // Check unique constraints
            $stmt = $pdo->prepare("SELECT email, user_id FROM users WHERE email = ? OR user_id = ? LIMIT 1");
            $stmt->execute([$newEmail, $newUserId]);
            $existing = $stmt->fetch();

            if ($existing) {
                if (strcasecmp($existing['email'], $newEmail) === 0) {
                    $error = 'An account with this email address already exists.';
                } else {
                    $error = 'An account with this ID Number already exists.';
                }
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                $insertStmt = $pdo->prepare("INSERT INTO users (full_name, email, user_id, password, department, role) VALUES (?, ?, ?, ?, ?, ?)");
                $insertStmt->execute([$newFullName, $newEmail, $newUserId, $hashedPassword, $newDept, $newRole]);

                $success = "Account created successfully: **" . htmlspecialchars($newFullName) . "** (" . ucfirst($newRole) . "). ID: **" . htmlspecialchars($newUserId) . "**";
            }
        } catch (PDOException $e) {
            $error = 'Database Error: ' . $e->getMessage();
        }
    }
}

// Handle Account Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $deleteId = intval($_POST['delete_account']);
    if ($deleteId > 0) {
        try {
            // Fetch the account to confirm it exists and is not an authority
            $chk = $pdo->prepare("SELECT full_name, role FROM users WHERE id = ? AND role IN ('student','teacher','guest') LIMIT 1");
            $chk->execute([$deleteId]);
            $target = $chk->fetch();
            if ($target) {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$deleteId]);
                $success = '**' . htmlspecialchars($target['full_name']) . '** (' . ucfirst($target['role']) . ') account has been permanently removed.';
            } else {
                $error = 'Account not found or cannot be deleted.';
            }
        } catch (PDOException $e) {
            $error = 'Database Error: ' . $e->getMessage();
        }
    }
}

// Handle Active Semester Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_semester'])) {
    $season = $_POST['season'] ?? '';
    $year   = intval($_POST['year'] ?? 0);
    $deadline = !empty($_POST['payment_deadline']) ? $_POST['payment_deadline'] : null;
    
    if (in_array($season, ['Summer', 'Fall', 'Spring']) && $year >= 2020 && $year <= 2040) {
        try {
            $label = makeSemesterLabel($season, $year);
            // Check if this semester exists in DB
            $chk = $pdo->prepare("SELECT id FROM semesters WHERE season = ? AND year = ? LIMIT 1");
            $chk->execute([$season, $year]);
            $semId = $chk->fetchColumn();
            
            $pdo->beginTransaction();
            if (!$semId) {
                // Insert new semester
                $ins = $pdo->prepare("INSERT INTO semesters (label, season, year, is_active, payment_deadline) VALUES (?, ?, ?, 0, ?)");
                $ins->execute([$label, $season, $year, $deadline]);
                $semId = $pdo->lastInsertId();
            } else {
                // Update deadline
                $updDead = $pdo->prepare("UPDATE semesters SET payment_deadline = ? WHERE id = ?");
                $updDead->execute([$deadline, $semId]);
            }
            
            // Set all active=0, then set this one active=1
            $pdo->exec("UPDATE semesters SET is_active = 0");
            $upd = $pdo->prepare("UPDATE semesters SET is_active = 1 WHERE id = ?");
            $upd->execute([$semId]);
            $pdo->commit();
            
            // Refresh global $activeSemester row
            $activeSemester = $pdo->query("SELECT * FROM semesters WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            
            $success = "Active Semester successfully set to **" . htmlspecialchars($label) . "** with payment deadline: **" . ($deadline ?: 'None Set') . "**.";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Database Error setting semester: ' . $e->getMessage();
        }
    } else {
        $error = 'Invalid season or year selected.';
    }
}

// Fetch all registered students, teachers, and guests
$accounts = [];
$counts = ['student' => 0, 'teacher' => 0, 'guest' => 0];
try {
    $stmt = $pdo->query("SELECT id, full_name, email, user_id, department, role, created_at FROM users WHERE role IN ('student', 'teacher', 'guest') ORDER BY created_at DESC");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get counts
    $countsStmt = $pdo->query("SELECT role, COUNT(*) as qty FROM users GROUP BY role");
    $qtyData = $countsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($qtyData as $q) {
        $counts[$q['role']] = intval($q['qty']);
    }

    // Fetch student payments list for the active semester
    $studentPaymentsList = [];
    if (isset($activeSemester['id'])) {
        $studentPaymentsList = $pdo->query("
            SELECT u.id, u.full_name, u.user_id, u.is_frozen, u.department,
                   sp.status AS payment_status, sp.paid_at, sp.unfrozen_by_authority,
                   GROUP_CONCAT(c.code SEPARATOR ', ') AS course_codes,
                   SUM(c.credit) AS total_credits,
                   SUM(c.course_fee) AS total_fee
            FROM users u
            JOIN enrollments e ON u.id = e.student_id
            JOIN course_sections cs ON e.section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            LEFT JOIN semester_payments sp ON u.id = sp.student_id AND sp.semester_id = {$activeSemester['id']}
            WHERE e.semester_id = {$activeSemester['id']} AND u.role = 'student'
            GROUP BY u.id
            ORDER BY u.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch teachers list and payroll logs
    $teachersList = $pdo->query("SELECT id, full_name, user_id, department FROM users WHERE role = 'teacher' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $payrollLogs = $pdo->query("
        SELECT tp.*, u.full_name, u.user_id, u.department
        FROM teacher_payments tp
        JOIN users u ON tp.teacher_id = u.id
        ORDER BY tp.paid_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'Database Error fetching accounts: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Authority Dashboard - BRACU Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="responsive.css?v=3">
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }
        
        .admin-layout {
            display: flex;
            min-height: 100vh;
            background: var(--gradient-bg);
            width: 100%;
        }

        /* ── Sidebar ── */
        .admin-sidebar {
            width: 280px;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            display: flex;
            flex-direction: column;
            padding: 30px 24px;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 950;
        }
        .sidebar-brand {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 30px;
            letter-spacing: -0.5px;
        }
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 30px;
        }
        .admin-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fff;
            font-weight: 700;
            box-shadow: 0 0 15px rgba(236, 72, 153, 0.3);
        }
        .admin-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .admin-role {
            font-size: 0.75rem;
            color: #ec4899;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-grow: 1;
        }
        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.92rem;
            transition: all 0.2s;
        }
        .menu-item:hover, .menu-item.active {
            background: rgba(236, 72, 153, 0.1);
            color: #ec4899;
        }
        .menu-item svg {
            width: 18px;
            height: 18px;
        }

        /* ── Main Content Area ── */
        .admin-main {
            margin-left: 280px;
            flex-grow: 1;
            padding: 40px;
            max-width: calc(100% - 280px);
            box-sizing: border-box;
        }

        .dashboard-header {
            margin-bottom: 36px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            border-bottom: none;
            background: none;
            box-shadow: none;
            padding: 0;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }
        .dashboard-title h1 {
            font-size: 1.8rem;
            font-family: 'Space Grotesque', sans-serif;
            background: var(--gradient-accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 4px;
        }
        .dashboard-title p {
            color: var(--text-secondary);
            font-size: 0.92rem;
        }

        /* ── Stats Strip ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 36px;
        }
        .stat-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 20px 24px;
            box-shadow: var(--card-glow);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
        }
        .stat-icon.pink { background: rgba(236, 72, 153, 0.12); color: #ec4899; }
        .stat-icon.cyan { background: rgba(6, 182, 212, 0.12); color: var(--accent-secondary); }
        .stat-icon.purple { background: rgba(168, 85, 247, 0.12); color: var(--accent-primary); }
        .stat-info { display: flex; flex-direction: column; gap: 2px; }
        .stat-label { font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; letter-spacing: 0.5px; }
        .stat-val { font-size: 1.6rem; font-weight: 700; font-family: 'Space Grotesque', sans-serif; color: var(--text-primary); }

        /* ── Grid Layout ── */
        .admin-grid {
            display: grid;
            grid-template-columns: 1.2fr 2fr;
            gap: 30px;
            align-items: start;
        }

        .creator-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--card-glow);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
        .creator-card h2 {
            font-size: 1.25rem;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
            font-family: 'Space Grotesque', sans-serif;
        }

        .table-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--card-glow);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            overflow: hidden;
        }
        .table-card h2 {
            font-size: 1.25rem;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
            font-family: 'Space Grotesque', sans-serif;
        }

        /* Form styling extensions */
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 8px;
        }
        .radio-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            color: var(--text-primary);
        }
        .radio-label input {
            cursor: pointer;
            accent-color: var(--accent-primary);
            width: 18px;
            height: 18px;
        }

        /* Table extensions */
        .badge-role {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        .badge-student { background: rgba(6, 182, 212, 0.12); color: var(--accent-secondary); border: 1px solid rgba(6, 182, 212, 0.25); }
        .badge-teacher { background: rgba(168, 85, 247, 0.12); color: var(--accent-primary); border: 1px solid rgba(168, 85, 247, 0.25); }
        .badge-guest { background: rgba(251, 191, 36, 0.12); color: #f59e0b; border: 1px solid rgba(251, 191, 36, 0.25); }

        /* Delete button */
        .btn-delete {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #ef4444;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.18);
            border-color: rgba(239, 68, 68, 0.5);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .btn-delete svg { flex-shrink: 0; }

        /* Confirmation Modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 2000;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 32px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
            animation: modalIn 0.25s ease-out;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.92) translateY(16px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: rgba(239, 68, 68, 0.12);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #ef4444;
            margin-bottom: 18px;
        }
        .modal-title {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .modal-body {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .modal-body strong { color: var(--text-primary); }
        .modal-actions {
            display: flex;
            gap: 12px;
        }
        .modal-cancel {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-cancel:hover { background: rgba(255,255,255,0.1); color: var(--text-primary); }
        .modal-confirm {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(239,68,68,0.3);
        }
        .modal-confirm:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(239,68,68,0.4); }

        .dept-tag {
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border-color);
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        @media (max-width: 1024px) {
            .admin-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            .admin-sidebar.open {
                transform: translateX(0);
            }
            .admin-main {
                margin-left: 0;
                max-width: 100%;
                padding: 24px 16px;
            }
        }
        /* Live clash hints */
        .clash-hint {
            margin-top: 6px;
            padding: 8px 12px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.10);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
            font-size: 0.8rem;
            font-weight: 600;
            animation: clashPop 0.25s ease-out;
        }
        @keyframes clashPop {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .input-clash input {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.15) !important;
        }
    </style>
</head>
<body>
    
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <div class="admin-layout">
        
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-brand">ONE LMS</div>
            
            <div class="admin-profile">
                <div class="admin-avatar">A</div>
                <div class="admin-info" style="display:flex; flex-direction:column; overflow:hidden;">
                    <span class="admin-name" title="<?= htmlspecialchars($fullName) ?>"><?= htmlspecialchars($fullName) ?></span>
                    <span class="admin-role">Institution Admin</span>
                </div>
            </div>

            <nav class="sidebar-menu">
                <a href="?tab=accounts" class="menu-item <?= $activeTab === 'accounts' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect>
                        <rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect>
                    </svg>
                    Account Control
                </a>

                <a href="?tab=payments" class="menu-item <?= $activeTab === 'payments' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2" ry="2"/>
                        <line x1="12" y1="4" x2="12" y2="20"/>
                        <line x1="2" y1="10" x2="22" y2="10"/>
                    </svg>
                    Student Payments
                </a>

                <a href="?tab=payroll" class="menu-item <?= $activeTab === 'payroll' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="1" x2="12" y2="23"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                    Teacher Payroll
                </a>

                <a href="authority_courses.php" class="menu-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                    Course Management
                </a>
                
                <a href="logout.php" class="menu-item" style="margin-top: auto; color: var(--error-color);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    Log Out
                </a>
            </nav>
        </aside>

        <!-- Main Dashboard View -->
        <main class="admin-main">
            
            <!-- Dashboard Header -->
            <header class="dashboard-header">
                <div class="dashboard-title">
                    <h1>Administrative Terminal</h1>
                    <p>Manage and authorize student and faculty credentials.</p>
                </div>
            </header>

            <!-- Stats Strip -->
            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon pink">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Faculty Accounts</span>
                        <span class="stat-val"><?= $counts['teacher'] ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon cyan">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a5 5 0 1 0 5 5 5 5 0 0 0-5-5zm0 12c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5z"/></svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Student Accounts</span>
                        <span class="stat-val"><?= $counts['student'] ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label">Guest Sessions</span>
                        <span class="stat-val"><?= $counts['guest'] ?></span>
                    </div>
                </div>
            </section>

            <!-- Status Alerts -->
            <?php if (!empty($error)): ?>
                <div class="alert-box alert-error" style="margin-bottom: 30px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-box alert-success" style="margin-bottom: 30px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <span>
                        <?php
                        // Enable simple markdown bold formatting for the success text
                        echo preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $success);
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'accounts'): ?>
            <!-- Interactive Panel Grid -->
            <div class="admin-grid">
                
                <!-- Left Column Wrapper -->
                <div style="display: flex; flex-direction: column; gap: 30px; width: 100%;">
                    <!-- Left: Account Creator -->
                    <div class="creator-card">
                        <h2>Authorize Credentials</h2>
                        
                        <form action="authority_dashboard.php" method="POST" autocomplete="off">
                            
                            <div class="form-group">
                                <label class="form-label" style="font-size:0.75rem;">Account Type</label>
                                <div class="radio-group">
                                    <label class="radio-label">
                                        <input type="radio" name="role" value="student" checked onclick="setEmailHint('student')">
                                        Student
                                    </label>
                                    <label class="radio-label">
                                        <input type="radio" name="role" value="teacher" onclick="setEmailHint('teacher')">
                                        Teacher
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="full_name">Full Name</label>
                                <div class="input-wrapper">
                                    <input class="form-input" type="text" id="full_name" name="full_name" placeholder="E.g., Tasnim Rahman" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="email">Institutional Email</label>
                                <div class="input-wrapper">
                                    <input class="form-input" type="email" id="email" name="email" placeholder="example@g.bracu.ac.bd" required oninput="checkClash('email', this.value)">
                                </div>
                                <div class="form-helper" id="emailHint">
                                    Domain must end with: <strong>@g.bracu.ac.bd</strong>
                                </div>
                                <div class="clash-hint" id="emailClash" style="display:none;">
                                    ⛔ This email is already registered. Please use a different email.
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="user_id">Identification ID</label>
                                <div class="input-wrapper">
                                    <input class="form-input" type="text" id="user_id" name="user_id" placeholder="E.g., 21101234 or F23049" required oninput="checkClash('user_id', this.value)">
                                </div>
                                <div class="clash-hint" id="userIdClash" style="display:none;">
                                    ⛔ This ID is already taken. Please choose a different ID.
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="department">Department</label>
                                <div class="input-wrapper">
                                    <select class="form-input" id="department" name="department" required>
                                        <option value="" disabled selected>Select Department</option>
                                        <option value="CSE">CSE (Computer Science & Engineering)</option>
                                        <option value="CS">CS (Computer Science)</option>
                                        <option value="EEE">EEE (Electrical & Electronic Engineering)</option>
                                        <option value="BBA">BBA (Bachelor of Business Administration)</option>
                                        <option value="MNS">MNS (Mathematics & Natural Sciences)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="password">Default Password</label>
                                <div class="input-wrapper">
                                    <input class="form-input" type="password" id="password" name="password" placeholder="••••••••" required>
                                </div>
                            </div>

                            <button type="submit" name="create_account" class="btn-primary" style="margin-top: 15px; background: var(--gradient-accent);">
                                Authorize Account
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                        </form>
                    </div>

                    <!-- Left: Semester Control -->
                    <div class="creator-card">
                        <h2>Semester Control</h2>
                        
                        <div style="margin-bottom: 20px; padding: 15px; border-radius: 12px; background: rgba(168, 85, 247, 0.08); border: 1px solid rgba(168, 85, 247, 0.2);">
                            <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; letter-spacing: 0.5px;">Active Semester</div>
                            <div style="font-size: 1.3rem; font-weight: 700; color: var(--accent-primary); font-family: 'Space Grotesque', sans-serif; margin-top: 4px;">
                                <?= htmlspecialchars($activeSemester['label'] ?? 'None Set') ?>
                            </div>
                        </div>

                        <form action="authority_dashboard.php" method="POST" autocomplete="off">
                            <input type="hidden" name="set_semester" value="1">
                            
                            <div class="form-group">
                                <label class="form-label" for="season">Season</label>
                                <div class="input-wrapper">
                                    <select class="form-input" id="season" name="season" required>
                                        <option value="" disabled selected>Select Season</option>
                                        <option value="Summer" <?= isset($activeSemester['season']) && $activeSemester['season'] === 'Summer' ? 'selected' : '' ?>>Summer</option>
                                        <option value="Fall" <?= isset($activeSemester['season']) && $activeSemester['season'] === 'Fall' ? 'selected' : '' ?>>Fall</option>
                                        <option value="Spring" <?= isset($activeSemester['season']) && $activeSemester['season'] === 'Spring' ? 'selected' : '' ?>>Spring</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="year">Year</label>
                                <div class="input-wrapper">
                                    <select class="form-input" id="year" name="year" required>
                                        <?php
                                        $startYear = (int)date('Y') - 1;
                                        for ($y = $startYear; $y <= $startYear + 4; $y++) {
                                            $selected = (isset($activeSemester['year']) && $activeSemester['year'] == $y) ? 'selected' : '';
                                            echo "<option value=\"$y\" $selected>$y</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="payment_deadline">Payment Deadline</label>
                                <div class="input-wrapper">
                                    <input class="form-input" type="date" id="payment_deadline" name="payment_deadline" value="<?= htmlspecialchars($activeSemester['payment_deadline'] ?? '') ?>">
                                </div>
                            </div>

                            <button type="submit" class="btn-primary" style="margin-top: 15px; background: var(--gradient-accent); width: 100%;">
                                Set Active Semester
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: 8px;">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            </button>
                        </form>

                        <div style="margin-top: 25px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <h3 style="font-size: 0.95rem; font-family: 'Space Grotesque', sans-serif; margin-bottom: 12px; color: var(--text-primary);">All Semesters</h3>
                            <div style="max-height: 150px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 5px;">
                                <?php
                                $allSems = getAllSemesters($pdo);
                                if (empty($allSems)):
                                ?>
                                    <span style="font-size: 0.8rem; color: var(--text-secondary);">No other semesters created.</span>
                                <?php else: ?>
                                    <?php foreach ($allSems as $sem): ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-radius: 8px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); font-size: 0.85rem;">
                                            <span style="font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($sem['label']) ?></span>
                                            <?php if ($sem['is_active']): ?>
                                                <span style="font-size: 0.7rem; font-weight: 700; color: var(--accent-primary); text-transform: uppercase;">Active</span>
                                            <?php else: ?>
                                                <span style="font-size: 0.7rem; color: var(--text-secondary);">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Account Registry -->
                <div class="table-card">
                    <h2>Accounts Registry</h2>
                    
                    <div class="table-responsive">
                        <table style="width: 100%; border-collapse: collapse; text-align: left;">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--border-color); background: rgba(255,255,255,0.02);">
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">ID Number</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Name</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Role</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Dept</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Date Authored</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($accounts)): ?>
                                    <tr>
                                        <td colspan="6" style="padding: 24px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                                            No user accounts registered yet. Use the panel on the left to authorize credentials.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($accounts as $row): ?>
                                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                            <td style="padding: 14px 16px; font-weight: 700; color: var(--text-primary); font-family: 'Space Grotesque', sans-serif;">
                                                <?= htmlspecialchars($row['user_id']) ?>
                                            </td>
                                            <td style="padding: 14px 16px; font-weight: 500;">
                                                <div style="display:flex; flex-direction:column;">
                                                    <span style="color:var(--text-primary);"><?= htmlspecialchars($row['full_name']) ?></span>
                                                    <span style="font-size:0.75rem; color:var(--text-secondary);"><?= htmlspecialchars($row['email']) ?></span>
                                                </div>
                                            </td>
                                            <td style="padding: 14px 16px;">
                                                <span class="badge-role badge-<?= $row['role'] ?>"><?= $row['role'] ?></span>
                                            </td>
                                            <td style="padding: 14px 16px;">
                                                <span class="dept-tag"><?= htmlspecialchars($row['department']) ?></span>
                                            </td>
                                            <td style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem;">
                                                <?= date('M j, Y', strtotime($row['created_at'])) ?>
                                            </td>
                                            <td style="padding: 14px 16px;">
                                                <button type="button" class="btn-delete"
                                                    onclick="confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name'])) ?>', '<?= $row['role'] ?>')">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                                                        <path d="M10 11v6"/><path d="M14 11v6"/>
                                                        <path d="M9 6V4h6v2"/>
                                                    </svg>
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php elseif ($activeTab === 'payments'): ?>
            <!-- Student Semester Payments View -->
            <div class="table-card" style="width: 100%;">
                <h2>Student Semester Payments (<?= htmlspecialchars($activeSemester['label'] ?? '') ?>)</h2>
                <p style="color: var(--text-secondary); font-size: 0.88rem; margin-top: -12px; margin-bottom: 20px;">
                    Deadline: <strong style="color: var(--accent-primary);"><?= !empty($activeSemester['payment_deadline']) ? date('F j, Y', strtotime($activeSemester['payment_deadline'])) : 'Not Set' ?></strong>
                </p>

                <div class="table-responsive">
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr style="border-bottom: 2px solid var(--border-color); background: rgba(255,255,255,0.02);">
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Student Name</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">ID</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Dept</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Enrolled Courses</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: center;">Credits</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: right;">Total Fee</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: center;">Status</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: center;">Freeze Status</th>
                                <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($studentPaymentsList)): ?>
                                <tr>
                                    <td colspan="9" style="padding: 24px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                                        No students enrolled in the active semester yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentPaymentsList as $row): ?>
                                    <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                        <td style="padding: 14px 16px; color: var(--text-primary); font-weight: 600;"><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td style="padding: 14px 16px; font-family: 'Space Grotesque', sans-serif;"><?= htmlspecialchars($row['user_id']) ?></td>
                                        <td style="padding: 14px 16px;"><span class="dept-tag"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td style="padding: 14px 16px; font-size: 0.82rem; color: var(--text-secondary); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['course_codes']) ?>">
                                            <?= htmlspecialchars($row['course_codes']) ?>
                                        </td>
                                        <td style="padding: 14px 16px; text-align: center; font-weight: 500;"><?= number_format($row['total_credits'], 1) ?></td>
                                        <td style="padding: 14px 16px; text-align: right; color: var(--accent-primary); font-weight: 700;"><?= number_format($row['total_fee'], 2) ?> TK</td>
                                        <td style="padding: 14px 16px; text-align: center;">
                                            <?php if ($row['payment_status'] === 'paid'): ?>
                                                <span class="badge-role" style="background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25);">Paid</span>
                                            <?php else: ?>
                                                <span class="badge-role" style="background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25);">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 16px; text-align: center;">
                                            <?php if ($row['is_frozen']): ?>
                                                <span class="badge-role" style="background: rgba(239, 68, 68, 0.15); color: #f43f5e; font-weight: 700; border: 1px solid rgba(239,68,68,0.35);">FROZEN</span>
                                            <?php else: ?>
                                                <span class="badge-role" style="background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16,185,129,0.25);">NORMAL</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 16px; text-align: center;">
                                            <div style="display: flex; gap: 8px; justify-content: center;">
                                                <?php if ($row['payment_status'] === 'paid'): ?>
                                                    <form method="POST" action="" style="margin:0;">
                                                        <input type="hidden" name="student_id" value="<?= $row['id'] ?>">
                                                        <input type="hidden" name="semester_id" value="<?= $activeSemester['id'] ?>">
                                                        <button type="submit" name="mark_unpaid" class="btn-delete" style="background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.25); color: #f59e0b;">Mark Unpaid</button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="" style="margin:0;">
                                                        <input type="hidden" name="student_id" value="<?= $row['id'] ?>">
                                                        <input type="hidden" name="semester_id" value="<?= $activeSemester['id'] ?>">
                                                        <button type="submit" name="mark_paid" class="btn-success btn-sm" style="padding: 5px 12px; border-radius: 8px; font-size: 0.78rem;">Mark Paid</button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="POST" action="" style="margin:0;">
                                                    <input type="hidden" name="student_id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="semester_id" value="<?= $activeSemester['id'] ?>">
                                                    <button type="submit" name="toggle_freeze" class="btn-primary btn-sm" style="padding: 5px 12px; border-radius: 8px; font-size: 0.78rem; background: <?= $row['is_frozen'] ? 'linear-gradient(135deg, #10b981, #059669)' : 'linear-gradient(135deg, #ef4444, #dc2626)' ?>; border: none; color: #fff;">
                                                        <?= $row['is_frozen'] ? 'Unfreeze' : 'Freeze' ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif ($activeTab === 'payroll'): ?>
            <!-- Teacher Payroll & Payments View -->
            <div class="admin-grid" style="grid-template-columns: 1fr 2fr;">
                <!-- Left: Record Payment Form -->
                <div class="creator-card">
                    <h2>Record Teacher Payment</h2>
                    
                    <form action="" method="POST" autocomplete="off">
                        <input type="hidden" name="pay_teacher" value="1">
                        
                        <div class="form-group">
                            <label class="form-label" for="teacher_id">Select Teacher</label>
                            <div class="input-wrapper">
                                <select class="form-input" id="teacher_id" name="teacher_id" required>
                                    <option value="" disabled selected>Select Teacher</option>
                                    <?php foreach ($teachersList as $t): ?>
                                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?> (<?= htmlspecialchars($t['user_id']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="month_year">Month & Year</label>
                            <div class="input-wrapper">
                                <select class="form-input" id="month_year" name="month_year" required>
                                    <?php
                                    for ($i = 0; $i < 12; $i++) {
                                        $dateStr = date('F Y', strtotime("-$i months"));
                                        echo "<option value=\"$dateStr\">$dateStr</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="amount">Payment Amount (TK)</label>
                            <div class="input-wrapper">
                                <input class="form-input" type="number" id="amount" name="amount" placeholder="E.g., 50000" min="1" step="500" required>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top: 15px; background: var(--gradient-accent); width: 100%;">
                            Record Payment
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left: 8px;">
                                <line x1="12" y1="1" x2="12" y2="23"/>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                            </svg>
                        </button>
                    </form>
                </div>

                <!-- Right: Past Payroll Registry -->
                <div class="table-card">
                    <h2>Payroll logs</h2>
                    
                    <div class="table-responsive">
                        <table style="width: 100%; border-collapse: collapse; text-align: left;">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--border-color); background: rgba(255,255,255,0.02);">
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Teacher</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">ID</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Dept</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Paid Month</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; text-align: right;">Amount</th>
                                    <th style="padding: 14px 16px; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase;">Paid At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payrollLogs)): ?>
                                    <tr>
                                        <td colspan="6" style="padding: 24px; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                                            No teacher payments recorded yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($payrollLogs as $row): ?>
                                        <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                            <td style="padding: 14px 16px; font-weight: 600; color: var(--text-primary);"><?= htmlspecialchars($row['full_name']) ?></td>
                                            <td style="padding: 14px 16px; font-family: 'Space Grotesque', sans-serif;"><?= htmlspecialchars($row['user_id']) ?></td>
                                            <td style="padding: 14px 16px;"><span class="dept-tag"><?= htmlspecialchars($row['department']) ?></span></td>
                                            <td style="padding: 14px 16px; font-weight: 500; color: var(--accent-secondary);"><?= htmlspecialchars($row['month_year']) ?></td>
                                            <td style="padding: 14px 16px; text-align: right; color: var(--accent-primary); font-weight: 700;"><?= number_format($row['amount'], 2) ?> TK</td>
                                            <td style="padding: 14px 16px; font-size: 0.8rem; color: var(--text-secondary);"><?= date('M j, Y, g:i A', strtotime($row['paid_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-backdrop" id="deleteModal">
        <div class="modal-box">
            <div class="modal-icon">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                    <path d="M10 11v6"/><path d="M14 11v6"/>
                    <path d="M9 6V4h6v2"/>
                </svg>
            </div>
            <div class="modal-title">Remove Account?</div>
            <div class="modal-body" id="modalBody">This action is permanent and cannot be undone.</div>
            <div class="modal-actions">
                <button class="modal-cancel" onclick="closeModal()">Cancel</button>
                <form id="deleteForm" method="POST" action="authority_dashboard.php" style="flex:1; margin:0;">
                    <input type="hidden" name="delete_account" id="deleteAccountId">
                    <button type="submit" class="modal-confirm" style="width:100%;">Yes, Remove</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Delete modal logic
        function confirmDelete(accountId, name, role) {
            document.getElementById('deleteAccountId').value = accountId;
            document.getElementById('modalBody').innerHTML =
                'You are about to permanently remove <strong>' + name + '</strong> (' + role + '). This action cannot be undone.';
            document.getElementById('deleteModal').classList.add('open');
        }
        function closeModal() {
            document.getElementById('deleteModal').classList.remove('open');
        }
        // Close modal when clicking outside the box
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        function setEmailHint(role) {
            const hint = document.getElementById('emailHint');
            const emailInput = document.getElementById('email');
            if (role === 'student') {
                hint.innerHTML = 'Domain must end with: <strong>@g.bracu.ac.bd</strong>';
                emailInput.placeholder = 'example@g.bracu.ac.bd';
            } else {
                hint.innerHTML = 'Domain must end with: <strong>@bracu.ac.bd</strong>';
                emailInput.placeholder = 'example@bracu.ac.bd';
            }
            // Re-validate email clash on role switch
            checkClash('email', emailInput.value);
        }

        // ── Live Clash Detection ─────────────────────────────────────────────
        const _clashTimers = {};
        function checkClash(field, value) {
            clearTimeout(_clashTimers[field]);
            const hintId   = field === 'user_id' ? 'userIdClash' : 'emailClash';
            const inputEl  = document.getElementById(field === 'user_id' ? 'user_id' : 'email');
            const hintEl   = document.getElementById(hintId);
            const submitEl = document.querySelector('form button[type="submit"]');

            if (!value || value.trim().length < 3) {
                hintEl.style.display = 'none';
                inputEl.style.borderColor = '';
                inputEl.style.boxShadow = '';
                _refreshSubmitState();
                return;
            }

            _clashTimers[field] = setTimeout(() => {
                fetch('check_user_id.php?field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value.trim()))
                    .then(r => r.json())
                    .then(data => {
                        if (data.exists) {
                            hintEl.style.display = 'block';
                            inputEl.style.borderColor = '#ef4444';
                            inputEl.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.15)';
                        } else {
                            hintEl.style.display = 'none';
                            inputEl.style.borderColor = '';
                            inputEl.style.boxShadow = '';
                        }
                        _refreshSubmitState();
                    })
                    .catch(() => { /* silently ignore network errors */ });
            }, 420);
        }

        function _refreshSubmitState() {
            const hasClash = document.getElementById('userIdClash').style.display === 'block'
                          || document.getElementById('emailClash').style.display  === 'block';
            const submitEl = document.querySelector('form button[type="submit"]');
            if (submitEl) {
                submitEl.disabled = hasClash;
                submitEl.style.opacity  = hasClash ? '0.5' : '1';
                submitEl.style.cursor   = hasClash ? 'not-allowed' : 'pointer';
                submitEl.title = hasClash ? 'Resolve the clash before submitting.' : '';
            }
        }
    </script>
</body>
</html>
