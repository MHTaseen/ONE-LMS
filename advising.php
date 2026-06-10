<?php
// advising.php – Student access only
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Block non-students
if ($_SESSION['role'] !== 'student') {
    header('Location: landing.php');
    exit();
}

require_once 'config.php';

$fullName  = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials  = count($nameParts) > 1
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1))
    : strtoupper(substr($fullName, 0, 2));

$errorMsg = '';
$successMsg = '';

// Handle Enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll') {
    $section_id = intval($_POST['section_id']);
    $course_id = intval($_POST['course_id']);
    $student_db_id = null;

    // Check advising state first
    $stmtState = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'advising_open'");
    $stateRow = $stmtState->fetch();
    $isAdvisingOpenPost = $stateRow ? ($stateRow['setting_value'] === '1') : false;

    if (!$isAdvisingOpenPost) {
        $errorMsg = "The advising portal is currently closed. You cannot enroll at this time.";
    } else {

    try {
        // Get student db id
        $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userRow = $stmt->fetch();
        $student_db_id = $userRow['id'];

        $is_switch = isset($_POST['switch_section']) && $_POST['switch_section'] === '1';

        // Check 4 course limit
        $stmtCount = $pdo->prepare("SELECT COUNT(DISTINCT cs.course_id) as course_count FROM enrollments e JOIN course_sections cs ON e.section_id = cs.id WHERE e.student_id = ?");
        $stmtCount->execute([$student_db_id]);
        $enrolledCount = $stmtCount->fetch()['course_count'];

        // Check if already enrolled in this course (any section)
        $stmt = $pdo->prepare("SELECT e.id, e.section_id FROM enrollments e JOIN course_sections cs ON e.section_id = cs.id WHERE e.student_id = ? AND cs.course_id = ?");
        $stmt->execute([$student_db_id, $course_id]);
        $existingEnrollment = $stmt->fetch();

        if ($enrolledCount >= 4 && !$existingEnrollment) {
            $errorMsg = "You cannot enroll in more than 4 courses in a semester.";
        } elseif ($existingEnrollment && !$is_switch) {
            $errorMsg = "You are already enrolled in a section for this course.";
        } else {
            // Check seat availability
            $stmt = $pdo->prepare("SELECT seats, (SELECT COUNT(*) FROM enrollments WHERE section_id = ?) as current_enrollment FROM course_sections WHERE id = ?");
            $stmt->execute([$section_id, $section_id]);
            $secInfo = $stmt->fetch();

            if ($secInfo && $secInfo['current_enrollment'] >= $secInfo['seats']) {
                $errorMsg = "This section is full.";
            } else {
                if ($is_switch && $existingEnrollment) {
                    // Update section_id (switch section)
                    $stmt = $pdo->prepare("UPDATE enrollments SET section_id = ? WHERE id = ?");
                    $stmt->execute([$section_id, $existingEnrollment['id']]);
                    $successMsg = "Successfully switched section!";
                } else {
                    // Enroll
                    $stmt = $pdo->prepare("INSERT INTO enrollments (student_id, section_id) VALUES (?, ?)");
                    $stmt->execute([$student_db_id, $section_id]);
                    $successMsg = "Successfully enrolled in the course!";
                }
            }
        }
    } catch (PDOException $e) {
        $errorMsg = "Enrollment failed: " . $e->getMessage();
    }
    }
}

