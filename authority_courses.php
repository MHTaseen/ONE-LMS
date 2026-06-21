<?php
// authority_courses.php - Full Course Management for Authority
session_start();
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'authority') {
    header('Location: authority_portal.php');
    exit();
}

$auth_db_id = 0;
$stmtA = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
$stmtA->execute([$_SESSION['user_id']]);
$auth_db_id = $stmtA->fetchColumn();

// Get active and viewed semester IDs
$activeSemId = isset($activeSemester['id']) ? intval($activeSemester['id']) : 0;
$viewSemId = isset($_GET['view_semester_id']) ? intval($_GET['view_semester_id']) : $activeSemId;
if (!$viewSemId) {
    $viewSemId = $activeSemId;
}

// ── Auto-migration: ensure teacher_id and lab_room_no columns exist in course_sections ──
// The original schema.sql did not include these columns; add them if missing.
try {
    $colExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'course_sections'
           AND COLUMN_NAME  = 'teacher_id'"
    )->fetchColumn();
    if (!$colExists) {
        $pdo->exec("ALTER TABLE course_sections ADD COLUMN teacher_id INT NULL DEFAULT NULL AFTER course_id");
    }
    $labRoomColExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'course_sections'
           AND COLUMN_NAME  = 'lab_room_no'"
    )->fetchColumn();
    if (!$labRoomColExists) {
        $pdo->exec("ALTER TABLE course_sections ADD COLUMN lab_room_no VARCHAR(20) NULL DEFAULT NULL");
    }
} catch (PDOException $e) {
    // Non-fatal: log and continue
    error_log('Migration warning: ' . $e->getMessage());
}

$error   = '';
$success = '';

// ── Time / Day presets (same as teacher_courses.php) ──────────────────────
$time_slots = [
    "08:00 AM - 09:20 AM","09:30 AM - 10:50 AM","11:00 AM - 12:20 PM",
    "12:30 PM - 01:50 PM","02:00 PM - 03:20 PM","03:30 PM - 04:50 PM","05:00 PM - 06:20 PM"
];
$lab_time_slots = [
    "08:00 AM - 11:00 AM","11:10 AM - 02:10 PM","02:20 PM - 05:20 PM","05:30 PM - 08:30 PM"
];
$days = ["Sunday","Monday","Tuesday","Wednesday","Thursday","Saturday"];
$categories = ['Syllabus','Video Lectures','Slides','Books','Previous Questions'];

// ════════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ════════════════════════════════════════════════════════════════════════════

// ── 1. CREATE COURSE ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='create_course') {
    $title       = trim($_POST['title']      ?? '');
    $code        = strtoupper(trim($_POST['code'] ?? ''));
    $credit      = floatval($_POST['credit']  ?? 0);
    $total_marks = intval($_POST['total_marks'] ?? 100);
    $theory_marks= intval($_POST['theory_marks'] ?? 60);
    $lab_marks   = intval($_POST['lab_marks']    ?? 40);
    $department  = trim($_POST['department']  ?? '');
    $teacher_id  = intval($_POST['teacher_id']?? 0);
    $course_fee  = floatval($_POST['course_fee'] ?? 5000.00);

    if (!$title||!$code||!$department) {
        $error = 'Title, Code, and Department are required.';
    } elseif ($theory_marks + $lab_marks !== 100) {
        $error = 'Theory + Lab marks must total 100.';
    } elseif ($teacher_id <= 0) {
        $error = 'Please select a teacher for this course.';
    } else {
        try {
            $s = $pdo->prepare("INSERT INTO courses (teacher_id,title,code,credit,total_marks,theory_marks,lab_marks,department,course_fee) VALUES (?,?,?,?,?,?,?,?,?)");
            $s->execute([$teacher_id,$title,$code,$credit,$total_marks,$theory_marks,$lab_marks,$department,$course_fee]);
            $success = "Course <strong>{$code} – {$title}</strong> created successfully.";
        } catch (PDOException $e) { $error = 'DB Error: '.$e->getMessage(); }
    }
}

// ── 2. DELETE COURSE ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='delete_course') {
    $cid = intval($_POST['course_id'] ?? 0);
    if ($cid > 0) {
        try {
            $c = $pdo->prepare("SELECT code, title FROM courses WHERE id = ?");
            $c->execute([$cid]);
            $cRow = $c->fetch(PDO::FETCH_ASSOC);
            if ($cRow) {
                // Delete physical material files first
                $mats = $pdo->prepare("SELECT file_path FROM course_materials WHERE course_id = ?");
                $mats->execute([$cid]);
                foreach ($mats->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                    if (file_exists($fp)) unlink($fp);
                }
                $pdo->prepare("DELETE FROM courses WHERE id = ?")->execute([$cid]);
                $success = "Course <strong>{$cRow['code']}</strong> and all its data have been removed.";
            }
        } catch (PDOException $e) { $error = 'DB Error: '.$e->getMessage(); }
    }
}

