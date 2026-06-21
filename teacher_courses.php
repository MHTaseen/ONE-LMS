<?php
// teacher_courses.php – Teacher access only
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SESSION['role'] !== 'teacher') {
    header('Location: landing.php');
    exit();
}

require_once 'config.php';

// ── Auto-migration: ensure teacher_id and lab_room_no columns exist ──────────
try {
    $colExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'course_sections'
           AND COLUMN_NAME  = 'teacher_id'"
    )->fetchColumn();
    if (!$colExists) {
        $pdo->exec("ALTER TABLE course_sections ADD COLUMN teacher_id INT NULL DEFAULT NULL AFTER course_id");
    }
    $labRoomColExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'course_sections'
           AND COLUMN_NAME  = 'lab_room_no'"
    )->fetchColumn();
    if (!$labRoomColExists) {
        $pdo->exec("ALTER TABLE course_sections ADD COLUMN lab_room_no VARCHAR(20) NULL DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log('Migration warning: ' . $e->getMessage());
}

$fullName = $_SESSION['full_name'];
$initials = '';
$nameParts = explode(' ', trim($fullName));
if (count($nameParts) > 1) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $initials = strtoupper(substr($fullName, 0, 2));
}

$errorMsg = '';
$successMsg = '';

$activeSemId = isset($activeSemester['id']) ? intval($activeSemester['id']) : 0;
$viewSemId = isset($_GET['view_semester_id']) ? intval($_GET['view_semester_id']) : $activeSemId;
if (!$viewSemId) {
    $viewSemId = $activeSemId;
}