// Fetch all courses and their sections
$advisingOpen = false;
try {
    // Fetch advising_open state
    $stmtState = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'advising_open'");
    $stateRow = $stmtState->fetch();
    $advisingOpen = $stateRow ? ($stateRow['setting_value'] === '1') : false;
    // Get student db id for tracking what they've enrolled in
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Get courses and sections student is enrolled in
    $enrolledCourseSections = [];
    $stmt = $pdo->prepare("SELECT cs.course_id, e.section_id FROM enrollments e JOIN course_sections cs ON e.section_id = cs.id WHERE e.student_id = ?");
    $stmt->execute([$student_db_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $enrolledCourseSections[$row['course_id']] = $row['section_id'];
    }

    // Get all courses with creator name
    $stmt = $pdo->query("SELECT c.*, u.full_name as teacher_name FROM courses c JOIN users u ON c.teacher_id = u.id ORDER BY c.title ASC");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all sections with enrollment counts and section teacher name
    $stmtSec = $pdo->query("
        SELECT cs.*, u.full_name as section_teacher_name,
               (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = cs.id) as current_enrollment 
        FROM course_sections cs 
        LEFT JOIN users u ON cs.teacher_id = u.id
        ORDER BY cs.section_no ASC
    ");
    $allSections = $stmtSec->fetchAll(PDO::FETCH_ASSOC);

    // Group sections by course_id
    $sectionsByCourse = [];
    foreach ($allSections as $sec) {
        $sectionsByCourse[$sec['course_id']][] = $sec;
    }

} catch (PDOException $e) {
    $courses = [];
    $sectionsByCourse = [];
    $enrolledCourseIds = [];
    $errorMsg = "Failed to load advising data: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Advising – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

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
        }
        .nav-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--gradient-accent); display: flex; justify-content: center; align-items: center;
            color: #ffffff; font-weight: 700; font-size: 0.95rem; box-shadow: var(--glow-shadow); cursor: default;
        }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .theme-switch-container { position: static; }

        .btn-back {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color);
            color: var(--text-primary); border-radius: 12px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none;
        }

        .page-wrap {
            padding: 100px 28px 60px; max-width: 1000px; margin: 0 auto; width: 100%;
        }
        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem;
            font-weight: 700; margin-bottom: 6px;
            background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading {
            color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 32px;
        }

        /* Accordion Styles */
        .advising-list {
            display: flex; flex-direction: column; gap: 15px;
        }
        
        .course-accordion {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; overflow: hidden; box-shadow: var(--card-glow);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }
        
        .course-header {
            padding: 20px 24px; display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; user-select: none; transition: background 0.2s;
        }
        .course-header:hover { background: rgba(168, 85, 247, 0.05); }
        
        .course-title-wrap { display: flex; align-items: center; gap: 15px; }
        
        .course-code-badge {
            background: var(--input-bg); border: 1px solid var(--accent-secondary);
            color: var(--accent-secondary); font-weight: 700; font-size: 0.85rem;
            padding: 6px 12px; border-radius: 8px; font-family: 'Space Grotesque', sans-serif;
        }
        
        .course-name {
            font-size: 1.15rem; font-weight: 600; color: var(--text-primary); font-family: 'Space Grotesque', sans-serif;
        }
        
        .chevron-icon {
            width: 20px; height: 20px; color: var(--text-secondary); transition: transform 0.3s ease;
        }
        
        /* Expanded state */
        .course-accordion.active .course-header {
            border-bottom: 1px solid var(--border-color); background: rgba(168, 85, 247, 0.05);
        }
        .course-accordion.active .chevron-icon { transform: rotate(180deg); color: var(--accent-primary); }
        
        .course-body {
            max-height: 0; overflow: hidden; transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .course-details-content {
            padding: 24px; background: rgba(0,0,0,0.1);
        }
        .light-theme .course-details-content { background: rgba(255,255,255,0.3); }
        
        .info-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px; margin-bottom: 25px;
        }
        .info-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 15px; display: flex; flex-direction: column; gap: 5px;
        }
        .info-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--text-secondary); letter-spacing: 0.5px; }
        .info-value { font-size: 1rem; font-weight: 600; color: var(--text-primary); }
        
        /* Sections Table */
        .sections-wrapper {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 12px; overflow: hidden;
        }
        .sections-header {
            padding: 15px 20px; border-bottom: 1px solid var(--border-color);
            font-weight: 600; font-family: 'Space Grotesque', sans-serif;
            background: rgba(168, 85, 247, 0.1); color: var(--accent-primary);
        }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 12px 20px; font-size: 0.9rem; border-bottom: 1px solid var(--border-color); }
        th { font-size: 0.8rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 600; letter-spacing: 0.5px; }
        tr:last-child td { border-bottom: none; }
        
        .btn-enroll {
            background: var(--gradient-accent); border: none; color: #fff;
            padding: 6px 12px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;
            cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-enroll:hover { transform: translateY(-1px); box-shadow: var(--glow-shadow); }

        .no-data { padding: 30px; text-align: center; color: var(--text-secondary); font-style: italic; }

        /* ── Search Bar ── */
        .search-wrap {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .search-input-wrap {
            position: relative;
            flex: 1;
            max-width: 380px;
        }
        .search-input-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: var(--text-secondary);
            pointer-events: none;
        }
        .course-search-input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .course-search-input:focus {
            border-color: var(--accent-primary);
            box-shadow: var(--glow-shadow);
        }
        .search-count {
            font-size: 0.85rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }
        #noSearchResult {
            display: none;
            padding: 30px;
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
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

    <?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


    <div class="page-wrap">
        <h1 class="page-heading">Advising Module</h1>
        <p class="page-subheading">View available courses and section statuses to plan your enrollment.</p>

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

        <div class="advising-list">
            <?php if (empty($courses)): ?>
                <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">
                    No courses are currently available in the database.
                </div>
            <?php else: ?>
                <div class="search-wrap">
                    <div class="search-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="courseSearchInput" class="course-search-input" placeholder="Search by Course Code (e.g. CSE110)..." autocomplete="off">
                    </div>
                    <span class="search-count" id="searchCount"></span>
                </div>
                <div class="sections-wrapper" style="box-shadow: var(--card-glow); border-radius: 16px;">
                    <div class="sections-header" style="background: var(--bg-secondary); padding: 20px 24px; font-size: 1.2rem; border-bottom: 2px solid var(--border-color);">Section Status</div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Section</th>
                                    <th>Credit</th>
                                    <th>Faculty</th>
                                    <th>Total Seat</th>
                                    <th>Seat Booked</th>
                                    <th>Seat Remaining</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="advisingTableBody">
                                <?php 
                                foreach ($courses as $course): 
                                    $cId = $course['id'];
                                    $sections = isset($sectionsByCourse[$cId]) ? $sectionsByCourse[$cId] : [];
                                    if (empty($sections)) continue;

                                    foreach ($sections as $sec): 
                                        $booked = $sec['current_enrollment'];
                                        $total = $sec['seats'];
                                        $availableSeats = $total - $booked;
                                        $isFull = $availableSeats <= 0;
                                        $isEnrolledInCourse = isset($enrolledCourseSections[$cId]);
                                        $enrolledSectionId = $isEnrolledInCourse ? $enrolledCourseSections[$cId] : null;
                                        $isEnrolledInThisSection = ($enrolledSectionId == $sec['id']);
                                ?>
                                <tr data-code="<?= strtolower(htmlspecialchars($course['code'])) ?>">
                                    <td style="font-weight:700; color:var(--text-primary);">
                                        <?= htmlspecialchars($course['code']) ?>
                                    </td>
                                    <td style="font-weight:700; color:var(--text-primary);">
                                        <?= str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT) ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($course['credit']) ?>
                                    </td>
                                    <td style="color:var(--accent-secondary); font-weight:600;">
                                        <?= htmlspecialchars($sec['section_teacher_name'] ?? $course['teacher_name']) ?>
                                    </td>
                                    <td>
                                        <?= $total ?>
                                    </td>
                                    <td>
                                        <?= $booked ?>
                                    </td>
                                    <td>
                                        <span style="<?= $isFull ? 'color:var(--error-color); font-weight:700;' : 'color:var(--success-color); font-weight:700;' ?>">
                                            <?= $availableSeats ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!$advisingOpen): ?>
                                            <button class="btn-enroll" style="background:var(--input-bg); color:var(--text-secondary); cursor:not-allowed;" disabled>Closed</button>
                                        <?php elseif ($isEnrolledInThisSection): ?>
                                            <button class="btn-enroll" style="background:var(--success-color); color:#fff; cursor:default;" disabled>Enrolled</button>
                                        <?php elseif ($isFull): ?>
                                            <button class="btn-enroll" style="background:var(--error-color); cursor:not-allowed;" disabled>Full</button>
                                        <?php elseif ($isEnrolledInCourse): ?>
                                            <button type="button" class="btn-enroll" style="background:var(--accent-primary);" onclick="promptSwitchSection(<?= $cId ?>, <?= $sec['id'] ?>, '<?= htmlspecialchars($course['code']) ?>', '<?= str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT) ?>')">Enroll</button>
                                        <?php else: ?>
                                            <form method="POST" action="advising.php" style="margin:0;">
                                                <input type="hidden" name="action" value="enroll">
                                                <input type="hidden" name="course_id" value="<?= $cId ?>">
                                                <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                                                <button type="submit" class="btn-enroll">Enroll</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    endforeach; 
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="noSearchResult">No courses found matching your search.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Switch Section Modal -->
    <div class="modal-overlay" id="switchModalOverlay">
        <div class="section-modal" style="max-width: 400px; text-align: center;">
            <h2 class="modal-title" style="margin-bottom: 15px;">Switch Section?</h2>
            <p style="color: var(--text-secondary); margin-bottom: 25px;">Can't enroll in multiple sections. Do you want to switch to <span id="switchCourseCode" style="font-weight: 700; color: var(--text-primary);"></span> Section <span id="switchSectionNo" style="font-weight: 700; color: var(--text-primary);"></span>?</p>
            
            <form method="POST" action="advising.php" id="switchForm" style="display: flex; gap: 15px; justify-content: center;">
                <input type="hidden" name="action" value="enroll">
                <input type="hidden" name="switch_section" value="1">
                <input type="hidden" name="course_id" id="switch_course_id">
                <input type="hidden" name="section_id" id="switch_section_id">
                
                <button type="button" class="btn-action" onclick="closeSwitchModal()">No</button>
                <button type="submit" class="btn-primary" style="width: auto; padding: 10px 24px;">Yes</button>
            </form>
        </div>
    </div>

