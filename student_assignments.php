<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'guest'])) {
    header('Location: landing.php'); exit();
}
$role = $_SESSION['role'];
require_once 'config.php';

$fullName  = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials  = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0],0,1).substr($nameParts[count($nameParts)-1],0,1))
    : strtoupper(substr($fullName,0,2));

$errorMsg = '';
$courseAssignments = [];

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Get enrolled sections for active semester
    $activeSemId = isset($activeSemester['id']) ? intval($activeSemester['id']) : 0;
    $stmt = $pdo->prepare("
        SELECT cs.id as section_id, cs.section_no, c.id as course_id, c.title, c.code, u.full_name as teacher_name
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE e.student_id = ? AND e.semester_id = ?
        ORDER BY c.title ASC
    ");
    $stmt->execute([$student_db_id, $activeSemId]);
    $enrolledSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all submission IDs for this student for easy lookup
    $stmt = $pdo->prepare("SELECT assignment_id, original_filename, submitted_at FROM assignment_submissions WHERE student_id = ?");
    $stmt->execute([$student_db_id]);
    $submittedMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $submittedMap[$row['assignment_id']] = $row;
    }

    foreach ($enrolledSections as $sec) {
        $stmt2 = $pdo->prepare("SELECT * FROM assignments WHERE section_id = ? ORDER BY assignment_number ASC");
        $stmt2->execute([$sec['section_id']]);
        $assignments = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($assignments)) {
            // Annotate submission status
            foreach ($assignments as &$a) {
                $a['submitted']    = isset($submittedMap[$a['id']]);
                $a['sub_filename'] = $a['submitted'] ? $submittedMap[$a['id']]['original_filename'] : null;
                $a['sub_at']       = $a['submitted'] ? $submittedMap[$a['id']]['submitted_at'] : null;
            }
            unset($a);

            $courseAssignments[] = [
                'course_id'    => $sec['course_id'],
                'title'        => $sec['title'],
                'code'         => $sec['code'],
                'section_no'   => $sec['section_no'],
                'teacher_name' => $sec['teacher_name'],
                'assignments'  => $assignments,
            ];
        }
    }
} catch (PDOException $e) {
    $errorMsg = "Failed to load assignments: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Assignments – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center; padding: 10px 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-avatar   { width: 40px; height: 40px; border-radius: 50%; background: var(--gradient-accent); display: flex; justify-content: center; align-items: center; color: #fff; font-weight: 700; box-shadow: var(--glow-shadow); }
        .btn-back     { display: flex; align-items: center; gap: 8px; padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 12px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none; transition: border-color .2s; }
        .btn-back:hover { border-color: var(--accent-primary); }
        .btn-back svg { width: 16px; height: 16px; }

        .page-wrap     { padding: 140px 28px 60px; max-width: 960px; margin: 0 auto; width: 100%; }
        .page-heading  { font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem; font-weight: 700; margin-bottom: 6px; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .page-subheading { color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px; }

        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; }
        .course-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 18px; padding: 24px; cursor: pointer; box-shadow: var(--card-glow); backdrop-filter: blur(14px); transition: transform .2s, border-color .2s, box-shadow .2s; position: relative; overflow: hidden; }
        .course-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: var(--gradient-accent); }
        .course-card:hover { transform: translateY(-4px); border-color: var(--accent-primary); box-shadow: var(--glow-shadow); }
        .course-card-code  { font-family: 'Space Grotesque', sans-serif; font-size: 0.8rem; font-weight: 700; color: var(--accent-secondary); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px; }
        .course-card-title { font-family: 'Space Grotesque', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 10px; }
        .course-card-meta  { font-size: 0.82rem; color: var(--text-secondary); }
        .assign-count-badge { display: inline-flex; align-items: center; gap: 5px; margin-top: 14px; background: rgba(168,85,247,0.12); border: 1px solid rgba(168,85,247,0.25); color: var(--accent-primary); padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.65); backdrop-filter: blur(6px); justify-content: center; align-items: center; padding: 20px; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 22px; width: 100%; max-width: 680px; max-height: 88vh; overflow-y: auto; box-shadow: 0 30px 80px rgba(0,0,0,.5); animation: slideUp .3s cubic-bezier(.4,0,.2,1); }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { padding: 24px 28px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; background: rgba(168,85,247,0.06); position: sticky; top: 0; z-index: 1; backdrop-filter: blur(12px); }
        .modal-title    { font-family: 'Space Grotesque', sans-serif; font-size: 1.2rem; font-weight: 700; color: var(--text-primary); }
        .modal-subtitle { font-size: 0.85rem; color: var(--text-secondary); margin-top: 4px; }
        .modal-close { background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; border-radius: 8px; transition: color .2s; }
        .modal-close:hover { color: var(--text-primary); }
        .modal-close svg { width: 22px; height: 22px; }
        .modal-body { padding: 24px 28px; display: flex; flex-direction: column; gap: 16px; }

        /* Assignment card */
        .asgn-card { background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; }
        .asgn-card-top { padding: 16px 20px; }
        .asgn-num  { font-size: 0.75rem; color: var(--accent-secondary); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
        .asgn-name { font-family: 'Space Grotesque', sans-serif; font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-bottom: 5px; }
        .asgn-date { font-size: 0.8rem; color: var(--text-secondary); }
        .asgn-file { font-size: 0.82rem; color: var(--text-secondary); margin-top: 5px; display: flex; align-items: center; gap: 5px; }
        .asgn-file svg { width: 14px; height: 14px; color: var(--accent-primary); flex-shrink: 0; }

        /* Status bar */
        .asgn-status-bar { padding: 10px 20px; border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }
        .status-submitted   { background: rgba(16,185,129,0.12); color: #10b981; border: 1px solid rgba(16,185,129,0.3); }
        .status-unsubmitted { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.25); }
        .status-badge svg { width: 13px; height: 13px; }

        /* Action buttons row */
        .asgn-actions { display: flex; gap: 8px; }
        .btn-dl, .btn-sub {
            display: flex; align-items: center; gap: 7px; padding: 8px 14px;
            border: none; border-radius: 10px; font-size: 0.82rem; font-weight: 700;
            cursor: pointer; text-decoration: none; transition: opacity .2s, transform .2s; white-space: nowrap;
        }
        .btn-dl  { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-primary); }
        .btn-dl:hover  { border-color: var(--accent-secondary); }
        .btn-sub { background: var(--gradient-accent); color: #fff; }
        .btn-sub:hover { opacity: .85; transform: translateY(-1px); }
        .btn-sub:disabled { opacity: .5; cursor: not-allowed; transform: none; }
        .btn-dl svg, .btn-sub svg { width: 14px; height: 14px; }

        /* Hidden file input for submit */
        .hidden-file { display: none; }

        /* Sub info */
        .sub-info { font-size: 0.78rem; color: var(--text-secondary); margin-top: 4px; }
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
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>
<div class="ambient-glow-1"></div>
<div class="ambient-glow-2"></div>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-wrap">
    <h1 class="page-heading">My Assignments</h1>
    <p class="page-subheading">Click a course to view, download, and submit your assignments.</p>

    <?php if ($errorMsg): ?>
        <div class="alert-box alert-error"><?= htmlspecialchars($errorMsg) ?></div>
    <?php elseif (empty($courseAssignments)): ?>
        <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">
            No assignments have been deployed for your enrolled courses yet.
        </div>
    <?php else: ?>
        <div class="course-grid">
            <?php foreach ($courseAssignments as $idx => $ca):
                $submittedCount = count(array_filter($ca['assignments'], fn($a) => $a['submitted']));
                $total = count($ca['assignments']);
            ?>
            <div class="course-card" onclick="openModal(<?= $idx ?>)">
                <div class="course-card-code"><?= htmlspecialchars($ca['code']) ?> · Sec <?= str_pad($ca['section_no'],2,'0',STR_PAD_LEFT) ?></div>
                <div class="course-card-title"><?= htmlspecialchars($ca['title']) ?></div>
                <div class="course-card-meta">Instructor: <?= htmlspecialchars($ca['teacher_name']) ?></div>
                <div class="assign-count-badge">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16"/><path d="M18 22H8a2 2 0 0 1-2-2V4h12v16a2 2 0 0 1-2 2z"/></svg>
                    <?= $submittedCount ?>/<?= $total ?> Submitted
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Modals -->
        <?php foreach ($courseAssignments as $idx => $ca): ?>
        <div class="modal-overlay" id="modal_<?= $idx ?>">
            <div class="modal-box">
                <div class="modal-header">
                    <div>
                        <div class="modal-title"><?= htmlspecialchars($ca['title']) ?></div>
                        <div class="modal-subtitle"><?= htmlspecialchars($ca['code']) ?> · Sec <?= str_pad($ca['section_no'],2,'0',STR_PAD_LEFT) ?> · <?= htmlspecialchars($ca['teacher_name']) ?></div>
                    </div>
                    <button class="modal-close" onclick="closeModal(<?= $idx ?>)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <?php foreach ($ca['assignments'] as $asgn): ?>
                    <div class="asgn-card" id="acard_<?= $asgn['id'] ?>">
                        <div class="asgn-card-top">
                            <div class="asgn-num">Assignment <?= $asgn['assignment_number'] ?></div>
                            <div class="asgn-name"><?= htmlspecialchars($asgn['assignment_name']) ?></div>
                            <div class="asgn-date">Deployed: <?= date('M d, Y · h:i A', strtotime($asgn['created_at'])) ?></div>
                            <div class="asgn-file">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <?= htmlspecialchars($asgn['original_filename']) ?>
                            </div>
                        </div>
                        <div class="asgn-status-bar">
                            <div>
                                <?php if ($asgn['submitted']): ?>
                                    <div class="status-badge status-submitted">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        Submitted
                                    </div>
                                    <div class="sub-info">📎 <?= htmlspecialchars($asgn['sub_filename']) ?> · <?= date('M d, Y', strtotime($asgn['sub_at'])) ?></div>
                                <?php else: ?>
                                    <div class="status-badge status-unsubmitted">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                        Not Submitted
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="asgn-actions">
                                <a class="btn-dl" href="download_assignment.php?id=<?= $asgn['id'] ?>" download>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Download
                                </a>
                                <?php if ($role === 'guest'): ?>
                                <button class="btn-sub" disabled style="opacity:.4; cursor:not-allowed;" title="Guests cannot submit assignments">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    Guest Restricted
                                </button>
                                <?php elseif (!$asgn['submitted']): ?>
                                <button class="btn-sub" id="sbtn_<?= $asgn['id'] ?>" onclick="triggerSubmit(<?= $asgn['id'] ?>)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    Submit
                                </button>
                                <input type="file" class="hidden-file" id="sfile_<?= $asgn['id'] ?>" onchange="doSubmit(<?= $asgn['id'] ?>, this)">
                                <?php else: ?>
                                <button class="btn-sub" disabled style="opacity:.4; cursor:not-allowed;">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                    Submitted
                                </button>
                                <?php endif; ?>
                            </div>
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
function openModal(idx) {
    document.getElementById('modal_' + idx).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(idx) {
    document.getElementById('modal_' + idx).classList.remove('open');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) { this.classList.remove('open'); document.body.style.overflow = ''; }
    });
});

