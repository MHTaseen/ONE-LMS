<?php
// student_materials.php - Student UI for viewing course materials
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'guest'])) {
    header("Location: login.php");
    exit();
}
$role = $_SESSION['role'];
require_once 'config.php';

$student_db_id = $_SESSION['user_pk'] ?? 0;
if (!$student_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $student_db_id = $row['id'] ?? 0;
}

$view_mode = $_GET['view'] ?? 'my'; // 'my' or 'all'
$active_course_id = intval($_GET['course_id'] ?? 0);
$active_cat = $_GET['cat'] ?? '';

$categories = [
    'Syllabus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
    'Video Lectures' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="2" y1="7" x2="7" y2="7"/><line x1="2" y1="17" x2="7" y2="17"/><line x1="17" y1="17" x2="22" y2="17"/><line x1="17" y1="7" x2="22" y2="7"/></svg>',
    'Slides' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
    'Books' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
    'Previous Questions' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
];

// Fetch courses
$courses = [];
if ($view_mode === 'my') {
    // Only courses the student is enrolled in this semester
    $activeSemId = isset($activeSemester['id']) ? intval($activeSemester['id']) : 0;
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.code, c.title, t.full_name as teacher_name
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users t ON c.teacher_id = t.id
        WHERE e.student_id = ? AND e.semester_id = ?
        ORDER BY c.code ASC
    ");
    $stmt->execute([$student_db_id, $activeSemId]);
    $courses = $stmt->fetchAll();
} else {
    // All courses in the university
    $stmt = $pdo->prepare("
        SELECT c.id, c.code, c.title, t.full_name as teacher_name
        FROM courses c
        JOIN users t ON c.teacher_id = t.id
        ORDER BY c.code ASC
    ");
    $stmt->execute();
    $courses = $stmt->fetchAll();
}

$active_course_data = null;
if ($active_course_id) {
    $stmt = $pdo->prepare("SELECT c.id, c.code, c.title, t.full_name as teacher_name FROM courses c JOIN users t ON c.teacher_id = t.id WHERE c.id = ?");
    $stmt->execute([$active_course_id]);
    $active_course_data = $stmt->fetch();
}

// Fetch materials if category selected
$materials = [];
if ($active_course_id && $active_cat && array_key_exists($active_cat, $categories)) {
    $stmt = $pdo->prepare("SELECT * FROM course_materials WHERE course_id = ? AND category = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$active_course_id, $active_cat]);
    $materials = $stmt->fetchAll();
}

// Material count for folders
$counts = [];
if ($active_course_id && !$active_cat) {
    $stmt = $pdo->prepare("SELECT category, COUNT(*) as cnt FROM course_materials WHERE course_id = ? GROUP BY category");
    $stmt->execute([$active_course_id]);
    $res = $stmt->fetchAll();
    foreach ($res as $r) {
        $counts[$r['category']] = $r['cnt'];
    }
}

$public_library_materials = [];
if (!$active_course_id && $view_mode === 'all') {
    $stmt = $pdo->prepare("
        SELECT cm.*, c.code AS course_code, c.title AS course_title, t.full_name AS teacher_name
        FROM course_materials cm
        JOIN courses c ON cm.course_id = c.id
        JOIN users t ON c.teacher_id = t.id
        ORDER BY cm.uploaded_at DESC
    ");
    $stmt->execute();
    $public_library_materials = $stmt->fetchAll();
}

function formatBytes($size, $precision = 2) {
    if ($size == 0) return '0 B';
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');   
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Course Materials - BRACU Thesis</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center; padding: 10px 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: color 0.2s; }
        .nav-btn-back:hover { color: var(--accent-primary); }
        .nav-btn-back svg { width: 18px; height: 18px; }

        .page-container {
            padding: 100px 40px 40px;
            max-width: 1200px;
            margin: 0 auto;
            min-height: 100vh;
        }
        .header h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2.2rem;
            background: var(--gradient-accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        .header p { color: var(--text-secondary); font-size: 1.1rem; margin-bottom: 30px; }

        .view-tabs { display: flex; gap: 16px; margin-bottom: 32px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; }
        .view-tabs a { text-decoration: none; padding: 8px 16px; border-radius: 8px; color: var(--text-secondary); font-weight: 600; font-size: 1rem; transition: 0.2s; }
        .view-tabs a.active { background: rgba(168,85,247,0.15); color: var(--accent-primary); }
        .view-tabs a:hover:not(.active) { background: rgba(255,255,255,0.05); }

        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .course-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 24px; text-decoration: none; display: flex; flex-direction: column;
            transition: all 0.3s;
        }
        .course-card:hover { transform: translateY(-4px); border-color: rgba(168,85,247,0.4); box-shadow: var(--card-glow); }
        .course-card h3 { color: var(--text-primary); font-size: 1.3rem; margin-bottom: 8px; }
        .course-card p { color: var(--text-secondary); font-size: 0.95rem; margin-bottom: 16px; flex-grow: 1; }
        .t-badge { display: inline-block; background: rgba(59,130,246,0.15); color: #3b82f6; padding: 4px 8px; border-radius: 6px; font-size: 0.8rem; font-weight: 600; align-self: flex-start; }

        /* Folders Grid */
        .folder-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .folder-card { background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 24px; text-decoration: none; display: flex; flex-direction: column; align-items: center; text-align: center; transition: all 0.2s; }
        .folder-card:hover { transform: translateY(-4px); border-color: var(--accent-primary); box-shadow: var(--card-glow); background: rgba(168,85,247,0.03); }
        .folder-card svg { width: 48px; height: 48px; color: var(--accent-primary); margin-bottom: 16px; stroke-width: 1.5; }
        .folder-card h3 { color: var(--text-primary); font-size: 1.1rem; margin-bottom: 8px; }
        .folder-card .count { color: var(--text-secondary); font-size: 0.85rem; font-weight: 600; background: rgba(255,255,255,0.05); padding: 4px 10px; border-radius: 20px; }

        /* Files List */
        .file-list { display: flex; flex-direction: column; gap: 12px; }
        .file-item { display: flex; align-items: center; justify-content: space-between; background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 16px 20px; border-radius: 12px; text-decoration: none; transition: 0.2s; }
        .file-item:hover { border-color: var(--accent-primary); background: rgba(168,85,247,0.03); }
        .file-info { display: flex; align-items: center; gap: 16px; }
        .file-icon { width: 40px; height: 40px; border-radius: 10px; background: rgba(168,85,247,0.1); color: var(--accent-primary); display: flex; align-items: center; justify-content: center; }
        .file-icon svg { width: 20px; height: 20px; }
        .file-details h4 { color: var(--text-primary); font-size: 1.05rem; margin-bottom: 4px; }
        .file-details p { color: var(--text-secondary); font-size: 0.85rem; }
        .file-action { color: var(--accent-primary); font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 6px; }
        .file-item:hover .file-action { text-decoration: underline; }

        .library-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 20px; }
        .library-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 22px;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: transform 0.25s, border-color 0.25s, box-shadow 0.25s;
            min-height: 230px;
        }
        .library-card:hover {
            transform: translateY(-4px);
            border-color: rgba(168,85,247,0.4);
            box-shadow: var(--card-glow);
        }
        .library-card.locked {
            filter: blur(1.8px);
            opacity: 0.72;
            cursor: not-allowed;
        }
        .library-card-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .library-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: rgba(168,85,247,0.12);
            color: var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .library-card-icon svg { width: 24px; height: 24px; }
        .library-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: rgba(59,130,246,0.12);
            color: #60a5fa;
        }
        .library-badge.locked {
            background: rgba(239,68,68,0.12);
            color: #f87171;
        }
        .library-card h3 {
            color: var(--text-primary);
            font-size: 1.05rem;
            line-height: 1.4;
            margin: 0;
        }
        .library-card p {
            color: var(--text-secondary);
            font-size: 0.88rem;
            line-height: 1.5;
            margin: 0;
        }
        .library-meta-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .library-meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            color: var(--text-secondary);
            font-size: 0.76rem;
            font-weight: 600;
        }
        .library-card-footer {
            margin-top: auto;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-secondary);
            font-size: 0.8rem;
        }
        .library-card-action {
            color: var(--accent-primary);
            font-weight: 700;
            font-size: 0.86rem;
            white-space: nowrap;
        }
        .library-card.locked .library-card-action {
            color: #ef4444;
        }

        .breadcrumb { margin-bottom: 24px; font-size: 0.95rem; display: flex; align-items: center; gap: 8px; }
        .breadcrumb a { color: var(--accent-primary); text-decoration: none; font-weight: 500; }
        .breadcrumb a:hover { text-decoration: underline; }
        .breadcrumb span { color: var(--text-secondary); }

        /* Search Bar */
        .mat-search-wrap { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 24px; }
        .mat-search-inner { position: relative; flex: 1; max-width: 420px; display: flex; flex-direction: column; gap: 8px; }
        .mat-search-field { position: relative; width: 100%; }
        .mat-search-inner svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; color: var(--text-secondary); pointer-events: none; }
        .mat-search-input { width: 100%; padding: 10px 132px 10px 40px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 12px; color: var(--text-primary); font-size: 0.95rem; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .mat-search-input:focus { border-color: var(--accent-primary); box-shadow: var(--glow-shadow); }
        .mat-search-filter-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            min-width: 104px;
            height: 34px;
            padding: 0 10px 0 12px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.2s;
        }
        .mat-search-filter-toggle span {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .mat-search-filter-toggle:hover,
        .mat-search-filter-toggle.active {
            background: rgba(168,85,247,0.08);
            color: var(--accent-primary);
            border-color: rgba(168,85,247,0.2);
        }
        .mat-search-filter-toggle.active {
            transform: translateY(-50%);
        }
        .mat-search-filter-toggle svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            transition: transform 0.2s ease;
        }
        .mat-search-filter-toggle.active svg {
            transform: rotate(180deg);
        }
        .mat-search-filter-panel {
            display: none;
            padding: 12px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.22);
            z-index: 140;
        }
        .mat-search-filter-panel.active { display: block; }
        .mat-search-filter-title {
            color: var(--text-secondary);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 10px;
        }
        .mat-search-filter-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .mat-search-chip {
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-secondary);
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, border-color 0.2s, box-shadow 0.2s;
        }
        .mat-search-chip:hover,
        .mat-search-chip.active {
            background: rgba(168,85,247,0.12);
            color: var(--accent-primary);
            border-color: rgba(168,85,247,0.24);
            box-shadow: 0 0 0 2px rgba(168,85,247,0.08);
        }
        .mat-search-count { font-size: 0.85rem; color: var(--text-secondary); white-space: nowrap; }
        .no-mat-result { display: none; padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary); }
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
            .mat-search-wrap { flex-direction: column; align-items: stretch; }
            .mat-search-inner { max-width: none; }
            .mat-search-count { white-space: normal; }
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
            .mat-search-input { padding-right: 118px; }
            .mat-search-filter-toggle { min-width: 92px; }
        }</style>
    <link rel="stylesheet" href="responsive.css">