// ── 3. CREATE SECTION ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='create_section') {
    $course_id        = intval($_POST['course_id']       ?? 0);
    $section_no       = intval($_POST['section_no']      ?? 0);
    $room_no          = trim($_POST['room_no']           ?? '');
    $seats            = intval($_POST['seats']           ?? 30);
    $theory_day_1     = trim($_POST['theory_day_1']      ?? '');
    $theory_day_2     = trim($_POST['theory_day_2']      ?? '');
    $theory_time_slot = trim($_POST['theory_time_slot']  ?? '');
    $lab_day          = trim($_POST['lab_day']           ?? '');
    $lab_time_slot    = trim($_POST['lab_time_slot']     ?? '');
    $lab_room_no      = trim($_POST['lab_room_no']       ?? '');
    $sec_teacher_id   = intval($_POST['sec_teacher_id']  ?? 0);

    if (!$course_id||!$section_no||!$room_no||!$theory_day_1||!$theory_day_2||!$theory_time_slot||$sec_teacher_id<=0) {
        $error = 'Please fill all required section fields and select a teacher.';
    } elseif ($theory_day_1 === $theory_day_2) {
        $error = 'Theory Day 1 and Theory Day 2 must be different.';
    } elseif ($seats > 40) {
        $error = 'Maximum 40 seats per section.';
    } else {
        try {
            // Dup section number check
            $dup = $pdo->prepare("SELECT id FROM course_sections WHERE course_id=? AND section_no=? AND semester_id=?");
            $dup->execute([$course_id, $section_no, $activeSemId]);
            if ($dup->fetch()) {
                $error = "Section {$section_no} already exists for this course.";
            } else {
                // Room clash (theory)
                $rc = $pdo->prepare("SELECT c.code,cs.section_no FROM course_sections cs JOIN courses c ON cs.course_id=c.id WHERE cs.semester_id=? AND cs.room_no=? AND cs.theory_time_slot=? AND (cs.theory_day_1 IN(?,?) OR cs.theory_day_2 IN(?,?)) LIMIT 1");
                $rc->execute([$activeSemId,$room_no,$theory_time_slot,$theory_day_1,$theory_day_2,$theory_day_1,$theory_day_2]);
                $roomClash = $rc->fetch(PDO::FETCH_ASSOC);

                // Lab room clash
                $labRoomClash = null;
                if (!empty($lab_day) && !empty($lab_time_slot) && !empty($lab_room_no)) {
                    $lrc = $pdo->prepare("SELECT c.code,cs.section_no FROM course_sections cs JOIN courses c ON cs.course_id=c.id WHERE cs.semester_id=? AND cs.lab_room_no=? AND cs.lab_time_slot=? AND cs.lab_day=? LIMIT 1");
                    $lrc->execute([$activeSemId,$lab_room_no,$lab_time_slot,$lab_day]);
                    $labRoomClash = $lrc->fetch(PDO::FETCH_ASSOC);
                }

                if ($roomClash) {
                    $error = "🚫 Room <strong>{$room_no}</strong> already booked in this semester at <strong>{$theory_time_slot}</strong> by <strong>{$roomClash['code']}</strong> Sec {$roomClash['section_no']}.";
                } elseif ($labRoomClash) {
                    $error = "🚫 Lab Room <strong>{$lab_room_no}</strong> already booked in this semester at <strong>{$lab_time_slot}</strong> on <strong>{$lab_day}</strong> by <strong>{$labRoomClash['code']}</strong> Sec {$labRoomClash['section_no']}.";
                } else {
                    $s = $pdo->prepare("INSERT INTO course_sections (course_id,teacher_id,section_no,room_no,seats,theory_day_1,theory_day_2,theory_time_slot,lab_day,lab_time_slot,lab_room_no,semester_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $s->execute([$course_id,$sec_teacher_id,$section_no,$room_no,$seats,$theory_day_1,$theory_day_2,$theory_time_slot,$lab_day,$lab_time_slot,$lab_room_no,$activeSemId]);
                    $success = "Section {$section_no} created successfully!";
                }
            }
        } catch (PDOException $e) { $error = 'DB Error: '.$e->getMessage(); }
    }
}

// ── 4. DELETE SECTION ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='delete_section') {
    $secId = intval($_POST['section_id'] ?? 0);
    if ($secId > 0) {
        try {
            $pdo->prepare("DELETE FROM course_sections WHERE id=?")->execute([$secId]);
            $success = "Section deleted successfully.";
        } catch (PDOException $e) { $error = 'DB Error: '.$e->getMessage(); }
    }
}