function triggerSubmit(assignmentId) {
    document.getElementById('sfile_' + assignmentId).click();
}

function doSubmit(assignmentId, input) {
    if (!input.files || !input.files[0]) return;
    if (!confirm('Are you sure you want to submit this assignment?')) {
        input.value = '';
        return;
    }
    const btn = document.getElementById('sbtn_' + assignmentId);
    btn.disabled = true;
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/></svg> Uploading…';

    const fd = new FormData();
    fd.append('assignment_id', assignmentId);
    fd.append('submission_file', input.files[0]);

    fetch('submit_assignment.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const card = document.getElementById('acard_' + assignmentId);
            const bar = card.querySelector('.asgn-status-bar');
            bar.querySelector('div:first-child').innerHTML =
                '<div class="status-badge status-submitted"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="13" height="13"><polyline points="20 6 9 17 4 12"/></svg> Submitted</div>' +
                '<div class="sub-info">📎 ' + data.filename + ' · Just now</div>';
            btn.outerHTML = '<button class="btn-sub" disabled style="opacity:.4; cursor:not-allowed;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg> Submitted</button>';
        } else {
            alert('Error: ' + (data.error || 'Upload failed'));
            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Submit';
        }
    })
    .catch(() => { alert('Network error. Please try again.'); btn.disabled = false; });
}
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
