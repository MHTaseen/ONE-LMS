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
                // Guard: check if this (course_id, section_no) already exists
                $dupCheck = $pdo->prepare("SELECT id FROM course_sections WHERE course_id = ? AND section_no = ?");
                $dupCheck->execute([$course_id, $section_no]);
                if ($dupCheck->fetch()) {
                    $errorMsg = "Section $section_no already exists for this course. Each section number must be unique within a course.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO course_sections (course_id, teacher_id, section_no, room_no, seats, theory_day_1, theory_day_2, theory_time_slot, lab_day, lab_time_slot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$course_id, $_SESSION['user_pk'], $section_no, $room_no, $seats, $theory_day_1, $theory_day_2, $theory_time_slot, $lab_day, $lab_time_slot]);
                    $successMsg = "Section $section_no created successfully!";
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
            position: fixed; top: 0; left: 0; right: 0; height: 64px;
            z-index: 900; display: flex; align-items: center;
            justify-content: space-between; padding: 0 28px;
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

    <nav class="top-navbar">
        <div class="navbar-left">
            <div class="nav-avatar"><?= $initials ?></div>
            <span class="navbar-brand">BRAC University Hub</span>
        </div>
        <div class="navbar-right">
            <a href="landing.php" class="btn-back">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Back
            </a>
            <div class="theme-switch-container">
                <button id="themeToggleBtn" class="theme-btn" aria-label="Toggle theme">
                    <svg class="sun-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.01c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/></svg>
                    <svg class="moon-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12.3 22h-.1c-5.5 0-10-4.5-10-10 0-4.7 3.3-8.8 8-9.7.3-.1.6 0 .8.2.2.2.3.6.1.8-1.5 2.1-1.1 5.1.9 6.8 1.8 1.6 4.7 1.6 6.5-.1.2-.2.5-.2.8-.1.2.2.3.5.2.8-.9 4.7-5 8-9.7 8z"/></svg>
                    <span>Theme Toggle</span>
                </button>
            </div>
        </div>
    </nav>
<?php include 'includes/shared_drawer.php'; ?>


    <div class="page-wrap">
        <h1 class="page-heading">Database Courses</h1>
        <p class="page-subheading">View all courses and create sections for enrollment.</p>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-box alert-error">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert-box alert-success">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php endif; ?>

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
            
            const labDaySelect = document.querySelector('select[name="lab_day"]');
            const labTimeSelect = document.querySelector('select[name="lab_time_slot"]');
            
            if (parseInt(labMarks) === 0) {
                labDaySelect.required = false;
                labTimeSelect.required = false;
                labDaySelect.disabled = true;
                labTimeSelect.disabled = true;
                labDaySelect.closest('.form-group').style.opacity = '0.4';
                labTimeSelect.closest('.form-group').style.opacity = '0.4';
            } else {
                labDaySelect.required = true;
                labTimeSelect.required = true;
                labDaySelect.disabled = false;
                labTimeSelect.disabled = false;
                labDaySelect.closest('.form-group').style.opacity = '1';
                labTimeSelect.closest('.form-group').style.opacity = '1';
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
    </script>
</body>
</html>
