<?php
// student_attendance.php - Student UI for viewing attendance records
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$student_db_id = $_SESSION['user_pk'] ?? 0;
if (!$student_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $student_db_id = $row['id'] ?? 0;
}

// Fetch enrolled courses
$stmt = $pdo->prepare("
    SELECT cs.id as section_id, cs.section_no, c.code, c.title, t.full_name as teacher_name
    FROM enrollments e
    JOIN course_sections cs ON e.section_id = cs.id
    JOIN courses c ON cs.course_id = c.id
    JOIN users t ON c.teacher_id = t.id
    WHERE e.student_id = ?
");
$stmt->execute([$student_db_id]);
$courses = $stmt->fetchAll();

// Active course logic
$active_section = intval($_GET['section_id'] ?? 0);
$active_course = null;
if ($active_section) {
    foreach ($courses as $c) {
        if ($c['section_id'] == $active_section) {
            $active_course = $c;
            break;
        }
    }
}

// Fetch attendance for the active course
$attendance = [];
$summary = ['Present' => 0, 'Absent' => 0, 'Late' => 0, 'Total' => 0];

if ($active_course) {
    $stmt = $pdo->prepare("
        SELECT attendance_date, status
        FROM attendance
        WHERE section_id = ? AND student_id = ?
        ORDER BY attendance_date DESC
    ");
    $stmt->execute([$active_section, $student_db_id]);
    $attendance = $stmt->fetchAll();

    foreach ($attendance as $record) {
        $summary['Total']++;
        if ($record['status'] === 'Present') $summary['Present']++;
        elseif ($record['status'] === 'Absent') $summary['Absent']++;
        elseif ($record['status'] === 'Late') $summary['Late']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Attendance Record - BRACU Thesis</title>
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

        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .course-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 24px; text-decoration: none; display: block;
            transition: all 0.3s;
        }
        .course-card:hover { transform: translateY(-4px); border-color: rgba(168,85,247,0.4); box-shadow: var(--card-glow); }
        .course-card h3 { color: var(--text-primary); font-size: 1.25rem; margin-bottom: 8px; }
        .course-card p { color: var(--text-secondary); font-size: 0.9rem; }
        .t-badge {
            display: inline-block; background: rgba(168,85,247,0.15); color: var(--accent-primary);
            padding: 4px 8px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; margin-top: 12px;
        }

        .summary-cards { display: flex; gap: 16px; margin-bottom: 32px; }
        .stat-card { flex: 1; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; text-align: center; }
        .stat-card .label { color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 8px; }
        .stat-card .value { font-size: 1.8rem; font-weight: 700; color: var(--text-primary); font-family: 'Space Grotesque', sans-serif; }
        .val-present { color: #10b981 !important; }
        .val-absent { color: #ef4444 !important; }
        .val-late { color: #f59e0b !important; }

        .table-wrap { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 16px 24px; border-bottom: 1px solid var(--border-color); }
        th { background: rgba(255,255,255,0.02); color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        td { color: var(--text-primary); font-size: 0.95rem; }
        tr:last-child td { border-bottom: none; }

        .status-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; }
        .badge-present { background: rgba(16,185,129,0.15); color: #10b981; }
        .badge-absent { background: rgba(239,68,68,0.15); color: #ef4444; }
        .badge-late { background: rgba(245,158,11,0.15); color: #f59e0b; }
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

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-container">
    <div class="header">
        <h1>Attendance</h1>
        <p>Track your daily attendance record for your enrolled courses.</p>
    </div>

    <?php if (!$active_course): ?>
        <?php if (empty($courses)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                You are not enrolled in any courses.
            </div>
        <?php else: ?>
            <div class="course-grid">
                <?php foreach ($courses as $c): ?>
                <a href="?section_id=<?= $c['section_id'] ?>" class="course-card">
                    <h3><?= htmlspecialchars($c['code']) ?></h3>
                    <p><?= htmlspecialchars($c['title']) ?></p>
                    <div class="t-badge">Section <?= $c['section_no'] ?> &bull; <?= htmlspecialchars($c['teacher_name']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div style="margin-bottom: 24px;">
            <a href="student_attendance.php" style="color: var(--accent-primary); text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><polyline points="15 18 9 12 15 6"/></svg> Choose another course
            </a>
            <h2 style="margin-top: 12px; color: var(--text-primary);"><?= htmlspecialchars($active_course['code']) ?> - Section <?= $active_course['section_no'] ?></h2>
        </div>

        <?php if (empty($attendance)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                No attendance records have been posted for this course yet.
            </div>
        <?php else: ?>
            <div class="summary-cards">
                <div class="stat-card">
                    <div class="label">Total Classes</div>
                    <div class="value"><?= $summary['Total'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Present</div>
                    <div class="value val-present"><?= $summary['Present'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Late</div>
                    <div class="value val-late"><?= $summary['Late'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Absent</div>
                    <div class="value val-absent"><?= $summary['Absent'] ?></div>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $a): ?>
                        <tr>
                            <td><span style="font-weight: 500;"><?= date('l, M j, Y', strtotime($a['attendance_date'])) ?></span></td>
                            <td>
                                <?php if ($a['status'] === 'Present'): ?>
                                    <span class="status-badge badge-present">Present</span>
                                <?php elseif ($a['status'] === 'Absent'): ?>
                                    <span class="status-badge badge-absent">Absent</span>
                                <?php else: ?>
                                    <span class="status-badge badge-late">Late</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="theme.js"></script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