// Fetch sections for this teacher in the selected semester
$mySections = [];
try {
    $stmtMy = $pdo->prepare("
        SELECT cs.*, c.title as course_title, c.code as course_code,
               (SELECT COUNT(*) FROM enrollments WHERE section_id = cs.id) as enrolled
        FROM course_sections cs
        JOIN courses c ON cs.course_id = c.id
        WHERE cs.teacher_id = ? AND cs.semester_id = ?
        ORDER BY c.code ASC, cs.section_no ASC
    ");
    $stmtMy->execute([$_SESSION['user_pk'], $viewSemId]);
    $mySections = $stmtMy->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ignore
}

// Handle section creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_section') {
    $course_id = intval($_POST['course_id']);
    $section_no = intval($_POST['section_no']);
    $room_no = trim($_POST['room_no']);
    $seats = intval($_POST['seats']);
    $theory_day_1 = trim($_POST['theory_day_1'] ?? '');
    $theory_day_2 = trim($_POST['theory_day_2'] ?? '');
    $theory_time_slot = trim($_POST['theory_time_slot'] ?? '');
    $lab_day = trim($_POST['lab_day'] ?? '');
    $lab_time_slot = trim($_POST['lab_time_slot'] ?? '');
    $lab_room_no = trim($_POST['lab_room_no'] ?? '');

    // Validation
    if ($seats > 40) {
        $errorMsg = "Maximum seats allowed per section is 40.";
    } elseif ($theory_day_1 === $theory_day_2) {
        $errorMsg = "Theory Day 1 and Theory Day 2 must be different.";
    } else {
        // Guard: check if this course requires lab
        $stmt_c = $pdo->prepare("SELECT lab_marks FROM courses WHERE id = ?");
        $stmt_c->execute([$course_id]);
        $course_data = $stmt_c->fetch(PDO::FETCH_ASSOC);
        $requires_lab = ($course_data && intval($course_data['lab_marks']) > 0);

        if (empty($section_no) || empty($room_no) || empty($theory_day_1) || empty($theory_day_2) || empty($theory_time_slot)) {
            $errorMsg = "Please fill out all required theory schedule fields.";
        } elseif ($requires_lab && (empty($lab_day) || empty($lab_time_slot))) {
            $errorMsg = "Please fill out all lab schedule fields since this course has lab marks.";
        } else {
            try {
                // Guard: check if this (course_id, section_no) already exists in active semester
                $dupCheck = $pdo->prepare("SELECT id FROM course_sections WHERE course_id = ? AND section_no = ? AND semester_id = ?");
                $dupCheck->execute([$course_id, $section_no, $activeSemId]);
                if ($dupCheck->fetch()) {
                    $errorMsg = "Section $section_no already exists for this course. Each section number must be unique within a course.";
                } else {

                    // ── ROOM BOOKING CLASH ─────────────────────────────────────
                    // Check if same room is already booked on any of the theory days at the same time in active semester
                    $roomClashStmt = $pdo->prepare("
                        SELECT c.code, cs.section_no
                        FROM course_sections cs
                        JOIN courses c ON cs.course_id = c.id
                        WHERE cs.semester_id = ?
                          AND cs.room_no = ?
                          AND cs.theory_time_slot = ?
                          AND (cs.theory_day_1 IN (?,?) OR cs.theory_day_2 IN (?,?))
                        LIMIT 1
                    ");
                    $roomClashStmt->execute([
                        $activeSemId,
                        $room_no, $theory_time_slot,
                        $theory_day_1, $theory_day_2,
                        $theory_day_1, $theory_day_2
                    ]);
                    $roomClash = $roomClashStmt->fetch(PDO::FETCH_ASSOC);

                    // Lab room clash (if lab is required)
                    $labRoomClash = null;
                    if ($requires_lab && !empty($lab_day) && !empty($lab_time_slot) && !empty($lab_room_no)) {
                        $labRoomClashStmt = $pdo->prepare("
                            SELECT c.code, cs.section_no
                            FROM course_sections cs
                            JOIN courses c ON cs.course_id = c.id
                            WHERE cs.semester_id = ?
                              AND cs.lab_room_no = ?
                              AND cs.lab_time_slot = ?
                              AND cs.lab_day = ?
                            LIMIT 1
                        ");
                        $labRoomClashStmt->execute([$activeSemId, $lab_room_no, $lab_time_slot, $lab_day]);
                        $labRoomClash = $labRoomClashStmt->fetch(PDO::FETCH_ASSOC);
                    }

                    // ── TIME SLOT CLASH (same days + same time for THIS teacher in active semester) ──────
                    $timeClashStmt = $pdo->prepare("
                        SELECT c.code, cs.section_no
                        FROM course_sections cs
                        JOIN courses c ON cs.course_id = c.id
                        WHERE cs.semester_id = ?
                          AND cs.teacher_id = ?
                          AND cs.theory_time_slot = ?
                          AND (
                            (cs.theory_day_1 IN (?,?) OR cs.theory_day_2 IN (?,?))
                          )
                        LIMIT 1
                    ");
                    $timeClashStmt->execute([
                        $activeSemId,
                        $_SESSION['user_pk'],
                        $theory_time_slot,
                        $theory_day_1, $theory_day_2,
                        $theory_day_1, $theory_day_2
                    ]);
                    $timeClash = $timeClashStmt->fetch(PDO::FETCH_ASSOC);

                    if ($roomClash) {
                        $errorMsg = "🚫 Room Conflict: Room <strong>{$room_no}</strong> is already booked at <strong>{$theory_time_slot}</strong> on those days by <strong>{$roomClash['code']}</strong> Section {$roomClash['section_no']}. Please choose a different room.";
                    } elseif ($labRoomClash) {
                        $errorMsg = "🚫 Lab Room Conflict: Room <strong>{$lab_room_no}</strong> is already booked for a lab at <strong>{$lab_time_slot}</strong> on <strong>{$lab_day}</strong> by <strong>{$labRoomClash['code']}</strong> Section {$labRoomClash['section_no']}. Please choose a different lab room or lab time.";
                    } elseif ($timeClash) {
                        $errorMsg = "⚠️ Schedule Conflict: The time slot <strong>{$theory_time_slot}</strong> on those days is already used by you for <strong>{$timeClash['code']}</strong> Section {$timeClash['section_no']}. This may cause teacher routine clashes — please choose a different day or time.";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO course_sections (course_id, teacher_id, section_no, room_no, seats, theory_day_1, theory_day_2, theory_time_slot, lab_day, lab_time_slot, lab_room_no, semester_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$course_id, $_SESSION['user_pk'], $section_no, $room_no, $seats, $theory_day_1, $theory_day_2, $theory_time_slot, $lab_day, $lab_time_slot, $lab_room_no, $activeSemId]);
                        $successMsg = "Section $section_no created successfully!";
                    }
                }
            } catch (PDOException $e) {
                // Catch UNIQUE constraint violation as a friendly message
                if ($e->getCode() === '23000') {
                    $errorMsg = "Section $section_no already exists for this course. Each section number must be unique within a course.";
                } else {
                    $errorMsg = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all courses for the teacher (or all courses in general depending on requirement - let's fetch all courses as requested "all the courses that are present in our website database")
try {
    $stmt = $pdo->query("SELECT c.*, u.full_name as teacher_name FROM courses c JOIN users u ON c.teacher_id = u.id ORDER BY c.created_at DESC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $courses = [];
    $errorMsg = "Failed to load courses.";
}

// Pre-define time slots (80 min class, 10 min gap, starting 08:00 AM)
$time_slots = [
    "08:00 AM - 09:20 AM",
    "09:30 AM - 10:50 AM",
    "11:00 AM - 12:20 PM",
    "12:30 PM - 01:50 PM",
    "02:00 PM - 03:20 PM",
    "03:30 PM - 04:50 PM",
    "05:00 PM - 06:20 PM"
];

// 180 min (3 hour) lab slots with 10 min gaps
$lab_time_slots = [
    "08:00 AM - 11:00 AM",
    "11:10 AM - 02:10 PM",
    "02:20 PM - 05:20 PM",
    "05:30 PM - 08:30 PM"
];

$days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Saturday"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Courses – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px;
            z-index: 900; display: flex; align-items: center; padding: 10px 28px;
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.25);
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        .navbar-brand {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem;
            font-weight: 700; background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .nav-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--gradient-accent); display: flex; justify-content: center; align-items: center;
            color: #ffffff; font-weight: 700; font-size: 0.95rem; box-shadow: var(--glow-shadow); cursor: default;
        }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .theme-switch-container { position: static; }

        .btn-back {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color);
            color: var(--text-primary); border-radius: 12px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none;
        }

        .page-wrap {
            padding: 100px 28px 60px; max-width: 1000px; margin: 0 auto; width: 100%;
        }
        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem;
            font-weight: 700; margin-bottom: 6px;
            background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading {
            color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px;
        }

        /* Course List Styles */
        .course-list {
            display: flex; flex-direction: column; gap: 20px;
        }
        .course-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 20px; padding: 25px; box-shadow: var(--card-glow);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            display: flex; justify-content: space-between; align-items: center;
        }
        .course-info h3 {
            font-size: 1.25rem; font-family: 'Space Grotesque', sans-serif; margin-bottom: 8px; color: var(--text-primary);
        }
        .course-meta {
            display: flex; gap: 15px; color: var(--text-secondary); font-size: 0.85rem; flex-wrap: wrap;
        }
        .meta-item { display: flex; align-items: center; gap: 6px; }
        .meta-item svg { width: 14px; height: 14px; color: var(--accent-secondary); }
        .course-meta span { background: rgba(0,0,0,0.1); padding: 4px 10px; border-radius: 10px; }
        
        .btn-action {
            background: var(--input-bg); border: 1px solid var(--border-color); color: var(--accent-primary);
            padding: 10px 18px; border-radius: 10px; font-weight: 600; cursor: pointer;
            transition: all 0.3s ease; display: flex; align-items: center; gap: 8px;
        }
        .btn-action:hover {
            border-color: var(--accent-primary); box-shadow: var(--glow-shadow); transform: translateY(-2px);
        }

        /* Section Modal Overlay */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 2000; display: flex; justify-content: center; align-items: center;
            background: rgba(7, 11, 20, 0.7); backdrop-filter: blur(8px);
            opacity: 0; pointer-events: none; transition: opacity 0.35s ease;
        }
        .modal-overlay.visible { opacity: 1; pointer-events: all; }
        
        .section-modal {
            position: relative; width: 100%; max-width: 600px; margin: 20px;
            background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 24px;
            padding: 40px; box-shadow: 0 30px 80px rgba(0,0,0,0.5), var(--glow-shadow);
            transform: scale(0.9) translateY(20px); transition: transform 0.4s ease; opacity: 0;
            overflow-y: auto;
            max-height: calc(100vh - 40px);
        }
        
        /* Custom scrollbar for modal */
        .section-modal::-webkit-scrollbar { width: 6px; }
        .section-modal::-webkit-scrollbar-track { background: transparent; }
        .section-modal::-webkit-scrollbar-thumb { background: var(--accent-primary); border-radius: 99px; opacity: 0.5; }
        .modal-overlay.visible .section-modal { transform: scale(1) translateY(0); opacity: 1; }
        .section-modal::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--gradient-accent);
        }
        
        .modal-close-btn {
            position: absolute; top: 16px; right: 16px; width: 34px; height: 34px; border-radius: 50%;
            border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-secondary);
            cursor: pointer; display: flex; justify-content: center; align-items: center;
        }
        .modal-close-btn:hover { background: rgba(239, 68, 68, 0.15); border-color: var(--error-color); color: var(--error-color); }
        
        .modal-title { font-size: 1.5rem; font-family: 'Space Grotesque', sans-serif; margin-bottom: 20px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .full-width { grid-column: span 2; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; }
        table { min-width: 600px; }
        @media (max-width: 768px) { .page-container, .inner-container { padding: 14px 10px; } }
        /* -- Mobile Responsive Overrides -- */
        @media (max-width: 900px) {
            .page-wrapper, .main-wrapper, .content-area, .page-content-inner { padding: 20px 15px; }
            .form-grid, .grid-2col { grid-template-columns: 1fr !important; }
            .filter-row, .action-row { flex-wrap: wrap; gap: 10px; }
        }
        @media (max-width: 768px) {
            .page-wrapper, .main-wrapper, .content-area, .page-content-inner { padding: 14px 10px; }
            .card-grid, .section-grid { grid-template-columns: 1fr !important; }
            .btn-row { flex-direction: column; }
            .modal-content, .popup-card { width: calc(100% - 24px); margin: 12px; max-height: 90vh; overflow-y: auto; }
            h1, .page-title { font-size: 1.5rem; }
            h2, .section-title { font-size: 1.2rem; }
        }
        @media (max-width: 600px) {
            .top-navbar { padding: 0 10px; }
            .navbar-brand { display: none; }
            .theme-btn span { display: none; }
            .theme-btn { padding: 8px 10px; }
            .nav-avatar { width: 34px; height: 34px; font-size: 0.8rem; }
            .btn-primary, .submit-btn, .action-btn { width: 100%; font-size: 0.95rem; }
            .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            table { min-width: 550px; font-size: 0.85rem; }
            th, td { padding: 8px 10px; }
        }</style>
    <link rel="stylesheet" href="responsive.css">
</head>
<body>

    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


    <div class="page-wrap">
        <div class="page-hdr" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom: 28px;">
            <div>
                <h1 class="page-heading" style="margin-bottom: 4px;">Database Courses</h1>
                <p class="page-subheading" style="margin-bottom: 0;">View all courses and create sections for enrollment.</p>
            </div>
            <!-- Semester Filter Dropdown -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 6px 12px; display: flex; align-items: center; gap: 8px; box-shadow: var(--card-glow); backdrop-filter: blur(10px);">
                <span style="font-size: 0.78rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 700;">Semester:</span>
                <select id="globalSemSelect" style="border: none; background: transparent; color: var(--text-primary); font-weight: 700; outline: none; cursor: pointer; font-size: 0.9rem;" onchange="updateSemesterFilter(this.value)">
                    <?php
                    $allSemsForFilter = getAllSemesters($pdo);
                    foreach ($allSemsForFilter as $sem):
                    ?>
                        <option value="<?= $sem['id'] ?>" <?= $sem['id'] == $viewSemId ? 'selected' : '' ?> style="background: var(--bg-primary); color: var(--text-primary);"><?= htmlspecialchars($sem['label']) ?> <?= $sem['is_active'] ? '(Active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-box alert-error">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?= $errorMsg ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert-box alert-success">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php endif; ?>

        <!-- Your Scheduled Sections Card -->
        <div class="card" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 20px; padding: 25px; box-shadow: var(--card-glow); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); margin-bottom: 30px;">
            <div class="card-title" style="font-family: 'Space Grotesque', sans-serif; font-size: 1.25rem; font-weight: 700; margin-bottom: 18px; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                <span>📋</span> Your Scheduled Sections (<?= count($mySections) ?>)
            </div>
            
            <?php if (empty($mySections)): ?>
                <div class="alert-box alert-success" style="background: rgba(168, 85, 247, 0.1); border: 1px solid rgba(168, 85, 247, 0.25); color: var(--accent-primary); margin: 0; display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-radius: 14px; font-size: 0.88rem; font-weight: 600;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    You have not scheduled any sections for <?= htmlspecialchars(getSemesterLabel($pdo, $viewSemId)) ?>.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table style="width: 100%; border-collapse: collapse; min-width: 600px; text-align: left;">
                        <thead>
                            <tr style="background: rgba(168, 85, 247, 0.05);">
                                <th style="padding: 13px 16px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color);">Course Code</th>
                                <th style="padding: 13px 16px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color);">Course Title</th>
                                <th style="padding: 13px 16px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color);">Sec</th>
                                <th style="padding: 13px 16px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color);">Room</th>
                                <th style="padding: 13px 16px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color);">Schedule</th>
                                <th style="padding: 13px 16px; font-size: 0.74rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color);">Enrolled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mySections as $sec): ?>
                                <tr>
                                    <td style="padding: 13px 16px; border-bottom: 1px solid var(--border-color);"><strong><?= htmlspecialchars($sec['course_code']) ?></strong></td>
                                    <td style="padding: 13px 16px; border-bottom: 1px solid var(--border-color); color: var(--text-secondary);"><?= htmlspecialchars($sec['course_title']) ?></td>
                                    <td style="padding: 13px 16px; border-bottom: 1px solid var(--border-color);"><span style="background: rgba(168, 85, 247, 0.12); color: var(--accent-primary); font-weight: 700; padding: 3px 9px; border-radius: 6px; font-size: 0.74rem;">§<?= str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT) ?></span></td>
                                    <td style="padding: 13px 16px; border-bottom: 1px solid var(--border-color); font-family: monospace; color: var(--accent-secondary);"><?= htmlspecialchars($sec['room_no']) ?></td>
                                    <td style="padding: 13px 16px; border-bottom: 1px solid var(--border-color); font-size: 0.8rem; line-height: 1.4;">
                                        <?= htmlspecialchars($sec['theory_day_1'] . ' + ' . $sec['theory_day_2'] . ' · ' . $sec['theory_time_slot']) ?>
                                        <?php if (!empty($sec['lab_day'])): ?>
                                            <br><span style="color: var(--accent-primary);">Lab: <?= htmlspecialchars($sec['lab_day'] . ' · ' . $sec['lab_time_slot'] . (!empty($sec['lab_room_no']) ? ' · Rm ' . $sec['lab_room_no'] : '')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 13px 16px; border-bottom: 1px solid var(--border-color);"><?= intval($sec['enrolled']) ?> / <?= intval($sec['seats']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <h2 class="page-heading" style="font-size: 1.5rem; margin-bottom: 15px;">All Database Courses</h2>
        <div class="course-list">
            <?php if (empty($courses)): ?>
                <div class="alert-box alert-success">No courses found in the database. Add a course first.</div>
            <?php else: ?>
                <?php foreach ($courses as $course): ?>
                    <div class="course-card">
                        <div class="course-info">
                            <h3><?= htmlspecialchars($course['title']) ?></h3>
                            <div class="course-meta">
                                <span class="meta-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> <?= htmlspecialchars($course['code']) ?></span>
                                <span class="meta-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg> Dept: <?= htmlspecialchars($course['department']) ?></span>
                                <span class="meta-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg> Credits: <?= htmlspecialchars($course['credit']) ?></span>
                                <span class="meta-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> Creator: <?= htmlspecialchars($course['teacher_name']) ?></span>
                            </div>
                        </div>
                        <button class="btn-action" onclick="openSectionModal(<?= $course['id'] ?>, '<?= htmlspecialchars(addslashes($course['title'])) ?>', '<?= htmlspecialchars(addslashes($course['code'])) ?>', <?= intval($course['lab_marks']) ?>)">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            Create Section
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section Creation Modal -->
    <div class="modal-overlay" id="sectionModalOverlay">
        <div class="section-modal">
            <button class="modal-close-btn" onclick="closeSectionModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <h2 class="modal-title">Create Section</h2>
            
            <form action="teacher_courses.php" method="POST" id="sectionForm">
                <input type="hidden" name="action" value="create_section">
                <input type="hidden" name="course_id" id="modal_course_id">
                
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">Course Name</label>
                        <input type="text" id="modal_course_title" class="form-input" disabled style="opacity: 0.7;">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Course Code</label>
                        <input type="text" id="modal_course_code" class="form-input" disabled style="opacity: 0.7;">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Section No.</label>
                        <input type="number" name="section_no" class="form-input" placeholder="e.g. 1" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Seats</label>
                        <input type="number" name="seats" class="form-input" placeholder="Max 40" max="40" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Room Number</label>
                        <input type="text" name="room_no" class="form-input" placeholder="e.g. 08A07C" pattern="[0-9]{2}[A-Za-z][0-9]{2}[A-Za-z]" title="Must be formatted like 08A07C" required>
                    </div>

                    <!-- Theory Schedule -->
                    <div class="form-group full-width" style="margin-top:10px; margin-bottom:-5px;">
                        <span class="drawer-section-label" style="font-size:0.8rem; letter-spacing:1px; color:var(--accent-secondary); padding:0;">Theory Schedule</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Theory Day 1</label>
                        <div class="input-wrapper">
                            <select name="theory_day_1" class="form-input" required>
                                <option value="" disabled selected>Select Day 1</option>
                                <?php foreach ($days as $day): ?>
                                    <option value="<?= $day ?>"><?= $day ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Theory Day 2</label>
                        <div class="input-wrapper">
                            <select name="theory_day_2" class="form-input" required>
                                <option value="" disabled selected>Select Day 2</option>
                                <?php foreach ($days as $day): ?>
                                    <option value="<?= $day ?>"><?= $day ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Theory Time</label>
                        <div class="input-wrapper">
                            <select name="theory_time_slot" class="form-input" required>
                                <option value="" disabled selected>Select Theory Time</option>
                                <?php foreach ($time_slots as $slot): ?>
                                    <option value="<?= $slot ?>"><?= $slot ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Lab Schedule -->
                    <div class="form-group full-width" style="margin-top:10px; margin-bottom:-5px;">
                        <span class="drawer-section-label" style="font-size:0.8rem; letter-spacing:1px; color:var(--accent-primary); padding:0;">Lab Schedule</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lab Day</label>
                        <div class="input-wrapper">
                            <select name="lab_day" class="form-input" required>
                                <option value="" disabled selected>Select Lab Day</option>
                                <?php foreach ($days as $day): ?>
                                    <option value="<?= $day ?>"><?= $day ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Lab Time (180 mins)</label>
                        <div class="input-wrapper">
                            <select name="lab_time_slot" class="form-input" required>
                                <option value="" disabled selected>Select Lab Time</option>
                                <?php foreach ($lab_time_slots as $slot): ?>
                                    <option value="<?= $slot ?>"><?= $slot ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Lab Room Number</label>
                        <div class="input-wrapper">
                            <input type="text" name="lab_room_no" class="form-input" placeholder="e.g. 08L01A" pattern="[0-9A-Za-z]+" title="Lab room number (e.g. 08L01A)">
                        </div>
                    </div>
                    
                    <div class="form-group full-width" style="margin-top: 15px;">
                        <button type="submit" class="btn-primary">
                            Create Section
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        const overlay = document.getElementById('sectionModalOverlay');
        
        function openSectionModal(courseId, courseTitle, courseCode, labMarks) {
            document.getElementById('modal_course_id').value = courseId;
            document.getElementById('modal_course_title').value = courseTitle;
            document.getElementById('modal_course_code').value = courseCode;
            
            const labDaySelect  = document.querySelector('select[name="lab_day"]');
            const labTimeSelect = document.querySelector('select[name="lab_time_slot"]');
            const labRoomInput  = document.querySelector('input[name="lab_room_no"]');
            
            if (parseInt(labMarks) === 0) {
                labDaySelect.required  = false;
                labTimeSelect.required = false;
                labRoomInput.required  = false;
                labDaySelect.disabled  = true;
                labTimeSelect.disabled = true;
                labRoomInput.disabled  = true;
                labDaySelect.closest('.form-group').style.opacity  = '0.4';
                labTimeSelect.closest('.form-group').style.opacity = '0.4';
                labRoomInput.closest('.form-group').style.opacity  = '0.4';
            } else {
                labDaySelect.required  = true;
                labTimeSelect.required = true;
                labRoomInput.required  = true;
                labDaySelect.disabled  = false;
                labTimeSelect.disabled = false;
                labRoomInput.disabled  = false;
                labDaySelect.closest('.form-group').style.opacity  = '1';
                labTimeSelect.closest('.form-group').style.opacity = '1';
                labRoomInput.closest('.form-group').style.opacity  = '1';
            }
            
            overlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
        }
        
        function closeSectionModal() {
            overlay.classList.remove('visible');
            document.body.style.overflow = '';
            document.getElementById('sectionForm').reset();
        }
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeSectionModal();
        });
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('visible')) {
                closeSectionModal();
            }
        });

        function updateSemesterFilter(id) {
            const url = new URL(window.location.href);
            url.searchParams.set('view_semester_id', id);
            window.location.href = url.toString();
        }
    </script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
