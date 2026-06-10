<?php
// Initialize variables if not already done in the parent script
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'guest';

// Compute initials
$nameParts = explode(' ', trim($fullName));
if (count($nameParts) > 1) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
} else {
    $initials = strtoupper(substr($fullName, 0, 2));
}

// Fetch Notifications if not already fetched
if (!isset($unreadCount) || !isset($recentNotifications)) {
    require_once __DIR__ . '/notification_system.php';
    if (isset($_SESSION['user_pk']) && isset($pdo)) {
        $unreadCount = getUnreadCount($pdo, $_SESSION['user_pk']);
        $stmt_notif = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND deleted_from_bell = 0 ORDER BY created_at DESC LIMIT 10");
        $stmt_notif->execute([$_SESSION['user_pk']]);
        $recentNotifications = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $unreadCount = 0;
        $recentNotifications = [];
    }
}
?>
<nav class="top-navbar global-search-nav" role="navigation" aria-label="Main navigation">
    <!-- Left: initials avatar + brand -->
    <div class="navbar-left">
        <div class="nav-avatar" title="<?= htmlspecialchars($fullName) ?>"><?= $initials ?></div>
        <span class="navbar-brand">ONE LMS</span>
    </div>

    <!-- Universal Search Bar -->
    <div class="universal-search-container">
        <div class="universal-search-field">
            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" id="universalSearchInput" class="universal-search-input" placeholder="Search messages, materials, routine, features..." autocomplete="off">
            <button type="button" id="searchFilterToggle" class="search-filter-toggle" aria-label="Open search filters" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
        </div>
        <div id="searchFilterPanel" class="search-filter-panel">
            <div class="search-filter-title">Search Type</div>
            <div class="search-chip-list">
                <button type="button" class="search-chip" data-filter="messages">Messages</button>
                <button type="button" class="search-chip" data-filter="materials">Course Materials</button>
                <button type="button" class="search-chip" data-filter="routine">Routine</button>
                <button type="button" class="search-chip active" data-filter="features">App Features</button>
            </div>
        </div>
        <div id="universalSearchDropdown" class="search-dropdown">
            <!-- Results populate here -->
        </div>
    </div>

    <!-- Right: theme toggle -->
    <div class="navbar-right">
        <?php if (basename($_SERVER['PHP_SELF']) !== 'landing.php'): ?>
        <a href="landing.php" class="nav-btn-back" style="display:inline-flex; align-items:center; gap:8px; color:var(--text-secondary); text-decoration:none; font-weight:500; font-size:0.95rem; margin-right: 12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:18px;height:18px;"><polyline points="15 18 9 12 15 6"/></svg> Back
        </a>
        <?php endif; ?>

        <!-- Notification Bell -->
        <?php if ($role !== 'guest'): ?>
        <div class="notif-wrapper">
            <button class="notif-btn" id="notifBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:22px;height:22px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                <?php if($unreadCount > 0): ?>
                    <div class="notif-badge"><?= $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <span>Notifications</span>
                    <a href="manage_notifications.php" style="font-size:0.8rem; color:var(--accent-primary); text-decoration:none;">Manage</a>
                </div>
                <div class="notif-list" id="notifList">
                    <?php if(empty($recentNotifications)): ?>
                        <div style="padding:16px; text-align:center; color:var(--text-secondary); font-size:0.9rem;">No notifications.</div>
                    <?php else: ?>
                        <?php foreach($recentNotifications as $n): ?>
                            <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>" id="notif_<?= $n['id'] ?>" style="position:relative; padding-right:32px;">
                                <a href="<?= htmlspecialchars($n['link_url'] ?? '#') ?>" style="text-decoration:none; color:inherit; display:flex; flex-direction:column; gap:4px;">
                                    <span style="font-size:0.9rem; line-height:1.4;"><?= htmlspecialchars($n['message']) ?></span>
                                    <span class="notif-time"><?= date('M j, g:i a', strtotime($n['created_at'])) ?></span>
                                </a>
                                <button onclick="deleteNotif(event, <?= $n['id'] ?>)" style="position:absolute; right:12px; top:12px; background:none; border:none; color:var(--text-secondary); cursor:pointer;">&times;</button>
                            </div>
                        <?php endforeach; ?>
                        <div style="padding:12px 16px; border-top:1px solid var(--border-color); text-align:center;">
                            <button onclick="deleteAllNotifs(event)" style="background:rgba(239,68,68,0.1); color:#ef4444; border:none; padding:8px 16px; border-radius:8px; font-weight:600; cursor:pointer; width:100%; font-size:0.85rem;">Clear All Notifications</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="theme-switch-container">
            <button id="themeToggleBtn" class="theme-btn" aria-label="Toggle Light/Dark Theme">
                <!-- Sun Icon (shown in light mode) -->
                <svg class="sun-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.01c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/>
                </svg>
                <!-- Moon Icon (shown in dark mode) -->
                <svg class="moon-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12.3 22h-.1c-5.5 0-10-4.5-10-10 0-4.7 3.3-8.8 8-9.7.3-.1.6 0 .8.2.2.2.3.6.1.8-1.5 2.1-1.1 5.1.9 6.8 1.8 1.6 4.7 1.6 6.5-.1.2-.2.5-.2.8-.1.2.2.3.5.2.8-.9 4.7-5 8-9.7 8z"/>
                </svg>
                <span>Theme Toggle</span>
            </button>
        </div>
    </div>
</nav>
