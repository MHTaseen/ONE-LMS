<?php
// view_material.php - In-browser file viewer and downloader
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
require_once 'config.php';

$mat_id = intval($_GET['id'] ?? 0);
if (!$mat_id) {
    die("Invalid material ID.");
}

$stmt = $pdo->prepare("
    SELECT cm.*, c.title as course_title, c.code as course_code
    FROM course_materials cm
    JOIN courses c ON cm.course_id = c.id
    WHERE cm.id = ?
");
$stmt->execute([$mat_id]);
$material = $stmt->fetch();

if (!$material) {
    die("Material not found.");
}

$file_path = $material['file_path'];
if (!file_exists($file_path)) {
    die("File is missing from the server.");
}

$ext = strtolower(pathinfo($material['original_filename'], PATHINFO_EXTENSION));
$is_video = in_array($ext, ['mp4', 'webm', 'ogg']);
$is_pdf = ($ext === 'pdf');
$is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
$is_docx = ($ext === 'docx');

// Handle explicit download request
if (isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $material['file_type']);
    header('Content-Disposition: attachment; filename="' . $material['original_filename'] . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($material['title']) ?> - BRACU Thesis</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { margin: 0; background: #0a0a0f; color: #fff; font-family: 'Inter', sans-serif; height: 100vh; display: flex; flex-direction: column; }
        
        .viewer-header {
            height: 64px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            flex-shrink: 0;
        }

        .header-left { display: flex; align-items: center; gap: 16px; }
        .back-btn { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; background: rgba(255,255,255,0.05); color: var(--text-secondary); text-decoration: none; transition: 0.2s; }
        .back-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .back-btn svg { width: 20px; height: 20px; }
        
        .mat-info { display: flex; flex-direction: column; }
        .mat-title { font-weight: 600; font-size: 1rem; color: #fff; }
        .mat-course { font-size: 0.8rem; color: var(--text-secondary); }

        .header-right { display: flex; align-items: center; gap: 12px; }
        .download-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; border-radius: 8px;
            background: var(--gradient-accent); color: #fff;
            text-decoration: none; font-weight: 600; font-size: 0.9rem;
            transition: opacity 0.2s;
        }
        .download-btn:hover { opacity: 0.9; }

        .viewer-container {
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            overflow: hidden;
            position: relative;
        }

        video { width: 100%; height: 100%; max-height: calc(100vh - 64px); background: #000; outline: none; }
        iframe { width: 100%; height: 100%; border: none; background: #fff; }
        img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .docx-preview {
            background: #fff;
            color: #000;
            width: 100%;
            max-width: 850px;
            height: 100%;
            overflow-y: auto;
            padding: 40px 60px;
            box-sizing: border-box;
            box-shadow: 0 0 40px rgba(0,0,0,0.8);
            font-family: 'Times New Roman', serif;
            line-height: 1.6;
        }
        .docx-preview h1, .docx-preview h2, .docx-preview h3, .docx-preview h4 { margin-top: 24px; margin-bottom: 12px; }
        .docx-preview p { margin-bottom: 16px; }
        .docx-preview table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        .docx-preview table, .docx-preview th, .docx-preview td { border: 1px solid #ccc; padding: 8px; }

        .unsupported {
            text-align: center;
            padding: 40px;
        }
        .unsupported svg { width: 64px; height: 64px; color: var(--text-secondary); margin-bottom: 16px; }
        .unsupported h2 { font-size: 1.5rem; margin-bottom: 8px; }
        .unsupported p { color: var(--text-secondary); margin-bottom: 24px; }
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

<div class="viewer-header">
    <div class="header-left">
        <!-- Go back to previous page in history -->
        <a href="javascript:history.back()" class="back-btn" title="Go Back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </a>
        <div class="mat-info">
            <span class="mat-title"><?= htmlspecialchars($material['title']) ?></span>
            <span class="mat-course"><?= htmlspecialchars($material['course_code']) ?> • <?= htmlspecialchars($material['category']) ?></span>
        </div>
    </div>
    <div class="header-right">
        <a href="?id=<?= $mat_id ?>&download=1" class="download-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download File
        </a>
    </div>
</div>

<div class="viewer-container">
    <?php if ($is_video): ?>
        <video controls autoplay>
            <source src="<?= htmlspecialchars($file_path) ?>" type="<?= htmlspecialchars($material['file_type']) ?>">
            Your browser does not support the video tag.
        </video>
    <?php elseif ($is_pdf): ?>
        <iframe src="<?= htmlspecialchars($file_path) ?>" title="<?= htmlspecialchars($material['title']) ?>"></iframe>
    <?php elseif ($is_image): ?>
        <img src="<?= htmlspecialchars($file_path) ?>" alt="<?= htmlspecialchars($material['title']) ?>">
    <?php elseif ($is_docx): ?>
        <div class="docx-preview" id="docx-container">
            <div style="text-align:center; padding:60px; color:#666; font-family:'Inter',sans-serif;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:40px;height:40px;margin-bottom:12px;animation:spin 1s linear infinite;"><circle cx="12" cy="12" r="10"/><path d="M12 2v4"/></svg>
                <div>Loading Document Preview...</div>
            </div>
        </div>
        <style>@keyframes spin { 100% { transform: rotate(360deg); } }        /* -- Mobile Responsive Overrides -- */
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
        <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.4.2/mammoth.browser.min.js"></script>
        <script>
            fetch('<?= htmlspecialchars($file_path) ?>')
                .then(response => response.arrayBuffer())
                .then(buffer => mammoth.convertToHtml({arrayBuffer: buffer}))
                .then(result => {
                    document.getElementById('docx-container').innerHTML = result.value;
                })
                .catch(err => {
                    document.getElementById('docx-container').innerHTML = '<div style="color:#ef4444; padding:40px; text-align:center; font-family:\'Inter\',sans-serif;">Failed to load document preview. You can still download the file.</div>';
                    console.error(err);
                });
        </script>
    <?php else: ?>
        <div class="unsupported">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            <h2>Preview Not Available</h2>
            <p>This file type (<?= strtoupper($ext) ?>) cannot be previewed natively in the browser.</p>
            <a href="?id=<?= $mat_id ?>&download=1" class="download-btn" style="padding: 12px 24px; font-size:1rem;">
                Download to View
            </a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
