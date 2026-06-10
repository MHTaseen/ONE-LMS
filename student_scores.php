<?php
// student_scores.php - Student UI for viewing current scores
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

// Fetch enrolled courses and static scores
$stmt = $pdo->prepare("
    SELECT cs.id as section_id, cs.section_no, c.code, c.title, t.full_name as teacher_name,
           e.score_attendance, e.score_mid, e.score_final, e.score_lab
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

// Fetch scores for the active course
$quizzes = [];
$assignments = [];
$avg_quiz = null;
$avg_assignment = null;
$total_score = null;

if ($active_course) {
    // Quizzes
    $stmt = $pdo->prepare("
        SELECT q.quiz_name, qsub.score, qsub.total, qsub.submitted_at
        FROM quiz_submissions qsub
        JOIN quizzes q ON qsub.quiz_id = q.id
        WHERE q.section_id = ? AND qsub.student_id = ?
        ORDER BY qsub.submitted_at ASC
    ");
    $stmt->execute([$active_section, $student_db_id]);
    $quizzes = $stmt->fetchAll();

    // Assignments
    $stmt = $pdo->prepare("
        SELECT a.assignment_name, asub.score, asub.total, asub.submitted_at
        FROM assignment_submissions asub
        JOIN assignments a ON asub.assignment_id = a.id
        WHERE a.section_id = ? AND asub.student_id = ?
        ORDER BY asub.submitted_at ASC
    ");
    $stmt->execute([$active_section, $student_db_id]);
    $assignments = $stmt->fetchAll();

    // --- Compute Averages ---
    // Quiz average: sum of raw scores divided by number of quizzes taken
    $quiz_sum = 0; $quiz_count = 0;
    foreach ($quizzes as $q) {
        $quiz_sum += $q['score'];
        $quiz_count++;
    }
    if ($quiz_count > 0) {
        $avg_quiz = round($quiz_sum / $quiz_count, 2);
    }

    // Assignment average: sum of raw scores divided by number of graded assignments
    $asgn_sum = 0; $asgn_count = 0;
    foreach ($assignments as $a) {
        if ($a['score'] !== null) {
            $asgn_sum += $a['score'];
            $asgn_count++;
        }
    }
    if ($asgn_count > 0) {
        $avg_assignment = round($asgn_sum / $asgn_count, 2);
    }

    // --- Compute Total Score ---
    // Sum fixed scores + average of quiz and assignment (each treated as out of 100)
    $total_parts = [];
    if ($active_course['score_attendance'] !== null) $total_parts[] = $active_course['score_attendance'];
    if ($active_course['score_mid']        !== null) $total_parts[] = $active_course['score_mid'];
    if ($active_course['score_final']      !== null) $total_parts[] = $active_course['score_final'];
    if ($active_course['score_lab']        !== null) $total_parts[] = $active_course['score_lab'];
    if ($avg_quiz       !== null) $total_parts[] = $avg_quiz;
    if ($avg_assignment !== null) $total_parts[] = $avg_assignment;

    if (!empty($total_parts)) {
        $total_score = round(array_sum($total_parts), 2);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Current Scores - BRACU Thesis</title>
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

        .score-section { margin-top: 32px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .score-header { padding: 16px 24px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--border-color); font-weight: 700; color: var(--text-primary); font-size: 1.1rem; }
        
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 16px 24px; border-bottom: 1px solid var(--border-color); }
        th { color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        td { color: var(--text-primary); font-size: 0.95rem; }
        tr:last-child td { border-bottom: none; }

        .score-value { font-weight: 700; font-size: 1.1rem; }
        .score-total { color: var(--text-secondary); font-size: 0.9rem; }
        .badge-pending { background: rgba(245,158,11,0.15); color: #f59e0b; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        
        .static-scores-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .static-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; text-align: center; display: flex; flex-direction: column; justify-content: center; align-items: center; }
        .static-card .label { color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 12px; }
        .static-card .val { font-size: 2rem; font-weight: 700; color: var(--text-primary); font-family: 'Space Grotesque', sans-serif; }
        .total-card { background: linear-gradient(135deg, rgba(168,85,247,0.18), rgba(139,92,246,0.10)); border: 2px solid rgba(168,85,247,0.45); }
        .total-card .label { color: rgba(216,180,254,0.85); }
        .total-card .val { font-size: 2.4rem; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .total-breakdown { font-size: 0.75rem; color: var(--text-secondary); margin-top: 8px; line-height: 1.6; }
        .grade-input-wrap { display: flex; flex-direction: column; gap: 2px; align-items: flex-start; }
        .grade-input-wrap label { font-size: 0.7rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; }
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
        <h1>Current Score</h1>
        <p>View your assignment and quiz grades for your enrolled courses.</p>
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
            <a href="student_scores.php" style="color: var(--accent-primary); text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><polyline points="15 18 9 12 15 6"/></svg> Choose another course
            </a>
            <h2 style="margin-top: 12px; color: var(--text-primary);"><?= htmlspecialchars($active_course['code']) ?> - Section <?= $active_course['section_no'] ?></h2>
        </div>

        <!-- Static Overall Scores -->
        <div class="static-scores-grid">
            <div class="static-card">
                <div class="label">Attendance Score</div>
                <div class="val"><?= $active_course['score_attendance'] !== null ? $active_course['score_attendance'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="static-card">
                <div class="label">Mid Exam</div>
                <div class="val"><?= $active_course['score_mid'] !== null ? $active_course['score_mid'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="static-card total-card">
                <div class="label">Total Obtained Marks</div>
                <div class="val">
                    <?php if ($total_score !== null): ?>
                        <?= $total_score ?>
                    <?php else: ?>
                        <span class="badge-pending">N/A</span>
                    <?php endif; ?>
                </div>
                <?php if ($total_score !== null): ?>
                <div class="total-breakdown">
                    <?php $breakdown_parts = [];
                        if ($active_course['score_attendance'] !== null) $breakdown_parts[] = 'Att: ' . $active_course['score_attendance'];
                        if ($active_course['score_mid']        !== null) $breakdown_parts[] = 'Mid: ' . $active_course['score_mid'];
                        if ($active_course['score_final']      !== null) $breakdown_parts[] = 'Final: ' . $active_course['score_final'];
                        if ($active_course['score_lab']        !== null) $breakdown_parts[] = 'Lab: ' . $active_course['score_lab'];
                        if ($avg_quiz       !== null) $breakdown_parts[] = 'Quiz avg: ' . $avg_quiz;
                        if ($avg_assignment !== null) $breakdown_parts[] = 'Asgn avg: ' . $avg_assignment;
                        echo implode(' + ', $breakdown_parts);
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="static-card">
                <div class="label">Final Exam</div>
                <div class="val"><?= $active_course['score_final'] !== null ? $active_course['score_final'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="static-card">
                <div class="label">Lab Score</div>
                <div class="val"><?= $active_course['score_lab'] !== null ? $active_course['score_lab'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
        </div>

        <!-- Quizzes Table -->
        <div class="score-section">
            <div class="score-header">Quiz Scores</div>
            <?php if (empty($quizzes)): ?>
                <div style="padding: 24px; color: var(--text-secondary); text-align: center;">No quizzes taken yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Quiz Name</th>
                            <th>Submitted At</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quizzes as $q): ?>
                        <tr>
                            <td><?= htmlspecialchars($q['quiz_name']) ?></td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);"><?= date('M j, Y, g:i A', strtotime($q['submitted_at'])) ?></span></td>
                            <td>
                                <span class="score-value"><?= $q['score'] ?></span>
                                <span class="score-total">/ <?= $q['total'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Assignments Table -->
        <div class="score-section" style="margin-top: 32px;">
            <div class="score-header">Assignment Scores</div>
            <?php if (empty($assignments)): ?>
                <div style="padding: 24px; color: var(--text-secondary); text-align: center;">No assignments submitted yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Assignment Name</th>
                            <th>Submitted At</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['assignment_name']) ?></td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);"><?= date('M j, Y, g:i A', strtotime($a['submitted_at'])) ?></span></td>
                            <td>
                                <?php if ($a['score'] !== null): ?>
                                    <span class="score-value"><?= $a['score'] ?></span>
                                    <span class="score-total">/ <?= $a['total'] ?></span>
                                <?php else: ?>
                                    <span class="badge-pending">Pending Grading</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="theme.js"></script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
