<?php
// app_support.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'];
$fullName = $_SESSION['full_name'];
$nameParts = explode(' ', trim($fullName));
$initials = count($nameParts) > 1 
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1))
    : strtoupper(substr($fullName, 0, 2));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>App Support - BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body {
            justify-content: flex-start;
            align-items: stretch;
            padding-top: 0;
            overflow-x: hidden;
        }

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

        .btn-back {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 18px; background: var(--bg-secondary); border: 1px solid var(--border-color);
            color: var(--text-primary); border-radius: 12px; cursor: pointer; font-size: 0.9rem; font-weight: 600; text-decoration: none;
        }

        .page-wrap {
            padding: 100px 28px 60px;
            max-width: 1000px;
            margin: 0 auto;
            width: 100%;
        }

        .page-heading {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            background: var(--gradient-accent);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-align: center;
        }

        .page-subheading {
            color: var(--text-secondary);
            font-size: 1rem;
            margin-bottom: 40px;
            text-align: center;
        }

        .video-container {
            position: relative;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2), var(--glow-shadow);
            border: 1px solid var(--border-color);
            background: #000;
        }

        video {
            width: 100%;
            display: block;
            outline: none;
        }

        /* Ambient glow specific for the video */
        .video-glow {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 80%;
            height: 80%;
            background: radial-gradient(circle, rgba(168,85,247,0.15) 0%, rgba(59,130,246,0) 70%);
            transform: translate(-50%, -50%);
            pointer-events: none;
            z-index: -1;
            filter: blur(40px);
        }

        /* --- Video Grid Styles --- */
        .btn-contact-wrap {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 20px;
            z-index: 10;
            position: relative;
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            width: 100%;
            z-index: 10;
            position: relative;
        }

        .video-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }

        .video-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.3), var(--glow-shadow);
            border-color: var(--accent-primary);
        }

        .video-thumb-wrap {
            position: relative;
            width: 100%;
            padding-top: 56.25%; /* 16:9 Aspect Ratio */
            background: linear-gradient(135deg, rgba(17, 24, 39, 0.95), rgba(76, 29, 149, 0.88));
        }

        .video-thumb-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Space Grotesque', sans-serif;
            font-size: clamp(3rem, 8vw, 5.5rem);
            font-weight: 700;
            color: rgba(255, 255, 255, 0.94);
            background:
                radial-gradient(circle at center, rgba(168, 85, 247, 0.28) 0%, rgba(168, 85, 247, 0.08) 36%, transparent 68%),
                linear-gradient(135deg, rgba(7, 11, 19, 0.15), rgba(59, 130, 246, 0.12));
            letter-spacing: 0.08em;
            user-select: none;
            transition: transform 0.2s, opacity 0.2s;
        }

        .video-card:hover .video-thumb-placeholder {
            transform: scale(1.04);
            opacity: 1;
        }

        .video-duration {
            position: absolute;
            bottom: 8px; right: 8px;
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            backdrop-filter: blur(4px);
        }

        .video-play-icon {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 48px; height: 48px;
            background: rgba(168, 85, 247, 0.8);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white;
            opacity: 0.8;
            transition: opacity 0.2s, transform 0.2s;
        }

        .video-card:hover .video-play-icon {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1.1);
        }

        .video-info {
            padding: 16px;
        }

        .video-title {
            color: var(--text-primary);
            font-size: 1.05rem;
            font-weight: 600;
            line-height: 1.3;
        }

        /* --- Video Player Modal --- */
        .video-player-modal {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            z-index: 3000;
            display: none; justify-content: center; align-items: center;
            opacity: 0; transition: opacity 0.3s;
        }
        .video-player-modal.active { display: flex; opacity: 1; }
        .video-player-content {
            width: 1000px; max-width: 95%; aspect-ratio: 16 / 9;
            background: #000; border-radius: 16px; overflow: hidden; position: relative;
            box-shadow: 0 25px 50px rgba(0,0,0,0.5), var(--glow-shadow);
            transform: scale(0.95); transition: transform 0.3s;
        }
        .video-player-modal.active .video-player-content { transform: scale(1); }
        .video-player-close {
            position: absolute; top: 20px; right: 20px;
            width: 40px; height: 40px; background: rgba(255,255,255,0.1); border: none; border-radius: 50%;
            color: white; font-size: 1.5rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
            z-index: 3010; transition: background 0.2s;
        }
        .video-player-close:hover { background: rgba(239, 68, 68, 0.8); }

        /* --- Chat Modal Styles --- */
        .chat-modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            z-index: 2000;
            display: none; justify-content: center; align-items: center;
            opacity: 0; transition: opacity 0.3s;
        }
        .chat-modal-overlay.active { display: flex; opacity: 1; }
        .chat-modal {
            width: 900px; max-width: 95%; height: 600px; max-height: 90vh;
            background: var(--bg-secondary); border: 1px solid var(--border-color);
            border-radius: 20px; box-shadow: 0 25px 50px rgba(0,0,0,0.5), var(--glow-shadow);
            display: flex; overflow: hidden; position: relative;
            transform: scale(0.95); transition: transform 0.3s;
        }
        .chat-modal-overlay.active .chat-modal { transform: scale(1); }
        .chat-close-btn {
            position: absolute; top: 15px; right: 15px;
            background: rgba(255,255,255,0.1); border: none; color: var(--text-primary);
            width: 32px; height: 32px; border-radius: 50%; cursor: pointer;
            z-index: 10; display: flex; align-items: center; justify-content: center;
        }
        .chat-close-btn:hover { background: rgba(239, 68, 68, 0.8); }
        .chat-sidebar {
            width: 280px; background: rgba(0,0,0,0.2); border-right: 1px solid var(--border-color);
            display: flex; flex-direction: column;
        }
        .chat-sidebar-header {
            padding: 20px; border-bottom: 1px solid var(--border-color);
            font-family: 'Space Grotesque', sans-serif; font-weight: 700; font-size: 1.1rem;
        }
        .dev-list { list-style: none; flex: 1; overflow-y: auto; }
        .dev-item {
            padding: 15px 20px; border-bottom: 1px solid var(--border-color);
            cursor: pointer; display: flex; align-items: center; gap: 12px; transition: background 0.2s;
        }
        .dev-item:hover { background: rgba(168, 85, 247, 0.1); }
        .dev-item.active { background: rgba(168, 85, 247, 0.2); border-right: 3px solid var(--accent-primary); }
        .dev-avatar {
            width: 36px; height: 36px; border-radius: 50%; background: var(--gradient-accent);
            display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem; color: #fff;
        }
        .chat-main { flex: 1; display: flex; flex-direction: column; background: rgba(0,0,0,0.1); }
        .chat-header {
            padding: 20px; border-bottom: 1px solid var(--border-color);
            font-family: 'Space Grotesque', sans-serif; font-weight: 700; font-size: 1.1rem;
            display: flex; align-items: center; gap: 12px;
        }
        .chat-messages {
            flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px;
        }
        .message {
            max-width: 75%; padding: 12px 16px; border-radius: 16px; line-height: 1.4; font-size: 0.95rem;
        }
        .message.from-user { background: var(--accent-primary); color: white; align-self: flex-end; border-bottom-right-radius: 4px; }
        .message.from-developer { background: var(--bg-secondary); border: 1px solid var(--border-color); align-self: flex-start; border-bottom-left-radius: 4px; }
        .chat-input-area { padding: 15px 20px; border-top: 1px solid var(--border-color); display: flex; gap: 10px; }
        .chat-input {
            flex: 1; background: var(--input-bg); border: 1px solid var(--border-color);
            border-radius: 20px; padding: 10px 15px; color: var(--text-primary); outline: none;
        }
        .chat-input:focus { border-color: var(--accent-primary); }
        .chat-send-btn {
            background: var(--gradient-accent); color: white; border: none;
            border-radius: 20px; padding: 0 20px; font-weight: 600; cursor: pointer; transition: opacity 0.2s;
        }
        .chat-send-btn:hover { opacity: 0.9; }
        .chat-send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .contact-btn-wrap { text-align: center; margin-top: 40px; }
        .btn-contact {
            background: var(--gradient-accent); color: white; border: none; padding: 12px 28px;
            border-radius: 12px; font-size: 1.05rem; font-weight: 600; cursor: pointer;
            box-shadow: var(--glow-shadow); transition: transform 0.2s, box-shadow 0.2s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-contact:hover { transform: translateY(-2px); box-shadow: 0 0 30px rgba(168, 85, 247, 0.6); }
        .empty-state { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-secondary); text-align: center; padding: 20px; }

        /* -- App Support Mobile Responsive -- */
        @media (max-width: 900px) {
            .page-wrap { padding: 85px 18px 40px; }
            .video-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
            .chat-modal { height: 520px; }
            .chat-sidebar { width: 220px; }
        }
        @media (max-width: 768px) {
            .page-wrap { padding: 80px 12px 30px; }
            .page-heading { font-size: 1.6rem; }
            .video-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .chat-modal { flex-direction: column; height: auto; max-height: 90vh; width: calc(100% - 24px); }
            .chat-sidebar { width: 100%; max-height: 160px; border-right: none; border-bottom: 1px solid var(--border-color); }
            .dev-list { display: flex; overflow-x: auto; }
            .dev-item { flex-shrink: 0; min-width: 140px; border-right: 1px solid var(--border-color); border-bottom: none; }
            .chat-main { flex: 1; min-height: 300px; }
            .video-player-content { aspect-ratio: 16/9; width: calc(100% - 24px); }
        }
        @media (max-width: 600px) {
            .page-wrap { padding: 75px 10px 25px; }
            .page-heading { font-size: 1.3rem; }
            .page-subheading { font-size: 0.9rem; margin-bottom: 20px; }
            .video-grid { grid-template-columns: 1fr; gap: 14px; }
            .video-title { font-size: 0.95rem; }
            .btn-contact { padding: 10px 18px; font-size: 0.95rem; }
            .navbar-brand { display: none; }
            .theme-btn span { display: none; }
            .theme-btn { padding: 8px 10px; }
            .btn-back { padding: 7px 12px; font-size: 0.85rem; }
            .nav-avatar { width: 34px; height: 34px; font-size: 0.8rem; }
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>
    <div class="video-glow"></div>

    <?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


    <div class="page-wrap">
        <h1 class="page-heading">Application Support</h1>
        <p class="page-subheading">Watch this demonstration to learn how to navigate and utilize the features of the BRAC University Hub.</p>

        <div class="btn-contact-wrap">
            <button class="btn-contact" id="openChatBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                Contact Developers
            </button>
        </div>

        <div class="video-grid">
            <!-- Box 1 -->
            <div class="video-card" data-video-src="https://www.youtube.com/embed/W6NZfCO5SIk?autoplay=1">
                <div class="video-thumb-wrap">
                    <div class="video-thumb-placeholder" aria-hidden="true">1</div>
                    <div class="video-play-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:24px;height:24px;"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </div>
                    <div class="video-duration">12:45</div>
                </div>
                <div class="video-info">
                    <div class="video-title">How to use Communication feature</div>
                </div>
            </div>

            <!-- Box 2 -->
            <div class="video-card" data-video-src="https://www.youtube.com/embed/PkZNo7MFOUg?autoplay=1">
                <div class="video-thumb-wrap">
                    <div class="video-thumb-placeholder" aria-hidden="true">2</div>
                    <div class="video-play-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:24px;height:24px;"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </div>
                    <div class="video-duration">08:20</div>
                </div>
                <div class="video-info">
                    <div class="video-title">How to do Advising</div>
                </div>
            </div>

            <!-- Box 3 -->
            <div class="video-card" data-video-src="https://www.youtube.com/embed/1Rs2ND1ryYc?autoplay=1">
                <div class="video-thumb-wrap">
                    <div class="video-thumb-placeholder" aria-hidden="true">3</div>
                    <div class="video-play-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" style="width:24px;height:24px;"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </div>
                    <div class="video-duration">05:15</div>
                </div>
                <div class="video-info">
                    <div class="video-title">How to Use Preference Features</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Video Player Modal Overlay -->
    <div class="video-player-modal" id="videoPlayerModal">
        <button class="video-player-close" id="videoPlayerClose">?</button>
        <div class="video-player-content">
            <iframe id="videoIframe" width="100%" height="100%" src="" title="Video Player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen style="display: block;"></iframe>
        </div>
    </div>

    <!-- Chat Modal Overlay -->
    <div class="chat-modal-overlay" id="chatModalOverlay">
        <div class="chat-modal">
            <button class="chat-close-btn" id="closeChatBtn">?</button>
            
            <div class="chat-sidebar">
                <div class="chat-sidebar-header">Developers</div>
                <ul class="dev-list">
                    <li class="dev-item" data-dev="SM Shuraim">
                        <div class="dev-avatar">SM</div>
                        <span>SM Shuraim</span>
                    </li>
                    <li class="dev-item" data-dev="Jannat Zarin">
                        <div class="dev-avatar">JZ</div>
                        <span>Jannat Zarin</span>
                    </li>
                    <li class="dev-item" data-dev="Afif Atanu">
                        <div class="dev-avatar">AA</div>
                        <span>Afif Atanu</span>
                    </li>
                    <li class="dev-item" data-dev="Mahmudul Hassan Taseen">
                        <div class="dev-avatar">MH</div>
                        <span>Mahmudul Hassan</span>
                    </li>
                </ul>
            </div>

            <div class="chat-main">
                <div class="chat-header" id="chatHeader">
                    <div class="empty-state">Select a developer to start chatting</div>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <div class="empty-state">No messages yet. Select a developer from the left.</div>
                </div>
                <div class="chat-input-area">
                    <input type="text" class="chat-input" id="chatInput" placeholder="Type your message..." disabled>
                    <button class="chat-send-btn" id="chatSendBtn" disabled>Send</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Video Player Modal Logic
        const videoCards = document.querySelectorAll('.video-card');
        const videoPlayerModal = document.getElementById('videoPlayerModal');
        const videoPlayerClose = document.getElementById('videoPlayerClose');
        const videoIframe = document.getElementById('videoIframe');

        videoCards.forEach(card => {
            card.addEventListener('click', () => {
                const videoSrc = card.getAttribute('data-video-src');
                videoIframe.src = videoSrc;
                videoPlayerModal.classList.add('active');
            });
        });

        const closeVideoModal = () => {
            videoPlayerModal.classList.remove('active');
            videoIframe.src = ''; // Stops the video from playing in background
        };

        videoPlayerClose.addEventListener('click', closeVideoModal);
        videoPlayerModal.addEventListener('click', (e) => {
            if (e.target === videoPlayerModal) {
                closeVideoModal();
            }
        });

        // Chat Modal Logic
        const openChatBtn = document.getElementById('openChatBtn');
        const closeChatBtn = document.getElementById('closeChatBtn');
        const chatModalOverlay = document.getElementById('chatModalOverlay');
        const devItems = document.querySelectorAll('.dev-item');
        const chatHeader = document.getElementById('chatHeader');
        const chatMessages = document.getElementById('chatMessages');
        const chatInput = document.getElementById('chatInput');
        const chatSendBtn = document.getElementById('chatSendBtn');

        let currentDeveloper = null;

        openChatBtn.addEventListener('click', () => {
            chatModalOverlay.classList.add('active');
        });

        closeChatBtn.addEventListener('click', () => {
            chatModalOverlay.classList.remove('active');
        });

        chatModalOverlay.addEventListener('click', (e) => {
            if (e.target === chatModalOverlay) {
                chatModalOverlay.classList.remove('active');
            }
        });

        devItems.forEach(item => {
            item.addEventListener('click', () => {
                devItems.forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                currentDeveloper = item.getAttribute('data-dev');
                
                // Update Header
                const avatar = item.querySelector('.dev-avatar').innerText;
                chatHeader.innerHTML = `
                    <div class="dev-avatar" style="width:30px;height:30px;font-size:0.8rem;">${avatar}</div>
                    ${currentDeveloper}
                `;

                // Enable Input
                chatInput.disabled = false;
                chatSendBtn.disabled = false;
                
                loadMessages();
            });
        });

        function loadMessages() {
            if (!currentDeveloper) return;
            chatMessages.innerHTML = '<div class="empty-state">Loading...</div>';
            
            fetch(`dev_chat_api.php?developer=${encodeURIComponent(currentDeveloper)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        chatMessages.innerHTML = '';
                        if (data.messages.length === 0) {
                            chatMessages.innerHTML = `<div class="empty-state">Say hi to ${currentDeveloper}!</div>`;
                        } else {
                            data.messages.forEach(msg => {
                                const div = document.createElement('div');
                                div.className = `message from-${msg.sender_type}`;
                                div.innerText = msg.message_text;
                                chatMessages.appendChild(div);
                            });
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                        }
                    } else {
                        chatMessages.innerHTML = `<div class="empty-state">Error loading messages.</div>`;
                    }
                })
                .catch(err => {
                    chatMessages.innerHTML = `<div class="empty-state">Network error.</div>`;
                });
        }

        function sendMessage() {
            if (!currentDeveloper) return;
            const text = chatInput.value.trim();
            if (!text) return;

            // Optimistic UI update
            const div = document.createElement('div');
            div.className = 'message from-user';
            div.innerText = text;
            
            // Remove empty state if present
            if (chatMessages.querySelector('.empty-state')) {
                chatMessages.innerHTML = '';
            }
            
            chatMessages.appendChild(div);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            chatInput.value = '';

            fetch('dev_chat_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ developer: currentDeveloper, message: text })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    alert('Failed to send message: ' + (data.message || 'Unknown error'));
                    div.style.opacity = '0.5';
                }
            })
            .catch(err => {
                alert('Network error while sending message.');
                div.style.opacity = '0.5';
            });
        }

        chatSendBtn.addEventListener('click', sendMessage);
        chatInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });

    </script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
