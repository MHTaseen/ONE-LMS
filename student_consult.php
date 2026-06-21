<?php
// student_consult.php - Student UI for messaging teachers
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

require_once 'config.php';

$student_id = $_SESSION['user_id'];
$full_name  = $_SESSION['full_name'];

// Get student's DB ID
$stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
$stmt->execute([$student_id]);
$student_db = $stmt->fetch();
if (!$student_db) die("Student not found.");
$student_db_id = $student_db['id'];

// Fetch all teachers this student is enrolled with
$stmt = $pdo->prepare("
    SELECT c.title as course_title, c.code as course_code, cs.section_no, cs.id as section_id,
           t.full_name as teacher_name, t.user_id as teacher_id_str, t.id as teacher_db_id
    FROM enrollments e
    JOIN course_sections cs ON e.section_id = cs.id
    JOIN courses c ON cs.course_id = c.id
    JOIN users t ON c.teacher_id = t.id
    WHERE e.student_id = ?
");
$stmt->execute([$student_db_id]);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if a specific chat is selected
$active_section_id = intval($_GET['section_id'] ?? 0);
$active_chat = null;

if ($active_section_id > 0) {
    foreach ($teachers as $t) {
        if ($t['section_id'] == $active_section_id) {
            $active_chat = $t;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Consult with Teacher - BRACU Thesis</title>
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
            max-width: 1400px;
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
        .header p {
            color: var(--text-secondary);
            font-size: 1.1rem;
            margin-bottom: 30px;
        }

        /* View: Teacher List */
        .teacher-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }
        .teacher-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
        }
        .teacher-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-glow);
            border-color: rgba(168, 85, 247, 0.4);
        }
        .t-avatar {
            width: 60px;
            height: 60px;
            background: var(--gradient-accent);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fff;
            font-size: 1.5rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .t-info h3 {
            color: var(--text-primary);
            font-size: 1.2rem;
            margin-bottom: 4px;
        }
        .t-badge {
            display: inline-block;
            background: rgba(168,85,247,0.15);
            color: var(--accent-primary);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .t-course {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.4;
        }

        /* View: Chat Interface */
        .chat-container {
            display: flex;
            flex-direction: column;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            height: 75vh;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .chat-header {
            display: flex;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.02);
        }
        .back-btn {
            color: var(--text-secondary);
            text-decoration: none;
            margin-right: 20px;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        .back-btn:hover { color: var(--accent-primary); }
        .chat-header .t-avatar { width: 48px; height: 48px; font-size: 1.2rem; margin-right: 16px; }
        .chat-header h2 { color: var(--text-primary); font-size: 1.2rem; margin: 0 0 4px 0; }
        .chat-header p { color: var(--text-secondary); font-size: 0.9rem; margin: 0; }
        
        .chat-messages {
            flex-grow: 1;
            padding: 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .message-row {
            display: flex;
            width: 100%;
        }
        .message-row.sent { justify-content: flex-end; }
        .message-row.received { justify-content: flex-start; }
        
        .bubble {
            max-width: 70%;
            padding: 14px 20px;
            border-radius: 20px;
            font-size: 0.95rem;
            line-height: 1.5;
            position: relative;
        }
        .sent .bubble {
            background: var(--gradient-accent);
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        .received .bubble {
            background: var(--input-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-bottom-left-radius: 4px;
        }
        .msg-time {
            font-size: 0.7rem;
            opacity: 0.7;
            margin-top: 6px;
            text-align: right;
            display: block;
        }
        .sent .msg-time { color: rgba(255,255,255,0.8); }
        .received .msg-time { color: var(--text-secondary); }

        /* Media Attachments inside Bubbles */
        .attach-media { max-width: 100%; border-radius: 12px; margin-top: 8px; }
        .attach-audio { width: 100%; margin-top: 8px; height: 40px; }
        .attach-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.1);
            padding: 8px 14px;
            border-radius: 8px;
            color: inherit;
            text-decoration: none;
            margin-top: 8px;
            font-size: 0.85rem;
        }

        .chat-input-area {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.02);
            display: flex;
            align-items: flex-end;
            gap: 16px;
        }
        .file-attach-btn {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .file-attach-btn:hover { color: var(--accent-primary); border-color: var(--accent-primary); }
        #fileInput { display: none; }
        
        .text-input-wrap {
            flex-grow: 1;
            position: relative;
        }
        #messageInput {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 14px 20px;
            color: var(--text-primary);
            font-family: inherit;
            resize: none;
            height: 50px;
            outline: none;
            transition: border-color 0.2s;
        }
        #messageInput:focus { border-color: var(--accent-primary); }
        
        .file-preview {
            position: absolute;
            bottom: 60px;
            left: 0;
            background: var(--bg-secondary);
            border: 1px solid var(--accent-primary);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            color: var(--text-primary);
            display: none;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .clear-file-btn { cursor: pointer; color: var(--error-color); }

        .send-btn {
            background: var(--gradient-accent);
            color: #fff;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            flex-shrink: 0;
        }
        .send-btn:hover { transform: scale(1.05); box-shadow: var(--glow-shadow); }

        /* Scrollbar styling for chat */
        .chat-messages::-webkit-scrollbar { width: 6px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
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
    <link rel="stylesheet" href="responsive.css?v=2">
</head>
<body>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-container">
    
    <?php if (!$active_chat): ?>
    <!-- ── TEACHER LIST VIEW ── -->
    <div class="header">
        <h1>Consult with Teacher</h1>
        <p>Select a teacher to start a conversation regarding your enrolled courses.</p>
    </div>

    <?php if (empty($teachers)): ?>
        <div style="text-align:center; padding: 60px; background:var(--bg-secondary); border-radius:20px; border:1px solid var(--border-color);">
            <p style="color:var(--text-secondary);">You are not enrolled in any courses yet.</p>
        </div>
    <?php else: ?>
        <div class="teacher-grid">
            <?php foreach ($teachers as $t): 
                $initials = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $t['teacher_name']), 0, 2));
            ?>
            <a href="?section_id=<?= $t['section_id'] ?>" class="teacher-card">
                <div class="t-avatar"><?= $initials ?></div>
                <div class="t-info">
                    <span class="t-badge">ID: <?= htmlspecialchars($t['teacher_id_str']) ?></span>
                    <h3><?= htmlspecialchars($t['teacher_name']) ?></h3>
                    <div class="t-course">
                        <strong><?= htmlspecialchars($t['course_code']) ?></strong> — <?= htmlspecialchars($t['course_title']) ?><br>
                        Section <?= $t['section_no'] ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <?php else: 
        // ── CHAT UI VIEW ──
        $initials = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $active_chat['teacher_name']), 0, 2));
    ?>
    
    <div class="chat-container">
        <!-- Chat Header -->
        <div class="chat-header">
            <a href="student_consult.php" class="back-btn" title="Back to teachers list">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:24px; height:24px;">
                    <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                </svg>
            </a>
            <div class="t-avatar"><?= $initials ?></div>
            <div>
                <h2><?= htmlspecialchars($active_chat['teacher_name']) ?> (<?= htmlspecialchars($active_chat['teacher_id_str']) ?>)</h2>
                <p><?= htmlspecialchars($active_chat['course_code']) ?> - Section <?= $active_chat['section_no'] ?></p>
            </div>
        </div>

        <!-- Messages Area -->
        <div class="chat-messages" id="chatBox">
            <!-- Messages injected via JS -->
            <div style="text-align:center; color:var(--text-secondary); margin-top:20px; font-size:0.9rem;" id="loadingIndicator">
                Loading messages...
            </div>
        </div>

        <!-- Input Area -->
        <div class="chat-input-area">
            <label class="file-attach-btn" title="Attach Media (Image, Audio, Video max 20MB)">
                <input type="file" id="fileInput" accept="image/*,audio/*,video/*">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px; height:22px;">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
            </label>
            
            <div class="text-input-wrap">
                <div class="file-preview" id="filePreview">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                    <span id="fileNameDisplay">filename.jpg</span>
                    <div class="clear-file-btn" id="clearFileBtn" title="Remove attachment">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </div>
                </div>
                <textarea id="messageInput" placeholder="Type your message here..."></textarea>
            </div>

            <button class="send-btn" id="sendBtn" title="Send Message">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px; height:20px; transform: translateX(-1px) translateY(1px);">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="theme.js"></script>
