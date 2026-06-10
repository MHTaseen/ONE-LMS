<?php
// teacher_materials.php - Teacher UI for uploading and managing course materials
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: login.php");
    exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';

$teacher_db_id = $_SESSION['user_pk'] ?? 0;
if (!$teacher_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $teacher_db_id = $row['id'] ?? 0;
}

// Categories
$categories = [
    'Syllabus',
    'Video Lectures',
    'Slides',
    'Books',
    'Previous Questions'
];

// Handle File Upload (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_material'])) {
    $course_id = intval($_POST['course_id']);
    $category  = $_POST['category'];
    $title     = trim($_POST['title']);
    
    // Validate teacher owns the course
    $stmt = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$course_id, $teacher_db_id]);
    if (!$stmt->fetch()) {
        $errorMsg = "Unauthorized action.";
    } elseif (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['material_file'];
        $original_filename = basename($file['name']);
        $file_size = $file['size'];
        $file_type = $file['type'];
        
        $upload_dir = 'uploads/materials/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $is_private = isset($_POST['is_private']) && $_POST['is_private'] === '1' ? 1 : 0;
        $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        // Simple security check (no php)
        if (in_array($ext, ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'sh', 'bat'])) {
            $errorMsg = "Invalid file type.";
        } else {
            $new_filename = uniqid('mat_') . '_' . time() . '.' . $ext;
            $destination = $upload_dir . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO course_materials (course_id, category, title, file_path, original_filename, file_type, file_size, is_private) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$course_id, $category, $title, $destination, $original_filename, $file_type, $file_size, $is_private]);
                    $successMsg = "Material uploaded successfully!";
                    
                    // Notify students
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT e.student_id 
                        FROM enrollments e 
                        JOIN course_sections cs ON e.section_id = cs.id 
                        WHERE cs.course_id = ?
                    ");
                    $stmt->execute([$course_id]);
                    $students = $stmt->fetchAll();
                    
                    // Fetch course code for msg
                    $cstmt = $pdo->prepare("SELECT code FROM courses WHERE id = ?");
                    $cstmt->execute([$course_id]);
                    $ccode = $cstmt->fetchColumn();
                    
                    $msg = "New material uploaded in {$ccode}: {$title} ({$category})";
                    $link = "student_materials.php?view=my&course_id={$course_id}&cat=".urlencode($category);
                    foreach($students as $st) {
                        sendNotification($pdo, $st['student_id'], 'material', $msg, $link);
                    }
                } catch (PDOException $e) {
                    $errorMsg = "Database error: " . $e->getMessage();
                }
            } else {
                $errorMsg = "Failed to move uploaded file.";
            }
        }
    } else {
        $errorMsg = "Please select a valid file to upload.";
    }
}

// Handle File Deletion (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_material'])) {
    $mat_id = intval($_POST['material_id']);
    $stmt = $pdo->prepare("
        SELECT cm.file_path 
        FROM course_materials cm
        JOIN courses c ON cm.course_id = c.id
        WHERE cm.id = ? AND c.teacher_id = ?
    ");
    $stmt->execute([$mat_id, $teacher_db_id]);
    $mat = $stmt->fetch();
    
    if ($mat) {
        if (file_exists($mat['file_path'])) {
            unlink($mat['file_path']);
        }
        $pdo->prepare("DELETE FROM course_materials WHERE id = ?")->execute([$mat_id]);
        $successMsg = "Material deleted successfully.";
    }
}

// Fetch teacher's courses
$stmt = $pdo->prepare("
    SELECT id, title, code, department 
    FROM courses 
    WHERE teacher_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$teacher_db_id]);
$courses = $stmt->fetchAll();

// Determine active course
$active_course_id = intval($_GET['course_id'] ?? 0);
$active_course = null;
if ($active_course_id) {
    foreach ($courses as $c) {
        if ($c['id'] == $active_course_id) {
            $active_course = $c;
            break;
        }
    }
}

