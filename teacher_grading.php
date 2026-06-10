<?php
// teacher_grading.php - Teacher UI for grading assignments & viewing quizzes
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'includes/grading.php';

$teacher_db_id = $_SESSION['user_pk'] ?? 0;
if (!$teacher_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $teacher_db_id = $row['id'] ?? 0;
}

// Handle grading submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['grade_submission'])) {
        $sub_id = intval($_POST['submission_id']);
        $score  = intval($_POST['score']);
        $total  = intval($_POST['total']);
        
        $sub_type = $_POST['sub_type'] ?? 'assignment';
        
        try {
            if ($sub_type === 'quiz') {
                $stmt = $pdo->prepare("
                    UPDATE quiz_submissions 
                    SET score = ?, total = ? 
                    WHERE id = ? AND quiz_id IN (
                        SELECT q.id FROM quizzes q
                        JOIN course_sections cs ON q.section_id = cs.id
                        JOIN courses c ON cs.course_id = c.id
                        WHERE c.teacher_id = ?
                    )
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE assignment_submissions 
                    SET score = ?, total = ? 
                    WHERE id = ? AND assignment_id IN (
                        SELECT a.id FROM assignments a
                        JOIN course_sections cs ON a.section_id = cs.id
                        JOIN courses c ON cs.course_id = c.id
                        WHERE c.teacher_id = ?
                    )
                ");
            }
            $stmt->execute([$score, $total, $sub_id, $teacher_db_id]);
            $successMsg = "Grade successfully updated.";
        } catch (PDOException $e) {
            $errorMsg = "Grading failed: " . $e->getMessage();
        }
    } elseif (isset($_POST['save_static_score'])) {
        $score_type = $_POST['score_type'];
        $student_id = intval($_POST['student_id']);
        $section_id = intval($_POST['section_id']);
        $score_val  = $_POST['score'] === '' ? null : intval($_POST['score']);
        
        $valid_cols = ['score_attendance', 'score_mid', 'score_final', 'score_lab'];
        if (in_array($score_type, $valid_cols)) {
            try {
                $stmt = $pdo->prepare("UPDATE enrollments SET $score_type = ? WHERE student_id = ? AND section_id = ?");
                $stmt->execute([$score_val, $student_id, $section_id]);
                $successMsg = "Score saved successfully.";
            } catch (PDOException $e) {
                $errorMsg = "Failed to save score: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['submit_total_score']) || isset($_POST['submit_all_total_scores'])) {
        $section_id = intval($_POST['section_id']);
        if (!teacherOwnsSection($pdo, $teacher_db_id, $section_id)) {
            $errorMsg = "Unauthorized section access.";
        } else {
            $stmtS = $pdo->prepare(
                "SELECT student_id, score_attendance, score_mid, score_final, score_lab
                 FROM enrollments WHERE section_id = ?"
            );
            $stmtS->execute([$section_id]);
            $rows = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            $submitAll = isset($_POST['submit_all_total_scores']);
            $targetStudent = intval($_POST['student_id'] ?? 0);
            $updated = 0;
            $skipped = 0;

            try {
                $upd = $pdo->prepare(
                    "UPDATE enrollments SET score_total = ?, score_published = 0
                     WHERE student_id = ? AND section_id = ?"
                );
                foreach ($rows as $sRow) {
                    if (!$submitAll && intval($sRow['student_id']) !== $targetStudent) {
                        continue;
                    }
                    $sTotal = computeEnrollmentTotal($sRow);
                    if ($sTotal === null) {
                        $skipped++;
                        continue;
                    }
                    $upd->execute([$sTotal, $sRow['student_id'], $section_id]);
                    $updated++;
                }

                if ($updated > 0) {
                    $successMsg = $submitAll
                        ? "Total scores submitted for {$updated} student(s). Review grades below and publish individually."
                        : "Total score submitted. Review the grade below and publish when ready.";
                    if ($skipped > 0 && $submitAll) {
                        $successMsg .= " ({$skipped} student(s) skipped — Final score not entered.)";
                    }
                } else {
                    $errorMsg = $submitAll
                        ? "No totals submitted. Enter Final exam scores for at least one student first."
                        : "Cannot submit total: Final exam score not yet entered for this student.";
                }
            } catch (PDOException $e) {
                $errorMsg = "Failed: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['publish_score'])) {
        $student_id = intval($_POST['student_id']);
        $section_id = intval($_POST['section_id']);
        if (!teacherOwnsSection($pdo, $teacher_db_id, $section_id)) {
            $errorMsg = "Unauthorized section access.";
        } else {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE enrollments SET score_published = 1
                     WHERE student_id = ? AND section_id = ? AND score_total IS NOT NULL"
                );
                $stmt->execute([$student_id, $section_id]);
                if ($stmt->rowCount() > 0) {
                    $successMsg = "Grade published. The student can now view it on their Grade Sheet.";
                } else {
                    $errorMsg = "Cannot publish: submit the total score for this student first.";
                }
            } catch (PDOException $e) {
                $errorMsg = "Publish failed: " . $e->getMessage();
            }
        }
    }
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

// Determine active section (preserve after POST)
$active_section = intval($_GET['section_id'] ?? $_POST['section_id'] ?? 0);
$active_section_data = null;
if ($active_section) {
    foreach ($sections as $s) {
        if ($s['section_id'] == $active_section) {
            $active_section_data = $s;
            break;
        }
    }
}

// If section selected, fetch assignments, quizzes submissions and enrolled students
$assignments = [];
$quizzes = [];
$enrolled_students = [];

if ($active_section_data) {
    // Fetch assignment submissions
    $stmt = $pdo->prepare("
        SELECT asub.id as sub_id, a.assignment_name, s.full_name as student_name, s.user_id as student_id_str,
               asub.original_filename, asub.file_path, asub.submitted_at, asub.score, asub.total
        FROM assignment_submissions asub
        JOIN assignments a ON asub.assignment_id = a.id
        JOIN users s ON asub.student_id = s.id
        WHERE a.section_id = ?
        ORDER BY asub.submitted_at DESC
    ");
    $stmt->execute([$active_section]);
    $assignments = $stmt->fetchAll();

    // Fetch quiz submissions
    $stmt = $pdo->prepare("
        SELECT qsub.id as sub_id, q.quiz_name, q.quiz_type, s.full_name as student_name, s.user_id as student_id_str,
               qsub.score, qsub.total, qsub.submitted_at, qsub.solution_file_path
        FROM quiz_submissions qsub
        JOIN quizzes q ON qsub.quiz_id = q.id
        JOIN users s ON qsub.student_id = s.id
        WHERE q.section_id = ?
        ORDER BY qsub.submitted_at DESC
    ");
    $stmt->execute([$active_section]);
    $quizzes = $stmt->fetchAll();

    // Fetch enrolled students for static scores (incl. score_total)
    $stmt = $pdo->prepare("
        SELECT s.id as student_db_id, s.full_name as student_name, s.user_id as student_id_str,
               e.score_attendance, e.score_mid, e.score_final, e.score_lab, e.score_total, e.score_published
        FROM enrollments e
        JOIN users s ON e.student_id = s.id
        WHERE e.section_id = ?
        ORDER BY s.full_name ASC
    ");
    $stmt->execute([$active_section]);
    $enrolled_students = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Submit Current Score - BRACU Thesis</title>
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
            max-width: 1200px;
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

        /* Grading Table */
        .table-wrap {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; overflow: hidden; margin-top: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 16px 20px; border-bottom: 1px solid var(--border-color); }
        th { background: rgba(255,255,255,0.02); font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: var(--text-primary); font-size: 0.95rem; }
        tr:last-child td { border-bottom: none; }
        .student-info { display: flex; flex-direction: column; }
        .s-name { font-weight: 600; }
        .s-id { font-size: 0.8rem; color: var(--text-secondary); }

        .grade-form { display: flex; align-items: center; gap: 8px; }
        .grade-input { width: 70px; padding: 8px 10px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-primary); text-align: center; font-size: 0.95rem; }
        .grade-input:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 2px rgba(168,85,247,0.2); }
        .grade-input-group { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .grade-input-group label { font-size: 0.68rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; }
        .grade-sep { color: var(--text-secondary); font-size: 1.2rem; font-weight: 300; padding-top: 14px; }
        .btn-save { padding: 8px 16px; border-radius: 8px; border: none; background: rgba(168,85,247,0.1); color: var(--accent-primary); font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; margin-top: 4px; }
        .btn-save:hover { background: var(--accent-primary); color: #fff; }

        .btn-dl { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; background: rgba(255,255,255,0.05); color: var(--text-primary); text-decoration: none; font-size: 0.85rem; border: 1px solid var(--border-color); transition: 0.2s; }
        .btn-dl:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); }

        .badge-graded { background: rgba(16,185,129,0.15); color: #10b981; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-pending { background: rgba(245,158,11,0.15); color: #f59e0b; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-published { background: rgba(16,185,129,0.15); color: #10b981; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .badge-draft { background: rgba(148,163,184,0.15); color: #94a3b8; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .btn-publish { padding: 8px 16px; border-radius: 8px; border: 1px solid rgba(16,185,129,0.35); background: rgba(16,185,129,0.12); color: #10b981; font-weight: 600; cursor: pointer; transition: all 0.2s; font-size: 0.9rem; }
        .btn-publish:hover { background: #10b981; color: #fff; }
        .btn-publish:disabled, .btn-publish.published { opacity: 0.55; cursor: default; background: rgba(16,185,129,0.08); }
        .grade-letter { font-weight: 700; font-size: 1.05rem; color: var(--accent-primary); font-family: 'Space Grotesque', sans-serif; }
        .grade-point { font-weight: 700; color: #10b981; }
        .total-actions-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .section-heading-sm { font-size: 1rem; font-weight: 600; color: var(--text-primary); margin: 28px 0 12px; }
        
        .section-tabs { display: flex; gap: 16px; margin-bottom: 24px; }
        .tab-btn { padding: 10px 20px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 10px; color: var(--text-secondary); font-weight: 600; cursor: pointer; transition: 0.2s; }
        .tab-btn.active { background: var(--gradient-accent); color: #fff; border-color: transparent; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
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
        <h1>Submit Current Score</h1>
        <p>Review and grade student assignments and quizzes.</p>
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
        <!-- GRADING INTERFACE -->
        <div style="margin-bottom: 24px;">
            <a href="teacher_grading.php" style="color: var(--accent-primary); text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><polyline points="15 18 9 12 15 6"/></svg> Change Section
            </a>
            <h2 style="margin-top: 12px; color: var(--text-primary);"><?= htmlspecialchars($active_section_data['code']) ?> - Section <?= $active_section_data['section_no'] ?></h2>
        </div>

        <div class="section-tabs">
            <button class="tab-btn active" onclick="switchTab('assignments')">Assignments</button>
            <button class="tab-btn" onclick="switchTab('quizzes')">Quizzes</button>
            <button class="tab-btn" onclick="switchTab('attendance')">Attendance Score</button>
            <button class="tab-btn" onclick="switchTab('mid')">Mid</button>
            <button class="tab-btn" onclick="switchTab('final')">Final</button>
            <button class="tab-btn" onclick="switchTab('lab')">Lab</button>
            <button class="tab-btn" onclick="switchTab('totalscore')" style="background:linear-gradient(135deg,rgba(168,85,247,.15),rgba(236,72,153,.12)); border-color:rgba(168,85,247,.3); color:var(--accent-primary);">Submit Total Score</button>
        </div>

        <!-- Assignments Tab -->
        <div id="tab-assignments" class="tab-content active">
            <?php if (empty($assignments)): ?>
                <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                    No assignment submissions yet.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assignment</th>
                                <th>File</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Marks Obtained &nbsp;/&nbsp; Total Marks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $a): 
                                $isGraded = ($a['score'] !== null);
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <span class="s-name"><?= htmlspecialchars($a['student_name']) ?></span>
                                        <span class="s-id"><?= htmlspecialchars($a['student_id_str']) ?></span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($a['assignment_name']) ?></td>
                                <td>
                                    <a href="download_submission.php?id=<?= $a['sub_id'] ?>&type=assignment" class="btn-dl">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px; height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download
                                    </a>
                                </td>
                                <td><span style="font-size:0.85rem; color:var(--text-secondary);"><?= date('M j, g:i A', strtotime($a['submitted_at'])) ?></span></td>
                                <td>
                                    <?php if ($isGraded): ?>
                                        <span class="badge-graded">Graded</span>
                                    <?php else: ?>
                                        <span class="badge-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" class="grade-form">
                                        <input type="hidden" name="section_id" value="<?= $active_section ?>">
                                        <input type="hidden" name="submission_id" value="<?= $a['sub_id'] ?>">
                                        <div class="grade-input-group">
                                            <label>Obtained</label>
                                            <input type="number" name="score" class="grade-input" required min="0" value="<?= $isGraded ? $a['score'] : '' ?>" placeholder="0">
                                        </div>
                                        <span class="grade-sep">/</span>
                                        <div class="grade-input-group">
                                            <label>Out of</label>
                                            <input type="number" name="total" class="grade-input" required min="1" value="<?= $isGraded ? $a['total'] : 100 ?>" placeholder="100">
                                        </div>
                                        <button type="submit" name="grade_submission" class="btn-save">Save</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quizzes Tab -->
        <div id="tab-quizzes" class="tab-content">
            <?php if (empty($quizzes)): ?>
                <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                    No quiz submissions yet.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Quiz</th>
                                <th>File</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Score &nbsp;/&nbsp; Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($quizzes as $q): 
                                $isGraded = ($q['score'] !== null);
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <span class="s-name"><?= htmlspecialchars($q['student_name']) ?></span>
                                        <span class="s-id"><?= htmlspecialchars($q['student_id_str']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?= htmlspecialchars($q['quiz_name']) ?>
                                    <div style="font-size:0.8rem; color:var(--text-secondary);"><?= $q['quiz_type'] === 'file' ? 'File Upload' : 'Auto-graded' ?></div>
                                </td>
                                <td>
                                    <?php if ($q['quiz_type'] === 'file' && !empty($q['solution_file_path'])): ?>
                                        <a href="download_submission.php?id=<?= $q['sub_id'] ?>&type=quiz" class="btn-dl">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px; height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--text-secondary); font-size: 0.85rem;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="font-size:0.85rem; color:var(--text-secondary);"><?= date('M j, g:i A', strtotime($q['submitted_at'])) ?></span></td>
                                <td>
                                    <?php if ($q['quiz_type'] === 'file'): ?>
                                        <?php if ($isGraded): ?>
                                            <span class="badge-graded">Graded</span>
                                        <?php else: ?>
                                            <span class="badge-pending">Pending</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge-graded">Graded</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($q['quiz_type'] === 'file'): ?>
                                        <form method="POST" class="grade-form">
                                            <input type="hidden" name="section_id" value="<?= $active_section ?>">
                                            <input type="hidden" name="submission_id" value="<?= $q['sub_id'] ?>">
                                            <input type="hidden" name="sub_type" value="quiz">
                                            <div class="grade-input-group">
                                                <label>Obtained</label>
                                                <input type="number" name="score" class="grade-input" required min="0" value="<?= $isGraded ? $q['score'] : '' ?>" placeholder="0">
                                            </div>
                                            <span class="grade-sep">/</span>
                                            <div class="grade-input-group">
                                                <label>Out of</label>
                                                <input type="number" name="total" class="grade-input" required min="1" value="<?= $isGraded ? $q['total'] : ($q['total'] ?? 10) ?>">
                                            </div>
                                            <button type="submit" name="grade_submission" class="btn-save">Save</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-weight:700; color:var(--text-primary); font-size:1.1rem;"><?= $q['score'] ?></span>
                                        <span style="color:var(--text-secondary);">/ <?= $q['total'] ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Static Scores Tabs Generator -->
        <?php 
        $static_tabs = [
            'attendance' => ['id' => 'tab-attendance', 'col' => 'score_attendance', 'label' => 'Attendance'],
            'mid' => ['id' => 'tab-mid', 'col' => 'score_mid', 'label' => 'Mid Exam'],
            'final' => ['id' => 'tab-final', 'col' => 'score_final', 'label' => 'Final Exam'],
            'lab' => ['id' => 'tab-lab', 'col' => 'score_lab', 'label' => 'Lab Score']
        ];
        
        foreach ($static_tabs as $tab_key => $tab_info):
        ?>
        <div id="<?= $tab_info['id'] ?>" class="tab-content">
            <?php if (empty($enrolled_students)): ?>
                <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                    No students enrolled yet.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th><?= $tab_info['label'] ?> Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrolled_students as $s): 
                                $current_score = $s[$tab_info['col']];
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <span class="s-name"><?= htmlspecialchars($s['student_name']) ?></span>
                                        <span class="s-id"><?= htmlspecialchars($s['student_id_str']) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <form method="POST" class="grade-form">
                                        <input type="hidden" name="section_id" value="<?= $active_section ?>">
                                        <input type="hidden" name="score_type" value="<?= $tab_info['col'] ?>">
                                        <input type="hidden" name="student_id" value="<?= $s['student_db_id'] ?>">
                                        <input type="number" name="score" class="grade-input" min="0" value="<?= $current_score !== null ? $current_score : '' ?>" placeholder="Score">
                                        <button type="submit" name="save_static_score" class="btn-save">Save</button>
                                        <?php if ($current_score !== null): ?>
                                            <span class="badge-graded" style="margin-left: 12px;">Saved</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- ─── SUBMIT TOTAL SCORE TAB ─── -->
        <div id="tab-totalscore" class="tab-content">
            <div style="background:rgba(168,85,247,.07); border:1px solid rgba(168,85,247,.2); border-radius:12px; padding:14px 18px; margin-bottom:20px; font-size:0.9rem; color:var(--text-secondary);">
                <strong style="color:var(--accent-primary);">How it works:</strong>
                Total = Attendance + Mid + Final + Lab. Submit totals for each student (or all at once), then review letter grades and grade points below. Use <em>Publish</em> to release a student's grade to their Grade Sheet.
            </div>
            <?php if (empty($enrolled_students)): ?>
                <div style="padding:40px; text-align:center; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:16px; color:var(--text-secondary);">No students enrolled yet.</div>
            <?php else: ?>
                <?php
                $eligibleCount = 0;
                $submittedCount = 0;
                foreach ($enrolled_students as $ts) {
                    if ($ts['score_final'] !== null) $eligibleCount++;
                    if ($ts['score_total'] !== null) $submittedCount++;
                }
                ?>
                <div class="total-actions-bar">
                    <div style="color:var(--text-secondary); font-size:0.9rem;">
                        <?= $eligibleCount ?> student(s) ready &bull; <?= $submittedCount ?> total(s) submitted
                    </div>
                    <form method="POST">
                        <input type="hidden" name="section_id" value="<?= $active_section ?>">
                        <button type="submit" name="submit_all_total_scores" class="btn-save"
                            style="background:linear-gradient(135deg,rgba(168,85,247,.25),rgba(236,72,153,.18)); color:var(--accent-primary); border:1px solid rgba(168,85,247,.35); padding:10px 18px;"
                            <?= $eligibleCount > 0 ? '' : 'disabled title="Enter Final scores first"' ?>>
                            Submit Total Scores (All Ready)
                        </button>
                    </form>
                </div>

                <h3 class="section-heading-sm">Component Scores</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th style="text-align:center;">Attendance</th>
                                <th style="text-align:center;">Mid</th>
                                <th style="text-align:center;">Final</th>
                                <th style="text-align:center;">Lab</th>
                                <th style="text-align:center;">Computed Total</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($enrolled_students as $ts):
                            $canSubmitTotal = ($ts['score_final'] !== null);
                            $computedTotal  = computeEnrollmentTotal($ts);
                            $alreadySubmitted = ($ts['score_total'] !== null);
                        ?>
                        <tr>
                            <td>
                                <div class="student-info">
                                    <span class="s-name"><?= htmlspecialchars($ts['student_name']) ?></span>
                                    <span class="s-id"><?= htmlspecialchars($ts['student_id_str']) ?></span>
                                </div>
                            </td>
                            <td style="text-align:center; color:var(--text-secondary);"><?= $ts['score_attendance'] ?? '—' ?></td>
                            <td style="text-align:center; color:var(--text-secondary);"><?= $ts['score_mid']        ?? '—' ?></td>
                            <td style="text-align:center; color:var(--text-secondary);"><?= $ts['score_final']      ?? '—' ?></td>
                            <td style="text-align:center; color:var(--text-secondary);"><?= $ts['score_lab']        ?? '—' ?></td>
                            <td style="text-align:center; font-weight:700; font-size:1.05rem; color:var(--text-primary);">
                                <?= $canSubmitTotal ? $computedTotal : '<span style="color:var(--text-secondary); font-size:0.85rem;">Awaiting Final</span>' ?>
                            </td>
                            <td style="text-align:center;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="student_id" value="<?= $ts['student_db_id'] ?>">
                                    <input type="hidden" name="section_id" value="<?= $active_section ?>">
                                    <button type="submit" name="submit_total_score"
                                        <?= $canSubmitTotal ? '' : 'disabled' ?>
                                        class="btn-save"
                                        style="<?= $canSubmitTotal
                                            ? 'background:linear-gradient(135deg,rgba(168,85,247,.2),rgba(236,72,153,.15)); color:var(--accent-primary); border:1px solid rgba(168,85,247,.3);'
                                            : 'opacity:.35; cursor:not-allowed;' ?>"
                                        title="<?= $canSubmitTotal ? 'Submit total score' : 'Enter Final score first' ?>">
                                        <?= $alreadySubmitted ? 'Update Total' : 'Submit Total' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="section-heading-sm">Grade Summary &amp; Publish</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Student ID</th>
                                <th>Course Title</th>
                                <th>Course Code</th>
                                <th style="text-align:center;">Total Score</th>
                                <th style="text-align:center;">Obtained CGPA</th>
                                <th style="text-align:center;">Grade Point</th>
                                <th style="text-align:center;">Status</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $hasSubmitted = false;
                        foreach ($enrolled_students as $ts):
                            if ($ts['score_total'] === null) continue;
                            $hasSubmitted = true;
                            $grade = getGradeInfo($ts['score_total']);
                            $isPublished = !empty($ts['score_published']);
                        ?>
                        <tr>
                            <td><span class="s-name"><?= htmlspecialchars($ts['student_name']) ?></span></td>
                            <td><span class="s-id"><?= htmlspecialchars($ts['student_id_str']) ?></span></td>
                            <td><?= htmlspecialchars($active_section_data['title']) ?></td>
                            <td><strong><?= htmlspecialchars($active_section_data['code']) ?></strong></td>
                            <td style="text-align:center; font-weight:700;"><?= intval($ts['score_total']) ?></td>
                            <td style="text-align:center;"><span class="grade-letter"><?= htmlspecialchars($grade['letter']) ?></span></td>
                            <td style="text-align:center;"><span class="grade-point"><?= number_format($grade['point'], 2) ?></span></td>
                            <td style="text-align:center;">
                                <?php if ($isPublished): ?>
                                    <span class="badge-published">Published</span>
                                <?php else: ?>
                                    <span class="badge-draft">Draft</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="student_id" value="<?= $ts['student_db_id'] ?>">
                                    <input type="hidden" name="section_id" value="<?= $active_section ?>">
                                    <button type="submit" name="publish_score" class="btn-publish<?= $isPublished ? ' published' : '' ?>"
                                        <?= $isPublished ? 'disabled' : '' ?>>
                                        <?= $isPublished ? 'Published' : 'Publish' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$hasSubmitted): ?>
                        <tr>
                            <td colspan="9" style="text-align:center; color:var(--text-secondary); padding:32px;">
                                No totals submitted yet. Enter component scores and click <strong>Submit Total</strong> to generate grades.
                            </td>
                        </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script src="theme.js"></script>
<script>
// Restore active tab after form submission
document.addEventListener("DOMContentLoaded", () => {
    let savedTab = sessionStorage.getItem("activeScoreTab");
    if (savedTab) {
        switchTab(savedTab);
    }
});

function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    // Find the correct button using text or standard logic
    const btns = document.querySelectorAll('.tab-btn');
    for(let btn of btns) {
        if(btn.getAttribute('onclick').includes(tabId)) {
            btn.classList.add('active');
            break;
        }
    }
    
    document.getElementById('tab-' + tabId).classList.add('active');
    sessionStorage.setItem("activeScoreTab", tabId);
}
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
