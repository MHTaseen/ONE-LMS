<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header('Location: landing.php'); exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';

$fullName = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0],0,1).substr($nameParts[count($nameParts)-1],0,1))
    : strtoupper(substr($fullName,0,2));

$successMsg = ''; $errorMsg = '';
$teacherSections = [];

// Get teacher DB id
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher_db_id = $stmt->fetch()['id'];

    // Fetch teacher's sections with course info
    $stmt = $pdo->prepare("
        SELECT cs.id, cs.section_no, c.title, c.code
        FROM course_sections cs
        JOIN courses c ON cs.course_id = c.id
        WHERE c.teacher_id = ?
        ORDER BY c.title ASC, cs.section_no ASC
    ");
    $stmt->execute([$teacher_db_id]);
    $teacherSections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorMsg = "Failed to load sections: " . $e->getMessage();
}

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deploy') {
    $section_id      = intval($_POST['section_id']);
    $assignment_name = trim($_POST['assignment_name']);
    $assignment_num  = intval($_POST['assignment_number']);
    $deadline        = !empty($_POST['deadline']) ? $_POST['deadline'] : null;

    if (empty($assignment_name) || $assignment_num < 1 || empty($_FILES['assignment_file']['name'])) {
        $errorMsg = "Please fill in all fields and select a file.";
    } elseif ($_FILES['assignment_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMsg = "File upload failed. Please try again.";
    } else {
        $originalName = basename($_FILES['assignment_file']['name']);
        // Save file with a unique prefix to avoid collisions but keep original name accessible
        $storedName   = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $uploadDir    = __DIR__ . '/uploads/';
        $filePath     = $uploadDir . $storedName;

        if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $filePath)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO assignments (section_id, assignment_name, assignment_number, original_filename, file_path, deadline) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$section_id, $assignment_name, $assignment_num, $originalName, 'uploads/' . $storedName, $deadline]);
                $successMsg = "Assignment successfully deployed to the selected section.";

                // Notify students
                $stmt = $pdo->prepare("SELECT student_id FROM enrollments WHERE section_id = ?");
                $stmt->execute([$section_id]);
                $students = $stmt->fetchAll();
                
                // Get course info for msg
                $cstmt = $pdo->prepare("SELECT c.code, cs.section_no FROM course_sections cs JOIN courses c ON cs.course_id = c.id WHERE cs.id = ?");
                $cstmt->execute([$section_id]);
                $cinfo = $cstmt->fetch();
                
                $msg = "New Assignment Deployed in {$cinfo['code']} (Sec {$cinfo['section_no']}): {$assignment_name}";
                if ($deadline) {
                    $msg .= "\nDeadline: " . date('M j, Y g:i A', strtotime($deadline));
                }
                $link = "student_assignments.php";
                foreach($students as $st) {
                    sendNotification($pdo, $st['student_id'], 'deployment', $msg, $link);
                }
            } catch (PDOException $e) {
                $errorMsg = "Database error: " . $e->getMessage();
            }
        } else {
            $errorMsg = "Could not save the uploaded file. Check server permissions.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Deploy Assignment – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: 64px; z-index: 900;
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0,0,0,.25);
        }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-avatar   { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-accent); display: flex; justify-content: center; align-items: center; color: #fff; font-weight: 700; box-shadow: var(--glow-shadow); }
        .btn-back     { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 12px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: border-color .2s; }
        .btn-back:hover { border-color: var(--accent-primary); }
        .btn-back svg { width: 16px; height: 16px; }

        .page-wrap    { padding: 100px 28px 60px; max-width: 760px; margin: 0 auto; width: 100%; }
        .page-heading { font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem; font-weight: 700; margin-bottom: 6px; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .page-subheading { color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px; }

        .deploy-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 20px; padding: 36px; box-shadow: var(--card-glow);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
        }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full-col  { grid-column: 1 / -1; }

        .form-label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 8px; }

        .form-input, .form-select {
            width: 100%; padding: 12px 16px; background: var(--input-bg); border: 1px solid var(--border-color);
            border-radius: 12px; color: var(--text-primary); font-size: 0.95rem; outline: none;
            transition: border-color .25s, box-shadow .25s; box-sizing: border-box;
        }
        .form-input:focus, .form-select:focus { border-color: var(--accent-primary); box-shadow: 0 0 0 3px rgba(168,85,247,.15); }
        .form-select option { background: var(--bg-primary); }

        .autofill-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .autofill-box {
            background: rgba(168,85,247,0.06); border: 1px solid rgba(168,85,247,0.2);
            border-radius: 12px; padding: 12px 16px;
        }
        .autofill-label { font-size: 0.75rem; color: var(--accent-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
        .autofill-value { font-family: 'Space Grotesque', sans-serif; font-size: 1rem; font-weight: 700; color: var(--text-primary); }

        .file-upload-area {
            border: 2px dashed var(--border-color); border-radius: 14px;
            padding: 30px; text-align: center; cursor: pointer; transition: border-color .25s, background .25s;
            position: relative;
        }
        .file-upload-area:hover, .file-upload-area.drag-over { border-color: var(--accent-primary); background: rgba(168,85,247,0.04); }
        .file-upload-area input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .file-icon { width: 40px; height: 40px; margin: 0 auto 12px; color: var(--accent-secondary); }
        .file-label-text { font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
        .file-sub-text  { font-size: 0.82rem; color: var(--text-secondary); }
        .file-chosen    { margin-top: 10px; font-size: 0.9rem; color: var(--accent-primary); font-weight: 600; display: none; }

        .btn-deploy {
            width: 100%; padding: 15px; background: var(--gradient-accent); border: none; border-radius: 14px;
            color: #fff; font-size: 1rem; font-weight: 700; cursor: pointer; font-family: 'Space Grotesque', sans-serif;
            letter-spacing: .5px; transition: opacity .2s, transform .2s; margin-top: 8px;
        }
        .btn-deploy:hover { opacity: .9; transform: translateY(-2px); }
        .btn-deploy:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        .section-divider { height: 1px; background: var(--border-color); margin: 28px 0; }
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
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
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
    <h1 class="page-heading">Deploy Assignment</h1>
    <p class="page-subheading">Select a course section and upload an assignment file for enrolled students.</p>

    <?php if ($successMsg): ?>
        <div class="alert-box alert-success" style="margin-bottom:20px;">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert-box alert-error" style="margin-bottom:20px;">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($teacherSections)): ?>
        <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">
            You have no course sections yet. Go to <a href="teacher_courses.php" style="color:var(--accent-primary);">Courses</a> to create one first.
        </div>
    <?php else: ?>
    <div class="deploy-card">
        <form method="POST" action="deploy_assignment.php" enctype="multipart/form-data" id="deployForm">
            <input type="hidden" name="action" value="deploy">

            <div class="form-grid">
                <!-- Section selector -->
                <div class="full-col">
                    <label class="form-label">Course Section</label>
                    <select name="section_id" class="form-select" id="sectionSelect" required onchange="updateCourseInfo(this)">
                        <option value="" disabled selected>— Select a section —</option>
                        <?php foreach ($teacherSections as $sec): ?>
                            <option value="<?= $sec['id'] ?>"
                                data-title="<?= htmlspecialchars($sec['title']) ?>"
                                data-code="<?= htmlspecialchars($sec['code']) ?>">
                                <?= htmlspecialchars($sec['code']) ?> – <?= htmlspecialchars($sec['title']) ?> (Sec <?= str_pad($sec['section_no'],2,'0',STR_PAD_LEFT) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Auto-filled course info -->
                <div class="full-col autofill-row" id="autofillRow" style="display:none;">
                    <div class="autofill-box">
                        <div class="autofill-label">Course Title</div>
                        <div class="autofill-value" id="autoCourseTitle">—</div>
                    </div>
                    <div class="autofill-box">
                        <div class="autofill-label">Course Code</div>
                        <div class="autofill-value" id="autoCourseCode">—</div>
                    </div>
                </div>

                <div class="section-divider full-col"></div>

                <!-- Assignment details -->
                <div>
                    <label class="form-label">Assignment Name</label>
                    <input type="text" name="assignment_name" class="form-input" placeholder="e.g. Data Structures Project" required>
                </div>
                <div>
                    <label class="form-label">Assignment Number</label>
                    <input type="number" name="assignment_number" class="form-input" placeholder="e.g. 1" min="1" required>
                </div>
                <div class="full-col">
                    <label class="form-label">Deadline</label>
                    <input type="datetime-local" name="deadline" class="form-input" required>
                </div>

                <!-- File upload -->
                <div class="full-col">
                    <label class="form-label">Assignment File</label>
                    <div class="file-upload-area" id="dropZone">
                        <input type="file" name="assignment_file" id="fileInput" required onchange="showFileName(this)">
                        <svg class="file-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                        <div class="file-label-text">Click or drag & drop to upload</div>
                        <div class="file-sub-text">Any file type accepted — PDF, DOCX, ZIP, etc.</div>
                        <div class="file-chosen" id="fileChosen"></div>
                    </div>
                </div>

                <div class="full-col">
                    <button type="submit" class="btn-deploy" id="deployBtn">
                        🚀 &nbsp;Deploy Assignment
                    </button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function updateCourseInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('autoCourseTitle').textContent = opt.dataset.title || '—';
    document.getElementById('autoCourseCode').textContent  = opt.dataset.code  || '—';
    document.getElementById('autofillRow').style.display = 'grid';
}

function showFileName(input) {
    const el = document.getElementById('fileChosen');
    if (input.files && input.files[0]) {
        el.textContent = '✓ ' + input.files[0].name;
        el.style.display = 'block';
    }
}

// Drag & drop visual
const zone = document.getElementById('dropZone');
if (zone) {
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        const fi = document.getElementById('fileInput');
        fi.files = e.dataTransfer.files;
        showFileName(fi);
    });
}
</script>
</body>
</html>
