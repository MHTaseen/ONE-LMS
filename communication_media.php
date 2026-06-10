<?php
// communication_media.php - Central Communication Media for Courses
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] === 'guest') {
    header("Location: landing.php");
    exit();
}
require_once 'config.php';
require_once 'includes/notification_system.php';

$user_db_id = $_SESSION['user_pk'] ?? 0;
if (!$user_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $user_db_id = $row['id'] ?? 0;
}

$role = $_SESSION['role'];
$active_section_id = intval($_GET['section_id'] ?? 0);
$channel = $_GET['channel'] ?? 'announcement';
if (!in_array($channel, ['announcement', 'theory', 'lab', 'queries'])) {
    $channel = 'announcement';
}

$channelLabels = [
    'announcement' => 'Announcements',
    'theory'       => 'Theory Section',
    'lab'          => 'Lab Section',
    'queries'      => 'Queries',
];

function canPostInChannel(string $role, string $channel): bool {
    if ($channel === 'announcement') {
        return $role === 'teacher';
    }
    return in_array($role, ['student', 'teacher']);
}

function handleCommFileUpload(): array {
    if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        return [null, null, null, null];
    }

    $maxSize = 25 * 1024 * 1024;
    if ($_FILES['attachment']['size'] > $maxSize) {
        return [null, null, null, 'File exceeds 25 MB limit.'];
    }

    $original = basename($_FILES['attachment']['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'sh', 'bat', 'js', 'html', 'htm'];
    if (in_array($ext, $blocked, true)) {
        return [null, null, null, 'This file type is not allowed.'];
    }

    $uploadDir = __DIR__ . '/uploads/communications/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
        return [null, null, null, 'File upload failed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($dest) ?: 'application/octet-stream';

    return ['uploads/communications/' . $filename, $original, $mime, null];
}

// Fetch user's sections
$sections = [];
if ($role === 'student') {
    $stmt = $pdo->prepare("
        SELECT cs.id, c.code, c.title, u.full_name as teacher_name, cs.section_no
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE e.student_id = ?
    ");
    $stmt->execute([$user_db_id]);
    $sections = $stmt->fetchAll();
} elseif ($role === 'teacher') {
    $stmt = $pdo->prepare("
        SELECT cs.id, c.code, c.title, u.full_name as teacher_name, cs.section_no
        FROM course_sections cs
        JOIN courses c ON cs.course_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE c.teacher_id = ?
    ");
    $stmt->execute([$user_db_id]);
    $sections = $stmt->fetchAll();
}

$active_sec = null;
if ($active_section_id) {
    foreach ($sections as $s) {
        if ($s['id'] == $active_section_id) {
            $active_sec = $s;
            break;
        }
    }
}

$postError = null;

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_msg']) && $active_sec) {
    if (!canPostInChannel($role, $channel)) {
        $postError = 'You are not allowed to post in this channel.';
    } else {
        $msg = trim($_POST['message'] ?? '');
        [$filePath, $fileName, $fileMime, $uploadError] = handleCommFileUpload();

        if ($uploadError) {
            $postError = $uploadError;
        } elseif ($msg === '' && !$filePath) {
            $postError = 'Please enter a message or attach a file.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO course_communications
                    (section_id, sender_id, channel, message, file_path, file_name, file_mime)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $active_section_id,
                $user_db_id,
                $channel,
                $msg !== '' ? $msg : null,
                $filePath,
                $fileName,
                $fileMime,
            ]);

            $tagged_ids = [];
            if (isset($_POST['tagged_users'])) {
                $tagged_ids = array_unique(array_filter(array_map('intval', explode(',', $_POST['tagged_users']))));
            }

            $link = "communication_media.php?section_id={$active_section_id}&channel={$channel}";

            foreach ($tagged_ids as $tid) {
                $label = $channelLabels[$channel] ?? $channel;
                $notif_msg = $_SESSION['full_name'] . " mentioned you in " . $active_sec['code'] . " ({$label}).";
                sendNotification($pdo, $tid, $channel === 'queries' ? 'theory' : $channel, $notif_msg, $link);
            }

            if ($role === 'teacher' && $channel === 'announcement') {
                $stmtSt = $pdo->prepare("SELECT student_id FROM enrollments WHERE section_id = ?");
                $stmtSt->execute([$active_section_id]);
                $students = $stmtSt->fetchAll(PDO::FETCH_COLUMN);
                $preview = $msg !== '' ? $msg : ($fileName ? "attached {$fileName}" : 'shared an update');
                if (strlen($preview) > 80) {
                    $preview = substr($preview, 0, 77) . '...';
                }
                $notif_msg = $_SESSION['full_name'] . " posted in " . $active_sec['code'] . " Announcements: " . $preview;
                $annLink = "communication_media.php?section_id={$active_section_id}&channel=announcement";
                foreach ($students as $sid) {
                    sendNotification($pdo, intval($sid), 'announcement', $notif_msg, $annLink);
                }
            }
        }
    }

    if ($postError) {
        $_SESSION['comm_post_error'] = $postError;
    }
    header("Location: communication_media.php?section_id={$active_section_id}&channel={$channel}");
    exit();
}

if (isset($_SESSION['comm_post_error'])) {
    $postError = $_SESSION['comm_post_error'];
    unset($_SESSION['comm_post_error']);
}

$canPost = canPostInChannel($role, $channel);

// Fetch messages
$messages = [];
if ($active_sec) {
    $stmt = $pdo->prepare("
        SELECT cc.*, u.full_name, u.role 
        FROM course_communications cc
        JOIN users u ON cc.sender_id = u.id
        WHERE cc.section_id = ? AND cc.channel = ?
        ORDER BY cc.created_at ASC
    ");
    $stmt->execute([$active_section_id, $channel]);
    $messages = $stmt->fetchAll();
}

// Fetch member list for mentions
$members = [];
if ($active_sec) {
    // Teacher
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.role FROM courses c JOIN users u ON c.teacher_id = u.id JOIN course_sections cs ON cs.course_id = c.id WHERE cs.id = ?");
    $stmt->execute([$active_section_id]);
    $t = $stmt->fetch();
    if($t) $members[] = $t;

    // Students
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, u.role FROM enrollments e JOIN users u ON e.student_id = u.id WHERE e.section_id = ?");
    $stmt->execute([$active_section_id]);
    $st = $stmt->fetchAll();
    foreach($st as $s) $members[] = $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Central Communication Media</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center; padding: 10px 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-weight: 500; transition: 0.2s; }
        .nav-btn-back:hover { color: var(--accent-primary); }

        .layout { display: flex; height: 100vh; padding-top: 64px; box-sizing: border-box; }
        
        .sidebar { width: 320px; background: var(--bg-secondary); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; overflow-y: auto; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid var(--border-color); }
        .sidebar-header h2 { font-size: 1.2rem; color: var(--text-primary); }
        
        .sec-list { display: flex; flex-direction: column; }
        .sec-item { padding: 16px 24px; border-bottom: 1px solid var(--border-color); text-decoration: none; color: var(--text-secondary); transition: 0.2s; }
        .sec-item:hover, .sec-item.active { background: rgba(168,85,247,0.1); color: var(--accent-primary); border-left: 3px solid var(--accent-primary); }
        .sec-item h4 { font-size: 1.05rem; margin-bottom: 4px; color: var(--text-primary); }
        .sec-item p { font-size: 0.85rem; }

        .main-chat { flex-grow: 1; display: flex; flex-direction: column; background: var(--bg-primary); }
        
        .chat-header { padding: 20px 32px; border-bottom: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 16px; background: var(--bg-secondary); }
        .chat-header h2 { font-size: 1.4rem; color: var(--text-primary); }
        .tabs { display: flex; gap: 12px; }
        .tab-btn { padding: 8px 16px; border-radius: 8px; font-size: 0.95rem; font-weight: 600; text-decoration: none; color: var(--text-secondary); border: 1px solid transparent; transition: 0.2s; }
        .tab-btn.active { background: rgba(168,85,247,0.1); color: var(--accent-primary); border-color: rgba(168,85,247,0.3); }
        .tab-btn:hover:not(.active) { background: rgba(255,255,255,0.05); }

        .chat-messages { flex-grow: 1; padding: 32px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; }
        
        .msg { display: flex; flex-direction: column; max-width: 75%; }
        .msg.me { align-self: flex-end; align-items: flex-end; }
        .msg-sender { font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 4px; }
        .msg-sender .role { background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; margin-left: 6px; }
        .msg.me .msg-sender { display: none; }
        .msg-bubble { background: var(--bg-secondary); border: 1px solid var(--border-color); padding: 12px 16px; border-radius: 12px; border-top-left-radius: 2px; color: var(--text-primary); line-height: 1.5; position: relative; }
        .msg.me .msg-bubble { background: var(--accent-primary); border-color: var(--accent-primary); color: #fff; border-top-left-radius: 12px; border-top-right-radius: 2px; }
        .msg-time { font-size: 0.7rem; color: var(--text-secondary); margin-top: 4px; }
        .msg.me .msg-time { text-align: right; }

        .chat-input { padding: 24px 32px; border-top: 1px solid var(--border-color); background: var(--bg-secondary); position: relative; }
        .chat-input form { display: flex; gap: 12px; align-items: flex-end; }
        .chat-input textarea { flex-grow: 1; padding: 12px 16px; border-radius: 12px; border: 1px solid var(--border-color); background: var(--input-bg); color: #fff; font-family: inherit; font-size: 1rem; resize: none; outline: none; transition: 0.2s; }
        .chat-input textarea:focus { border-color: var(--accent-primary); }
        .chat-input button[type="submit"] { padding: 0 24px; border-radius: 12px; background: var(--gradient-accent); color: #fff; font-weight: 600; border: none; cursor: pointer; height: 48px; }
        .attach-btn { width: 48px; height: 48px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.04); color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; flex-shrink: 0; }
        .attach-btn:hover { border-color: var(--accent-primary); color: var(--accent-primary); }
        .attach-btn svg { width: 20px; height: 20px; }
        .file-preview { font-size: 0.82rem; color: var(--accent-primary); margin-top: 8px; display: none; }
        .file-preview.visible { display: block; }
        .msg-attachment { margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.12); }
        .msg.me .msg-attachment { border-top-color: rgba(255,255,255,0.25); }
        .msg-file-link { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; background: rgba(255,255,255,0.08); color: inherit; text-decoration: none; font-size: 0.88rem; font-weight: 600; transition: 0.2s; }
        .msg-file-link:hover { background: rgba(255,255,255,0.14); }
        .msg.me .msg-file-link { background: rgba(255,255,255,0.18); }
        .read-only-notice { padding: 20px 32px; border-top: 1px solid var(--border-color); background: rgba(245,158,11,0.06); color: var(--text-secondary); font-size: 0.92rem; text-align: center; }
        .read-only-notice strong { color: #f59e0b; }
        .post-error { margin: 0 32px 16px; padding: 12px 16px; border-radius: 10px; background: rgba(239,68,68,0.1); border: 1px solid #ef4444; color: #ef4444; font-size: 0.9rem; }

        /* Mention dropdown */
        .mention-dropdown { position: absolute; bottom: 85px; left: 32px; width: 300px; max-height: 200px; overflow-y: auto; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 -4px 20px rgba(0,0,0,0.5); display: none; z-index: 100; }
        .mention-item { padding: 10px 16px; cursor: pointer; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .mention-item:hover, .mention-item.selected { background: rgba(168,85,247,0.2); }
        .mention-item .name { color: #fff; font-size: 0.9rem; font-weight: 500; }
        .mention-item .role { color: var(--text-secondary); font-size: 0.75rem; text-transform: capitalize; }
        
        .tagged-pill { color: #38bdf8; font-weight: 600; cursor: pointer; }
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

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="layout">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>Your Enrolled Sections</h2>
        </div>
        <div class="sec-list">
            <?php foreach ($sections as $s): ?>
                <a href="?section_id=<?= $s['id'] ?>&channel=announcement" class="sec-item <?= ($s['id'] == $active_section_id) ? 'active' : '' ?>">
                    <h4><?= htmlspecialchars($s['code']) ?></h4>
                    <p><?= htmlspecialchars($s['title']) ?></p>
                    <p style="margin-top:6px; color:var(--text-secondary);"><span style="color:var(--accent-primary);">Sec: <?= htmlspecialchars($s['section_no']) ?></span> • <?= htmlspecialchars($s['teacher_name']) ?></p>
                </a>
            <?php endforeach; ?>
            <?php if (empty($sections)): ?>
                <div style="padding:24px; color:var(--text-secondary);">No sections found.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Chat -->
    <div class="main-chat">
        <?php if ($active_sec): ?>
            <div class="chat-header">
                <h2><?= htmlspecialchars($active_sec['code']) ?> - Sec <?= htmlspecialchars($active_sec['section_no']) ?></h2>
                <div class="tabs">
                    <a href="?section_id=<?= $active_section_id ?>&channel=announcement" class="tab-btn <?= $channel==='announcement'?'active':'' ?>">Announcements</a>
                    <a href="?section_id=<?= $active_section_id ?>&channel=theory" class="tab-btn <?= $channel==='theory'?'active':'' ?>">Theory Section</a>
                    <a href="?section_id=<?= $active_section_id ?>&channel=lab" class="tab-btn <?= $channel==='lab'?'active':'' ?>">Lab Section</a>
                    <a href="?section_id=<?= $active_section_id ?>&channel=queries" class="tab-btn <?= $channel==='queries'?'active':'' ?>">Queries</a>
                </div>
            </div>

            <?php if (!empty($postError)): ?>
                <div class="post-error"><?= htmlspecialchars($postError) ?></div>
            <?php endif; ?>

            <div class="chat-messages" id="msgContainer">
                <?php foreach ($messages as $m):
                    $isMe = ($m['sender_id'] == $user_db_id);
                    $parsedText = '';
                    if (!empty($m['message'])) {
                        $parsedText = preg_replace(
                            '/@\[(.*?)\]\(\d+\)/',
                            '<span class="tagged-pill">@$1</span>',
                            htmlspecialchars($m['message'])
                        );
                    }
                ?>
                    <div class="msg <?= $isMe ? 'me' : '' ?>">
                        <div class="msg-sender">
                            <?= htmlspecialchars($m['full_name']) ?>
                            <span class="role"><?= htmlspecialchars($m['role']) ?></span>
                        </div>
                        <div class="msg-bubble">
                            <?php if ($parsedText !== ''): ?>
                                <?= nl2br($parsedText) ?>
                            <?php endif; ?>
                            <?php if (!empty($m['file_path'])): ?>
                                <div class="msg-attachment">
                                    <a href="comm_file.php?id=<?= intval($m['id']) ?>" class="msg-file-link" target="_blank" rel="noopener">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                        <?= htmlspecialchars($m['file_name'] ?? 'Download attachment') ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="msg-time"><?= date('M j, g:i A', strtotime($m['created_at'])) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($messages)): ?>
                    <div style="text-align:center; color:var(--text-secondary); margin-top:40px;">No messages in this channel yet. Start the conversation!</div>
                <?php endif; ?>
            </div>

            <?php if ($canPost): ?>
            <div class="chat-input">
                <div class="mention-dropdown" id="mentionDropdown"></div>
                <form method="POST" id="chatForm" enctype="multipart/form-data">
                    <input type="hidden" name="tagged_users" id="taggedUsersInput">
                    <input type="file" name="attachment" id="fileInput" style="display:none;" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.rar">
                    <button type="button" class="attach-btn" id="attachBtn" title="Attach file" aria-label="Attach file">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    </button>
                    <div style="flex-grow:1;">
                        <textarea name="message" id="msgTextarea" rows="2" placeholder="Type a message... Use @ to tag someone"></textarea>
                        <div class="file-preview" id="filePreview"></div>
                    </div>
                    <button type="submit" name="send_msg">Send</button>
                </form>
            </div>
            <?php else: ?>
            <div class="read-only-notice">
                <strong>View only.</strong> Only your instructor can post announcements and upload files in this channel.
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="flex-grow:1; display:flex; align-items:center; justify-content:center; color:var(--text-secondary);">
                Select a section from the left to view communications.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Scroll to bottom
    const container = document.getElementById('msgContainer');
    if(container) container.scrollTop = container.scrollHeight;

    // Mention System Logic
    const members = <?= json_encode($members) ?>;
    const textarea = document.getElementById('msgTextarea');
    const dropdown = document.getElementById('mentionDropdown');
    const taggedInput = document.getElementById('taggedUsersInput');
    let taggedIds = new Set();
    let isMentioning = false;
    let mentionQuery = '';
    let mentionStartIndex = -1;
    let selectedIdx = 0;
    let filteredMembers = [];

    const fileInput = document.getElementById('fileInput');
    const attachBtn = document.getElementById('attachBtn');
    const filePreview = document.getElementById('filePreview');

    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                filePreview.textContent = 'Attached: ' + fileInput.files[0].name;
                filePreview.classList.add('visible');
            } else {
                filePreview.textContent = '';
                filePreview.classList.remove('visible');
            }
        });
    }

    if (textarea) {
        textarea.addEventListener('input', (e) => {
            const val = textarea.value;
            const cursor = textarea.selectionStart;

            // Check if we just typed '@' or are currently typing a name after '@'
            const textBeforeCursor = val.slice(0, cursor);
            const match = textBeforeCursor.match(/@([a-zA-Z0-9\s]*)$/);

            if (match) {
                isMentioning = true;
                mentionQuery = match[1].toLowerCase();
                mentionStartIndex = match.index;
                showDropdown();
            } else {
                closeDropdown();
            }
        });

        textarea.addEventListener('keydown', (e) => {
            if (!isMentioning) {
                // Enter to submit
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const hasText = textarea.value.trim() !== '';
                    const hasFile = fileInput && fileInput.files.length > 0;
                    if (hasText || hasFile) document.getElementById('chatForm').submit();
                }
                return;
            }
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIdx = (selectedIdx + 1) % filteredMembers.length;
                renderDropdown();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIdx = (selectedIdx - 1 + filteredMembers.length) % filteredMembers.length;
                renderDropdown();
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                if(filteredMembers.length > 0) {
                    selectMember(filteredMembers[selectedIdx]);
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
            }
        });
    }

    function showDropdown() {
        filteredMembers = members.filter(m => m.full_name.toLowerCase().includes(mentionQuery));
        if (filteredMembers.length === 0) {
            closeDropdown();
            return;
        }
        selectedIdx = 0;
        dropdown.style.display = 'block';
        renderDropdown();
    }

    function renderDropdown() {
        dropdown.innerHTML = '';
        filteredMembers.forEach((m, idx) => {
            const div = document.createElement('div');
            div.className = `mention-item ${idx === selectedIdx ? 'selected' : ''}`;
            div.innerHTML = `<span class="name">${m.full_name}</span><span class="role">${m.role}</span>`;
            div.onmousedown = (e) => {
                e.preventDefault(); // prevent losing focus on textarea
                selectMember(m);
            };
            dropdown.appendChild(div);
        });
    }

    function selectMember(m) {
        const val = textarea.value;
        const prefix = val.slice(0, mentionStartIndex);
        // Insert custom format @[Name](id)
        const insertion = `@[${m.full_name}](${m.id}) `;
        const suffix = val.slice(textarea.selectionStart);
        
        textarea.value = prefix + insertion + suffix;
        textarea.selectionStart = textarea.selectionEnd = prefix.length + insertion.length;
        textarea.focus();
        
        taggedIds.add(m.id);
        taggedInput.value = Array.from(taggedIds).join(',');
        
        closeDropdown();
    }

    function closeDropdown() {
        isMentioning = false;
        dropdown.style.display = 'none';
    }
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
