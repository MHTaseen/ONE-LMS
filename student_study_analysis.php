<?php
// student_study_analysis.php – Student academic progress dashboard
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

// ── Fetch all enrolled courses with static scores ──────────────────────────
$stmt = $pdo->prepare("
    SELECT cs.id as section_id, cs.section_no, c.code, c.title, c.credit,
           t.full_name as teacher_name,
           e.score_attendance, e.score_mid, e.score_final, e.score_lab
    FROM enrollments e
    JOIN course_sections cs ON e.section_id = cs.id
    JOIN courses c ON cs.course_id = c.id
    JOIN users t ON c.teacher_id = t.id
    WHERE e.student_id = ?
    ORDER BY c.code ASC
");
$stmt->execute([$student_db_id]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Build per-course analytics ─────────────────────────────────────────────
$analytics = [];
$overall_total = 0;
$overall_count = 0;

foreach ($courses as $c) {
    $sid = $c['section_id'];

    // Quizzes
    $q = $pdo->prepare("
        SELECT q.quiz_name, qs.score, qs.total, qs.submitted_at
        FROM quiz_submissions qs
        JOIN quizzes q ON qs.quiz_id = q.id
        WHERE q.section_id = ? AND qs.student_id = ?
        ORDER BY qs.submitted_at ASC
    ");
    $q->execute([$sid, $student_db_id]);
    $quizzes = $q->fetchAll(PDO::FETCH_ASSOC);

    // Assignments
    $a = $pdo->prepare("
        SELECT a.assignment_name, asub.score, asub.total, asub.submitted_at
        FROM assignment_submissions asub
        JOIN assignments a ON asub.assignment_id = a.id
        WHERE a.section_id = ? AND asub.student_id = ?
        ORDER BY asub.submitted_at ASC
    ");
    $a->execute([$sid, $student_db_id]);
    $assignments = $a->fetchAll(PDO::FETCH_ASSOC);

    // Averages
    $quiz_scores = array_column($quizzes, 'score');
    $quiz_totals = array_column($quizzes, 'total');
    $avg_quiz = count($quiz_scores) ? round(array_sum($quiz_scores) / count($quiz_scores), 1) : null;
    $max_quiz  = count($quiz_totals) ? round(array_sum($quiz_totals) / count($quiz_totals), 1) : null;

    $graded_asn = array_filter($assignments, fn($x) => $x['score'] !== null);
    $avg_asgn = count($graded_asn) ? round(array_sum(array_column($graded_asn, 'score')) / count($graded_asn), 1) : null;
    $max_asgn  = count($graded_asn) ? round(array_sum(array_column($graded_asn, 'total'))  / count($graded_asn), 1) : null;

    // Total score
    $parts = array_filter([
        $c['score_attendance'], $c['score_mid'], $c['score_final'], $c['score_lab'],
        $avg_quiz, $avg_asgn
    ], fn($v) => $v !== null);
    $total = count($parts) ? round(array_sum($parts), 1) : null;

    // Letter grade (rough 100-point scale mapping)
    $grade = '—';
    $grade_color = 'var(--text-secondary)';
    if ($total !== null) {
        if ($total >= 90)      { $grade = 'A+';  $grade_color = '#10b981'; }
        elseif ($total >= 85)  { $grade = 'A';   $grade_color = '#10b981'; }
        elseif ($total >= 80)  { $grade = 'A−';  $grade_color = '#34d399'; }
        elseif ($total >= 75)  { $grade = 'B+';  $grade_color = '#06b6d4'; }
        elseif ($total >= 70)  { $grade = 'B';   $grade_color = '#06b6d4'; }
        elseif ($total >= 65)  { $grade = 'B−';  $grade_color = '#a855f7'; }
        elseif ($total >= 60)  { $grade = 'C+';  $grade_color = '#f59e0b'; }
        elseif ($total >= 55)  { $grade = 'C';   $grade_color = '#f59e0b'; }
        elseif ($total >= 50)  { $grade = 'D';   $grade_color = '#fb923c'; }
        else                   { $grade = 'F';   $grade_color = '#ef4444'; }
        $overall_total += $total;
        $overall_count++;
    }

    $analytics[$sid] = [
        'course'      => $c,
        'quizzes'     => $quizzes,
        'assignments' => $assignments,
        'avg_quiz'    => $avg_quiz,
        'max_quiz'    => $max_quiz,
        'avg_asgn'    => $avg_asgn,
        'max_asgn'    => $max_asgn,
        'total'       => $total,
        'grade'       => $grade,
        'grade_color' => $grade_color,
    ];
}

$overall_avg = $overall_count ? round($overall_total / $overall_count, 1) : null;
$quiz_count_total = array_sum(array_map(fn($a) => count($a['quizzes']), $analytics));
$asgn_count_total = array_sum(array_map(fn($a) => count($a['assignments']), $analytics));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Study Analysis - ONE LMS</title>
    <meta name="description" content="View your personal academic progress, quiz scores, assignment performance, and overall grades across all enrolled courses.">
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .page-wrap { padding: 140px 40px 60px; max-width: 1100px; margin: 0 auto; }

        /* ── Hero Header ── */
        .analysis-hero {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 36px;
            padding: 28px 32px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            box-shadow: var(--card-glow);
            backdrop-filter: blur(16px);
        }
        .hero-avatar {
            width: 60px; height: 60px; border-radius: 18px;
            background: var(--gradient-accent);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem; font-weight: 700; color: #fff;
            flex-shrink: 0;
            box-shadow: 0 0 20px rgba(168,85,247,0.3);
        }
        .hero-text h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.8rem;
            background: var(--gradient-accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 4px;
        }
        .hero-text p { color: var(--text-secondary); font-size: 0.95rem; }

        /* ── Overview stats ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 36px;
        }
        .stat-pill {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 20px 22px;
            display: flex; align-items: center; gap: 14px;
            box-shadow: var(--card-glow);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-pill:hover { transform: translateY(-3px); box-shadow: var(--glow-shadow); }
        .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .si-purple { background: rgba(168,85,247,0.12); color: var(--accent-primary); }
        .si-cyan   { background: rgba(6,182,212,0.12);  color: var(--accent-secondary); }
        .si-green  { background: rgba(16,185,129,0.12); color: #10b981; }
        .si-amber  { background: rgba(245,158,11,0.12); color: #f59e0b; }
        .stat-info { display: flex; flex-direction: column; gap: 2px; }
        .stat-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); font-weight: 700; }
        .stat-value { font-size: 1.55rem; font-weight: 800; font-family: 'Space Grotesque', sans-serif; color: var(--text-primary); }

        /* ── Course tabs ── */
        .course-tab-bar {
            display: flex; gap: 10px; flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .course-tab {
            padding: 9px 18px;
            border-radius: 30px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            font-weight: 600; font-size: 0.88rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .course-tab:hover { border-color: var(--accent-primary); color: var(--accent-primary); }
        .course-tab.active {
            background: var(--gradient-accent);
            border-color: transparent;
            color: #fff;
            box-shadow: 0 4px 15px rgba(168,85,247,0.3);
        }

        /* ── Course panel ── */
        .course-panel { display: none; }
        .course-panel.active { display: block; animation: fadeUp 0.3s ease-out; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Score cards ── */
        .score-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .score-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--card-glow);
        }
        .score-card.highlight {
            background: linear-gradient(135deg, rgba(168,85,247,0.15), rgba(139,92,246,0.08));
            border-color: rgba(168,85,247,0.4);
        }
        .score-card .sc-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-secondary); font-weight: 700; margin-bottom: 10px; }
        .score-card .sc-val { font-size: 1.8rem; font-weight: 800; font-family: 'Space Grotesque', sans-serif; color: var(--text-primary); }
        .score-card.highlight .sc-val { background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .badge-pending { display: inline-block; background: rgba(245,158,11,0.15); color: #f59e0b; padding: 3px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; }

        /* ── Charts grid ── */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        .chart-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--card-glow);
        }
        .chart-card h3 {
            font-size: 0.88rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--text-secondary);
            margin-bottom: 18px;
        }
        .chart-card.full-width { grid-column: 1 / -1; }
        .chart-wrap { position: relative; height: 260px; }
        .chart-wrap canvas { max-height: 260px; }

        /* ── Summary table ── */
        .summary-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-glow);
            margin-bottom: 36px;
        }
        .summary-card-header {
            padding: 18px 24px; font-weight: 700; font-size: 1rem;
            border-bottom: 1px solid var(--border-color);
            background: rgba(255,255,255,0.02);
        }
        .summary-table { width: 100%; border-collapse: collapse; text-align: left; }
        .summary-table th { padding: 13px 18px; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.6px; color: var(--text-secondary); font-weight: 700; border-bottom: 1px solid var(--border-color); }
        .summary-table td { padding: 14px 18px; color: var(--text-primary); font-size: 0.9rem; border-bottom: 1px solid var(--border-color); }
        .summary-table tr:last-child td { border-bottom: none; }
        .grade-badge { display: inline-block; padding: 4px 10px; border-radius: 8px; font-size: 0.82rem; font-weight: 800; background: rgba(168,85,247,0.12); color: var(--accent-primary); }

        /* ── Empty state ── */
        .empty-state { padding: 60px 40px; text-align: center; color: var(--text-secondary); }
        .empty-state svg { width: 56px; height: 56px; opacity: 0.3; margin-bottom: 16px; }
        .empty-state p { font-size: 1.05rem; }

        @media (max-width: 900px) {
            .page-wrap { padding: 140px 20px 40px; }
            .charts-grid { grid-template-columns: 1fr; }
            .analysis-hero { flex-direction: column; align-items: flex-start; gap: 14px; padding: 22px 20px; }
        }
        @media (max-width: 600px) {
            .page-wrap { padding: 120px 14px 40px; }
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>

<div class="page-wrap">

    <!-- Hero -->
    <div class="analysis-hero">
        <div class="hero-avatar"><?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?></div>
        <div class="hero-text">
            <h1>My Study Analysis</h1>
            <p>Academic performance breakdown for <?= htmlspecialchars($_SESSION['full_name']) ?> &mdash; <?= htmlspecialchars($_SESSION['department']) ?> &bull; ID: <?= htmlspecialchars($_SESSION['user_id']) ?></p>
        </div>
    </div>

    <?php if (empty($courses)): ?>
    <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2z"/><path d="M12 8v4l3 3"/></svg>
        <p>You are not enrolled in any courses yet. Analysis will appear here once you are enrolled.</p>
    </div>
    <?php else: ?>

    <!-- Overview Stats -->
    <div class="stats-row">
        <div class="stat-pill">
            <div class="stat-icon si-purple">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Courses</span>
                <span class="stat-value"><?= count($courses) ?></span>
            </div>
        </div>
        <div class="stat-pill">
            <div class="stat-icon si-cyan">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Avg Score</span>
                <span class="stat-value"><?= $overall_avg !== null ? $overall_avg : '—' ?></span>
            </div>
        </div>
        <div class="stat-pill">
            <div class="stat-icon si-green">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Quizzes</span>
                <span class="stat-value"><?= $quiz_count_total ?></span>
            </div>
        </div>
        <div class="stat-pill">
            <div class="stat-icon si-amber">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-label">Assignments</span>
                <span class="stat-value"><?= $asgn_count_total ?></span>
            </div>
        </div>
    </div>

    <!-- All-Courses Summary Table -->
    <div class="summary-card">
        <div class="summary-card-header">📊 Course Performance Summary</div>
        <div style="overflow-x:auto;">
        <table class="summary-table">
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Attendance</th>
                    <th>Mid Exam</th>
                    <th>Final Exam</th>
                    <th>Lab</th>
                    <th>Quiz Avg</th>
                    <th>Asgn Avg</th>
                    <th>Total</th>
                    <th>Grade</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($analytics as $sid => $an): $c = $an['course']; ?>
                <tr>
                    <td>
                        <div style="font-weight:700; color:var(--text-primary);"><?= htmlspecialchars($c['code']) ?></div>
                        <div style="font-size:0.78rem; color:var(--text-secondary);"><?= htmlspecialchars($c['title']) ?></div>
                    </td>
                    <td><?= $c['score_attendance'] !== null ? $c['score_attendance'] : '<span class="badge-pending">–</span>' ?></td>
                    <td><?= $c['score_mid']        !== null ? $c['score_mid']        : '<span class="badge-pending">–</span>' ?></td>
                    <td><?= $c['score_final']      !== null ? $c['score_final']      : '<span class="badge-pending">–</span>' ?></td>
                    <td><?= $c['score_lab']        !== null ? $c['score_lab']        : '<span class="badge-pending">–</span>' ?></td>
                    <td><?= $an['avg_quiz']  !== null ? $an['avg_quiz']  : '<span class="badge-pending">–</span>' ?></td>
                    <td><?= $an['avg_asgn']  !== null ? $an['avg_asgn']  : '<span class="badge-pending">–</span>' ?></td>
                    <td style="font-weight:800; font-size:1.05rem;"><?= $an['total'] !== null ? $an['total'] : '<span class="badge-pending">N/A</span>' ?></td>
                    <td><span class="grade-badge" style="background: <?= $an['grade'] === 'F' ? 'rgba(239,68,68,0.12)' : 'rgba(168,85,247,0.12)' ?>; color: <?= $an['grade_color'] ?>;"><?= $an['grade'] ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Course Tabs -->
    <div class="course-tab-bar" id="courseTabs">
        <?php $first = true; foreach ($analytics as $sid => $an): $c = $an['course']; ?>
        <button class="course-tab <?= $first ? 'active' : '' ?>"
                onclick="showCourse(<?= $sid ?>)"
                id="tab-<?= $sid ?>">
            <?= htmlspecialchars($c['code']) ?> §<?= $c['section_no'] ?>
        </button>
        <?php $first = false; endforeach; ?>
    </div>

    <!-- Per-Course Panels -->
    <?php $first = true; foreach ($analytics as $sid => $an): $c = $an['course']; ?>
    <div class="course-panel <?= $first ? 'active' : '' ?>" id="panel-<?= $sid ?>">

        <!-- Score Strip -->
        <div class="score-strip">
            <div class="score-card">
                <div class="sc-label">Attendance</div>
                <div class="sc-val"><?= $c['score_attendance'] !== null ? $c['score_attendance'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="score-card">
                <div class="sc-label">Mid Exam</div>
                <div class="sc-val"><?= $c['score_mid'] !== null ? $c['score_mid'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="score-card">
                <div class="sc-label">Final Exam</div>
                <div class="sc-val"><?= $c['score_final'] !== null ? $c['score_final'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="score-card">
                <div class="sc-label">Lab Score</div>
                <div class="sc-val"><?= $c['score_lab'] !== null ? $c['score_lab'] : '<span class="badge-pending">Pending</span>' ?></div>
            </div>
            <div class="score-card highlight">
                <div class="sc-label">Total &amp; Grade</div>
                <div class="sc-val"><?= $an['total'] !== null ? $an['total'] : '—' ?></div>
                <div style="font-size:1.1rem; font-weight:800; margin-top:6px; color:<?= $an['grade_color'] ?>;"><?= $an['grade'] ?></div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <!-- Radar -->
            <div class="chart-card">
                <h3>📡 Performance Radar</h3>
                <div class="chart-wrap">
                    <canvas id="radar-<?= $sid ?>"></canvas>
                </div>
            </div>

            <!-- Quiz Bar -->
            <div class="chart-card">
                <h3>🎯 Quiz Scores</h3>
                <div class="chart-wrap">
                    <?php if (empty($an['quizzes'])): ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-secondary);font-size:0.9rem;">No quizzes taken yet</div>
                    <?php else: ?>
                    <canvas id="quiz-<?= $sid ?>"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Assignment Bar -->
            <div class="chart-card full-width">
                <h3>📝 Assignment Scores</h3>
                <div class="chart-wrap" style="height:220px;">
                    <?php $graded = array_filter($an['assignments'], fn($a) => $a['score'] !== null); ?>
                    <?php if (empty($graded)): ?>
                    <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--text-secondary);font-size:0.9rem;">No graded assignments yet</div>
                    <?php else: ?>
                    <canvas id="asgn-<?= $sid ?>"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
    <?php $first = false; endforeach; ?>

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
Chart.defaults.color = labelColor();

// ── Course switching ────────────────────────────────────────────────────────
function showCourse(sid) {
    document.querySelectorAll('.course-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.course-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('panel-' + sid)?.classList.add('active');
    document.getElementById('tab-' + sid)?.classList.add('active');
}

// ── Chart data injected from PHP ────────────────────────────────────────────
const analyticsData = <?php
$chartData = [];
foreach ($analytics as $sid => $an) {
    $c = $an['course'];
    $radarVals = [
        $c['score_attendance'] ?? 0,
        $c['score_mid']        ?? 0,
        $c['score_final']      ?? 0,
        $c['score_lab']        ?? 0,
        $an['avg_quiz']        ?? 0,
        $an['avg_asgn']        ?? 0,
    ];
    $quizLabels  = array_map(fn($q) => $q['quiz_name'], $an['quizzes']);
    $quizScores  = array_map(fn($q) => $q['score'], $an['quizzes']);
    $quizTotals  = array_map(fn($q) => $q['total'], $an['quizzes']);
    $graded = array_values(array_filter($an['assignments'], fn($a) => $a['score'] !== null));
    $asgnLabels  = array_map(fn($a) => $a['assignment_name'], $graded);
    $asgnScores  = array_map(fn($a) => $a['score'], $graded);
    $asgnTotals  = array_map(fn($a) => $a['total'], $graded);
    $chartData[$sid] = [
        'radar'       => $radarVals,
        'quizLabels'  => $quizLabels,
        'quizScores'  => $quizScores,
        'quizTotals'  => $quizTotals,
        'asgnLabels'  => $asgnLabels,
        'asgnScores'  => $asgnScores,
        'asgnTotals'  => $asgnTotals,
    ];
}
echo json_encode($chartData);
?>;

// ── Render charts ───────────────────────────────────────────────────────────
Object.entries(analyticsData).forEach(([sid, d]) => {

    // Radar
    const radarCtx = document.getElementById('radar-' + sid);
    if (radarCtx) {
        new Chart(radarCtx, {
            type: 'radar',
            data: {
                labels: ['Attendance', 'Mid', 'Final', 'Lab', 'Quiz Avg', 'Asgn Avg'],
                datasets: [{
                    label: 'Your Score',
                    data: d.radar,
                    backgroundColor: PURPLE + '0.18)',
                    borderColor:     PURPLE + '0.85)',
                    borderWidth: 2.5,
                    pointBackgroundColor: PURPLE + '1)',
                    pointRadius: 5,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    r: {
                        min: 0,
                        grid: { color: gridColor() },
                        angleLines: { color: gridColor() },
                        pointLabels: { color: labelColor(), font: { size: 11, weight: '600' } },
                        ticks: { color: labelColor(), backdropColor: 'transparent', stepSize: 25 }
                    }
                },
                plugins: { legend: { display: false } }
            }
        });
    }

    // Quiz Bar
    const quizCtx = document.getElementById('quiz-' + sid);
    if (quizCtx && d.quizLabels.length) {
        new Chart(quizCtx, {
            type: 'bar',
            data: {
                labels: d.quizLabels,
                datasets: [
                    { label: 'Your Score', data: d.quizScores, backgroundColor: PURPLE + '0.75)', borderRadius: 8, borderSkipped: false },
                    { label: 'Total',      data: d.quizTotals, backgroundColor: 'rgba(255,255,255,0.07)', borderRadius: 8, borderSkipped: false }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: labelColor(), font: { size: 10 } }, grid: { color: gridColor() } },
                    y: { ticks: { color: labelColor() }, grid: { color: gridColor() }, beginAtZero: true }
                },
                plugins: { legend: { labels: { color: labelColor(), boxWidth: 12, font: { size: 11 } } } }
            }
        });
    }

    // Assignment Bar
    const asgnCtx = document.getElementById('asgn-' + sid);
    if (asgnCtx && d.asgnLabels.length) {
        new Chart(asgnCtx, {
            type: 'bar',
            data: {
                labels: d.asgnLabels,
                datasets: [
                    { label: 'Your Score', data: d.asgnScores, backgroundColor: CYAN + '0.75)', borderRadius: 8, borderSkipped: false },
                    { label: 'Total',      data: d.asgnTotals, backgroundColor: 'rgba(255,255,255,0.07)', borderRadius: 8, borderSkipped: false }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: labelColor() }, grid: { color: gridColor() }, beginAtZero: true },
                    y: { ticks: { color: labelColor(), font: { size: 10 } }, grid: { color: gridColor() } }
                },
                plugins: { legend: { labels: { color: labelColor(), boxWidth: 12, font: { size: 11 } } } }
            }
        });
    }
});
</script>
</body>
</html>
