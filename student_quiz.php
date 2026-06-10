<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: landing.php'); exit();
}
require_once 'config.php';

$fullName  = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials  = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0],0,1).substr($nameParts[count($nameParts)-1],0,1))
    : strtoupper(substr($fullName,0,2));

$errorMsg = '';
$quizzes = [];

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Get enrolled sections
    $stmt = $pdo->prepare("
        SELECT cs.id as section_id, cs.section_no, c.id as course_id, c.title, c.code, u.full_name as teacher_name
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE e.student_id = ?
        ORDER BY c.title ASC
    ");
    $stmt->execute([$student_db_id]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sections as $sec) {
        $stmt2 = $pdo->prepare("SELECT * FROM quizzes WHERE section_id = ? ORDER BY quiz_number ASC");
        $stmt2->execute([$sec['section_id']]);
        $qs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        if ($qs) {
            $quizzes[] = [
                'course_id'    => $sec['course_id'],
                'title'        => $sec['title'],
                'code'         => $sec['code'],
                'section_no'   => $sec['section_no'],
                'teacher_name' => $sec['teacher_name'],
                'quizzes'      => $qs,
            ];
        }
    }
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Quizzes – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center; padding: 10px 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesk', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-avatar   { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-accent); display: flex; justify-content: center; align-items: center; color: #fff; font-weight: 700; }
        .btn-back { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 12px; font-size: 0.9rem; font-weight: 600; text-decoration: none; }
        .btn-back:hover { border-color: var(--accent-primary); }
        .page-wrap { padding: 100px 28px 80px; max-width: 960px; margin: 0 auto; }
        .page-heading { font-family: 'Space Grotesk', sans-serif; font-size: 1.9rem; font-weight: 700; margin-bottom: 6px; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .page-subheading { color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px; }
        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 20px; }
        .course-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 18px; padding: 24px; cursor: pointer; box-shadow: var(--card-glow); backdrop-filter: blur(14px); transition: transform .2s, border-color .2s; }
        .course-card:hover { transform: translateY(-4px); border-color: var(--accent-primary); }
        .course-card-code { font-size:0.8rem;color:var(--accent-secondary);text-transform:uppercase;margin-bottom:8px; }
        .course-card-title { font-size:1.1rem;color:var(--text-primary);margin-bottom:10px; }
        .assign-count-badge { background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.25);color:var(--accent-primary);padding:5px 12px;border-radius:20px;font-size:0.8rem; }
        .modal-overlay { display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.65);justify-content:center;align-items:center; }
        .modal-overlay.open{display:flex;}
        .modal-box{background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:22px;width:100%;max-width:720px;max-height:90vh;overflow-y:auto;box-shadow:0 30px 80px rgba(0,0,0,.5);animation:slideUp .3s;}
        @keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
        .modal-header{padding:24px 28px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:flex-start;background:rgba(168,85,247,0.06);}
        .modal-title{font-size:1.2rem;color:var(--text-primary);font-weight:700;}
        .modal-close{background:none;border:none;color:var(--text-secondary);cursor:pointer;padding:4px;}
        .modal-body{padding:24px 28px;display:flex;flex-direction:column;gap:16px;}
        .quiz-card{background:var(--input-bg);border:1px solid var(--border-color);border-radius:16px;padding:16px 20px;margin-bottom:12px;}
        .quiz-title{font-weight:700;color:var(--text-primary);margin-bottom:6px;}
        .quiz-meta{font-size:0.82rem;color:var(--text-secondary);margin-bottom:8px;}
        .quiz-actions{display:flex;gap:8px;}
        .btn-start{background:var(--gradient-accent);color:#fff;padding:8px 14px;border:none;border-radius:10px;cursor:pointer;}
        .btn-start:hover{opacity:.9;}
        .btn-disabled{background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.25);cursor:not-allowed;}
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
    <h1 class="page-heading">My Quizzes</h1>
    <p class="page-subheading">Select a quiz to start answering.</p>
    <?php if ($errorMsg): ?>
        <div class="alert-box alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php elseif (empty($quizzes)): ?>
        <div class="alert-box" style="background:var(--bg-secondary);border-color:var(--border-color);">No quizzes available.</div>
    <?php else: ?>
        <div class="course-grid">
            <?php foreach ($quizzes as $idx => $c): ?>
                <div class="course-card" onclick="openModal(<?= $idx ?>)">
                    <div class="course-card-code"><?= htmlspecialchars($c['code']) ?> · Sec <?= str_pad($c['section_no'],2,'0',STR_PAD_LEFT) ?></div>
                    <div class="course-card-title"><?= htmlspecialchars($c['title']) ?></div>
                    <div class="assign-count-badge">
                        <?= count($c['quizzes']) ?> Quiz(es)
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <!-- Modals -->
        <?php foreach ($quizzes as $idx => $c): ?>
        <div class="modal-overlay" id="modal_<?= $idx ?>">
            <div class="modal-box">
                <div class="modal-header">
                    <div>
                        <div class="modal-title"><?= htmlspecialchars($c['title']) ?></div>
                        <div class="modal-subtitle"><?= htmlspecialchars($c['code']) ?> · Sec <?= str_pad($c['section_no'],2,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($c['teacher_name']) ?></div>
                    </div>
                    <button class="modal-close" onclick="closeModal(<?= $idx ?>)">✕</button>
                </div>
                <div class="modal-body">
                    <?php foreach ($c['quizzes'] as $q): ?>
                    <div class="quiz-card" id="quiz_<?= $q['id'] ?>">
                        <div class="quiz-title">Quiz <?= $q['quiz_number'] ?> – <?= htmlspecialchars($q['quiz_name']) ?></div>
                        
                        <?php
                            $now = new DateTime();
                            $start = new DateTime($q['start_time']);
                            $end = new DateTime($q['end_time']);
                            
                            $is_before = $now < $start;
                            $is_after = $now > $end;

                            // Check if already submitted
                            $stmt = $pdo->prepare("SELECT id FROM quiz_submissions WHERE quiz_id = ? AND student_id = ?");
                            $stmt->execute([$q['id'], $student_db_id]);
                            $submitted = $stmt->fetch();
                        ?>

                        <div class="quiz-meta">
                            <span style="font-weight:600;">Scheduled:</span> <?= date('M j, g:i A', strtotime($q['start_time'])) ?> to <?= date('M j, g:i A', strtotime($q['end_time'])) ?><br>
                            <span style="font-weight:600;">Type:</span> <?= $q['quiz_type'] === 'file' ? 'File Upload (PDF/Doc)' : 'Manual (Multiple Choice)' ?>
                            <?php if ($q['quiz_type'] === 'file'): ?>
                                <br><span style="font-weight:600;">Total Marks:</span> <?= $q['total_marks'] ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="quiz-actions" style="margin-top: 12px;">
                            <?php if ($submitted): ?>
                                <button class="btn-disabled" disabled>Submitted</button>
                            <?php elseif ($is_before): ?>
                                <button class="btn-disabled" disabled>Starts at <?= date('M j, g:i A', strtotime($q['start_time'])) ?></button>
                            <?php elseif ($is_after): ?>
                                <button class="btn-disabled" disabled>Missed</button>
                            <?php else: ?>
                                <button class="btn-start" onclick="startQuiz(<?= $q['id'] ?>)">Take Quiz</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script>
function openModal(idx){document.getElementById('modal_'+idx).classList.add('open');document.body.style.overflow='hidden';}
function closeModal(idx){document.getElementById('modal_'+idx).classList.remove('open');document.body.style.overflow='';}
function startQuiz(quizId){
    // Fetch quiz data and launch UI (simplified: redirect to quiz page)
    window.location.href = 'quiz_take.php?id=' + quizId;
}
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
