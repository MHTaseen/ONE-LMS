<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: landing.php'); exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';

$fullName  = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials  = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0],0,1).substr($nameParts[count($nameParts)-1],0,1))
    : strtoupper(substr($fullName,0,2));

$successMsg = ''; $errorMsg = '';
$teacherSections = [];

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher_db_id = $stmt->fetch()['id'];

    $stmt = $pdo->prepare("
        SELECT cs.id, cs.section_no, c.title, c.code
        FROM course_sections cs JOIN courses c ON cs.course_id = c.id
        WHERE c.teacher_id = ? ORDER BY c.title ASC, cs.section_no ASC
    ");
    $stmt->execute([$teacher_db_id]);
    $teacherSections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $errorMsg = $e->getMessage(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deploy_quiz') {
    $section_id  = intval($_POST['section_id']);
    $quiz_name   = trim($_POST['quiz_name']);
    $quiz_number = intval($_POST['quiz_number']);
    $quiz_type   = $_POST['quiz_type'] ?? 'manual';
    $start_time  = $_POST['start_time']; // format YYYY-MM-DDTHH:MM
    $end_time    = $_POST['end_time'];
    $questions   = $_POST['questions'] ?? [];
    $total_marks = isset($_POST['total_marks']) ? intval($_POST['total_marks']) : null;

    if (empty($quiz_name) || $quiz_number < 1 || empty($start_time) || empty($end_time)) {
        $errorMsg = "Please fill in all required fields.";
    } else {
        $quiz_file_path = null;
        $valid = true;

        if ($quiz_type === 'file') {
            if (empty($_FILES['quiz_file']['name']) || empty($total_marks)) {
                $errorMsg = "Please upload a quiz file and specify total marks.";
                $valid = false;
            } else {
                $filename = time() . '_' . basename($_FILES['quiz_file']['name']);
                $target_dir = 'uploads/quizzes/';
                $quiz_file_path = $target_dir . $filename;
                if (!move_uploaded_file($_FILES['quiz_file']['tmp_name'], $quiz_file_path)) {
                    $errorMsg = "Failed to upload quiz file.";
                    $valid = false;
                }
            }
        } else {
            if (count($questions) < 1) {
                $errorMsg = "Please add at least one question for a manual quiz.";
                $valid = false;
            } else {
                foreach ($questions as $q) {
                    if (empty($q['text']) || empty($q['a']) || empty($q['b']) || empty($q['c']) || empty($q['d']) || empty($q['correct'])) {
                        $errorMsg = "Please fill in all question fields and select the correct answer for each.";
                        $valid = false; break;
                    }
                }
            }
        }

        if ($valid) {
            try {
                $pdo->beginTransaction();
                $start_ts = strtotime($start_time);
                $end_ts = strtotime($end_time);
                $time_limit_mins = max(1, round(($end_ts - $start_ts) / 60));

                $stmt = $pdo->prepare("INSERT INTO quizzes (section_id, quiz_name, quiz_number, time_limit, quiz_type, quiz_file_path, start_time, end_time, total_marks) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$section_id, $quiz_name, $quiz_number, $time_limit_mins, $quiz_type, $quiz_file_path, $start_time, $end_time, $total_marks]);
                $quiz_id = $pdo->lastInsertId();

                if ($quiz_type === 'manual') {
                    $stmt2 = $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (?,?,?,?,?,?,?)");
                    foreach ($questions as $q) {
                        $stmt2->execute([$quiz_id, trim($q['text']), trim($q['a']), trim($q['b']), trim($q['c']), trim($q['d']), $q['correct']]);
                    }
                }
                $pdo->commit();
                $successMsg = "Quiz \"{$quiz_name}\" scheduled successfully!";

                // Notify students
                $stmt = $pdo->prepare("SELECT student_id FROM enrollments WHERE section_id = ?");
                $stmt->execute([$section_id]);
                $students = $stmt->fetchAll();
                
                // Get course info for msg
                $cstmt = $pdo->prepare("SELECT c.code, cs.section_no FROM course_sections cs JOIN courses c ON cs.course_id = c.id WHERE cs.id = ?");
                $cstmt->execute([$section_id]);
                $cinfo = $cstmt->fetch();
                
                $msg = "New Quiz Scheduled in {$cinfo['code']} (Sec {$cinfo['section_no']}): {$quiz_name} on " . date('M j, Y g:i A', $start_ts);
                $link = "student_quiz.php";
                foreach($students as $st) {
                    sendNotification($pdo, $st['student_id'], 'deployment', $msg, $link);
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errorMsg = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Deploy Quiz – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: 64px; z-index: 900; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-avatar   { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-accent); display: flex; justify-content: center; align-items: center; color: #fff; font-weight: 700; box-shadow: var(--glow-shadow); }
        .btn-back     { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 12px; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: border-color .2s; }
        .btn-back:hover { border-color: var(--accent-primary); }
        .btn-back svg { width: 16px; height: 16px; }

        .page-wrap  { padding: 100px 28px 80px; max-width: 820px; margin: 0 auto; width: 100%; }
        .page-heading { font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem; font-weight: 700; margin-bottom: 6px; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .page-subheading { color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px; }

        .card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 20px; padding: 30px; box-shadow: var(--card-glow); backdrop-filter: blur(16px); margin-bottom: 20px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .full-col { grid-column: 1/-1; }
        .form-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 8px; }
        .form-input, .form-select { width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 12px; color: var(--text-primary); font-size: 0.95rem; outline: none; transition: border-color .25s, box-shadow .25s; box-sizing: border-box; }
        .form-input:focus, .form-select:focus { border-color: var(--accent-primary); box-shadow: 0 0 0 3px rgba(168,85,247,.15); }
        .form-select option { background: var(--bg-primary); }
        .autofill-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .autofill-box { background: rgba(168,85,247,0.06); border: 1px solid rgba(168,85,247,0.2); border-radius: 12px; padding: 12px 16px; }
        .autofill-label { font-size: 0.75rem; color: var(--accent-secondary); font-weight: 600; text-transform: uppercase; margin-bottom: 4px; }
        .autofill-value { font-family: 'Space Grotesque', sans-serif; font-size: 1rem; font-weight: 700; color: var(--text-primary); }
        .divider { height: 1px; background: var(--border-color); margin: 22px 0; }

        /* Questions area */
        .questions-list { display: flex; flex-direction: column; gap: 16px; }
        .q-card { background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 20px; position: relative; }
        .q-num-label { font-size: 0.78rem; font-weight: 700; color: var(--accent-primary); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }
        .q-card .form-input { margin-bottom: 10px; }
        .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
        .opt-row { display: flex; align-items: center; gap: 8px; }
        .opt-label { font-weight: 700; font-size: 0.85rem; color: var(--text-secondary); width: 20px; flex-shrink: 0; }
        .correct-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .correct-row label { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; cursor: pointer; }
        .correct-row input[type=radio] { accent-color: var(--accent-primary); width: 16px; height: 16px; }
        .btn-remove-q { position: absolute; top: 14px; right: 16px; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25); color: #ef4444; border-radius: 8px; padding: 5px 10px; font-size: 0.78rem; font-weight: 600; cursor: pointer; }
        .btn-remove-q:hover { background: rgba(239,68,68,0.2); }

        .btn-add-q { display: flex; align-items: center; gap: 8px; padding: 12px 22px; background: transparent; border: 2px dashed var(--border-color); border-radius: 12px; color: var(--text-secondary); font-size: 0.9rem; font-weight: 600; cursor: pointer; width: 100%; justify-content: center; transition: border-color .2s, color .2s; }
        .btn-add-q:hover { border-color: var(--accent-primary); color: var(--accent-primary); }

        .btn-deploy { width: 100%; padding: 15px; background: var(--gradient-accent); border: none; border-radius: 14px; color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; font-family: 'Space Grotesque', sans-serif; margin-top: 8px; transition: opacity .2s, transform .2s; }
        .btn-deploy:hover { opacity: .9; transform: translateY(-2px); }

        .section-heading { font-family: 'Space Grotesque', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .section-heading span { color: var(--accent-primary); }
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
    <h1 class="page-heading">Deploy Quiz</h1>
    <p class="page-subheading">Create a multiple-choice quiz or upload a quiz file (PDF/Doc) for your course section. Schedule a start and end time — students are notified instantly.</p>

    <?php if ($successMsg): ?><div class="alert-box alert-success" style="margin-bottom:20px;"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
    <?php if ($errorMsg):   ?><div class="alert-box alert-error"   style="margin-bottom:20px;"><?= htmlspecialchars($errorMsg)   ?></div><?php endif; ?>

    <?php if (empty($teacherSections)): ?>
        <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">No sections found. <a href="teacher_courses.php" style="color:var(--accent-primary);">Create one first.</a></div>
    <?php else: ?>
    <form method="POST" action="deploy_quiz.php" id="quizForm" enctype="multipart/form-data">
        <input type="hidden" name="action" value="deploy_quiz">

        <!-- Section 1: Quiz Info -->
        <div class="card">
            <div class="section-heading">Quiz Info</div>
            <div class="form-grid">
                <div class="full-col">
                    <label class="form-label">Course Section</label>
                    <select name="section_id" class="form-select" required onchange="updateInfo(this)">
                        <option value="" disabled selected>— Select a section —</option>
                        <?php foreach ($teacherSections as $s): ?>
                        <option value="<?= $s['id'] ?>" data-title="<?= htmlspecialchars($s['title']) ?>" data-code="<?= htmlspecialchars($s['code']) ?>">
                            <?= htmlspecialchars($s['code']) ?> – <?= htmlspecialchars($s['title']) ?> (Sec <?= str_pad($s['section_no'],2,'0',STR_PAD_LEFT) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="full-col autofill-row" id="autofillRow" style="display:none;">
                    <div class="autofill-box"><div class="autofill-label">Course Title</div><div class="autofill-value" id="aTitle">—</div></div>
                    <div class="autofill-box"><div class="autofill-label">Course Code</div><div class="autofill-value"  id="aCode">—</div></div>
                </div>
                <div class="divider full-col"></div>
                <div>
                    <label class="form-label">Quiz Name</label>
                    <input type="text" name="quiz_name" class="form-input" placeholder="e.g. Midterm Quiz 1" required>
                </div>
                <div>
                    <label class="form-label">Quiz Number</label>
                    <input type="number" name="quiz_number" class="form-input" placeholder="e.g. 1" min="1" required>
                </div>
                <div class="full-col">
                    <label class="form-label">Quiz Type</label>
                    <div style="display:flex; gap: 20px; align-items:center; margin-top:4px;">
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.9rem; color:var(--text-primary);"><input type="radio" name="quiz_type" value="manual" checked onchange="toggleQuizType()"> Manual (Multiple Choice)</label>
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.9rem; color:var(--text-primary);"><input type="radio" name="quiz_type" value="file" onchange="toggleQuizType()"> File Upload (PDF/Doc)</label>
                    </div>
                </div>
                <div>
                    <label class="form-label">Start Date & Time</label>
                    <input type="datetime-local" name="start_time" class="form-input" required>
                </div>
                <div>
                    <label class="form-label">End Date & Time</label>
                    <input type="datetime-local" name="end_time" class="form-input" required>
                </div>
                
                <div id="fileUploadSection" style="display:none;" class="full-col form-grid">
                    <div>
                        <label class="form-label">Quiz Question File (PDF/Doc)</label>
                        <input type="file" name="quiz_file" class="form-input" accept=".pdf,.doc,.docx">
                    </div>
                    <div>
                        <label class="form-label">Total Marks</label>
                        <input type="number" name="total_marks" class="form-input" placeholder="e.g. 10" min="1">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Questions (Manual only) -->
        <div class="card" id="manualQuestionsCard">
            <div class="section-heading">Questions <span id="qCount">(0)</span></div>
            <div class="questions-list" id="questionsList"></div>
            <button type="button" class="btn-add-q" onclick="addQuestion()" style="margin-top:12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Question
            </button>
        </div>

        <!-- Always-visible Deploy button -->
        <div style="margin-top:20px;">
            <button type="submit" class="btn-deploy">🚀 &nbsp;Deploy Quiz</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
let qCount = 0;

function toggleQuizType() {
    const type = document.querySelector('input[name="quiz_type"]:checked').value;
    const manualCard = document.getElementById('manualQuestionsCard');
    const fileSection = document.getElementById('fileUploadSection');

    if (type === 'file') {
        fileSection.style.display = 'grid';
        manualCard.style.display = 'none';
        // Disable all inputs inside manual card so browser validation ignores them
        manualCard.querySelectorAll('input, textarea, select').forEach(el => el.disabled = true);

        document.querySelector('input[name="quiz_file"]').required = true;
        document.querySelector('input[name="total_marks"]').required = true;
    } else {
        fileSection.style.display = 'none';
        manualCard.style.display = 'block';
        // Re-enable manual card inputs
        manualCard.querySelectorAll('input, textarea, select').forEach(el => el.disabled = false);

        document.querySelector('input[name="quiz_file"]').required = false;
        document.querySelector('input[name="total_marks"]').required = false;
    }
}


function updateInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('aTitle').textContent = opt.dataset.title || '—';
    document.getElementById('aCode').textContent  = opt.dataset.code  || '—';
    document.getElementById('autofillRow').style.display = 'grid';
}

function addQuestion() {
    qCount++;
    document.getElementById('qCount').textContent = '(' + qCount + ')';
    const idx = qCount - 1;
    const div = document.createElement('div');
    div.className = 'q-card';
    div.id = 'q_' + idx;
    div.innerHTML = `
        <div class="q-num-label">Question ${qCount}</div>
        <button type="button" class="btn-remove-q" onclick="removeQuestion('q_${idx}')">✕ Remove</button>
        <input type="text" name="questions[${idx}][text]" class="form-input" placeholder="Enter question text…" required>
        <div class="options-grid">
            <div class="opt-row"><span class="opt-label">A</span><input type="text" name="questions[${idx}][a]" class="form-input" placeholder="Option A" required></div>
            <div class="opt-row"><span class="opt-label">B</span><input type="text" name="questions[${idx}][b]" class="form-input" placeholder="Option B" required></div>
            <div class="opt-row"><span class="opt-label">C</span><input type="text" name="questions[${idx}][c]" class="form-input" placeholder="Option C" required></div>
            <div class="opt-row"><span class="opt-label">D</span><input type="text" name="questions[${idx}][d]" class="form-input" placeholder="Option D" required></div>
        </div>
        <div style="font-size:.82rem;color:var(--text-secondary);font-weight:600;margin-bottom:8px;">Correct Answer:</div>
        <div class="correct-row">
            <label><input type="radio" name="questions[${idx}][correct]" value="A" required> A</label>
            <label><input type="radio" name="questions[${idx}][correct]" value="B"> B</label>
            <label><input type="radio" name="questions[${idx}][correct]" value="C"> C</label>
            <label><input type="radio" name="questions[${idx}][correct]" value="D"> D</label>
        </div>`;
    document.getElementById('questionsList').appendChild(div);
}

function removeQuestion(id) {
    document.getElementById(id).remove();
    qCount--;
    document.getElementById('qCount').textContent = '(' + qCount + ')';
}

// Start with one question and initialize correct quiz type state
addQuestion();
toggleQuizType();
</script>
</body>
</html>
