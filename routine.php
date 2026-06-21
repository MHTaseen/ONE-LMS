<?php
// routine.php – Student access only
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
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
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $student_db_id = $stmt->fetch()['id'];

    $activeSemId = isset($activeSemester['id']) ? intval($activeSemester['id']) : 0;
    $stmt = $pdo->prepare("
        SELECT cs.*, c.title, c.code, u.full_name as teacher_name
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE e.student_id = ? AND e.semester_id = ?
    ");
    $stmt->execute([$student_db_id, $activeSemId]);
    $enrolledSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errorMsg = "Failed to load routine: " . $e->getMessage();
}

// ── Build schedule data ──────────────────────────────────────────────────────
$days = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Saturday"];
$scheduleData = [];
foreach ($days as $day) $scheduleData[$day] = [];

foreach ($enrolledSections as $sec) {
    $base = [
        'code'       => $sec['code'],
        'title'      => $sec['title'],
        'section_no' => str_pad($sec['section_no'], 2, '0', STR_PAD_LEFT),
        'room'       => $sec['room_no'],
        'teacher'    => $sec['teacher_name'],
        'type'       => 'Theory',
    ];
    if (!empty($sec['theory_day_1']) && isset($scheduleData[$sec['theory_day_1']])) {
        $scheduleData[$sec['theory_day_1']][] = array_merge($base, ['time' => $sec['theory_time_slot']]);
    }
    if (!empty($sec['theory_day_2']) && isset($scheduleData[$sec['theory_day_2']])) {
        $scheduleData[$sec['theory_day_2']][] = array_merge($base, ['time' => $sec['theory_time_slot']]);
    }
    if (!empty($sec['lab_day']) && isset($scheduleData[$sec['lab_day']])) {
        $scheduleData[$sec['lab_day']][] = array_merge($base, [
            'type' => 'Lab',
            'time' => $sec['lab_time_slot'],
            'room' => $sec['lab_room_no'] ?: $sec['room_no'],
        ]);
    }
}

// ── Collect & sort unique time slots ────────────────────────────────────────
$allTimeSlots = [];
foreach ($scheduleData as $classes) {
    foreach ($classes as $cls) {
        if (!empty($cls['time']) && !in_array($cls['time'], $allTimeSlots)) {
            $allTimeSlots[] = $cls['time'];
        }
    }
}
usort($allTimeSlots, function($a, $b) {
    return strtotime(explode(' - ', $a)[0]) - strtotime(explode(' - ', $b)[0]);
});

// ── Build lookup: day -> timeSlot -> [classes] ────────────────────────────
$gridData = [];
foreach ($days as $day) {
    $gridData[$day] = [];
    foreach ($scheduleData[$day] as $cls) {
        $gridData[$day][$cls['time']][] = $cls;
    }
}