<?php if ($active_chat): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const chatBox = document.getElementById('chatBox');
    const msgInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const fileInput = document.getElementById('fileInput');
    const filePreview = document.getElementById('filePreview');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    const clearFileBtn = document.getElementById('clearFileBtn');

    const receiverId = <?= $active_chat['teacher_db_id'] ?>;
    const sectionId = <?= $active_chat['section_id'] ?>;
    const myId = <?= $student_db_id ?>;
    
    let lastMsgId = 0;
    let isSending = false;

    // Scroll to bottom
    function scrollToBottom() {
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    // Auto-resize textarea
    msgInput.addEventListener('input', function() {
        this.style.height = '50px';
        const newHeight = Math.min(this.scrollHeight, 150);
        this.style.height = newHeight + 'px';
    });

    // Handle file selection preview
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const file = this.files[0];
            if (file.size > 20 * 1024 * 1024) {
                alert("File is too large. Maximum size is 20MB.");
                this.value = '';
                return;
            }
            fileNameDisplay.textContent = file.name;
            filePreview.style.display = 'flex';
        } else {
            filePreview.style.display = 'none';
        }
    });

    clearFileBtn.addEventListener('click', () => {
        fileInput.value = '';
        filePreview.style.display = 'none';
    });

    // Render a single message bubble
    function renderMessage(msg) {
        const isSent = (msg.sender_id == myId);
        const row = document.createElement('div');
        row.className = `message-row ${isSent ? 'sent' : 'received'}`;
        
        let mediaHtml = '';
        if (msg.attach_path) {
            const fileUrl = `message_file.php?id=${msg.id}`;
            const mime = msg.attach_mime || '';
            
            if (mime.startsWith('image/')) {
                mediaHtml = `<img src="${fileUrl}" class="attach-media" alt="Attached Image">`;
            } else if (mime.startsWith('audio/')) {
                mediaHtml = `<audio src="${fileUrl}" controls class="attach-audio"></audio>`;
            } else if (mime.startsWith('video/')) {
                mediaHtml = `<video src="${fileUrl}" controls class="attach-media"></video>`;
            } else {
                // Fallback for other file types
                mediaHtml = `
                    <a href="${fileUrl}" target="_blank" class="attach-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
                        ${msg.attach_name || 'Download Attachment'}
                    </a>`;
            }
        }

        const textHtml = msg.message_text ? `<div>${escapeHtml(msg.message_text)}</div>` : '';
        const timeStr = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

        row.innerHTML = `
            <div class="bubble">
                ${textHtml}
                ${mediaHtml}
                <span class="msg-time">${timeStr}</span>
            </div>
        `;
        chatBox.appendChild(row);
    }

    function escapeHtml(str) {
        return str.replace(/[&<>'"]/g, tag => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[tag] || tag));
    }

    // Fetch messages
    async function fetchMessages() {
        try {
            const res = await fetch(`get_messages.php?other_id=${receiverId}&section_id=${sectionId}&after_id=${lastMsgId}`);
            if (!res.ok) return;
            const messages = await res.json();
            
            if (Array.isArray(messages) && messages.length > 0) {
                const loading = document.getElementById('loadingIndicator');
                if (loading) loading.remove();

                messages.forEach(msg => {
                    renderMessage(msg);
                    if (msg.id > lastMsgId) lastMsgId = msg.id;
                });
                scrollToBottom();
            } else if (lastMsgId === 0) {
                const loading = document.getElementById('loadingIndicator');
                if (loading) loading.innerHTML = "No messages yet. Say hi!";
            }
        } catch (e) {
            console.error(e);
        }
    }

    // Send Message
    async function sendMessage() {
        const text = msgInput.value.trim();
        const file = fileInput.files[0];
        
        if (!text && !file) return;
        if (isSending) return;
        
        isSending = true;
        sendBtn.style.opacity = '0.5';

        const fd = new FormData();
        fd.append('receiver_id', receiverId);
        fd.append('section_id', sectionId);
        if (text) fd.append('message_text', text);
        if (file) fd.append('attachment', file);

        try {
            const res = await fetch('send_message.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            
            if (data.success) {
                msgInput.value = '';
                msgInput.style.height = '50px';
                fileInput.value = '';
                filePreview.style.display = 'none';
                await fetchMessages(); // immediately fetch the sent message to render
            } else {
                alert("Failed to send: " + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert("Network error sending message");
        } finally {
            isSending = false;
            sendBtn.style.opacity = '1';
            msgInput.focus();
        }
    }

    sendBtn.addEventListener('click', sendMessage);
    msgInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Initial fetch and polling
    fetchMessages();
    setInterval(fetchMessages, 3000);
});
</script>
<?php endif; ?>

<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