<script src="theme.js"></script>
<script>
    const switchOverlay = document.getElementById('switchModalOverlay');

    function promptSwitchSection(courseId, sectionId, code, section) {
        document.getElementById('switch_course_id').value = courseId;
        document.getElementById('switch_section_id').value = sectionId;
        document.getElementById('switchCourseCode').textContent = code;
        document.getElementById('switchSectionNo').textContent = section;
        switchOverlay.classList.add('visible');
        document.body.style.overflow = 'hidden';
    }

    function closeSwitchModal() {
        switchOverlay.classList.remove('visible');
        document.body.style.overflow = '';
    }

    // Close on background click
    switchOverlay.addEventListener('click', (e) => {
        if (e.target === switchOverlay) closeSwitchModal();
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && switchOverlay.classList.contains('visible')) {
            closeSwitchModal();
        }
    });

    const searchInput = document.getElementById('courseSearchInput');
    const searchCount = document.getElementById('searchCount');
    const noResult   = document.getElementById('noSearchResult');
    const allRows    = document.querySelectorAll('#advisingTableBody tr');

    function updateSearch() {
        const q = searchInput.value.trim().toLowerCase();
        let visible = 0;
        allRows.forEach(row => {
            const code = (row.getAttribute('data-code') || '').toLowerCase();
            const match = !q || code.startsWith(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        if (q) {
            searchCount.textContent = visible + ' result' + (visible !== 1 ? 's' : '');
            noResult.style.display = visible === 0 ? 'block' : 'none';
        } else {
            searchCount.textContent = '';
            noResult.style.display = 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', updateSearch);
    }
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
