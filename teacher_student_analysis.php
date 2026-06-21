<?php
// teacher_student_analysis.php – Teacher view for analyzing any student's progress
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$teacher_db_id = $_SESSION['user_pk'] ?? 0;
if (!$teacher_db_id) {
    $s = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $s->execute([$_SESSION['user_id']]);
    $r = $s->fetch();
    $teacher_db_id = $r['id'] ?? 0;
}

// ── All sections this teacher teaches ─────────────────────────────────────
$stmtSec = $pdo->prepare("
    SELECT cs.id as section_id, cs.section_no, c.code, c.title
    FROM courses c
    JOIN course_sections cs ON cs.course_id = c.id
    WHERE c.teacher_id = ?
    ORDER BY c.code, cs.section_no
");
$stmtSec->execute([$teacher_db_id]);
$mySections = $stmtSec->fetchAll(PDO::FETCH_ASSOC);
$mySectionIds = array_column($mySections, 'section_id');

// ── Filter by section (optional) ──────────────────────────────────────────
$filterSection = intval($_GET['section_id'] ?? 0);
$searchQ       = trim($_GET['q'] ?? '');

// ── Students enrolled in teacher's sections ───────────────────────────────
$studentRows = [];
if (!empty($mySectionIds)) {
    $placeholders = implode(',', array_fill(0, count($mySectionIds), '?'));
    $params = $mySectionIds;
    $sectionFilter = '';
    if ($filterSection && in_array($filterSection, $mySectionIds)) {
        $sectionFilter = ' AND e.section_id = ?';
        $params[] = $filterSection;
    }
    $searchFilter = '';
    if ($searchQ) {
        $searchFilter = ' AND (u.full_name LIKE ? OR u.user_id LIKE ?)';
        $params[] = "%$searchQ%";
        $params[] = "%$searchQ%";
    }
    $stmtStudents = $pdo->prepare("
        SELECT DISTINCT u.id, u.full_name, u.user_id, u.department, u.email
        FROM enrollments e
        JOIN users u ON u.id = e.student_id
        WHERE e.section_id IN ($placeholders)
        $sectionFilter
        $searchFilter
        ORDER BY u.full_name ASC
    ");
    $stmtStudents->execute($params);
    $studentRows = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);
}

// ── Load selected student detail ──────────────────────────────────────────
$selectedId = intval($_GET['student_id'] ?? 0);
$selectedStudent = null;
$studentAnalytics = [];

if ($selectedId) {
    // Verify this student is enrolled in at least one of the teacher's sections
    if (!empty($mySectionIds)) {
        $phs = implode(',', array_fill(0, count($mySectionIds), '?'));
        $chk = $pdo->prepare("SELECT u.* FROM users u JOIN enrollments e ON u.id = e.student_id WHERE u.id = ? AND e.section_id IN ($phs) LIMIT 1");
        $chk->execute(array_merge([$selectedId], $mySectionIds));
        $selectedStudent = $chk->fetch(PDO::FETCH_ASSOC);
    }

    if ($selectedStudent) {
        // Only show sections taught by this teacher
        $phs = implode(',', array_fill(0, count($mySectionIds), '?'));
        $stmtCourses = $pdo->prepare("
            SELECT cs.id as section_id, cs.section_no, c.code, c.title, c.credit,
                   e.score_attendance, e.score_mid, e.score_final, e.score_lab
            FROM enrollments e
            JOIN course_sections cs ON e.section_id = cs.id
            JOIN courses c ON cs.course_id = c.id
            WHERE e.student_id = ? AND cs.id IN ($phs)
            ORDER BY c.code ASC
        ");
        $stmtCourses->execute(array_merge([$selectedId], $mySectionIds));
        $sCourses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sCourses as $c) {
            $sid = $c['section_id'];

            $q = $pdo->prepare("SELECT q.quiz_name, qs.score, qs.total FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id = q.id WHERE q.section_id = ? AND qs.student_id = ? ORDER BY qs.submitted_at ASC");
            $q->execute([$sid, $selectedId]);
            $quizzes = $q->fetchAll(PDO::FETCH_ASSOC);

            $a = $pdo->prepare("SELECT a.assignment_name, asub.score, asub.total, asub.submitted_at FROM assignment_submissions asub JOIN assignments a ON asub.assignment_id = a.id WHERE a.section_id = ? AND asub.student_id = ? ORDER BY asub.submitted_at ASC");
            $a->execute([$sid, $selectedId]);
            $assignments = $a->fetchAll(PDO::FETCH_ASSOC);

            $quiz_scores = array_column($quizzes, 'score');
            $quiz_totals = array_column($quizzes, 'total');
            $avg_quiz = count($quiz_scores) ? round(array_sum($quiz_scores) / count($quiz_scores), 1) : null;

            $graded_asn = array_filter($assignments, fn($x) => $x['score'] !== null);
            $avg_asgn = count($graded_asn) ? round(array_sum(array_column($graded_asn, 'score')) / count($graded_asn), 1) : null;

            $parts = array_filter([$c['score_attendance'], $c['score_mid'], $c['score_final'], $c['score_lab'], $avg_quiz, $avg_asgn], fn($v) => $v !== null);
            $total = count($parts) ? round(array_sum($parts), 1) : null;

            $grade = '—'; $grade_color = 'var(--text-secondary)';
            if ($total !== null) {
                if ($total >= 90)     { $grade = 'A+'; $grade_color = '#10b981'; }
                elseif ($total >= 85) { $grade = 'A';  $grade_color = '#10b981'; }
                elseif ($total >= 80) { $grade = 'A−'; $grade_color = '#34d399'; }
                elseif ($total >= 75) { $grade = 'B+'; $grade_color = '#06b6d4'; }
                elseif ($total >= 70) { $grade = 'B';  $grade_color = '#06b6d4'; }
                elseif ($total >= 65) { $grade = 'B−'; $grade_color = '#a855f7'; }
                elseif ($total >= 60) { $grade = 'C+'; $grade_color = '#f59e0b'; }
                elseif ($total >= 55) { $grade = 'C';  $grade_color = '#f59e0b'; }
                elseif ($total >= 50) { $grade = 'D';  $grade_color = '#fb923c'; }
                else                  { $grade = 'F';  $grade_color = '#ef4444'; }
            }

            $studentAnalytics[$sid] = compact('c','quizzes','assignments','avg_quiz','avg_asgn','total','grade','grade_color');
        }
    }
}

// ── Quick per-student overview stats for list ─────────────────────────────
$studentStats = [];
foreach ($studentRows as $stu) {
    $sid_list = $mySectionIds;
    if (!empty($sid_list)) {
        $phs = implode(',', array_fill(0, count($sid_list), '?'));
        $params = array_merge([$stu['id']], $sid_list);

        // Count quizzes
        $qc = $pdo->prepare("SELECT COUNT(*) FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id = q.id WHERE qs.student_id = ? AND q.section_id IN ($phs)");
        $qc->execute($params);
        $quizCount = $qc->fetchColumn();

        // Count assignments
        $ac = $pdo->prepare("SELECT COUNT(*) FROM assignment_submissions asub JOIN assignments a ON asub.assignment_id = a.id WHERE asub.student_id = ? AND a.section_id IN ($phs)");
        $ac->execute($params);
        $asgnCount = $ac->fetchColumn();

        // Average score (static)
        $sc = $pdo->prepare("SELECT AVG((COALESCE(score_attendance,0)+COALESCE(score_mid,0)+COALESCE(score_final,0)+COALESCE(score_lab,0))/4.0) FROM enrollments WHERE student_id = ? AND section_id IN ($phs)");
        $sc->execute($params);
        $avgStatic = round($sc->fetchColumn() ?? 0, 1);

        $studentStats[$stu['id']] = ['quizCount' => $quizCount, 'asgnCount' => $asgnCount, 'avgScore' => $avgStatic];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Analyze Students - ONE LMS</title>
    <meta name="description" content="Teacher dashboard to search, browse and analyze individual student academic progress across enrolled courses.">
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .page-wrap { padding: 140px 40px 60px; max-width: 1200px; margin: 0 auto; }

        /* ── Page Header ── */
        .page-header { margin-bottom: 32px; }
        .page-header h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2rem;
            background: linear-gradient(135deg, #06b6d4, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 6px;
        }
        .page-header p { color: var(--text-secondary); font-size: 0.95rem; }

        /* ── Filter bar ── */
        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap;
            margin-bottom: 28px;
            padding: 18px 22px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: var(--card-glow);
            align-items: center;
        }
        .filter-bar label { font-size: 0.8rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.6px; white-space: nowrap; }
        .filter-bar select,
        .filter-bar input[type="text"] {
            padding: 9px 14px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.9rem;
            min-width: 200px;
            outline: none;
            transition: border-color 0.2s;
        }
        .filter-bar select:focus, .filter-bar input:focus { border-color: var(--accent-primary); }
        .filter-btn {
            padding: 9px 20px;
            border-radius: 10px;
            background: var(--gradient-accent);
            border: none;
            color: #fff;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.2s;
        }
        .filter-btn:hover { opacity: 0.88; transform: translateY(-1px); }

        /* ── Layout: list + detail side by side ── */
        .analysis-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── Student list ── */
        .student-list-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-glow);
            position: sticky;
            top: 140px;
            max-height: calc(100vh - 180px);
            overflow-y: auto;
        }
        .student-list-card::-webkit-scrollbar { width: 4px; }
        .student-list-card::-webkit-scrollbar-thumb { background: var(--accent-primary); border-radius: 99px; }
        .list-header {
            padding: 16px 20px;
            font-weight: 700; font-size: 0.88rem;
            text-transform: uppercase; letter-spacing: 0.8px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
            background: rgba(255,255,255,0.02);
            position: sticky; top: 0; z-index: 2;
            backdrop-filter: blur(8px);
        }
        .student-list-item {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            text-decoration: none;
            transition: background 0.18s;
            cursor: pointer;
        }
        .student-list-item:last-child { border-bottom: none; }
        .student-list-item:hover { background: rgba(168,85,247,0.06); }
        .student-list-item.active { background: rgba(168,85,247,0.12); border-left: 3px solid var(--accent-primary); }
        .s-avatar {
            width: 38px; height: 38px; border-radius: 12px;
            background: linear-gradient(135deg, #a855f7, #06b6d4);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 0.9rem;
            flex-shrink: 0;
        }
        .s-info { flex: 1; min-width: 0; }
        .s-name { font-weight: 600; font-size: 0.92rem; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .s-meta { font-size: 0.75rem; color: var(--text-secondary); margin-top: 2px; }
        .s-avg  { font-weight: 800; font-size: 0.88rem; color: var(--accent-primary); flex-shrink: 0; }

        /* ── Detail panel ── */
        .detail-placeholder {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
            color: var(--text-secondary);
            box-shadow: var(--card-glow);
        }
        .detail-placeholder svg { width: 60px; height: 60px; opacity: 0.25; margin-bottom: 18px; }
        .detail-placeholder p { font-size: 1rem; }

        /* ── Student profile card ── */
        .student-profile {
            display: flex; align-items: center; gap: 18px;
            padding: 24px 28px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: var(--card-glow);
            margin-bottom: 24px;
        }
        .sp-avatar {
            width: 58px; height: 58px; border-radius: 16px;
            background: linear-gradient(135deg, #a855f7, #06b6d4);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 1.4rem;
            flex-shrink: 0;
            box-shadow: 0 0 20px rgba(168,85,247,0.3);
        }
        .sp-info h2 { font-family:'Space Grotesque',sans-serif; font-size:1.35rem; color:var(--text-primary); margin-bottom:4px; }
        .sp-info p  { font-size:0.87rem; color:var(--text-secondary); }
        .sp-badges  { display:flex; gap:8px; flex-wrap:wrap; margin-top:6px; }
        .sp-badge   { padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; background:rgba(168,85,247,0.12); color:var(--accent-primary); border:1px solid rgba(168,85,247,0.25); }

        /* ── Reused styles ── */
        .score-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .score-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 18px; text-align: center;
            box-shadow: var(--card-glow);
        }
        .score-card.highlight {
            background: linear-gradient(135deg, rgba(6,182,212,0.15), rgba(168,85,247,0.08));
            border-color: rgba(6,182,212,0.4);
        }
        .sc-label { font-size:0.7rem; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-secondary); font-weight:700; margin-bottom:8px; }
        .sc-val   { font-size:1.7rem; font-weight:800; font-family:'Space Grotesque',sans-serif; color:var(--text-primary); }
        .score-card.highlight .sc-val { background: linear-gradient(135deg,#06b6d4,#a855f7); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .badge-pending { display:inline-block; background:rgba(245,158,11,0.15); color:#f59e0b; padding:3px 8px; border-radius:6px; font-size:0.72rem; font-weight:700; }

        /* course tabs */
        .course-tab-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; }
        .course-tab { padding:8px 16px; border-radius:30px; border:1px solid var(--border-color); background:var(--bg-secondary); color:var(--text-secondary); font-weight:600; font-size:0.85rem; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
        .course-tab:hover { border-color:var(--accent-secondary); color:var(--accent-secondary); }
        .course-tab.active { background: linear-gradient(135deg,#06b6d4,#a855f7); border-color:transparent; color:#fff; box-shadow:0 4px 15px rgba(6,182,212,0.3); }

        .course-panel { display:none; }
        .course-panel.active { display:block; animation:fadeUp 0.3s ease-out; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(10px);} to{opacity:1;transform:translateY(0);} }

        .charts-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
        .chart-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:18px; padding:20px; box-shadow:var(--card-glow); }
        .chart-card h3 { font-size:0.82rem; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-secondary); margin-bottom:14px; }
        .chart-card.full-width { grid-column:1/-1; }
        .chart-wrap { position:relative; height:240px; }
        .chart-wrap canvas { max-height:240px; }

        /* summary table */
        .summary-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:18px; overflow:hidden; box-shadow:var(--card-glow); margin-bottom:24px; }
        .summary-card-header { padding:16px 22px; font-weight:700; font-size:0.95rem; border-bottom:1px solid var(--border-color); background:rgba(255,255,255,0.02); }
        .summary-table { width:100%; border-collapse:collapse; text-align:left; }
        .summary-table th { padding:12px 16px; font-size:0.76rem; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-secondary); font-weight:700; border-bottom:1px solid var(--border-color); }
        .summary-table td { padding:12px 16px; color:var(--text-primary); font-size:0.88rem; border-bottom:1px solid var(--border-color); }
        .summary-table tr:last-child td { border-bottom:none; }
        .grade-badge { display:inline-block; padding:3px 9px; border-radius:7px; font-size:0.8rem; font-weight:800; }

        /* empty state */
        .empty-state { padding:50px 30px; text-align:center; color:var(--text-secondary); }
        .empty-state svg { width:52px; height:52px; opacity:0.25; margin-bottom:14px; }

        @media (max-width: 960px) {
            .analysis-layout { grid-template-columns: 1fr; }
            .student-list-card { position:static; max-height:none; }
            .charts-grid { grid-template-columns: 1fr; }
            .page-wrap { padding: 140px 20px 40px; }
        }
        @media (max-width: 600px) {
            .page-wrap { padding: 120px 12px 40px; }
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=2">
</head>
<body>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>

<div class="page-wrap">

    <div class="page-header">
        <h1>Analyze Students</h1>
        <p>Browse students enrolled in your sections and drill into their full academic progress.</p>
    </div>

    <!-- Filter Bar -->
    <form class="filter-bar" method="GET" action="teacher_student_analysis.php">
        <?php if ($selectedId): ?>
        <input type="hidden" name="student_id" value="<?= $selectedId ?>">
        <?php endif; ?>
        <label for="section_filter">Section:</label>
        <select name="section_id" id="section_filter">
            <option value="">All My Sections</option>
            <?php foreach ($mySections as $sec): ?>
            <option value="<?= $sec['section_id'] ?>" <?= $filterSection == $sec['section_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($sec['code']) ?> §<?= $sec['section_no'] ?>
            </option>
            <?php endforeach; ?>
        </select>
        <label for="student_search">Search:</label>
        <input type="text" id="student_search" name="q" placeholder="Name or Student ID…" value="<?= htmlspecialchars($searchQ) ?>">
        <button type="submit" class="filter-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline;margin-right:5px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Filter
        </button>
        <?php if ($filterSection || $searchQ): ?>
        <a href="teacher_student_analysis.php" style="color:var(--text-secondary);font-size:0.85rem;text-decoration:none;align-self:center;">✕ Clear</a>
        <?php endif; ?>
    </form>

    <?php if (empty($mySections)): ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <p>You have no active course sections. Add a course first.</p>
    </div>
    <?php else: ?>
    <div class="analysis-layout">

        <!-- Left: Student List -->
        <aside class="student-list-card">
            <div class="list-header">Students (<?= count($studentRows) ?>)</div>
            <?php if (empty($studentRows)): ?>
            <div class="empty-state" style="padding:30px 20px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <p style="font-size:0.88rem;">No students found matching your filter.</p>
            </div>
            <?php else: ?>
            <?php foreach ($studentRows as $stu):
                $stats = $studentStats[$stu['id']] ?? ['quizCount'=>0,'asgnCount'=>0,'avgScore'=>0];
                $isActive = $selectedId == $stu['id'];
            ?>
            <a href="teacher_student_analysis.php?student_id=<?= $stu['id'] ?>&section_id=<?= $filterSection ?>&q=<?= urlencode($searchQ) ?>"
               class="student-list-item <?= $isActive ? 'active' : '' ?>">
                <div class="s-avatar"><?= strtoupper(substr($stu['full_name'], 0, 1)) ?></div>
                <div class="s-info">
                    <div class="s-name"><?= htmlspecialchars($stu['full_name']) ?></div>
                    <div class="s-meta"><?= htmlspecialchars($stu['user_id']) ?> &bull; <?= htmlspecialchars($stu['department']) ?></div>
                    <div class="s-meta" style="margin-top:2px;">
                        <span style="color:var(--accent-primary);">⬡ <?= $stats['quizCount'] ?> quizzes</span>
                        &nbsp;·&nbsp;
                        <span style="color:var(--accent-secondary);">✎ <?= $stats['asgnCount'] ?> assignments</span>
                    </div>
                </div>
                <div class="s-avg"><?= $stats['avgScore'] > 0 ? $stats['avgScore'] : '—' ?></div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <!-- Right: Detail Panel -->
        <div>
        <?php if (!$selectedStudent): ?>
            <div class="detail-placeholder">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <p>Select a student from the list to see their detailed academic analysis.</p>
            </div>
        <?php elseif (empty($studentAnalytics)): ?>
            <div class="detail-placeholder">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2z"/><path d="M12 8v4l3 3"/></svg>
                <p>This student is not enrolled in any of your course sections.</p>
            </div>
        <?php else: ?>

            <!-- Student Profile Card -->
            <div class="student-profile">
                <div class="sp-avatar"><?= strtoupper(substr($selectedStudent['full_name'], 0, 1)) ?></div>
                <div class="sp-info">
                    <h2><?= htmlspecialchars($selectedStudent['full_name']) ?></h2>
                    <p><?= htmlspecialchars($selectedStudent['email']) ?></p>
                    <div class="sp-badges">
                        <span class="sp-badge"><?= htmlspecialchars($selectedStudent['user_id']) ?></span>
                        <span class="sp-badge" style="background:rgba(6,182,212,0.12);color:var(--accent-secondary);border-color:rgba(6,182,212,0.25);"><?= htmlspecialchars($selectedStudent['department']) ?></span>
                        <span class="sp-badge" style="background:rgba(16,185,129,0.12);color:#10b981;border-color:rgba(16,185,129,0.25);"><?= count($studentAnalytics) ?> course(s)</span>
                    </div>
                </div>
            </div>

            <!-- Course Performance Summary -->
            <div class="summary-card">
                <div class="summary-card-header">📊 Course Performance Overview</div>
                <div style="overflow-x:auto;">
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Course</th><th>Att.</th><th>Mid</th><th>Final</th><th>Lab</th><th>Quiz Avg</th><th>Asgn Avg</th><th>Total</th><th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($studentAnalytics as $sid => $an): $c = $an['c']; ?>
                    <tr>
                        <td><div style="font-weight:700;"><?= htmlspecialchars($c['code']) ?></div><div style="font-size:0.75rem;color:var(--text-secondary);"><?= htmlspecialchars($c['title']) ?></div></td>
                        <td><?= $c['score_attendance'] ?? '<span class="badge-pending">–</span>' ?></td>
                        <td><?= $c['score_mid']        ?? '<span class="badge-pending">–</span>' ?></td>
                        <td><?= $c['score_final']      ?? '<span class="badge-pending">–</span>' ?></td>
                        <td><?= $c['score_lab']        ?? '<span class="badge-pending">–</span>' ?></td>
                        <td><?= $an['avg_quiz'] ?? '<span class="badge-pending">–</span>' ?></td>
                        <td><?= $an['avg_asgn'] ?? '<span class="badge-pending">–</span>' ?></td>
                        <td style="font-weight:800;"><?= $an['total'] ?? '<span class="badge-pending">N/A</span>' ?></td>
                        <td><span class="grade-badge" style="background:rgba(168,85,247,0.1);color:<?= $an['grade_color'] ?>;"><?= $an['grade'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Course Tabs -->
            <div class="course-tab-bar">
                <?php $first = true; foreach ($studentAnalytics as $sid => $an): $c = $an['c']; ?>
                <button class="course-tab <?= $first ? 'active' : '' ?>" onclick="showCourse(<?= $sid ?>)" id="tab-<?= $sid ?>">
                    <?= htmlspecialchars($c['code']) ?> §<?= $c['section_no'] ?>
                </button>
                <?php $first = false; endforeach; ?>
            </div>

            <!-- Per-Course Panels -->
            <?php $first = true; foreach ($studentAnalytics as $sid => $an): $c = $an['c']; ?>
            <div class="course-panel <?= $first ? 'active' : '' ?>" id="panel-<?= $sid ?>">
                <!-- Score strip -->
                <div class="score-strip">
                    <div class="score-card"><div class="sc-label">Attendance</div><div class="sc-val"><?= $c['score_attendance'] !== null ? $c['score_attendance'] : '<span class="badge-pending">Pending</span>' ?></div></div>
                    <div class="score-card"><div class="sc-label">Mid Exam</div><div class="sc-val"><?= $c['score_mid'] !== null ? $c['score_mid'] : '<span class="badge-pending">Pending</span>' ?></div></div>
                    <div class="score-card"><div class="sc-label">Final Exam</div><div class="sc-val"><?= $c['score_final'] !== null ? $c['score_final'] : '<span class="badge-pending">Pending</span>' ?></div></div>
                    <div class="score-card"><div class="sc-label">Lab</div><div class="sc-val"><?= $c['score_lab'] !== null ? $c['score_lab'] : '<span class="badge-pending">Pending</span>' ?></div></div>
                    <div class="score-card highlight">
                        <div class="sc-label">Total &amp; Grade</div>
                        <div class="sc-val"><?= $an['total'] !== null ? $an['total'] : '—' ?></div>
                        <div style="font-size:1.1rem;font-weight:800;margin-top:4px;color:<?= $an['grade_color'] ?>;"><?= $an['grade'] ?></div>
                    </div>
                </div>
                <!-- Charts -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h3>📡 Performance Radar</h3>
                        <div class="chart-wrap"><canvas id="radar-<?= $sid ?>"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <h3>🎯 Quiz Scores</h3>
                        <div class="chart-wrap">
                            <?php if (empty($an['quizzes'])): ?>
                            <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-secondary);font-size:0.88rem;">No quizzes taken yet</div>
                            <?php else: ?><canvas id="quiz-<?= $sid ?>"></canvas><?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-card full-width">
                        <h3>📝 Assignment Scores</h3>
                        <div class="chart-wrap" style="height:210px;">
                            <?php $graded = array_filter($an['assignments'], fn($a) => $a['score'] !== null); ?>
                            <?php if (empty($graded)): ?>
                            <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-secondary);font-size:0.88rem;">No graded assignments yet</div>
                            <?php else: ?><canvas id="asgn-<?= $sid ?>"></canvas><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php $first = false; endforeach; ?>

        <?php endif; ?>
        </div>

    </div><!-- /analysis-layout -->
    <?php endif; ?>

</div><!-- /page-wrap -->

<script src="theme.js"></script>
<?php include 'includes/global_search_js.php'; ?>

<script>
const isDark = () => document.documentElement.getAttribute('data-theme') !== 'light';
const gridColor  = () => isDark() ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)';
const labelColor = () => isDark() ? 'rgba(255,255,255,0.55)' : 'rgba(0,0,0,0.55)';
const PURPLE = 'rgba(168,85,247,';
const CYAN   = 'rgba(6,182,212,';

