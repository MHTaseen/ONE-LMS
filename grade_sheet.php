<?php
// grade_sheet.php – Student access only
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'student') {
    header('Location: landing.php');
    exit();
}
$fullName  = $_SESSION['full_name'];
$role      = $_SESSION['role'];
$nameParts = explode(' ', trim($fullName));
$initials  = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1))
    : strtoupper(substr($fullName, 0, 2));

require_once 'config.php';
require_once 'includes/grading.php';

// Fetch enrolled courses with published scores only visible to students
$gradeRows = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.code, c.title, c.credit, cs.section_no, u.full_name AS teacher_name,
               e.score_total, e.score_published
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE e.student_id = ?
        ORDER BY c.code ASC
    ");
    $stmt->execute([$_SESSION['user_pk']]);
    $gradeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Grade Sheet – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

        /* ── Navbar ── */
        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px;
            z-index: 900; display: flex; align-items: center; padding: 10px 28px;
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0,0,0,.25);
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        .navbar-brand {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem;
            font-weight: 700; background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            letter-spacing: -.5px; user-select: none;
        }
        .nav-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--gradient-accent); display: flex;
            justify-content: center; align-items: center; color: #fff;
            font-weight: 700; font-size: .95rem; box-shadow: var(--glow-shadow);
            cursor: default; font-family: 'Space Grotesque', sans-serif;
        }
        .navbar-right { display: flex; align-items: center; gap: 12px; }

        .btn-back {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--bg-secondary);
            border: 1px solid var(--border-color); color: var(--text-primary);
            border-radius: 12px; cursor: pointer; font-size: .9rem;
            font-weight: 600; backdrop-filter: blur(12px); text-decoration: none;
            transition: border-color .25s, box-shadow .25s, transform .2s;
        }
        .btn-back:hover { border-color: var(--accent-primary); box-shadow: var(--glow-shadow); transform: translateY(-2px); }
        .btn-back svg { width: 16px; height: 16px; }

        /* ── Page ── */
        .page-wrap { padding: 90px 28px 60px; max-width: 1100px; margin: 0 auto; width: 100%; }

        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 2rem;
            font-weight: 700; margin-bottom: 4px;
            background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading { color: var(--text-secondary); font-size: .95rem; margin-bottom: 36px; }

        /* Summary strip */
        .summary-strip {
            display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 28px;
        }
        .sum-card {
            flex: 1; min-width: 140px;
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 18px 20px;
            display: flex; flex-direction: column; gap: 4px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
        }
        .sum-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); font-weight: 600; }
        .sum-value { font-size: 1.6rem; font-weight: 700; font-family: 'Space Grotesque', sans-serif; color: var(--text-primary); }
        .sum-value.accent { background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        /* Course cards */
        .course-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 20px; overflow: hidden; margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
            transition: border-color .25s, box-shadow .25s;
        }
        .course-card:hover { border-color: rgba(168,85,247,.3); box-shadow: var(--card-glow); }

        .course-card-header {
            padding: 18px 24px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
            background: rgba(168,85,247,.04);
        }
        .course-info-left { display: flex; flex-direction: column; gap: 3px; }
        .course-code {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.1rem; font-weight: 700;
            background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .course-title { font-size: 0.9rem; color: var(--text-secondary); }
        .course-meta { font-size: 0.78rem; color: var(--text-secondary); margin-top: 2px; }

        /* Score grid inside card */
        .score-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 0;
        }
        .score-cell {
            padding: 18px 20px; border-right: 1px solid var(--border-color);
            display: flex; flex-direction: column; align-items: center; gap: 4px;
        }
        .score-cell:last-child { border-right: none; }
        .score-cell-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); font-weight: 600; }
        .score-cell-value { font-size: 1.5rem; font-weight: 700; font-family: 'Space Grotesque', sans-serif; color: var(--text-primary); }
        .score-cell-value.pending { font-size: 0.85rem; color: var(--text-secondary); font-family: inherit; font-weight: 500; font-style: italic; }
        .score-cell.total-cell { background: rgba(168,85,247,.06); }
        .score-cell.total-cell .score-cell-value { background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-size: 1.7rem; }

        /* Badge */
        .badge-locked {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            background: rgba(245,158,11,.12); color: #f59e0b;
            font-size: 0.72rem; font-weight: 700;
        }
        .badge-released {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            background: rgba(16,185,129,.12); color: #10b981;
            font-size: 0.72rem; font-weight: 700;
        }

        /* Empty state */
        .empty-state {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 24px; padding: 60px 40px; text-align: center;
            box-shadow: var(--card-glow); position: relative; overflow: hidden;
        }
        .empty-state::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 4px; background: var(--gradient-accent);
        }
        .empty-icon {
            width: 68px; height: 68px; border-radius: 50%;
            background: rgba(168,85,247,.1); border: 1px solid rgba(168,85,247,.25);
            display: flex; justify-content: center; align-items: center;
            margin: 0 auto 18px; color: var(--accent-primary);
        }
        .empty-icon svg { width: 30px; height: 30px; }
        .empty-state h2 { font-family: 'Space Grotesque', sans-serif; font-size: 1.3rem; margin-bottom: 10px; }
        .empty-state p { color: var(--text-secondary); line-height: 1.6; max-width: 400px; margin: 0 auto; }

        @media (max-width: 768px) { .page-wrap { padding: 80px 14px 40px; } .score-grid { grid-template-columns: repeat(3,1fr); } }
        @media (max-width: 500px) { .score-grid { grid-template-columns: repeat(2,1fr); } .score-cell { border-bottom: 1px solid var(--border-color); } }
    </style>
    <link rel="stylesheet" href="responsive.css">