// ── Helper: shorten time label ────────────────────────────────────────────
function shortTimeLabel(string $slot): string {
    // "08:00 AM - 09:20 AM" → "8:00–9:20"
    $parts = explode(' - ', $slot);
    $fmt = function($t) {
        $t = trim($t);
        $d = date_create($t);
        if (!$d) return $t;
        $h = (int)date('g', $d->getTimestamp());
        $m = date('i', $d->getTimestamp());
        $suffix = date('A', $d->getTimestamp()) === 'PM' && $h !== 12 ? 'pm' : ($m === '00' ? '' : '');
        return $m === '00' ? "{$h}{$suffix}" : "{$h}:{$m}";
    };
    if (count($parts) === 2) {
        return $fmt($parts[0]) . '–' . $fmt($parts[1]);
    }
    return $slot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Routine – ONE LMS</title>
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
        .btn-back:hover { border-color: var(--accent-primary); box-shadow: var(--glow-shadow); transform: translateY(-2px); }
        .btn-back svg { width: 16px; height: 16px; }

        .page-wrap {
            padding: 90px 24px 48px;
            max-width: 1100px;
            margin: 0 auto;
            width: 100%;
        }
        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.9rem;
            font-weight: 700; margin-bottom: 6px;
            background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading { color: var(--text-secondary); font-size: .95rem; margin-bottom: 28px; }

        /* ── Timetable wrapper card ── */
        .tt-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: var(--card-glow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            overflow: hidden;
            margin-top: 20px;
        }
        .tt-header {
            background: linear-gradient(135deg, rgba(168,85,247,.12), rgba(6,182,212,.08));
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; gap: 10px;
            font-family: 'Space Grotesque', sans-serif;
            font-size: 1.2rem; font-weight: 700; color: var(--text-primary);
        }
        .tt-header-icon { font-size: 1.3rem; }

        /* Scroll wrapper – horizontal scroll on small screens */
        .tt-scroll {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
        }

        /* ── The timetable grid ──
           Column 1 = time label, columns 2-7 = one per day (6 days) */
        .tt-grid {
            display: grid;
            grid-template-columns: 140px repeat(6, minmax(130px, 1fr));
            min-width: 800px;
            border-collapse: collapse; /* visual effect via borders */
        }

        /* ── Header row ── */
        .tt-corner {
            background: rgba(168,85,247,.06);
            border-bottom: 2px solid var(--accent-primary);
            border-right: 1px solid var(--border-color);
        }
        .tt-day-head {
            padding: 14px 10px;
            background: rgba(168,85,247,.08);
            border-bottom: 2px solid var(--accent-primary);
            border-right: 1px solid var(--border-color);
            text-align: center;
            font-family: 'Space Grotesque', sans-serif;
            font-weight: 700; font-size: 0.85rem;
            text-transform: uppercase; letter-spacing: 0.7px;
            color: var(--accent-primary);
        }
        .tt-day-head:last-child { border-right: none; }

        /* ── Time-slot row label ── */
        .tt-time {
            padding: 10px 12px;
            background: rgba(0,0,0,.14);
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            text-align: center; gap: 2px;
        }
        .light-theme .tt-time { background: rgba(255,255,255,.45); }
        .tt-time-main {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 0.8rem; font-weight: 700;
            color: var(--accent-secondary);
        }
        .tt-time-full {
            font-size: 0.65rem; color: var(--text-secondary);
            line-height: 1.3;
        }

        /* ── Day cell in each row ── */
        .tt-cell {
            padding: 8px;
            border-bottom: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            display: flex; flex-direction: column; gap: 6px;
            min-height: 80px;
        }
        .tt-cell:last-child { border-right: none; }

        /* ── Class card inside a cell ── */
        .tt-class-card {
            position: relative;
            overflow: hidden;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 8px 10px 8px 14px;
            transition: transform .18s, box-shadow .18s;
            cursor: default;
        }
        .tt-class-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow-shadow);
        }
        .tt-class-card::before {
            content: '';
            position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
            border-radius: 2px 0 0 2px;
        }
        .tt-class-card.theory::before { background: var(--accent-secondary); }
        .tt-class-card.lab::before    { background: var(--accent-primary); }

        .ttc-code {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 0.9rem; font-weight: 700;
            color: var(--text-primary); margin-bottom: 3px;
        }
        .ttc-meta {
            display: flex; flex-wrap: wrap; gap: 4px 10px;
            font-size: 0.7rem; color: var(--text-secondary);
        }
        .ttc-meta span { white-space: nowrap; }
        .ttc-pill {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 5px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .ttc-pill.theory { background: rgba(6,182,212,.14); color: var(--accent-secondary); }
        .ttc-pill.lab    { background: rgba(168,85,247,.14); color: var(--accent-primary); }

        /* Empty cell */
        .tt-cell-empty {
            color: transparent; /* completely invisible – just empty space */
        }

        /* Scroll hint */
        .tt-scroll-hint {
            display: none;
            text-align: center;
            font-size: 0.72rem;
            color: var(--text-secondary);
            padding: 8px 0 4px;
            opacity: .7;
        }

        /* Legend */
        .tt-legend {
            display: flex; gap: 18px; flex-wrap: wrap;
            padding: 14px 24px;
            border-top: 1px solid var(--border-color);
            background: rgba(168,85,247,.03);
        }
        .legend-item {
            display: flex; align-items: center; gap: 7px;
            font-size: 0.8rem; color: var(--text-secondary);
        }
        .legend-dot {
            width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0;
        }
        .legend-dot.theory { background: var(--accent-secondary); }
        .legend-dot.lab    { background: var(--accent-primary); }

        @media (max-width: 768px) {
            .page-wrap { padding: 80px 12px 32px; }
            .tt-scroll-hint { display: block; }
            .tt-grid { min-width: 700px; }
            .tt-corner, .tt-time { width: 110px; }
        }
        @media (max-width: 600px) {
            .top-navbar { padding: 0 10px; }
            .navbar-brand { display: none; }
            .nav-avatar { width: 34px; height: 34px; font-size: 0.8rem; }
            .page-wrap { padding: 72px 10px 28px; }
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <?php include 'includes/global_nav.php'; ?>
    <?php include 'includes/shared_drawer.php'; ?>

    <div class="page-wrap">
        <h1 class="page-heading">Class Routine — <?= htmlspecialchars($activeSemester['label'] ?? 'Unknown Semester') ?></h1>
        <p class="page-subheading">Your weekly timetable. Classes are placed at their exact time slot.</p>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert-box alert-error"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if (empty($enrolledSections)): ?>
            <div class="alert-box" style="background:var(--bg-secondary); border-color:var(--border-color);">
                You are not enrolled in any courses yet. Visit
                <a href="advising.php" style="color:var(--accent-primary);text-decoration:none;">Advising</a>
                to register for classes.
            </div>
        <?php else: ?>

        <div class="tt-card">
            <div class="tt-header">
                <span class="tt-header-icon">📅</span>
                Weekly Timetable
            </div>

            <div class="tt-scroll">
                <div class="tt-grid">

                    <!-- ── Header row: corner + day names ── -->
                    <div class="tt-corner"></div>
                    <?php foreach ($days as $day): ?>
                        <div class="tt-day-head"><?= $day ?></div>
                    <?php endforeach; ?>

                    <?php if (empty($allTimeSlots)): ?>
                        <!-- No time slots at all – show a single empty row -->
                        <div class="tt-time"><span class="tt-time-main">—</span></div>
                        <?php foreach ($days as $day): ?>
                            <div class="tt-cell tt-cell-empty"></div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <!-- ── One row per time slot ── -->
                        <?php foreach ($allTimeSlots as $slot): ?>
                            <div class="tt-time">
                                <span class="tt-time-main"><?= htmlspecialchars(shortTimeLabel($slot)) ?></span>
                                <span class="tt-time-full"><?= nl2br(htmlspecialchars(str_replace(' - ', "\n", $slot))) ?></span>
                            </div>

                            <?php foreach ($days as $day): ?>
                                <div class="tt-cell">
                                    <?php if (!empty($gridData[$day][$slot])): ?>
                                        <?php foreach ($gridData[$day][$slot] as $cls):
                                            $isLab   = $cls['type'] === 'Lab';
                                            $typeKey = $isLab ? 'lab' : 'theory';
                                        ?>
                                        <div class="tt-class-card <?= $typeKey ?>">
                                            <div class="ttc-code"><?= htmlspecialchars($cls['code']) ?></div>
                                            <div class="ttc-meta">
                                                <span>Sec <?= htmlspecialchars($cls['section_no']) ?></span>
                                                <span><?= htmlspecialchars($cls['room']) ?></span>
                                            </div>
                                            <span class="ttc-pill <?= $typeKey ?>"><?= $cls['type'] ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>

                </div><!-- /tt-grid -->
            </div><!-- /tt-scroll -->

            <p class="tt-scroll-hint">← Swipe left / right to see all days →</p>

            <!-- Legend -->
            <div class="tt-legend">
                <div class="legend-item">
                    <div class="legend-dot theory"></div>
                    <span>Theory class</span>
                </div>
                <div class="legend-item">
                    <div class="legend-dot lab"></div>
                    <span>Lab class</span>
                </div>
            </div>
        </div><!-- /tt-card -->

        <?php endif; ?>
    </div>

<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
