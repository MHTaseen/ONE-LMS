<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: landing.php'); exit();
}
require_once 'config.php';

$quiz_id = intval($_GET['id'] ?? 0);
if ($quiz_id < 1) { header('Location: student_quiz.php'); exit(); }

$fullName  = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials  = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0],0,1).substr($nameParts[count($nameParts)-1],0,1))
    : strtoupper(substr($fullName,0,2));

$quiz = null; $questions = []; $alreadySubmitted = false; $submission = null;

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Load quiz meta
    $stmt = $pdo->prepare("SELECT q.*, cs.section_no, c.title as course_title, c.code as course_code
        FROM quizzes q
        JOIN course_sections cs ON q.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        WHERE q.id = ?");
    $stmt->execute([$quiz_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) { header('Location: student_quiz.php'); exit(); }

    // Check if already submitted
    $stmt = $pdo->prepare("SELECT * FROM quiz_submissions WHERE quiz_id = ? AND student_id = ?");
    $stmt->execute([$quiz_id, $student_db_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    $alreadySubmitted = (bool)$submission;

    // Load questions
    $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC");
    $stmt->execute([$quiz_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

$timeLimitSeconds = $quiz['time_limit'] * 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Quiz: <?= htmlspecialchars($quiz['quiz_name']) ?> – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: 64px; z-index: 900; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display:flex; align-items:center; gap:14px; }
        .navbar-right { display:flex; align-items:center; gap:12px; }
        .navbar-brand { font-family:'Space Grotesque',sans-serif; font-size:1.15rem; font-weight:700; background:var(--gradient-accent); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .nav-avatar   { width:40px; height:40px; border-radius:50%; background:var(--gradient-accent); display:flex; justify-content:center; align-items:center; color:#fff; font-weight:700; box-shadow:var(--glow-shadow); }
        .btn-back     { display:flex; align-items:center; gap:8px; padding:9px 18px; background:var(--bg-secondary); border:1px solid var(--border-color); color:var(--text-primary); border-radius:12px; font-size:0.9rem; font-weight:600; text-decoration:none; }
        .btn-back:hover { border-color:var(--accent-primary); }
        .btn-back svg { width:16px; height:16px; }

        .page-wrap { padding:100px 28px 80px; max-width:860px; margin:0 auto; width:100%; }
        .quiz-header { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:20px; padding:24px 28px; margin-bottom:24px; backdrop-filter:blur(16px); box-shadow:var(--card-glow); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; }
        .quiz-title-block .quiz-course-tag { font-size:0.8rem; color:var(--accent-secondary); text-transform:uppercase; font-weight:600; margin-bottom:6px; }
        .quiz-title-block h1 { font-family:'Space Grotesque',sans-serif; font-size:1.4rem; color:var(--text-primary); margin:0; }
        .quiz-title-block .quiz-meta { font-size:0.85rem; color:var(--text-secondary); margin-top:4px; }

        /* Timer */
        .timer-box { display:flex; flex-direction:column; align-items:center; background:rgba(168,85,247,0.1); border:2px solid var(--accent-primary); border-radius:16px; padding:14px 22px; min-width:120px; }
        .timer-label { font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--accent-primary); letter-spacing:1px; margin-bottom:4px; }
        .timer-value { font-family:'Space Grotesque',sans-serif; font-size:1.8rem; font-weight:700; color:var(--text-primary); }
        .timer-box.warning { border-color:#ef4444; background:rgba(239,68,68,0.1); }
        .timer-box.warning .timer-label { color:#ef4444; }
        .timer-box.warning .timer-value { color:#ef4444; animation:blink 1s infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.5} }

        /* Questions */
        .q-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:18px; padding:24px 28px; margin-bottom:18px; box-shadow:var(--card-glow); backdrop-filter:blur(14px); }
        .q-number { font-size:0.78rem; font-weight:700; color:var(--accent-primary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px; }
        .q-text { font-size:1.05rem; color:var(--text-primary); font-weight:600; margin-bottom:20px; line-height:1.5; }
        .options-list { display:flex; flex-direction:column; gap:10px; }
        .option-label { display:flex; align-items:center; gap:14px; padding:13px 18px; border:1px solid var(--border-color); border-radius:12px; cursor:pointer; background:var(--input-bg); transition:border-color .2s, background .2s; }
        .option-label:hover { border-color:var(--accent-primary); background:rgba(168,85,247,0.07); }
        .option-label input[type=radio] { display:none; }
        .option-label.selected { border-color:var(--accent-primary); background:rgba(168,85,247,0.12); }
        .option-label.correct  { border-color:var(--success-color); background:rgba(16,185,129,0.12); }
        .option-label.wrong    { border-color:var(--error-color);   background:rgba(239,68,68,0.12); }
        .opt-badge { width:30px; height:30px; border-radius:50%; display:flex; justify-content:center; align-items:center; font-weight:700; font-size:0.85rem; background:rgba(168,85,247,0.12); color:var(--accent-primary); flex-shrink:0; }
        .opt-text  { font-size:0.95rem; color:var(--text-primary); }

        /* Submit bar */
        .submit-bar { position:fixed; bottom:0; left:0; right:0; background:var(--bg-secondary); border-top:1px solid var(--border-color); backdrop-filter:blur(20px); padding:16px 28px; display:flex; justify-content:space-between; align-items:center; z-index:800; }
        .submit-progress { font-size:0.88rem; color:var(--text-secondary); }
        .submit-progress strong { color:var(--text-primary); }
        .btn-submit { padding:12px 32px; background:var(--gradient-accent); border:none; border-radius:12px; color:#fff; font-size:1rem; font-weight:700; cursor:pointer; font-family:'Space Grotesque',sans-serif; transition:opacity .2s, transform .2s; }
        .btn-submit:hover { opacity:.9; transform:translateY(-2px); }
        .btn-submit:disabled { opacity:.5; cursor:not-allowed; transform:none; }

        /* Result card */
        .result-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:22px; padding:40px; text-align:center; box-shadow:var(--card-glow); backdrop-filter:blur(16px); margin-bottom:30px; }
        .result-score-ring { width:130px; height:130px; border-radius:50%; border:6px solid var(--accent-primary); display:flex; flex-direction:column; justify-content:center; align-items:center; margin:0 auto 24px; background:rgba(168,85,247,0.08); box-shadow:var(--glow-shadow); }
        .result-score-num { font-family:'Space Grotesque',sans-serif; font-size:2rem; font-weight:700; color:var(--text-primary); }
        .result-score-den { font-size:0.85rem; color:var(--text-secondary); }
        .result-title { font-family:'Space Grotesque',sans-serif; font-size:1.5rem; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .result-sub   { font-size:0.95rem; color:var(--text-secondary); margin-bottom:28px; }
        .btn-back-quiz { display:inline-flex; align-items:center; gap:8px; padding:12px 24px; background:var(--gradient-accent); border:none; border-radius:12px; color:#fff; font-weight:700; text-decoration:none; font-size:0.95rem; }
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
        <a href="student_quiz.php" class="btn-back">
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

<div class="page-wrap">
    <!-- Quiz Header -->
    <div class="quiz-header">
        <div class="quiz-title-block">
            <div class="quiz-course-tag"><?= htmlspecialchars($quiz['course_code']) ?> · Section <?= str_pad($quiz['section_no'],2,'0',STR_PAD_LEFT) ?></div>
            <h1>Quiz <?= $quiz['quiz_number'] ?> – <?= htmlspecialchars($quiz['quiz_name']) ?></h1>
            <div class="quiz-meta">
                <?= $quiz['quiz_type'] === 'file' ? 'File Upload Quiz' : count($questions) . ' Questions' ?> 
                &nbsp;·&nbsp; Scheduled: <?= date('M j, g:i A', strtotime($quiz['start_time'])) ?> to <?= date('M j, g:i A', strtotime($quiz['end_time'])) ?>
            </div>
        </div>
        <?php if (!$alreadySubmitted && $quiz['quiz_type'] !== 'file'): ?>
        <div class="timer-box" id="timerBox">
            <div class="timer-label">Time Left</div>
            <div class="timer-value" id="timerDisplay">--:--</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($alreadySubmitted): ?>
        <!-- ── RESULT VIEW ── -->
        <div class="result-card">
            <?php if ($quiz['quiz_type'] === 'file'): ?>
                <div class="result-title">Solution Submitted</div>
                <div class="result-sub">You have successfully submitted your solution for this quiz.</div>
                <?php if ($submission['score'] !== null): ?>
                    <div class="result-score-ring">
                        <div class="result-score-num"><?= $submission['score'] ?></div>
                        <div class="result-score-den">out of <?= $submission['total'] ?></div>
                    </div>
                <?php else: ?>
                    <div style="padding: 20px; background: rgba(245,158,11,0.1); border: 1px solid #f59e0b; color: #f59e0b; border-radius: 12px; margin-bottom: 24px; font-weight: 600;">
                        Grade Pending
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="result-score-ring">
                    <div class="result-score-num"><?= $submission['score'] ?></div>
                    <div class="result-score-den">out of <?= $submission['total'] ?></div>
                </div>
                <div class="result-title">
                    <?php
                    $pct = $submission['total'] > 0 ? round(($submission['score']/$submission['total'])*100) : 0;
                    if ($pct >= 80) echo '🎉 Excellent!';
                    elseif ($pct >= 60) echo '👍 Good Job!';
                    elseif ($pct >= 40) echo '📚 Keep Studying!';
                    else echo '💡 Better Luck Next Time!';
                    ?>
                </div>
                <div class="result-sub">You scored <strong><?= $pct ?>%</strong> on this quiz. Submitted on <?= date('d M Y, h:i A', strtotime($submission['submitted_at'])) ?>.</div>
            <?php endif; ?>
            <a href="student_quiz.php" class="btn-back-quiz">← Back to My Quizzes</a>
        </div>

        <!-- Review answers -->
        <?php
        if ($quiz['quiz_type'] !== 'file'):
            $savedAnswers = json_decode($submission['answers_json'], true) ?? [];
            foreach ($questions as $i => $q):
                $qid = $q['id'];
                $chosen = $savedAnswers[$qid] ?? null;
                $correct = $q['correct_option'];
        ?>
        <div class="q-card">
            <div class="q-number">Question <?= $i+1 ?></div>
            <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>
            <div class="options-list">
                <?php foreach (['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']] as $letter => $text): ?>
                <?php
                    $cls = '';
                    if ($letter === $correct) $cls = 'correct';
                    elseif ($letter === $chosen) $cls = 'wrong';
                ?>
                <div class="option-label <?= $cls ?>">
                    <div class="opt-badge"><?= $letter ?></div>
                    <div class="opt-text"><?= htmlspecialchars($text) ?></div>
                    <?php if ($letter === $correct): ?><span style="margin-left:auto;color:var(--success-color);font-weight:700;">✓</span><?php endif; ?>
                    <?php if ($letter === $chosen && $letter !== $correct): ?><span style="margin-left:auto;color:var(--error-color);font-weight:700;">✗</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php 
            endforeach; 
        endif; 
        ?>

    <?php else: ?>
        <!-- ── QUIZ FORM ── -->
        <?php if ($quiz['quiz_type'] === 'file'): ?>
            <div class="q-card" style="text-align: center;">
                <h3 style="margin-bottom: 20px;">Download Quiz File</h3>
                <a href="<?= htmlspecialchars($quiz['quiz_file_path']) ?>" download class="btn-submit" style="display:inline-block; margin-bottom: 30px; text-decoration:none;">Download Question PDF</a>
                
                <h3 style="margin-bottom: 20px;">Upload Your Solution</h3>
                <form id="quizFileForm">
                    <input type="file" name="solution_file" class="form-input" accept=".pdf,.doc,.docx" required style="max-width: 300px; margin: 0 auto 20px auto; display: block;">
                </form>
            </div>
            
            <div class="submit-bar">
                <div class="submit-progress">File Upload Quiz</div>
                <button class="btn-submit" id="submitFileBtn" onclick="submitFileQuiz()">Submit Solution</button>
            </div>
            
            <script>
            function submitFileQuiz() {
                const fd = new FormData(document.getElementById('quizFileForm'));
                fd.append('quiz_id', <?= $quiz_id ?>);
                
                document.getElementById('submitFileBtn').disabled = true;
                document.getElementById('submitFileBtn').textContent = 'Uploading...';
                
                fetch('submit_quiz.php', { method:'POST', body:fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success || data.error === 'Quiz already submitted') {
                            window.location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Unknown'));
                            document.getElementById('submitFileBtn').disabled = false;
                            document.getElementById('submitFileBtn').textContent = 'Submit Solution';
                        }
                    })
                    .catch(() => {
                        alert('Network error. Please try again.');
                        document.getElementById('submitFileBtn').disabled = false;
                        document.getElementById('submitFileBtn').textContent = 'Submit Solution';
                    });
            }
            </script>
        <?php else: ?>
            <?php if (empty($questions)): ?>
                <div class="alert-box alert-error">This quiz has no questions yet.</div>
            <?php else: ?>
            <form id="quizForm">
                <?php foreach ($questions as $i => $q): ?>
                <div class="q-card" id="qcard_<?= $q['id'] ?>">
                    <div class="q-number">Question <?= $i+1 ?> of <?= count($questions) ?></div>
                    <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>
                    <div class="options-list">
                        <?php foreach (['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']] as $letter => $text): ?>
                        <label class="option-label" id="opt_<?= $q['id'] ?>_<?= $letter ?>" onclick="selectOpt(<?= $q['id'] ?>, '<?= $letter ?>', this)">
                            <input type="radio" name="q<?= $q['id'] ?>" value="<?= $letter ?>">
                            <div class="opt-badge"><?= $letter ?></div>
                            <div class="opt-text"><?= htmlspecialchars($text) ?></div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </form>

            <!-- Sticky submit bar -->
            <div class="submit-bar">
                <div class="submit-progress">Answered: <strong><span id="answeredCount">0</span> / <?= count($questions) ?></strong></div>
                <button class="btn-submit" id="submitBtn" onclick="submitQuiz()">Submit Quiz</button>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$alreadySubmitted && $quiz['quiz_type'] !== 'file' && !empty($questions)): ?>
<script>
const TOTAL_SECONDS = <?= $timeLimitSeconds ?>;
const QUIZ_ID = <?= $quiz_id ?>;
let secondsLeft = TOTAL_SECONDS;
let answers = {};
let submitted = false;

// Timer
const timerDisplay = document.getElementById('timerDisplay');
const timerBox     = document.getElementById('timerBox');
function updateTimer() {
    const m = Math.floor(secondsLeft / 60);
    const s = secondsLeft % 60;
    timerDisplay.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    if (secondsLeft <= 60) timerBox.classList.add('warning');
    if (secondsLeft <= 0 && !submitted) submitQuiz(true);
    else secondsLeft--;
}
updateTimer();
const timerInterval = setInterval(updateTimer, 1000);

// Option selection
function selectOpt(qid, letter, el) {
    // Deselect siblings
    const siblings = document.querySelectorAll(`[id^="opt_${qid}_"]`);
    siblings.forEach(s => s.classList.remove('selected'));
    el.classList.add('selected');
    answers[qid] = letter;
    document.getElementById('answeredCount').textContent = Object.keys(answers).length;
}

// Submit
function submitQuiz(auto = false) {
    if (submitted) return;
    if (!auto && Object.keys(answers).length < <?= count($questions) ?>) {
        if (!confirm('You have unanswered questions. Submit anyway?')) return;
    }
    submitted = true;
    clearInterval(timerInterval);
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').textContent = 'Submitting…';

    // Build form data
    const fd = new FormData();
    fd.append('quiz_id', QUIZ_ID);
    for (const [qid, letter] of Object.entries(answers)) {
        fd.append('q' + qid, letter);
    }

    fetch('submit_quiz.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(data => {
            if (data.success || data.error === 'Quiz already submitted') {
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown'));
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').textContent = 'Submit Quiz';
                submitted = false;
            }
        })
        .catch(() => {
            alert('Network error. Please try again.');
            submitted = false;
        });
}
</script>
<?php endif; ?>
</body>
</html>