</head>
<body>
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


    <div class="page-wrap">
        <h1 class="page-heading">Grade Sheet</h1>
        <p class="page-subheading">Your current semester scores across all enrolled courses.</p>

        <?php if (isset($dbError)): ?>
            <div style="background:rgba(239,68,68,.1); border:1px solid #ef4444; color:#ef4444; padding:16px; border-radius:12px; margin-bottom:24px;">
                Database error: <?= htmlspecialchars($dbError) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($gradeRows)): ?>
        <!-- Empty state -->
        <div class="empty-state">
            <div class="empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
            </div>
            <h2>No Grade Records Yet</h2>
            <p>You are not enrolled in any courses, or no scores have been entered by your teachers yet. Check back later.</p>
        </div>

        <?php else: ?>

        <!-- Summary strip -->
        <?php
        $totalCourses     = count($gradeRows);
        $releasedCount    = 0;
        $totalCreditPoints = 0;
        $totalCreditsEarned = 0;

        foreach ($gradeRows as &$r) {
            $visibleScore = !empty($r['score_published']) ? $r['score_total'] : null;
            $grade = getGradeInfo($visibleScore);
            $r['letter'] = $grade['letter'];
            $r['point'] = $grade['point'];
            $r['visible_score'] = $visibleScore;

            if ($visibleScore !== null) {
                $releasedCount++;
                $totalCreditPoints += ($r['credit'] * $r['point']);
                if ($r['point'] > 0) {
                    $totalCreditsEarned += $r['credit'];
                }
            }
        }
        unset($r);

        // Calculate total attempted credits for CGPA calculation (published only)
        $totalAttemptedCredits = 0;
        foreach ($gradeRows as $r) {
            if ($r['visible_score'] !== null) {
                $totalAttemptedCredits += $r['credit'];
            }
        }
        
        $cgpa = $totalAttemptedCredits > 0 ? number_format($totalCreditPoints / $totalAttemptedCredits, 2) : 'N/A';
        ?>
        <div class="summary-strip">
            <div class="sum-card">
                <span class="sum-label">Enrolled Courses</span>
                <span class="sum-value"><?= $totalCourses ?></span>
            </div>
            <div class="sum-card">
                <span class="sum-label">Scores Released</span>
                <span class="sum-value"><?= $releasedCount ?> / <?= $totalCourses ?></span>
            </div>
            <div class="sum-card">
                <span class="sum-label">Credits Earned</span>
                <span class="sum-value accent"><?= number_format($totalCreditsEarned, 1) ?></span>
            </div>
            <div class="sum-card">
                <span class="sum-label">Cumulative CGPA</span>
                <span class="sum-value accent"><?= $cgpa ?></span>
            </div>
        </div>

        <!-- Grades Table -->
        <div class="table-responsive" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: var(--card-glow); overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; text-align: left;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color); background: rgba(168,85,247,.04);">
                        <th style="padding: 18px 24px; color: var(--text-secondary); font-size: 0.85rem; letter-spacing: 1px; text-transform: uppercase;">Course Code</th>
                        <th style="padding: 18px 24px; color: var(--text-secondary); font-size: 0.85rem; letter-spacing: 1px; text-transform: uppercase;">Course Title</th>
                        <th style="padding: 18px 24px; color: var(--text-secondary); font-size: 0.85rem; letter-spacing: 1px; text-transform: uppercase;">Credit</th>
                        <th style="padding: 18px 24px; color: var(--text-secondary); font-size: 0.85rem; letter-spacing: 1px; text-transform: uppercase;">Obtained Grade</th>
                        <th style="padding: 18px 24px; color: var(--text-secondary); font-size: 0.85rem; letter-spacing: 1px; text-transform: uppercase;">Grade Point</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gradeRows as $row): ?>
                    <tr style="border-bottom: 1px solid var(--border-color); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                        <td style="padding: 18px 24px; font-weight: 700; color: var(--text-primary); font-family: 'Space Grotesque', sans-serif;">
                            <span style="background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?= htmlspecialchars($row['code']) ?></span>
                        </td>
                        <td style="padding: 18px 24px; color: var(--text-secondary); font-weight: 500;"><?= htmlspecialchars($row['title']) ?></td>
                        <td style="padding: 18px 24px; color: var(--text-primary); font-weight: 600;"><?= number_format($row['credit'], 1) ?></td>
                        <td style="padding: 18px 24px; font-weight: 700; font-size: 1.1rem; font-family: 'Space Grotesque', sans-serif; color: <?= $row['point'] !== null ? 'var(--accent-primary)' : 'var(--text-secondary)' ?>;">
                            <?= $row['letter'] ?>
                        </td>
                        <td style="padding: 18px 24px; font-weight: 700; font-size: 1.1rem; font-family: 'Space Grotesque', sans-serif; color: <?= $row['point'] !== null ? 'var(--success-color)' : 'var(--text-secondary)' ?>;">
                            <?= $row['point'] !== null ? number_format($row['point'], 1) : 'Pending' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php endif; ?>
    </div>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