// Fetch existing materials for active course
$materials = [];
if ($active_course) {
    $stmt = $pdo->prepare("SELECT * FROM course_materials WHERE course_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$active_course_id]);
    $mats = $stmt->fetchAll();
    
    // Group by category
    foreach ($categories as $cat) {
        $materials[$cat] = [];
    }
    foreach ($mats as $m) {
        $materials[$m['category']][] = $m;
    }
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
    <title>Add Course Materials - BRACU Thesis</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: 64px; z-index: 900; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
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

        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .course-card {
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 16px; padding: 24px; text-decoration: none; display: block;
            transition: all 0.3s;
        }
        .course-card:hover { transform: translateY(-4px); border-color: rgba(168,85,247,0.4); box-shadow: var(--card-glow); }
        .course-card h3 { color: var(--text-primary); font-size: 1.25rem; margin-bottom: 8px; }
        .course-card p { color: var(--text-secondary); font-size: 0.9rem; }

        .upload-section {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .upload-section h2 { color: var(--text-primary); margin-bottom: 20px; font-size: 1.3rem; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        .form-group label { color: var(--text-secondary); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .form-control { padding: 12px 16px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-primary); font-size: 1rem; }
        .form-control:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 2px rgba(168,85,247,0.2); }
        .file-input { padding: 10px; }
        .btn-upload { padding: 12px 24px; border-radius: 10px; border: none; background: var(--gradient-accent); color: #fff; font-weight: 600; font-size: 1rem; cursor: pointer; transition: opacity 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-upload:hover { opacity: 0.9; }

        .cat-section { margin-bottom: 32px; }
        .cat-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .cat-header h3 { color: var(--text-primary); font-size: 1.2rem; }
        .cat-header .badge { background: rgba(168,85,247,0.15); color: var(--accent-primary); padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }

        table { width: 100%; border-collapse: collapse; text-align: left; background: var(--bg-secondary); border-radius: 12px; overflow: hidden; }
        th, td { padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
        th { background: rgba(255,255,255,0.02); font-weight: 600; color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        td { color: var(--text-primary); font-size: 0.95rem; }
        tr:last-child td { border-bottom: none; }
        
        .mat-title { font-weight: 600; display: block; margin-bottom: 4px; }
        .mat-meta { font-size: 0.8rem; color: var(--text-secondary); }
        
        .btn-danger { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); font-size: 0.85rem; cursor: pointer; transition: 0.2s; }
        .btn-danger:hover { background: #ef4444; color: #fff; }
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
    <link rel="stylesheet" href="responsive.css">
</head>
<body>

<nav class="top-navbar">
    <div class="navbar-left">
        <span class="navbar-brand">BRAC University Hub</span>
    </div>
    <div class="navbar-right">
        <a href="landing.php" class="nav-btn-back"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Back to Dashboard</a>
    </div>
</nav>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-container">
    <div class="header">
        <h1>Add Course Materials</h1>
        <p>Upload syllabus, lecture videos, slides, and reference books.</p>
    </div>

    <?php if (isset($successMsg)): ?>
        <div style="background: rgba(16,185,129,0.1); border: 1px solid #10b981; color: #10b981; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
            <?= htmlspecialchars($successMsg) ?>
        </div>
    <?php endif; ?>
    <?php if (isset($errorMsg)): ?>
        <div style="background: rgba(239,68,68,0.1); border: 1px solid #ef4444; color: #ef4444; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <?php if (!$active_course): ?>
        <?php if (empty($courses)): ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                You have not created any courses yet.
            </div>
        <?php else: ?>
            <div class="course-grid">
                <?php foreach ($courses as $c): ?>
                <a href="?course_id=<?= $c['id'] ?>" class="course-card">
                    <h3><?= htmlspecialchars($c['code']) ?></h3>
                    <p><?= htmlspecialchars($c['title']) ?></p>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div style="margin-bottom: 24px;">
            <a href="teacher_materials.php" style="color: var(--accent-primary); text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><polyline points="15 18 9 12 15 6"/></svg> Choose another course
            </a>
            <h2 style="margin-top: 12px; color: var(--text-primary);"><?= htmlspecialchars($active_course['code']) ?> - <?= htmlspecialchars($active_course['title']) ?></h2>
        </div>

        <!-- Upload Form -->
        <div class="upload-section">
            <h2>Upload Material</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="course_id" value="<?= $active_course['id'] ?>">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Lecture 1: Introduction">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" class="form-control" required>
                            <option value="">Select Category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>File (PDF, PPT, MP4, JPEG, PNG)</label>
                        <input type="file" name="material_file" class="form-control file-input" required>
                    </div>
                    <div class="form-group">
                        <label>Visibility</label>
                        <select name="is_private" class="form-control">
                            <option value="0">Public (All students & guests)</option>
                            <option value="1">Private (Enrolled students only)</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" name="upload_material" class="btn-upload" style="margin-top:20px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload File
                </button>
            </form>
        </div>

        <!-- Existing Materials -->
        <?php foreach ($categories as $cat): 
            $cat_mats = $materials[$cat];
            if (empty($cat_mats)) continue;
        ?>
        <div class="cat-section">
            <div class="cat-header">
                <h3><?= htmlspecialchars($cat) ?></h3>
                <span class="badge"><?= count($cat_mats) ?> Files</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Type & Size</th>
                        <th>Uploaded On</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cat_mats as $m): ?>
                    <tr>
                        <td>
                            <span class="mat-title">
                                <?= htmlspecialchars($m['title']) ?>
                                <?php if($m['is_private']): ?>
                                    <span style="font-size:0.7rem; background:rgba(239,68,68,0.1); color:#ef4444; padding:2px 6px; border-radius:4px; margin-left:6px;">Private</span>
                                <?php else: ?>
                                    <span style="font-size:0.7rem; background:rgba(16,185,129,0.1); color:#10b981; padding:2px 6px; border-radius:4px; margin-left:6px;">Public</span>
                                <?php endif; ?>
                            </span>
                            <span class="mat-meta"><?= htmlspecialchars($m['original_filename']) ?></span>
                        </td>
                        <td>
                            <span style="display:block;"><?= strtoupper(pathinfo($m['original_filename'], PATHINFO_EXTENSION)) ?></span>
                            <span class="mat-meta"><?= formatBytes($m['file_size']) ?></span>
                        </td>
                        <td><span style="font-size:0.85rem; color:var(--text-secondary);"><?= date('M j, Y', strtotime($m['uploaded_at'])) ?></span></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this material?');">
                                <input type="hidden" name="material_id" value="<?= $m['id'] ?>">
                                <button type="submit" name="delete_material" class="btn-danger">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <?php 
        $hasAny = false;
        foreach($materials as $c) if(count($c)>0) $hasAny = true;
        if(!$hasAny): 
        ?>
            <div style="padding: 40px; text-align: center; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; color: var(--text-secondary);">
                No materials uploaded for this course yet.
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script src="theme.js"></script>
</body>
</html>
