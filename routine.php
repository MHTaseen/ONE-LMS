<?php
// routine.php – Student access only
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
$enrolledSections = [];

try {
    // Get student db id
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    // Fetch enrolled sections with course info
    $stmt = $pdo->prepare("
        SELECT cs.*, c.title, c.code, u.full_name as teacher_name 
        FROM enrollments e 
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE e.student_id = ?
    ");
    $stmt->execute([$student_db_id]);
    $enrolledSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errorMsg = "Failed to load routine: " . $e->getMessage();
}

// Prepare schedule grid data
$days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Saturday"];
$scheduleData = [];
foreach ($days as $day) {
    $scheduleData[$day] = [];
}

foreach ($enrolledSections as $sec) {
    $courseInfo = [
        'code' => $sec['code'],
        'section_no' => str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT),
        'room' => $sec['room_no'],
        'type' => 'Theory'
    ];
    
    // Add theory classes
    if (isset($scheduleData[$sec['theory_day_1']])) {
        $scheduleData[$sec['theory_day_1']][] = array_merge($courseInfo, ['time' => $sec['theory_time_slot']]);
    }
    if (isset($scheduleData[$sec['theory_day_2']])) {
        $scheduleData[$sec['theory_day_2']][] = array_merge($courseInfo, ['time' => $sec['theory_time_slot']]);
    }
    
    // Add lab class
    if (isset($scheduleData[$sec['lab_day']])) {
        $labInfo = $courseInfo;
        $labInfo['type'] = 'Lab';
        $labInfo['time'] = $sec['lab_time_slot'];
        $scheduleData[$sec['lab_day']][] = $labInfo;
    }
}

// Sort each day's classes by time (simple string sort works since time formats start with 08, 09, 10, 11, 12, 01...)
// Actually, AM/PM sort requires a bit more logic. Let's do a simple sort function.
function sortTime($a, $b) {
    $timeA = strtotime(explode('-', $a['time'])[0]);
    $timeB = strtotime(explode('-', $b['time'])[0]);
    return $timeA - $timeB;
}

foreach ($days as $day) {
    usort($scheduleData[$day], 'sortTime');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Routine – BRAC University Hub</title>
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
        .theme-switch-container { position: static; }

        .btn-back {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--bg-secondary);
            border: 1px solid var(--border-color); color: var(--text-primary);
            border-radius: 12px; cursor: pointer; font-size: .9rem;
            font-weight: 600; backdrop-filter: blur(12px); text-decoration: none;
            transition: border-color .25s, box-shadow .25s, transform .2s;
        }
        .btn-back:hover {
            border-color: var(--accent-primary);
            box-shadow: var(--glow-shadow); transform: translateY(-2px);
        }
        .btn-back svg { width: 16px; height: 16px; }

        .page-wrap {
            padding: 90px 28px 40px; max-width: 1000px;
            margin: 0 auto; width: 100%;
        }
        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem;
            font-weight: 700; margin-bottom: 6px;
            background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading {
            color: var(--text-secondary); font-size: .95rem; margin-bottom: 32px;
        }

        /* Calendar Styles */
        .calendar-wrapper {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 20px; box-shadow: var(--card-glow);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            overflow: hidden; margin-top: 20px;
        }
        
        .calendar-header {
            background: rgba(168, 85, 247, 0.1); padding: 20px;
            border-bottom: 1px solid var(--border-color);
            font-family: 'Space Grotesque', sans-serif; font-size: 1.25rem;
            color: var(--text-primary); font-weight: 700;
        }

        .day-row {
            display: flex; border-bottom: 1px solid var(--border-color);
            min-height: 100px;
        }
        .day-row:last-child { border-bottom: none; }
        
        .day-label {
            width: 140px; min-width: 140px; padding: 20px;
            background: rgba(0,0,0,0.2); border-right: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-family: 'Space Grotesque', sans-serif;
            color: var(--accent-secondary); letter-spacing: 0.5px;
        }
        .light-theme .day-label { background: rgba(255,255,255,0.4); }
        
        .classes-container {
            flex-grow: 1; padding: 15px; display: flex; gap: 15px; flex-wrap: wrap;
        }
        
        .class-card {
            background: var(--input-bg); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 12px 16px; width: 220px;
            position: relative; overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .class-card:hover { transform: translateY(-2px); box-shadow: var(--glow-shadow); }
        .class-card::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
        }
        .class-theory::before { background: var(--accent-secondary); }
        .class-lab::before { background: var(--accent-primary); }
        
        .class-time { font-size: 0.8rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 5px; }
        .class-code { font-family: 'Space Grotesque', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
        .class-details { display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.85rem; color: var(--text-secondary); }
        .class-type { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .class-type.theory { color: var(--accent-secondary); }
        .class-type.lab { color: var(--accent-primary); }
        
        .empty-day {
            display: flex; align-items: center; color: var(--text-secondary);
            font-style: italic; font-size: 0.9rem; padding: 10px; opacity: 0.6;
        }

        @media (max-width: 768px) {
            .day-row { flex-direction: column; }
            .day-label { width: 100%; border-right: none; border-bottom: 1px solid var(--border-color); padding: 10px 20px; justify-content: flex-start; }
            .class-card { width: 100%; }
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
        <h1 class="page-heading">Class Routine</h1>
        <p class="page-subheading">Your weekly academic schedule based on your current enrollments.</p>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-box alert-error"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if (empty($enrolledSections)): ?>
            <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">
                You are not enrolled in any courses yet. Go to the Advising panel to register for classes.
            </div>
        <?php else: ?>
            <div class="calendar-wrapper">
                <div class="calendar-header">Weekly Calendar</div>
                
                <?php foreach ($days as $day): ?>
                    <div class="day-row">
                        <div class="day-label"><?= $day ?></div>
                        <div class="classes-container">
                            <?php if (empty($scheduleData[$day])): ?>
                                <div class="empty-day">No classes scheduled</div>
                            <?php else: ?>
                                <?php foreach ($scheduleData[$day] as $class): 
                                    $isLab = $class['type'] === 'Lab';
                                    $cardClass = $isLab ? 'class-lab' : 'class-theory';
                                    $typeClass = $isLab ? 'lab' : 'theory';
                                ?>
                                    <div class="class-card <?= $cardClass ?>">
                                        <div class="class-time"><?= htmlspecialchars($class['time']) ?></div>
                                        <div class="class-code"><?= htmlspecialchars($class['code']) ?></div>
                                        <div class="class-details">
                                            <span>Sec: <?= htmlspecialchars($class['section_no']) ?></span>
                                            <span>Room: <?= htmlspecialchars($class['room']) ?></span>
                                        </div>
                                        <div style="margin-top:8px;">
                                            <span class="class-type <?= $typeClass ?>"><?= $class['type'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
