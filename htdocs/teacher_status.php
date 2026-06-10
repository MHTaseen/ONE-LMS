<?php
// teacher_status.php – Teacher access only
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
$teacherSections = [];
$enrolledStudents = [];

try {
    // Get teacher DB id
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher_db_id = $stmt->fetch()['id'];

    // Fetch all sections for this teacher's courses
    $stmt = $pdo->prepare("
        SELECT cs.*, c.title, c.code,
               (SELECT COUNT(*) FROM enrollments WHERE section_id = cs.id) as current_enrollment
        FROM course_sections cs
        JOIN courses c ON cs.course_id = c.id
        WHERE c.teacher_id = ?
        ORDER BY c.title ASC, cs.section_no ASC
    ");
    $stmt->execute([$teacher_db_id]);
    $teacherSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If there are sections, fetch the students enrolled in them
    if (!empty($teacherSections)) {
        $sectionIds = array_column($teacherSections, 'id');
        $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
        
        $stmt = $pdo->prepare("
            SELECT e.section_id, u.full_name, u.user_id as student_id, u.department
            FROM enrollments e
            JOIN users u ON e.student_id = u.id
            WHERE e.section_id IN ($placeholders)
            ORDER BY u.full_name ASC
        ");
        $stmt->execute($sectionIds);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group students by section_id
        foreach ($students as $student) {
            $enrolledStudents[$student['section_id']][] = $student;
        }
    }

} catch (PDOException $e) {
    $errorMsg = "Failed to load status data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Course Status – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: 64px;
            z-index: 900; display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0,0,0,.25);
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-accent); display: flex; justify-content: center; align-items: center; color: #ffffff; font-weight: 700; box-shadow: var(--glow-shadow); }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        
        .btn-back { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 12px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none; }
        
        .page-wrap { padding: 100px 28px 60px; max-width: 900px; margin: 0 auto; width: 100%; }
        .page-heading { font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem; font-weight: 700; margin-bottom: 6px; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .page-subheading { color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px; }

        /* Status Accordion */
        .status-list { display: flex; flex-direction: column; gap: 15px; }
        .section-accordion {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; overflow: hidden; box-shadow: var(--card-glow);
        }
        
        .section-header {
            padding: 20px 24px; display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; user-select: none; transition: background 0.2s;
        }
        .section-header:hover { background: rgba(168, 85, 247, 0.05); }
        
        .section-title-wrap { display: flex; align-items: center; gap: 15px; }
        .course-code {
            background: var(--input-bg); border: 1px solid var(--accent-secondary);
            color: var(--accent-secondary); font-weight: 700; font-size: 0.85rem;
            padding: 6px 12px; border-radius: 8px; font-family: 'Space Grotesque', sans-serif;
        }
        .course-title { font-size: 1.1rem; font-weight: 600; color: var(--text-primary); font-family: 'Space Grotesque', sans-serif; }
        .sec-badge { background: rgba(6,182,212,0.1); color: var(--accent-primary); padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; margin-left: 10px; }
        
        .header-right { display: flex; align-items: center; gap: 15px; }
        .enrollment-count { font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); }
        .chevron-icon { width: 20px; height: 20px; color: var(--text-secondary); transition: transform 0.3s ease; }
        
        .section-accordion.active .section-header { border-bottom: 1px solid var(--border-color); background: rgba(168, 85, 247, 0.05); }
        .section-accordion.active .chevron-icon { transform: rotate(180deg); color: var(--accent-primary); }
        
        .section-body { max-height: 0; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        
        .students-content { padding: 24px; background: rgba(0,0,0,0.1); }
        .light-theme .students-content { background: rgba(255,255,255,0.3); }
        
        .students-table-wrap { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 20px; font-size: 0.9rem; border-bottom: 1px solid var(--border-color); }
        th { font-size: 0.8rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; letter-spacing: 0.5px; background: rgba(168, 85, 247, 0.1); color: var(--accent-primary); }
        tr:last-child td { border-bottom: none; }
        
        .empty-students { padding: 20px; text-align: center; color: var(--text-secondary); font-style: italic; font-size: 0.9rem; }
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Back
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
        <h1 class="page-heading">Course Status</h1>
        <p class="page-subheading">View the current enrollment status and student list for your active sections.</p>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-box alert-error"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="status-list">
            <?php if (empty($teacherSections)): ?>
                <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">
                    You haven't created any course sections yet. Go to 'Courses' to create some.
                </div>
            <?php else: ?>
                <?php foreach ($teacherSections as $sec): 
                    $secId = $sec['id'];
                    $students = isset($enrolledStudents[$secId]) ? $enrolledStudents[$secId] : [];
                ?>
                <div class="section-accordion">
                    <div class="section-header" onclick="toggleAccordion(this)">
                        <div class="section-title-wrap">
                            <span class="course-code"><?= htmlspecialchars($sec['code']) ?></span>
                            <span class="course-title"><?= htmlspecialchars($sec['title']) ?></span>
                            <span class="sec-badge">Sec <?= str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT) ?></span>
                        </div>
                        <div class="header-right">
                            <span class="enrollment-count"><?= $sec['current_enrollment'] ?> / <?= $sec['seats'] ?> Enrolled</span>
                            <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                    </div>
                    <div class="section-body">
                        <div class="students-content">
                            <div class="students-table-wrap">
                                <?php if (empty($students)): ?>
                                    <div class="empty-students">No students are currently enrolled in this section.</div>
                                <?php else: ?>
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Student Name</th>
                                                <th>Student ID</th>
                                                <th>Department</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $count = 1; foreach ($students as $stu): ?>
                                            <tr>
                                                <td style="color:var(--text-secondary); width:40px;"><?= $count++ ?></td>
                                                <td style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($stu['full_name']) ?></td>
                                                <td style="font-family:monospace; color:var(--accent-secondary);"><?= htmlspecialchars($stu['student_id']) ?></td>
                                                <td><?= htmlspecialchars($stu['department']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleAccordion(element) {
            const accordion = element.parentElement;
            const body = accordion.querySelector('.section-body');
            const isActive = accordion.classList.contains('active');
            
            document.querySelectorAll('.section-accordion').forEach(acc => {
                acc.classList.remove('active');
                acc.querySelector('.section-body').style.maxHeight = null;
            });
            
            if (!isActive) {
                accordion.classList.add('active');
                body.style.maxHeight = body.scrollHeight + "px";
            }
        }
    </script>
</body>
</html>
