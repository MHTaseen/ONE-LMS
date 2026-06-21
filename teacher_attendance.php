<?php
// teacher_attendance.php - Teacher UI for marking attendance
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$teacher_db_id = $_SESSION['user_pk'] ?? 0;
if (!$teacher_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $teacher_db_id = $row['id'] ?? 0;
}

// Fetch teacher's sections
$stmt = $pdo->prepare("
    SELECT cs.id as section_id, cs.section_no, c.code, c.title
    FROM course_sections cs
    JOIN courses c ON cs.course_id = c.id
    WHERE c.teacher_id = ?
");
$stmt->execute([$teacher_db_id]);
$sections = $stmt->fetchAll();

// Determine active section
$active_section = intval($_GET['section_id'] ?? 0);
$active_section_data = null;
if ($active_section) {
    foreach ($sections as $s) {
        if ($s['section_id'] == $active_section) {
            $active_section_data = $s;
            break;
        }
    }
}

// Handle Attendance Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $date = $_POST['attendance_date'];
    $attendance_data = $_POST['status'] ?? [];
    
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO attendance (section_id, student_id, attendance_date, status)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status)
        ");
        
        foreach ($attendance_data as $student_id => $status) {
            $stmt->execute([$active_section, $student_id, $date, $status]);
        }
        $pdo->commit();
        $successMsg = "Attendance saved successfully for $date.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errorMsg = "Failed to save attendance: " . $e->getMessage();
    }
}

// If section selected, fetch enrolled students and current date's attendance if any
$students = [];
$selected_date = $_GET['date'] ?? date('Y-m-d');

if ($active_section_data) {
    $stmt = $pdo->prepare("
        SELECT s.id as student_db_id, s.full_name as student_name, s.user_id as student_id_str,
               COALESCE(a.status, 'Present') as current_status
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        LEFT JOIN attendance a ON a.student_id = s.id AND a.section_id = e.section_id AND a.attendance_date = ?
        WHERE e.section_id = ?
        ORDER BY s.full_name ASC
    ");
    $stmt->execute([$selected_date, $active_section]);
    $students = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Take Attendance - BRACU Thesis</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center; padding: 10px 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: color 0.2s; }
        .nav-btn-back:hover { color: var(--accent-primary); }
        .nav-btn-back svg { width: 18px; height: 18px; }

        .page-container {
            padding: 100px 40px 40px;
            max-width: 1000px;
            margin: 0 auto;
            min-height: 100vh;
        }
        .header h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2.2rem;
            background: var(--gradient-accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        .header p { color: var(--text-secondary); font-size: 1.1rem; margin-bottom: 30px; }

        /* Grid for Sections */
        .section-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .section-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 24px; text-decoration: none; display: block;
            transition: all 0.3s;
        }
        .section-card:hover { transform: translateY(-4px); border-color: rgba(168,85,247,0.4); box-shadow: var(--card-glow); }
        .section-card h3 { color: var(--text-primary); font-size: 1.25rem; margin-bottom: 8px; }
        .section-card p { color: var(--text-secondary); font-size: 0.9rem; }

        /* Attendance Table */
        .controls-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: var(--bg-secondary); padding: 16px 24px; border-radius: 16px; border: 1px solid var(--border-color); }
        .date-picker { padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-primary); font-family: inherit; font-size: 1rem; outline: none; }
        .date-picker:focus { border-color: var(--accent-primary); }
        
        .table-wrap {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 16px 24px; border-bottom: 1px solid var(--border-color); }
        th { background: rgba(255,255,255,0.02); font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: var(--text-primary); font-size: 0.95rem; }
        tr:last-child td { border-bottom: none; }
        .student-info { display: flex; flex-direction: column; }
        .s-name { font-weight: 600; }
        .s-id { font-size: 0.8rem; color: var(--text-secondary); }

        .radio-group { display: flex; gap: 24px; }
        .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; color: var(--text-secondary); transition: 0.2s; }
        .radio-label:hover { color: var(--text-primary); }
        .radio-label input[type="radio"] { accent-color: var(--accent-primary); width: 16px; height: 16px; cursor: pointer; }
        
        .radio-label.present input:checked { accent-color: #10b981; }
        .radio-label.absent input:checked { accent-color: #ef4444; }
        .radio-label.late input:checked { accent-color: #f59e0b; }

        .btn-submit {
            padding: 12px 24px; background: var(--gradient-accent); color: #fff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; display: inline-flex; justify-content: center; width: 100%; margin-top: 24px;
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: var(--glow-shadow); }
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
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-container">
    <div class="header">
        <h1>Attendance</h1>
        <p>Manage and track daily student attendance for your sections.</p>
    </div>

    <?php if (isset($successMsg)): ?>
        <div style="background: rgba(16,185,129,0.1); border: 1px solid #10b981; color: #10b981; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>
    <?php if (isset($errorMsg)): ?>
        <div style="background: rgba(239,68,68,0.1); border: 1px solid #ef4444; color: #ef4444; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if (!$active_section_data): ?>
        <!-- SECTION SELECTION -->
        <?php if (empty($sections)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                You are not teaching any courses.
            </div>
        <?php else: ?>
            <div class="section-grid">
                <?php foreach ($sections as $s): ?>
                <a href="?section_id=<?= $s['section_id'] ?>" class="section-card">
                    <h3><?= htmlspecialchars($s['code']) ?></h3>
                    <p><?= htmlspecialchars($s['title']) ?> &bull; Section <?= $s['section_no'] ?></p>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ATTENDANCE INTERFACE -->
        <div style="margin-bottom: 24px;">
            <a href="teacher_attendance.php" style="color: var(--accent-primary); text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><polyline points="15 18 9 12 15 6"/></svg> Change Section
            </a>
            <h2 style="margin-top: 12px; color: var(--text-primary);"><?= htmlspecialchars($active_section_data['code']) ?> - Section <?= $active_section_data['section_no'] ?></h2>
        </div>

        <form method="GET" action="" id="dateForm">
            <input type="hidden" name="section_id" value="<?= $active_section ?>">
            <div class="controls-bar">
                <div style="color:var(--text-secondary); font-weight:500;">Select Date:</div>
                <input type="date" name="date" class="date-picker" value="<?= htmlspecialchars($selected_date) ?>" onchange="document.getElementById('dateForm').submit();" max="<?= date('Y-m-d') ?>">
            </div>
        </form>

        <?php if (empty($students)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                No students enrolled in this section yet.
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selected_date) ?>">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Attendance Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <span class="s-name"><?= htmlspecialchars($s['student_name']) ?></span>
                                        <span class="s-id"><?= htmlspecialchars($s['student_id_str']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="radio-group">
                                        <label class="radio-label present">
                                            <input type="radio" name="status[<?= $s['student_db_id'] ?>]" value="Present" <?= $s['current_status'] === 'Present' ? 'checked' : '' ?> required> Present
                                        </label>
                                        <label class="radio-label absent">
                                            <input type="radio" name="status[<?= $s['student_db_id'] ?>]" value="Absent" <?= $s['current_status'] === 'Absent' ? 'checked' : '' ?> required> Absent
                                        </label>
                                        <label class="radio-label late">
                                            <input type="radio" name="status[<?= $s['student_db_id'] ?>]" value="Late" <?= $s['current_status'] === 'Late' ? 'checked' : '' ?> required> Late
                                        </label>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" name="save_attendance" class="btn-submit">Save Attendance</button>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script src="theme.js"></script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
