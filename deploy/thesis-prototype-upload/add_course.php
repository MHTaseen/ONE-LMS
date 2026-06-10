<?php
// add_course.php – Teacher access only
session_start();

// Redirect to login if user is not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Block non-teachers
if ($_SESSION['role'] !== 'teacher') {
    header('Location: landing.php');
    exit();
}

$fullName = $_SESSION['full_name'];
$initials = '';
$nameParts = explode(' ', trim($fullName));
if (count($nameParts) > 1) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $initials = strtoupper(substr($fullName, 0, 2));
}

$errorMsg = '';
$successMsg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $code = trim($_POST['code']);
    $credit = floatval($_POST['credit']);
    $total_marks = intval($_POST['total_marks']);
    $theory_marks = intval($_POST['theory_marks']);
    $lab_marks = intval($_POST['lab_marks']);
    $department = trim($_POST['department']);

    // Validation
    if ($theory_marks + $lab_marks !== 100) {
        $errorMsg = "Theory and Lab marks must add up to exactly 100.";
    } elseif (empty($title) || empty($code) || empty($department)) {
        $errorMsg = "Please fill out all required fields.";
    } else {
        // DB connection
        require_once 'config.php';
        
        try {
            // we use the DB ID from session. If it doesn't exist, we query it.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $userRow = $stmt->fetch();
            $dbUserId = $userRow['id'];

            $stmt = $pdo->prepare("INSERT INTO courses (teacher_id, title, code, credit, total_marks, theory_marks, lab_marks, department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$dbUserId, $title, $code, $credit, $total_marks, $theory_marks, $lab_marks, $department]);
            
            $successMsg = "Course added successfully!";
        } catch (PDOException $e) {
            $errorMsg = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Add Course – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

        /* ── Navbar ── */
        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: 64px;
            z-index: 900; display: flex; align-items: center;
            justify-content: space-between; padding: 0 28px;
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.25);
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        .navbar-brand {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem;
            font-weight: 700; background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px; user-select: none;
        }
        .nav-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--gradient-accent); display: flex;
            justify-content: center; align-items: center; color: #ffffff;
            font-weight: 700; font-size: 0.95rem; box-shadow: var(--glow-shadow);
            cursor: default; letter-spacing: 0.5px; font-family: 'Space Grotesque', sans-serif;
        }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .theme-switch-container { position: static; }

        /* Back Button */
        .btn-back {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--bg-secondary);
            border: 1px solid var(--border-color); color: var(--text-primary);
            border-radius: 12px; cursor: pointer; font-size: 0.9rem;
            font-weight: 600; backdrop-filter: blur(12px); text-decoration: none;
            transition: border-color 0.25s, box-shadow 0.25s, transform 0.2s;
        }
        .btn-back:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--glow-shadow); transform: translateY(-2px);
        }
        .btn-back svg { width: 16px; height: 16px; }

        /* ── Page content ── */
        .page-wrap {
            padding: 100px 28px 60px; max-width: 800px;
            margin: 0 auto; width: 100%;
        }
        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem;
            font-weight: 700; margin-bottom: 6px;
            background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading {
            color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px;
        }

        /* ── Form Card ── */
        .form-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 24px; padding: 40px; box-shadow: var(--card-glow);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            position: relative; overflow: hidden;
        }
        .form-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 4px; background: var(--gradient-accent);
        }

        /* Form Grid Layout */
        .form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
        }
        .full-width { grid-column: span 2; }
        
        .marks-group {
            display: grid; grid-template-columns: 1fr 1fr; gap: 15px;
            background: rgba(0,0,0,0.1); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color);
        }
        .light-theme .marks-group { background: rgba(255,255,255,0.4); }
        .marks-label { grid-column: span 2; font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); margin-bottom: -5px; }
        
        #marksError {
            grid-column: span 2; color: var(--error-color); font-size: 0.8rem;
            margin-top: -5px; display: none; font-weight: 600;
        }
        
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .full-width { grid-column: span 1; }
        }

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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                Back
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
        <h1 class="page-heading">Add Course</h1>
        <p class="page-subheading">Create a new course offering for the university repository.</p>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-box alert-error">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert-box alert-success">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                <?= htmlspecialchars($successMsg) ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form action="add_course.php" method="POST" id="addCourseForm">
                <div class="form-grid">
                    
                    <div class="form-group full-width">
                        <label class="form-label" for="title">Course Title</label>
                        <input type="text" id="title" name="title" class="form-input" placeholder="e.g. Data Structures and Algorithms" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="code">Course Code</label>
                        <input type="text" id="code" name="code" class="form-input" placeholder="e.g. CSE220" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="department">Department</label>
                        <div class="input-wrapper">
                            <select id="department" name="department" class="form-input" required>
                                <option value="" disabled selected>Select Department</option>
                                <option value="ALL">ALL (University Wide)</option>
                                <option value="CSE">CSE (Computer Science & Engineering)</option>
                                <option value="CS">CS (Computer Science)</option>
                                <option value="EEE">EEE (Electrical & Electronics)</option>
                                <option value="BBA">BBA (Business Administration)</option>
                                <option value="MNS">MNS (Mathematics & Natural Sciences)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="credit">Credit Hours</label>
                        <input type="number" step="0.5" id="credit" name="credit" class="form-input" placeholder="e.g. 3.0" value="3.0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="total_marks">Total Marks</label>
                        <input type="number" id="total_marks" name="total_marks" class="form-input" placeholder="e.g. 100" value="100" required>
                    </div>

                    <div class="marks-group full-width">
                        <div class="marks-label">Marks Distribution (Must total 100)</div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="theory_marks" style="color:var(--accent-secondary)">Theory Marks</label>
                            <input type="number" id="theory_marks" name="theory_marks" class="form-input" placeholder="0-100" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label" for="lab_marks" style="color:var(--accent-primary)">Lab Marks</label>
                            <input type="number" id="lab_marks" name="lab_marks" class="form-input" placeholder="0-100" required>
                        </div>
                        <div id="marksError">Sum of Theory and Lab must equal 100. Current sum: <span id="marksSum">0</span></div>
                    </div>

                    <div class="form-group full-width" style="margin-top: 15px;">
                        <button type="submit" class="btn-primary" id="submitBtn">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Publish Course to Database
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const theoryInput = document.getElementById('theory_marks');
            const labInput = document.getElementById('lab_marks');
            const marksError = document.getElementById('marksError');
            const marksSumDisplay = document.getElementById('marksSum');
            const form = document.getElementById('addCourseForm');
            const submitBtn = document.getElementById('submitBtn');

            function validateMarks() {
                const theory = parseInt(theoryInput.value) || 0;
                const lab = parseInt(labInput.value) || 0;
                const sum = theory + lab;
                
                marksSumDisplay.textContent = sum;

                if (sum !== 100 && (theoryInput.value !== '' && labInput.value !== '')) {
                    marksError.style.display = 'block';
                    theoryInput.style.borderColor = 'var(--error-color)';
                    labInput.style.borderColor = 'var(--error-color)';
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                    submitBtn.style.cursor = 'not-allowed';
                } else {
                    marksError.style.display = 'none';
                    theoryInput.style.borderColor = '';
                    labInput.style.borderColor = '';
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                }
            }

            theoryInput.addEventListener('input', validateMarks);
            labInput.addEventListener('input', validateMarks);
            
            form.addEventListener('submit', (e) => {
                const theory = parseInt(theoryInput.value) || 0;
                const lab = parseInt(labInput.value) || 0;
                if (theory + lab !== 100) {
                    e.preventDefault();
                    validateMarks();
                }
            });
        });
    </script>
</body>
</html>