Chart.defaults.font.family = "'Inter', sans-serif";

function showCourse(sid) {
    document.querySelectorAll('.course-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.course-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('panel-' + sid)?.classList.add('active');
    document.getElementById('tab-' + sid)?.classList.add('active');
}

const analyticsData = <?php
$chartData = [];
foreach ($studentAnalytics as $sid => $an) {
    $c = $an['c'];
    $radarVals = [
        $c['score_attendance'] ?? 0, $c['score_mid'] ?? 0, $c['score_final'] ?? 0,
        $c['score_lab'] ?? 0, $an['avg_quiz'] ?? 0, $an['avg_asgn'] ?? 0,
    ];
    $quizLabels = array_column($an['quizzes'], 'quiz_name');
    $quizScores = array_column($an['quizzes'], 'score');
    $quizTotals = array_column($an['quizzes'], 'total');
    $graded = array_values(array_filter($an['assignments'], fn($a) => $a['score'] !== null));
    $asgnLabels = array_column($graded, 'assignment_name');
    $asgnScores = array_column($graded, 'score');
    $asgnTotals = array_column($graded, 'total');
    $chartData[$sid] = compact('radarVals','quizLabels','quizScores','quizTotals','asgnLabels','asgnScores','asgnTotals');
}
echo json_encode($chartData);
?>;

Object.entries(analyticsData).forEach(([sid, d]) => {
    const radarCtx = document.getElementById('radar-' + sid);
    if (radarCtx) {
        new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: ['Attendance','Mid','Final','Lab','Quiz Avg','Asgn Avg'],
                datasets: [{
                    label: 'Score', data: d.radarVals,
                    backgroundColor: CYAN + '0.15)', borderColor: CYAN + '0.85)',
                    borderWidth: 2.5, pointBackgroundColor: CYAN + '1)', pointRadius: 5,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { r: { min: 0, grid: { color: gridColor() }, angleLines: { color: gridColor() }, pointLabels: { color: labelColor(), font: { size: 10, weight: '600' } }, ticks: { color: labelColor(), backdropColor: 'transparent', stepSize: 25 } } },
                plugins: { legend: { display: false } }
            }
        });
    }
    const quizCtx = document.getElementById('quiz-' + sid);
    if (quizCtx && d.quizLabels.length) {
        new Chart(quizCtx, {
            type: 'bar',
            data: { labels: d.quizLabels, datasets: [
                { label: 'Score', data: d.quizScores, backgroundColor: PURPLE + '0.75)', borderRadius: 8, borderSkipped: false },
                { label: 'Total', data: d.quizTotals, backgroundColor: 'rgba(255,255,255,0.06)', borderRadius: 8, borderSkipped: false }
            ]},
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { color: labelColor(), font:{size:10} }, grid: { color: gridColor() } }, y: { ticks: { color: labelColor() }, grid: { color: gridColor() }, beginAtZero: true } }, plugins: { legend: { labels: { color: labelColor(), boxWidth: 12, font:{size:10} } } } }
        });
    }
    const asgnCtx = document.getElementById('asgn-' + sid);
    if (asgnCtx && d.asgnLabels.length) {
        new Chart(asgnCtx, {
            type: 'bar',
            data: { labels: d.asgnLabels, datasets: [
                { label: 'Score', data: d.asgnScores, backgroundColor: CYAN + '0.75)', borderRadius: 8, borderSkipped: false },
                { label: 'Total', data: d.asgnTotals, backgroundColor: 'rgba(255,255,255,0.06)', borderRadius: 8, borderSkipped: false }
            ]},
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { color: labelColor() }, grid: { color: gridColor() }, beginAtZero: true }, y: { ticks: { color: labelColor(), font:{size:10} }, grid: { color: gridColor() } } }, plugins: { legend: { labels: { color: labelColor(), boxWidth: 12, font:{size:10} } } } }
        });
    }
});
</script>
</body>
</html>