</head>
<body>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-container">
    <div class="header">
        <h1>Course Materials</h1>
        <p>Access syllabus, lecture videos, slides, and reference books.</p>
    </div>

    <?php if (!$active_course_id): ?>
        <?php if ($role === 'student' || $role === 'guest'): ?>
        <div class="view-tabs">
            <a href="?view=my" class="<?= $view_mode === 'my' ? 'active' : '' ?>">My Courses</a>
            <a href="?view=all" class="<?= $view_mode === 'all' ? 'active' : '' ?>">Public Materials</a>
        </div>
        <?php else: ?>
        <div class="view-tabs">
            <a href="?view=all" class="active">Public Materials</a>
        </div>
        <?php endif; ?>

        <?php if ($view_mode === 'my' && $role === 'guest'): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:48px;height:48px;color:#ef4444;margin-bottom:16px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <h3 style="color:var(--text-primary); margin-bottom:8px;">Private Access Locked</h3>
                <p style="color:var(--text-secondary);">Guest accounts cannot access private enrolled courses.</p>
            </div>
        <?php elseif (empty($courses)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                No courses found.
            </div>
        <?php else: ?>
            <div class="mat-search-wrap">
                <div class="mat-search-inner">
                    <div class="mat-search-field">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="matSearchInput" class="mat-search-input" placeholder="<?= ($role === 'guest' && $view_mode === 'all') ? 'Search public materials globally...' : 'Search courses or specific materials globally...' ?>" autocomplete="off">
                        <button type="button" id="matSearchFilterToggle" class="mat-search-filter-toggle" aria-label="Open material filters" aria-expanded="false">
                            <span id="matSearchFilterLabel">All Types</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                    </div>
                    <div id="matSearchFilterPanel" class="mat-search-filter-panel">
                        <div class="mat-search-filter-title">Content Type</div>
                        <div class="mat-search-filter-list">
                            <button type="button" class="mat-search-chip active" data-filter="all">All Types</button>
                            <button type="button" class="mat-search-chip" data-filter="audio">Audio</button>
                            <button type="button" class="mat-search-chip" data-filter="video">Video</button>
                            <button type="button" class="mat-search-chip" data-filter="pdf">PDF</button>
                            <button type="button" class="mat-search-chip" data-filter="book">BOOK</button>
                            <button type="button" class="mat-search-chip" data-filter="slides">SLIDES</button>
                        </div>
                    </div>
                    <div id="matSearchResults" style="display:none; position:absolute; top:100%; left:0; right:0; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:12px; margin-top:8px; z-index:100; box-shadow:var(--glow-shadow); max-height:400px; overflow-y:auto;"></div>
                </div>
                <span class="mat-search-count" id="matSearchCount"></span>
            </div>
            <?php if ($view_mode === 'all'): ?>
                <?php if (empty($public_library_materials)): ?>
                    <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                        No public materials found.
                    </div>
                <?php else: ?>
                    <div class="library-grid" id="libraryGrid">
                        <?php foreach ($public_library_materials as $m): 
                            $ext = strtoupper(pathinfo($m['original_filename'], PATHINFO_EXTENSION));
                            $isVid = in_array(strtolower($ext), ['mp4', 'webm', 'ogg']);
                            $is_locked = ($role === 'guest' && !empty($m['is_private']));
                        ?>
                            <?php if ($is_locked): ?>
                                <div class="library-card locked">
                                    <div class="library-card-top">
                                        <div class="library-card-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        </div>
                                        <span class="library-badge locked">Locked</span>
                                    </div>
                                    <h3>Private Material</h3>
                                    <p>Only enrolled students can view this file.</p>
                                    <div class="library-meta-list">
                                        <span class="library-meta-pill"><?= htmlspecialchars($m['course_code']) ?></span>
                                        <span class="library-meta-pill"><?= htmlspecialchars($m['category']) ?></span>
                                    </div>
                                    <div class="library-card-footer">
                                        <span><?= date('M j, Y', strtotime($m['uploaded_at'])) ?></span>
                                        <span class="library-card-action">Locked</span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="view_material.php?id=<?= $m['id'] ?>" class="library-card">
                                    <div class="library-card-top">
                                        <div class="library-card-icon">
                                            <?php if ($isVid): ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                            <?php else: ?>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                                            <?php endif; ?>
                                        </div>
                                        <span class="library-badge"><?= $isVid ? 'Video' : $ext ?></span>
                                    </div>
                                    <h3><?= htmlspecialchars($m['title']) ?></h3>
                                    <p><?= htmlspecialchars($m['course_code']) ?> · <?= htmlspecialchars($m['course_title']) ?></p>
                                    <div class="library-meta-list">
                                        <span class="library-meta-pill"><?= htmlspecialchars($m['category']) ?></span>
                                        <span class="library-meta-pill"><?= htmlspecialchars($m['teacher_name']) ?></span>
                                    </div>
                                    <div class="library-card-footer">
                                        <span><?= formatBytes($m['file_size']) ?> • <?= date('M j, Y', strtotime($m['uploaded_at'])) ?></span>
                                        <span class="library-card-action"><?= $isVid ? 'Watch' : 'Open' ?></span>
                                    </div>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="no-mat-result" id="noMatResult">No materials found matching your search.</div>
            <?php else: ?>
                <div class="course-grid" id="courseGrid">
                    <?php foreach ($courses as $c): ?>
                    <a href="?view=<?= $view_mode ?>&course_id=<?= $c['id'] ?>" class="course-card" data-code="<?= strtolower(htmlspecialchars($c['code'])) ?>" data-title="<?= strtolower(htmlspecialchars($c['title'])) ?>">
                        <h3><?= htmlspecialchars($c['code']) ?></h3>
                        <p><?= htmlspecialchars($c['title']) ?></p>
                        <div class="t-badge"><?= htmlspecialchars($c['teacher_name']) ?></div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <div class="no-mat-result" id="noMatResult">No courses found matching your search.</div>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($active_course_id && !$active_cat): ?>
        <div class="breadcrumb">
            <a href="?view=<?= $view_mode ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;vertical-align:-2px;"><polyline points="15 18 9 12 15 6"/></svg> Back to Courses</a>
            <span>/</span>
            <span style="color: var(--text-primary); font-weight: 600;"><?= htmlspecialchars($active_course_data['code']) ?></span>
        </div>

        <h2 style="color: var(--text-primary); margin-bottom: 24px; font-size: 1.5rem;"><?= htmlspecialchars($active_course_data['title']) ?></h2>

        <div class="folder-grid">
            <?php foreach ($categories as $name => $icon): 
                $cnt = $counts[$name] ?? 0;
            ?>
            <a href="?view=<?= $view_mode ?>&course_id=<?= $active_course_id ?>&cat=<?= urlencode($name) ?>" class="folder-card">
                <?= $icon ?>
                <h3><?= htmlspecialchars($name) ?></h3>
                <span class="count"><?= $cnt ?> <?= $cnt == 1 ? 'file' : 'files' ?></span>
            </a>
            <?php endforeach; ?>
        </div>

    <?php elseif ($active_course_id && $active_cat): ?>
        <div class="breadcrumb">
            <a href="?view=<?= $view_mode ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;vertical-align:-2px;"><polyline points="15 18 9 12 15 6"/></svg> Courses</a>
            <span>/</span>
            <a href="?view=<?= $view_mode ?>&course_id=<?= $active_course_id ?>"><?= htmlspecialchars($active_course_data['code']) ?></a>
            <span>/</span>
            <span style="color: var(--text-primary); font-weight: 600;"><?= htmlspecialchars($active_cat) ?></span>
        </div>

        <div style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
            <div style="width:56px; height:56px; border-radius:14px; background:rgba(168,85,247,0.1); color:var(--accent-primary); display:flex; align-items:center; justify-content:center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;"><?= preg_replace('/<svg[^>]*>|<\/svg>/', '', $categories[$active_cat]) ?></svg>
            </div>
            <div>
                <h2 style="color: var(--text-primary); font-size: 1.8rem; margin-bottom:4px;"><?= htmlspecialchars($active_cat) ?></h2>
                <p style="color: var(--text-secondary);"><?= htmlspecialchars($active_course_data['code']) ?> - <?= htmlspecialchars($active_course_data['title']) ?></p>
            </div>
        </div>

        <?php if (empty($materials)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                No files have been uploaded to this category yet.
            </div>
        <?php else: ?>
            <div class="file-list">
                <?php foreach ($materials as $m): 
                    $ext = strtoupper(pathinfo($m['original_filename'], PATHINFO_EXTENSION));
                    $isVid = in_array(strtolower($ext), ['mp4', 'webm', 'ogg']);
                    $is_locked = ($role === 'guest' && $m['is_private']);
                ?>
                <?php if ($is_locked): ?>
                <div class="file-item" style="filter: blur(2px); cursor: not-allowed; opacity: 0.7;">
                    <div class="file-info">
                        <div class="file-icon" style="background: rgba(239,68,68,0.1); color: #ef4444;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </div>
                        <div class="file-details">
                            <h4>Private Material</h4>
                            <p>Only enrolled students can view this file.</p>
                        </div>
                    </div>
                    <div class="file-action" style="color: #ef4444;">Locked</div>
                </div>
                <?php else: ?>
                <a href="view_material.php?id=<?= $m['id'] ?>" class="file-item">
                    <div class="file-info">
                        <div class="file-icon">
                            <?php if ($isVid): ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
                            <?php endif; ?>
                        </div>
                        <div class="file-details">
                            <h4><?= htmlspecialchars($m['title']) ?></h4>
                            <p><?= $ext ?> • <?= formatBytes($m['file_size']) ?> • <?= date('M j, Y', strtotime($m['uploaded_at'])) ?></p>
                        </div>
                    </div>
                    <div class="file-action">
                        <?php if ($isVid): ?>
                            Watch Video <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 12 12 8"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <?php else: ?>
                            View File <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 12 12 8"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script src="theme.js"></script>
<script>
    // Global AJAX Search Logic for Materials and Courses
    const matSearch = document.getElementById('matSearchInput');
    const matResults = document.getElementById('matSearchResults');
    const matSearchFilterToggle = document.getElementById('matSearchFilterToggle');
    const matSearchFilterPanel = document.getElementById('matSearchFilterPanel');
    const matSearchFilterLabel = document.getElementById('matSearchFilterLabel');
    const matSearchFilterChips = document.querySelectorAll('.mat-search-chip');
    const viewMode = "<?= $view_mode ?>";
    let debounceTimeout;
    let selectedMaterialFilter = 'all';

    if (matSearch) {
        const materialFilterLabels = {
            all: 'All Types',
            audio: 'Audio',
            video: 'Video',
            pdf: 'PDF',
            book: 'BOOK',
            slides: 'SLIDES'
        };

        const runMaterialSearch = () => {
            clearTimeout(debounceTimeout);
            const q = matSearch.value.trim();
            if (!q) {
                matResults.style.display = 'none';
                return;
            }
            debounceTimeout = setTimeout(() => {
                fetch(`search_materials.php?q=${encodeURIComponent(q)}&view=${viewMode}&filter=${encodeURIComponent(selectedMaterialFilter)}`)
                    .then(res => res.json())
                    .then(data => {
                        matResults.innerHTML = '';
                        if (data.length === 0) {
                            matResults.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-secondary);">No results found.</div>';
                        } else {
                            data.forEach(item => {
                                const isLocked = item.is_locked;
                                const tag = isLocked ? 'div' : 'a';
                                const badgeColor = item.type === 'Course' ? 'var(--accent-primary)' : '#10b981';
                                const materialTypeLabel = item.content_type
                                    ? (item.content_type === 'other' ? 'Material' : item.content_type.toUpperCase())
                                    : item.type;
                                const badgeLabel = isLocked ? 'Locked' : (item.type === 'Course' ? 'Course' : materialTypeLabel);
                                
                                matResults.innerHTML += `
                                    <${tag} ${isLocked ? `style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border-color);color:inherit;text-decoration:none;filter:blur(1.5px);opacity:0.7;cursor:not-allowed;"` : `href="${item.url}" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border-color);color:inherit;text-decoration:none;transition:0.2s;" onmouseover="this.style.background='rgba(168,85,247,0.05)'" onmouseout="this.style.background='none'"`}>
                                        <div>
                                            <div style="font-weight:600;color:var(--text-primary);margin-bottom:4px;">${item.title}</div>
                                            <div style="font-size:0.85rem;color:var(--text-secondary);">${item.meta}</div>
                                        </div>
                                        <div style="font-size:0.75rem;padding:4px 8px;border-radius:12px;background:rgba(${item.type === 'Course' ? '168,85,247' : '16,185,129'},0.1);color:${badgeColor};font-weight:600;">
                                            ${badgeLabel}
                                        </div>
                                    </${tag}>
                                `;
                            });
                        }
                        matResults.style.display = 'block';
                    });
            }, 300);
        };

        matSearch.addEventListener('input', runMaterialSearch);

        if (matSearchFilterToggle && matSearchFilterPanel) {
            matSearchFilterToggle.addEventListener('click', e => {
                e.stopPropagation();
                const isOpen = matSearchFilterPanel.classList.toggle('active');
                matSearchFilterToggle.classList.toggle('active', isOpen);
                matSearchFilterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        matSearchFilterChips.forEach(chip => {
            chip.addEventListener('click', e => {
                e.stopPropagation();
                selectedMaterialFilter = chip.dataset.filter;
                matSearchFilterLabel.textContent = materialFilterLabels[selectedMaterialFilter] || 'All Types';
                matSearchFilterChips.forEach(btn => btn.classList.remove('active'));
                chip.classList.add('active');
                matSearchFilterPanel?.classList.remove('active');
                matSearchFilterToggle?.classList.remove('active');
                matSearchFilterToggle?.setAttribute('aria-expanded', 'false');
                if (matSearch.value.trim()) {
                    runMaterialSearch();
                }
            });
        });
        
        document.addEventListener('click', e => {
            if (!matSearch.contains(e.target) && !matResults.contains(e.target) && !matSearchFilterPanel?.contains(e.target) && !matSearchFilterToggle?.contains(e.target)) {
                matResults.style.display = 'none';
                matSearchFilterPanel?.classList.remove('active');
                matSearchFilterToggle?.classList.remove('active');
                matSearchFilterToggle?.setAttribute('aria-expanded', 'false');
            }
        });
    }
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