// ── 5. ENROLL STUDENT ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='enroll_student') {
    $section_id = intval($_POST['section_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    if ($section_id && $student_id) {
        try {
            // Already enrolled in any section of this course in this semester?
            $chkCourse = $pdo->prepare("
                SELECT cs.section_no FROM enrollments e 
                JOIN course_sections cs ON e.section_id = cs.id 
                WHERE e.student_id = ? 
                  AND cs.course_id = (SELECT course_id FROM course_sections WHERE id = ?) 
                  AND e.semester_id = ?
                LIMIT 1
            ");
            $chkCourse->execute([$student_id, $section_id, $activeSemId]);
            $existingSecNo = $chkCourse->fetchColumn();

            if ($existingSecNo !== false) {
                $error = "Student is already enrolled in Section " . str_pad($existingSecNo, 2, '0', STR_PAD_LEFT) . " of this course in this semester.";
            } else {
                // Routine clash check (within the active semester only)
                $tgtStmt = $pdo->prepare("SELECT cs.theory_day_1,cs.theory_day_2,cs.theory_time_slot,cs.lab_day,cs.lab_time_slot,c.code,cs.section_no FROM course_sections cs JOIN courses c ON cs.course_id=c.id WHERE cs.id=?");
                $tgtStmt->execute([$section_id]);
                $tgt = $tgtStmt->fetch(PDO::FETCH_ASSOC);
                
                $enrStmt = $pdo->prepare("SELECT cs.theory_day_1,cs.theory_day_2,cs.theory_time_slot,cs.lab_day,cs.lab_time_slot,c.code,cs.section_no FROM enrollments e JOIN course_sections cs ON e.section_id=cs.id JOIN courses c ON cs.course_id=c.id WHERE e.student_id=? AND e.semester_id=?");
                $enrStmt->execute([$student_id, $activeSemId]);
                $enrolled = $enrStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $clash = '';
                if ($tgt) {
                    foreach ($enrolled as $ex) {
                        if ($tgt['theory_time_slot']===$ex['theory_time_slot']) {
                            $shared = array_intersect([$tgt['theory_day_1'],$tgt['theory_day_2']],[$ex['theory_day_1'],$ex['theory_day_2']]);
                            if ($shared) { $clash = "⚠️ Routine clash with {$ex['code']} Sec {$ex['section_no']} — same time on ".implode(' & ',$shared)."."; break; }
                        }
                        if (!empty($tgt['lab_day'])&&!empty($ex['lab_day'])&&$tgt['lab_day']===$ex['lab_day']&&$tgt['lab_time_slot']===$ex['lab_time_slot']) {
                            $clash = "⚠️ Lab clash with {$ex['code']} Sec {$ex['section_no']}."; break;
                        }
                    }
                }
                if ($clash) { $error = $clash; }
                else {
                    $pdo->prepare("INSERT INTO enrollments (student_id,section_id,semester_id) VALUES (?,?,?)")->execute([$student_id,$section_id,$activeSemId]);
                    $success = "Student enrolled successfully in the section.";
                }
            }
        } catch (PDOException $e) { $error = 'DB Error: '.$e->getMessage(); }
    }
}

// ── 6. REMOVE STUDENT ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='remove_student') {
    $section_id = intval($_POST['section_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);
    if ($section_id && $student_id) {
        $pdo->prepare("DELETE FROM enrollments WHERE student_id=? AND section_id=?")->execute([$student_id,$section_id]);
        $success = "Student removed from the section.";
    }
}

// ── 7. UPLOAD MATERIAL ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='upload_material') {
    $course_id = intval($_POST['course_id'] ?? 0);
    $category  = $_POST['category'] ?? '';
    $title     = trim($_POST['mat_title'] ?? '');
    $is_private = isset($_POST['is_private']) ? 1 : 0;
    if (!$course_id || !$title || !in_array($category,$categories)) {
        $error = 'Please fill all upload fields.';
    } elseif (!isset($_FILES['material_file']) || $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a valid file.';
    } else {
        $file = $_FILES['material_file'];
        $ext  = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        if (in_array($ext,['php','php3','php4','php5','phtml','exe','sh','bat'])) {
            $error = 'Invalid file type.';
        } else {
            $upload_dir = 'uploads/materials/';
            if (!is_dir($upload_dir)) mkdir($upload_dir,0777,true);
            $new_fn = uniqid('mat_').'_'.time().'.'.$ext;
            $dest   = $upload_dir.$new_fn;
            if (move_uploaded_file($file['tmp_name'],$dest)) {
                try {
                    $pdo->prepare("INSERT INTO course_materials (course_id,category,title,file_path,original_filename,file_type,file_size,is_private) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$course_id,$category,$title,$dest,$file['name'],$file['type'],$file['size'],$is_private]);
                    $success = "Material <strong>".htmlspecialchars($title)."</strong> uploaded successfully.";
                } catch (PDOException $e) { $error = 'DB Error: '.$e->getMessage(); }
            } else { $error = 'Upload failed. Check server permissions.'; }
        }
    }
}

// ── 8. DELETE MATERIAL ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='delete_material') {
    $mat_id = intval($_POST['material_id'] ?? 0);
    if ($mat_id > 0) {
        $m = $pdo->prepare("SELECT file_path FROM course_materials WHERE id=?");
        $m->execute([$mat_id]);
        $mat = $m->fetch(PDO::FETCH_ASSOC);
        if ($mat) {
            if (file_exists($mat['file_path'])) unlink($mat['file_path']);
            $pdo->prepare("DELETE FROM course_materials WHERE id=?")->execute([$mat_id]);
            $success = "Material deleted.";
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// DATA FETCH
// ════════════════════════════════════════════════════════════════════════════
// All courses
$allCourses = $pdo->query("SELECT c.*,u.full_name as teacher_name FROM courses c JOIN users u ON c.teacher_id=u.id ORDER BY c.code ASC")->fetchAll(PDO::FETCH_ASSOC);

// All teachers (for dropdowns)
$allTeachers = $pdo->query("SELECT id,full_name,user_id,department FROM users WHERE role='teacher' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// All students (for enrollment)
$allStudents = $pdo->query("SELECT id,full_name,user_id,department FROM users WHERE role='student' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Active tab
$activeTab = $_GET['tab'] ?? 'courses';
// Selected course / section for context
$selCourseId  = intval($_GET['course_id']  ?? 0);
$selSectionId = intval($_GET['section_id'] ?? 0);

// Sections for selected course
$selSections = [];
if ($selCourseId) {
    $ss = $pdo->prepare("SELECT cs.*,u.full_name as teacher_name,(SELECT COUNT(*) FROM enrollments e WHERE e.section_id=cs.id) as enrolled FROM course_sections cs LEFT JOIN users u ON cs.teacher_id=u.id WHERE cs.course_id=? AND cs.semester_id=? ORDER BY cs.section_no ASC");
    $ss->execute([$selCourseId, $viewSemId]);
    $selSections = $ss->fetchAll(PDO::FETCH_ASSOC);
}

// Students enrolled in selected section
$sectionStudents = [];
if ($selSectionId) {
    $se = $pdo->prepare("SELECT u.id,u.full_name,u.user_id,u.department FROM enrollments e JOIN users u ON e.student_id=u.id WHERE e.section_id=? ORDER BY u.full_name ASC");
    $se->execute([$selSectionId]);
    $sectionStudents = $se->fetchAll(PDO::FETCH_ASSOC);
}

// Materials for selected course
$selMaterials = [];
if ($selCourseId) {
    $sm = $pdo->prepare("SELECT * FROM course_materials WHERE course_id=? ORDER BY uploaded_at DESC");
    $sm->execute([$selCourseId]);
    $selMaterials = $sm->fetchAll(PDO::FETCH_ASSOC);
}

function formatBytes($b,$p=2){if(!$b)return'0 B';$base=log($b,1024);$s=['B','KB','MB','GB'];return round(pow(1024,$base-floor($base)),$p).' '.$s[floor($base)];}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Course Management – Authority | ONE LMS</title>
    <meta name="description" content="Authority course management: create courses, assign teachers, enroll students, manage content.">
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="responsive.css?v=3">
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }

        /* ── Sidebar ── */
        .admin-layout  { display: flex; min-height: 100vh; }
        .admin-sidebar { width: 260px; background: var(--bg-secondary); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; padding: 28px 20px; position: fixed; top: 0; bottom: 0; left: 0; z-index: 950; overflow-y: auto; }
        .sidebar-brand { font-family:'Space Grotesque',sans-serif; font-size:1.4rem; font-weight:700; background:linear-gradient(135deg,#a855f7,#ec4899); -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:28px; }
        .admin-profile { display:flex; align-items:center; gap:10px; padding:14px; background:rgba(255,255,255,.03); border:1px solid var(--border-color); border-radius:14px; margin-bottom:26px; }
        .admin-avatar  { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#a855f7,#ec4899); display:flex; justify-content:center; align-items:center; color:#fff; font-weight:700; }
        .admin-name    { font-weight:600; font-size:.9rem; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .admin-role    { font-size:.7rem; color:#ec4899; font-weight:700; text-transform:uppercase; }
        .sidebar-menu  { display:flex; flex-direction:column; gap:6px; flex-grow:1; }
        .menu-item     { display:flex; align-items:center; gap:11px; padding:11px 14px; border-radius:11px; color:var(--text-secondary); text-decoration:none; font-weight:500; font-size:.88rem; transition:all .2s; }
        .menu-item:hover, .menu-item.active { background:rgba(168,85,247,.12); color:var(--accent-primary); }
        .menu-item svg { width:18px; height:18px; flex-shrink:0; }
        .menu-divider  { height:1px; background:var(--border-color); margin:10px 0; }

        /* ── Main ── */
        .admin-main { margin-left:260px; flex:1; padding:40px; max-width:1100px; }

        /* ── Page header ── */
        .page-hdr h1  { font-family:'Space Grotesque',sans-serif; font-size:2rem; background:linear-gradient(135deg,#a855f7,#06b6d4); -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:4px; }
        .page-hdr p   { color:var(--text-secondary); font-size:.92rem; margin-bottom:28px; }

        /* ── Tabs ── */
        .tab-bar  { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:28px; }
        .tab-btn  { padding:9px 20px; border-radius:30px; border:1px solid var(--border-color); background:var(--bg-secondary); color:var(--text-secondary); font-weight:600; font-size:.86rem; cursor:pointer; text-decoration:none; transition:all .2s; white-space:nowrap; }
        .tab-btn:hover  { border-color:var(--accent-primary); color:var(--accent-primary); }
        .tab-btn.active { background:var(--gradient-accent); border-color:transparent; color:#fff; box-shadow:0 4px 14px rgba(168,85,247,.3); }

        /* ── Cards ── */
        .panel { display:none; }
        .panel.active { display:block; animation:fadeUp .3s ease-out; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);} }

        .card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:20px; padding:28px; box-shadow:var(--card-glow); margin-bottom:24px; }
        .card-title { font-family:'Space Grotesque',sans-serif; font-size:1.05rem; font-weight:700; margin-bottom:18px; color:var(--text-primary); display:flex; align-items:center; gap:8px; }
        .card-title span { font-size:1.15rem; }

        /* ── Form ── */
        .fg { margin-bottom:16px; }
        .fg label { display:block; font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-secondary); margin-bottom:6px; }
        .fg input, .fg select, .fg textarea { width:100%; padding:10px 14px; background:var(--input-bg); border:1px solid var(--border-color); border-radius:10px; color:var(--text-primary); font-size:.9rem; outline:none; transition:border-color .2s; }
        .fg input:focus, .fg select:focus { border-color:var(--accent-primary); }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .form-row-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }

        /* ── Buttons ── */
        .btn-primary { background:var(--gradient-accent); border:none; color:#fff; padding:11px 22px; border-radius:10px; font-weight:700; font-size:.9rem; cursor:pointer; transition:opacity .2s,transform .2s; }
        .btn-primary:hover { opacity:.88; transform:translateY(-1px); }
        .btn-danger  { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#ef4444; padding:7px 14px; border-radius:8px; font-weight:600; font-size:.8rem; cursor:pointer; transition:all .2s; }
        .btn-danger:hover { background:rgba(239,68,68,.2); }
        .btn-success { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.3); color:#10b981; padding:7px 14px; border-radius:8px; font-weight:600; font-size:.8rem; cursor:pointer; transition:all .2s; }
        .btn-success:hover { background:rgba(16,185,129,.2); }
        .btn-sm { padding:5px 11px; border-radius:7px; font-size:.76rem; }

        /* ── Context nav ── */
        .ctx-nav { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; align-items:center; }
        .ctx-nav select { padding:8px 12px; border-radius:10px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-primary); font-size:.88rem; outline:none; }
        .ctx-nav a { font-size:.82rem; color:var(--text-secondary); text-decoration:none; }
        .ctx-nav a:hover { color:var(--accent-primary); }

        /* ── Tables ── */
        .tbl-wrap { overflow-x:auto; border-radius:14px; }
        table { width:100%; border-collapse:collapse; min-width:500px; }
        th,td { padding:13px 16px; text-align:left; border-bottom:1px solid var(--border-color); font-size:.88rem; }
        th { font-size:.74rem; text-transform:uppercase; letter-spacing:.6px; color:var(--text-secondary); font-weight:700; background:rgba(168,85,247,.05); }
        tr:last-child td { border-bottom:none; }
        td { color:var(--text-primary); }

        /* ── Alerts ── */
        .alert { display:flex; align-items:center; gap:10px; padding:14px 18px; border-radius:14px; font-size:.88rem; font-weight:600; margin-bottom:22px; }
        .alert-error   { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#ef4444; }
        .alert-success { background:rgba(16,185,129,.1); border:1px solid rgba(16,185,129,.25); color:#10b981; }

        /* ── Badges ── */
        .badge { display:inline-block; padding:3px 9px; border-radius:6px; font-size:.74rem; font-weight:700; }
        .badge-dept { background:rgba(6,182,212,.12); color:var(--accent-secondary); }
        .badge-sec  { background:rgba(168,85,247,.12); color:var(--accent-primary); }

        /* ── Material rows ── */
        .mat-row { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--border-color); }
        .mat-row:last-child { border-bottom:none; }
        .mat-icon { width:40px; height:40px; border-radius:10px; background:rgba(168,85,247,.1); display:flex; align-items:center; justify-content:center; color:var(--accent-primary); flex-shrink:0; font-size:1.1rem; }
        .mat-info { flex:1; }
        .mat-name { font-weight:600; font-size:.9rem; color:var(--text-primary); }
        .mat-meta { font-size:.75rem; color:var(--text-secondary); margin-top:2px; }

        /* ── Empty state ── */
        .empty-state { text-align:center; padding:40px; color:var(--text-secondary); }
        .empty-state svg { width:48px; height:48px; opacity:.25; margin-bottom:12px; }

        /* ── Confirm modal ── */
        .conf-modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(6px); z-index:3000; display:none; justify-content:center; align-items:center; }
        .conf-modal-bg.open { display:flex; }
        .conf-modal { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:18px; padding:28px; max-width:380px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,.4); animation:fadeUp .25s ease-out; }
        .conf-modal h3 { font-family:'Space Grotesque',sans-serif; font-size:1.15rem; margin-bottom:10px; color:var(--text-primary); }
        .conf-modal p  { color:var(--text-secondary); font-size:.88rem; margin-bottom:22px; line-height:1.5; }
        .conf-modal .actions { display:flex; gap:10px; }
        .conf-cancel { flex:1; padding:10px; border-radius:9px; background:rgba(255,255,255,.05); border:1px solid var(--border-color); color:var(--text-secondary); font-weight:600; cursor:pointer; }
        .conf-confirm{ flex:1; padding:10px; border-radius:9px; background:linear-gradient(135deg,#ef4444,#dc2626); border:none; color:#fff; font-weight:700; cursor:pointer; }

        @media(max-width:900px){
            .admin-sidebar { display:none; }
            .admin-main { margin-left:0; padding:24px 16px; }
            .form-row,.form-row-3 { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="admin-layout">

    <!-- ── Sidebar ── -->
    <aside class="admin-sidebar">
        <div class="sidebar-brand">ONE LMS</div>
        <div class="admin-profile">
            <div class="admin-avatar">A</div>
            <div style="display:flex;flex-direction:column;overflow:hidden;">
                <span class="admin-name"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
                <span class="admin-role">Institution Admin</span>
            </div>
        </div>
        <nav class="sidebar-menu">
            <a href="authority_dashboard.php" class="menu-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
                Account Control
            </a>
            <a href="authority_courses.php" class="menu-item active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                Course Management
            </a>
            <div class="menu-divider"></div>
            <a href="logout.php" class="menu-item" style="color:var(--error-color);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </nav>
    </aside>

    <!-- ── Main ── -->
    <main class="admin-main">
        <div class="page-hdr" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom: 28px;">
            <div>
                <h1>Course Management</h1>
                <p>Create courses, assign teachers, enroll students, and manage course content — all from one place.</p>
            </div>
            <!-- Semester Filter Dropdown -->
            <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; padding: 6px 12px; display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 0.78rem; text-transform: uppercase; color: var(--text-secondary); font-weight: 700;">Semester:</span>
                <select id="globalSemSelect" style="border: none; background: transparent; color: var(--text-primary); font-weight: 700; outline: none; cursor: pointer; font-size: 0.9rem;" onchange="updateSemesterFilter(this.value)">
                    <?php
                    $allSemsForFilter = getAllSemesters($pdo);
                    foreach ($allSemsForFilter as $sem):
                    ?>
                        <option value="<?= $sem['id'] ?>" <?= $sem['id'] == $viewSemId ? 'selected' : '' ?> style="background: var(--bg-secondary); color: var(--text-primary);"><?= htmlspecialchars($sem['label']) ?> <?= $sem['is_active'] ? '(Active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
        <div class="alert alert-error">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= $error ?>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= $success ?>
        </div>
        <?php endif; ?>

        <!-- Tab Bar -->
        <div class="tab-bar">
            <a href="?tab=courses"  class="tab-btn <?= $activeTab==='courses'  ? 'active':'' ?>">📚 Courses</a>
            <a href="?tab=sections&course_id=<?= $selCourseId ?>" class="tab-btn <?= $activeTab==='sections' ? 'active':'' ?>">📋 Sections</a>
            <a href="?tab=enroll&course_id=<?= $selCourseId ?>&section_id=<?= $selSectionId ?>" class="tab-btn <?= $activeTab==='enroll'   ? 'active':'' ?>">👥 Enroll Students</a>
            <a href="?tab=content&course_id=<?= $selCourseId ?>" class="tab-btn <?= $activeTab==='content'  ? 'active':'' ?>">📁 Course Content</a>
        </div>

        <!-- ════════════════════════════════════════════════════════ -->
        <!-- TAB 1: COURSES                                          -->
        <!-- ════════════════════════════════════════════════════════ -->
        <div class="panel <?= $activeTab==='courses' ? 'active':'' ?>">

            <!-- Create Course Form -->
            <div class="card">
                <div class="card-title"><span>➕</span> Create New Course</div>
                <form method="POST" action="authority_courses.php?tab=courses">
                    <input type="hidden" name="action" value="create_course">
                    <div class="fg">
                        <label>Course Title</label>
                        <input type="text" name="title" placeholder="e.g. Introduction to Programming" required>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label>Course Code</label>
                            <input type="text" name="code" placeholder="e.g. CSE110" required>
                        </div>
                        <div class="fg">
                            <label>Credit Hours</label>
                            <input type="number" name="credit" step="0.5" value="3.0" min="0.5" max="6" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label>Department</label>
                            <select name="department" required>
                                <option value="" disabled selected>Select…</option>
                                <option value="ALL">ALL (University-wide)</option>
                                <option value="CSE">CSE</option><option value="CS">CS</option>
                                <option value="EEE">EEE</option><option value="BBA">BBA</option>
                                <option value="MNS">MNS</option>
                            </select>
                        </div>
                        <div class="fg">
                            <label>Assign Teacher</label>
                            <select name="teacher_id" required>
                                <option value="" disabled selected>Select Teacher…</option>
                                <?php foreach ($allTeachers as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?> (<?= htmlspecialchars($t['user_id']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label>Total Marks</label>
                            <input type="number" name="total_marks" value="100" required>
                        </div>
                        <div class="fg">
                            <label>Course Fee (TK)</label>
                            <input type="number" name="course_fee" value="5000" min="0" step="100" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg">
                            <label>Theory Marks</label>
                            <input type="number" name="theory_marks" id="theory_marks" value="60" required>
                        </div>
                        <div class="fg">
                            <label>Lab Marks (0 = no lab)</label>
                            <input type="number" name="lab_marks" id="lab_marks" value="40" required>
                        </div>
                    </div>
                    <div id="marksErr" style="color:#ef4444;font-size:.8rem;margin-bottom:10px;display:none;">Theory + Lab must equal 100.</div>
                    <button type="submit" class="btn-primary">📚 Publish Course</button>
                </form>
            </div>

            <!-- All Courses List -->
            <div class="card">
                <div class="card-title"><span>📚</span> All Courses (<?= count($allCourses) ?>)</div>
                <?php if (empty($allCourses)): ?>
                <div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20"/></svg><p>No courses yet.</p></div>
                <?php else: ?>
                <div class="tbl-wrap">
                <table>
                    <thead><tr><th>Code</th><th>Title</th><th>Dept</th><th>Credits</th><th>Course Fee</th><th>Teacher</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($allCourses as $c): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['code']) ?></strong></td>
                        <td><?= htmlspecialchars($c['title']) ?></td>
                        <td><span class="badge badge-dept"><?= $c['department'] ?></span></td>
                        <td><?= $c['credit'] ?></td>
                        <td style="color:var(--accent-primary);font-weight:700;"><?= number_format($c['course_fee'], 2) ?> TK</td>
                        <td style="color:var(--accent-secondary);"><?= htmlspecialchars($c['teacher_name']) ?></td>
                        <td>
                            <a href="?tab=sections&course_id=<?= $c['id'] ?>" class="btn-success btn-sm" style="text-decoration:none;display:inline-block;">Sections</a>
                            &nbsp;
                            <button class="btn-danger btn-sm" onclick="confirmDelete('course',<?= $c['id'] ?>,'<?= htmlspecialchars(addslashes($c['code'])) ?>')">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════ -->
        <!-- TAB 2: SECTIONS                                         -->
        <!-- ════════════════════════════════════════════════════════ -->
        <div class="panel <?= $activeTab==='sections' ? 'active':'' ?>">
            <!-- Course picker -->
            <div class="ctx-nav">
                <form method="GET" action="authority_courses.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="tab" value="sections">
                    <select name="course_id" onchange="this.form.submit()" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border-color);background:var(--input-bg);color:var(--text-primary);font-size:.88rem;outline:none;">
                        <option value="">— Select a Course —</option>
                        <?php foreach ($allCourses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id']==$selCourseId?'selected':'' ?>><?= htmlspecialchars($c['code'].' – '.$c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($selCourseId): ?>
            <!-- Create Section Form -->
            <div class="card">
                <div class="card-title"><span>➕</span> Create Section for <span style="color:var(--accent-primary);"><?php foreach($allCourses as $c) if($c['id']==$selCourseId) echo htmlspecialchars($c['code']); ?></span></div>
                <form method="POST" action="authority_courses.php?tab=sections&course_id=<?= $selCourseId ?>">
                    <input type="hidden" name="action" value="create_section">
                    <input type="hidden" name="course_id" value="<?= $selCourseId ?>">
                    <div class="form-row">
                        <div class="fg"><label>Section No.</label><input type="number" name="section_no" min="1" placeholder="e.g. 1" required></div>
                        <div class="fg"><label>Room No.</label><input type="text" name="room_no" placeholder="e.g. 08A07C" required></div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Seats (max 40)</label><input type="number" name="seats" max="40" value="30" required></div>
                        <div class="fg">
                            <label>Assign Teacher</label>
                            <select name="sec_teacher_id" required>
                                <option value="" disabled selected>Select Teacher…</option>
                                <?php foreach ($allTeachers as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="fg"><label>Theory Day 1</label><select name="theory_day_1" required><option value="" disabled selected>Day 1</option><?php foreach($days as $d): ?><option><?= $d ?></option><?php endforeach; ?></select></div>
                        <div class="fg"><label>Theory Day 2</label><select name="theory_day_2" required><option value="" disabled selected>Day 2</option><?php foreach($days as $d): ?><option><?= $d ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="fg"><label>Theory Time Slot</label><select name="theory_time_slot" required><option value="" disabled selected>Select time…</option><?php foreach($time_slots as $ts): ?><option><?= $ts ?></option><?php endforeach; ?></select></div>
                    <div class="form-row">
                        <div class="fg"><label>Lab Day (if applicable)</label><select name="lab_day"><option value="">No Lab</option><?php foreach($days as $d): ?><option><?= $d ?></option><?php endforeach; ?></select></div>
                        <div class="fg"><label>Lab Time Slot</label><select name="lab_time_slot"><option value="">No Lab</option><?php foreach($lab_time_slots as $ts): ?><option><?= $ts ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="fg"><label>Lab Room No. (if applicable)</label><input type="text" name="lab_room_no" placeholder="e.g. 08L01A"></div>
                    <button type="submit" class="btn-primary">📋 Create Section</button>
                </form>
            </div>

            <!-- Sections list -->
            <div class="card">
                <div class="card-title"><span>📋</span> Sections (<?= count($selSections) ?>)</div>
                <?php if (empty($selSections)): ?>
                <div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg><p>No sections yet for this course.</p></div>
                <?php else: ?>
                <div class="tbl-wrap"><table>
                    <thead><tr><th>Sec</th><th>Room</th><th>Teacher</th><th>Schedule</th><th>Enrolled/Seats</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($selSections as $sec): ?>
                    <tr>
                        <td><span class="badge badge-sec">§<?= str_pad($sec['section_no'],2,'0',STR_PAD_LEFT) ?></span></td>
                        <td><?= htmlspecialchars($sec['room_no']) ?></td>
                        <td style="color:var(--accent-secondary);"><?= htmlspecialchars($sec['teacher_name'] ?? '—') ?></td>
                        <td style="font-size:.8rem;"><?= htmlspecialchars($sec['theory_day_1'].'+'.$sec['theory_day_2'].' · '.$sec['theory_time_slot']) ?><?= $sec['lab_day'] ? '<br><span style="color:var(--accent-primary);">Lab: '.$sec['lab_day'].' · '.$sec['lab_time_slot'].(!empty($sec['lab_room_no']) ? ' · Rm '.$sec['lab_room_no'] : '').'</span>' : '' ?></td>
                        <td><?= $sec['enrolled'] ?>/<?= $sec['seats'] ?></td>
                        <td>
                            <a href="?tab=enroll&course_id=<?= $selCourseId ?>&section_id=<?= $sec['id'] ?>" style="text-decoration:none;" class="btn-success btn-sm">Enroll</a>
                            &nbsp;
                            <button class="btn-danger btn-sm" onclick="confirmDelete('section',<?= $sec['id'] ?>,'Sec <?= $sec['section_no'] ?>')">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding:60px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="56"><rect x="3" y="4" width="18" height="18" rx="2"/></svg><p>Please select a course above to manage its sections.</p></div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════════════ -->
        <!-- TAB 3: ENROLL STUDENTS                                  -->
        <!-- ════════════════════════════════════════════════════════ -->
        <div class="panel <?= $activeTab==='enroll' ? 'active':'' ?>">
            <!-- Course + Section picker -->
            <div class="ctx-nav" style="flex-wrap:wrap;gap:12px;">
                <form method="GET" action="authority_courses.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="tab" value="enroll">
                    <select name="course_id" onchange="this.form.submit()" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border-color);background:var(--input-bg);color:var(--text-primary);font-size:.88rem;outline:none;">
                        <option value="">— Select Course —</option>
                        <?php foreach ($allCourses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id']==$selCourseId?'selected':'' ?>><?= htmlspecialchars($c['code'].' – '.$c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selCourseId && $selSections): ?>
                    <select name="section_id" onchange="this.form.submit()" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border-color);background:var(--input-bg);color:var(--text-primary);font-size:.88rem;outline:none;">
                        <option value="">— Select Section —</option>
                        <?php foreach ($selSections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sec['id']==$selSectionId?'selected':'' ?>>Section <?= str_pad($sec['section_no'],2,'0',STR_PAD_LEFT) ?> (<?= $sec['theory_day_1'].' '.$sec['theory_time_slot'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($selSectionId): ?>
            <!-- Enroll a student -->
            <div class="card">
                <div class="card-title"><span>➕</span> Enroll Student into Section <?= $selSectionId ?></div>
                <form method="POST" action="authority_courses.php?tab=enroll&course_id=<?= $selCourseId ?>&section_id=<?= $selSectionId ?>" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <input type="hidden" name="action" value="enroll_student">
                    <input type="hidden" name="section_id" value="<?= $selSectionId ?>">
                    <div class="fg" style="flex:1;min-width:240px;margin:0;">
                        <label>Student</label>
                        <select name="student_id" required>
                            <option value="" disabled selected>Select a student…</option>
                            <?php
                            $enrolledIds = array_column($sectionStudents, 'id');
                            foreach ($allStudents as $stu):
                                $alreadyIn = in_array($stu['id'], $enrolledIds);
                            ?>
                            <option value="<?= $stu['id'] ?>" <?= $alreadyIn?'disabled':'' ?>><?= htmlspecialchars($stu['full_name']) ?> (<?= htmlspecialchars($stu['user_id']) ?>) <?= $alreadyIn?'✓ enrolled':'' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary" style="margin-bottom:0;">Enroll →</button>
                </form>
            </div>

            <!-- Enrolled students list -->
            <div class="card">
                <div class="card-title"><span>👥</span> Enrolled Students (<?= count($sectionStudents) ?>)</div>
                <?php if (empty($sectionStudents)): ?>
                <div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><p>No students enrolled yet.</p></div>
                <?php else: ?>
                <div class="tbl-wrap"><table>
                    <thead><tr><th>#</th><th>Name</th><th>ID</th><th>Dept</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php $i=1; foreach ($sectionStudents as $stu): ?>
                    <tr>
                        <td style="color:var(--text-secondary);"><?= $i++ ?></td>
                        <td><?= htmlspecialchars($stu['full_name']) ?></td>
                        <td style="font-family:monospace;color:var(--accent-secondary);"><?= htmlspecialchars($stu['user_id']) ?></td>
                        <td><span class="badge badge-dept"><?= $stu['department'] ?></span></td>
                        <td>
                            <form method="POST" action="authority_courses.php?tab=enroll&course_id=<?= $selCourseId ?>&section_id=<?= $selSectionId ?>" style="margin:0;">
                                <input type="hidden" name="action" value="remove_student">
                                <input type="hidden" name="section_id" value="<?= $selSectionId ?>">
                                <input type="hidden" name="student_id" value="<?= $stu['id'] ?>">
                                <button type="submit" class="btn-danger btn-sm" onclick="return confirm('Remove <?= htmlspecialchars(addslashes($stu['full_name'])) ?> from this section?')">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding:60px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="56"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><p>Select a course and section above to manage enrollments.</p></div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════════════════════════════════════ -->
        <!-- TAB 4: COURSE CONTENT                                   -->
        <!-- ════════════════════════════════════════════════════════ -->
        <div class="panel <?= $activeTab==='content' ? 'active':'' ?>">
            <!-- Course picker -->
            <div class="ctx-nav">
                <form method="GET" action="authority_courses.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="tab" value="content">
                    <select name="course_id" onchange="this.form.submit()" style="padding:8px 12px;border-radius:10px;border:1px solid var(--border-color);background:var(--input-bg);color:var(--text-primary);font-size:.88rem;outline:none;">
                        <option value="">— Select a Course —</option>
                        <?php foreach ($allCourses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id']==$selCourseId?'selected':'' ?>><?= htmlspecialchars($c['code'].' – '.$c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($selCourseId): ?>
            <!-- Upload form -->
            <div class="card">
                <div class="card-title"><span>⬆️</span> Upload Course Material</div>
                <form method="POST" action="authority_courses.php?tab=content&course_id=<?= $selCourseId ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_material">
                    <input type="hidden" name="course_id" value="<?= $selCourseId ?>">
                    <div class="form-row">
                        <div class="fg">
                            <label>Category</label>
                            <select name="category" required>
                                <option value="" disabled selected>Select category…</option>
                                <?php foreach ($categories as $cat): ?><option><?= $cat ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fg">
                            <label>Title</label>
                            <input type="text" name="mat_title" placeholder="e.g. Week 3 Slides" required>
                        </div>
                    </div>
                    <div class="fg">
                        <label>File</label>
                        <input type="file" name="material_file" required>
                    </div>
                    <label style="display:flex;align-items:center;gap:8px;font-size:.84rem;color:var(--text-secondary);margin-bottom:14px;cursor:pointer;">
                        <input type="checkbox" name="is_private" value="1"> Mark as private (only enrolled students can see)
                    </label>
                    <button type="submit" class="btn-primary">📁 Upload Material</button>
                </form>
            </div>

            <!-- Materials list -->
            <div class="card">
                <div class="card-title"><span>📁</span> Uploaded Materials (<?= count($selMaterials) ?>)</div>
                <?php if (empty($selMaterials)): ?>
                <div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><p>No materials uploaded yet.</p></div>
                <?php else: ?>
                <?php $catIcons=['Syllabus'=>'📄','Video Lectures'=>'🎬','Slides'=>'🖼️','Books'=>'📚','Previous Questions'=>'❓'];
                // Group by category
                $grouped=[];
                foreach($selMaterials as $m) $grouped[$m['category']][]=$m;
                foreach ($grouped as $cat => $mats): ?>
                <div style="margin-bottom:20px;">
                    <div style="font-weight:700;font-size:.8rem;text-transform:uppercase;letter-spacing:.8px;color:var(--text-secondary);margin-bottom:10px;"><?= $catIcons[$cat]??'📄' ?> <?= htmlspecialchars($cat) ?> (<?= count($mats) ?>)</div>
                    <?php foreach ($mats as $m): ?>
                    <div class="mat-row">
                        <div class="mat-icon"><?= $catIcons[$cat]??'📄' ?></div>
                        <div class="mat-info">
                            <div class="mat-name"><?= htmlspecialchars($m['title']) ?></div>
                            <div class="mat-meta"><?= htmlspecialchars($m['original_filename']) ?> · <?= formatBytes($m['file_size']) ?> · <?= $m['is_private']?'🔒 Private':'🌐 Public' ?> · <?= date('M j, Y', strtotime($m['uploaded_at'])) ?></div>
                        </div>
                        <form method="POST" action="authority_courses.php?tab=content&course_id=<?= $selCourseId ?>" style="margin:0;">
                            <input type="hidden" name="action" value="delete_material">
                            <input type="hidden" name="material_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn-danger btn-sm" onclick="return confirm('Delete this material? This cannot be undone.')">Delete</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="empty-state" style="padding:60px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="56"><path d="M14 2H6a2 2 0 0 0-2 2v16"/><polyline points="14 2 14 8 20 8"/></svg><p>Select a course to manage its content.</p></div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ── Confirm Delete Modal ── -->
<div class="conf-modal-bg" id="delModal">
    <div class="conf-modal">
        <h3>⚠️ Confirm Deletion</h3>
        <p id="delMsg">Are you sure you want to delete this item? This action cannot be undone.</p>
        <div class="actions">
            <button class="conf-cancel" onclick="closeModal()">Cancel</button>
            <form method="POST" id="delForm" style="flex:1;margin:0;">
                <input type="hidden" name="action" id="delAction">
                <input type="hidden" name="course_id"  id="delCourseId">
                <input type="hidden" name="section_id" id="delSectionId">
                <button type="submit" class="conf-confirm" style="width:100%;">Yes, Delete</button>
            </form>
        </div>
    </div>
</div>

<script>
// ── Marks validation ────────────────────────────────────────────────────────
(function(){
    const th = document.getElementById('theory_marks');
    const lb = document.getElementById('lab_marks');
    const er = document.getElementById('marksErr');
    if(!th||!lb) return;
    function check(){
        const s=parseInt(th.value||0)+parseInt(lb.value||0);
        er.style.display=s!==100?'block':'none';
    }
    th.addEventListener('input',check);
    lb.addEventListener('input',check);
})();

// ── Delete confirmation modal ────────────────────────────────────────────────
function confirmDelete(type, id, label) {
    const modal   = document.getElementById('delModal');
    const msg     = document.getElementById('delMsg');
    const form    = document.getElementById('delForm');
    const action  = document.getElementById('delAction');
    const cid     = document.getElementById('delCourseId');
    const sid     = document.getElementById('delSectionId');

    if (type === 'course') {
        action.value  = 'delete_course';
        cid.value     = id;
        sid.value     = '';
        form.action   = 'authority_courses.php?tab=courses';
        msg.innerHTML = `Delete course <strong>${label}</strong>? All its sections, enrollments, quizzes, assignments, and materials will be permanently removed.`;
    } else {
        action.value  = 'delete_section';
        cid.value     = '';
        sid.value     = id;
        form.action   = 'authority_courses.php?tab=sections&course_id=<?= $selCourseId ?>';
        msg.innerHTML = `Delete <strong>${label}</strong>? All students enrolled in this section will be unenrolled.`;
    }
    modal.classList.add('open');
}

function closeModal() {
    document.getElementById('delModal').classList.remove('open');
}
document.getElementById('delModal').addEventListener('click', function(e){
    if(e.target===this) closeModal();
});
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeModal(); });

function updateSemesterFilter(id) {
    const url = new URL(window.location.href);
    url.searchParams.set('view_semester_id', id);
    url.searchParams.delete('section_id'); // Clear section ID since it is specific to old semester
    window.location.href = url.toString();
}
</script>
</body>
</html>
