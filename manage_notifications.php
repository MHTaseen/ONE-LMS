<?php
// manage_notifications.php - User preferences for notifications
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

$user_db_id = $_SESSION['user_pk'] ?? 0;
if (!$user_db_id) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $user_db_id = $row['id'] ?? 0;
}

// Fetch current preferences
$stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
$stmt->execute([$user_db_id]);
$prefs = $stmt->fetch();

if (!$prefs) {
    // Insert defaults if not exist
    $pdo->prepare("INSERT INTO user_preferences (user_id) VALUES (?)")->execute([$user_db_id]);
    $prefs = ['mute_announcements' => 0, 'mute_theory' => 0, 'mute_lab' => 0, 'mute_dms' => 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle toggle
    $field = $_POST['field'] ?? '';
    if (in_array($field, ['mute_announcements', 'mute_theory', 'mute_lab', 'mute_dms'])) {
        $val = intval($_POST['val'] ?? 0);
        $stmt = $pdo->prepare("UPDATE user_preferences SET $field = ? WHERE user_id = ?");
        $stmt->execute([$val, $user_db_id]);
        echo "OK";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Manage Notifications - BRACU Thesis</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .top-navbar { position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center; padding: 10px 28px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 2px 20px rgba(0,0,0,.25); }
        .navbar-left  { display: flex; align-items: center; gap: 14px; }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem; font-weight: 700; background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-btn-back { display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-weight: 500; font-size: 0.95rem; transition: color 0.2s; }
        .nav-btn-back:hover { color: var(--accent-primary); }
        
        .page-container {
            padding: 100px 40px 40px;
            max-width: 800px;
            margin: 0 auto;
            min-height: 100vh;
        }
        .header h1 {
            font-family: 'Space Grotesque', sans-serif;
            font-size: 2.2rem;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .header p { color: var(--text-secondary); font-size: 1.1rem; margin-bottom: 40px; }

        .settings-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
        }

        .setting-row {
            display: flex;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .setting-row:last-child { border-bottom: none; }
        
        .setting-info { display: flex; flex-direction: column; gap: 4px; }
        .setting-title { font-size: 1.1rem; color: var(--text-primary); font-weight: 600; }
        .setting-desc { font-size: 0.9rem; color: var(--text-secondary); max-width: 80%; line-height: 1.4; }

        /* Custom Toggle Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 28px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #3f3f46; transition: .3s; border-radius: 34px;
        }
        .slider:before {
            position: absolute; content: ""; height: 20px; width: 20px; left: 4px; bottom: 4px;
            background-color: white; transition: .3s; border-radius: 50%;
        }
        /* Mute = ON -> Red/Muted style. Active notifications = OFF -> Normal style */
        input:checked + .slider { background-color: #ef4444; }
        input:focus + .slider { box-shadow: 0 0 1px #ef4444; }
        input:checked + .slider:before { transform: translateX(22px); }
        
        .toast {
            position: fixed; bottom: 24px; right: 24px; background: var(--gradient-accent); color: #fff;
            padding: 12px 24px; border-radius: 8px; font-weight: 500; transform: translateY(100px);
            opacity: 0; transition: 0.3s; z-index: 1000;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
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
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>

<?php include 'includes/global_nav.php'; ?>
<?php include 'includes/shared_drawer.php'; ?>


<div class="page-container">
    <div class="header">
        <h1 class="translate" data-key="Manage Notifications">Manage Notifications</h1>
        <p>Control what you want to be notified about. Toggling a switch ON will <b>mute</b> those notifications.</p>
    </div>

    <div class="settings-card">
        
        <div class="setting-row">
            <div class="setting-info">
                <span class="setting-title">Mute Central Announcements</span>
                <span class="setting-desc">If toggled on, you will not receive notifications from the Announcements section.</span>
            </div>
            <label class="switch">
                <input type="checkbox" class="pref-toggle" data-field="mute_announcements" <?= $prefs['mute_announcements'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <div class="setting-row">
            <div class="setting-info">
                <span class="setting-title">Mute Theory Section</span>
                <span class="setting-desc">If toggled on, you will not receive notifications from Theory chat channels.</span>
            </div>
            <label class="switch">
                <input type="checkbox" class="pref-toggle" data-field="mute_theory" <?= $prefs['mute_theory'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <div class="setting-row">
            <div class="setting-info">
                <span class="setting-title">Mute Lab Section</span>
                <span class="setting-desc">If toggled on, you will not receive notifications from Lab chat channels.</span>
            </div>
            <label class="switch">
                <input type="checkbox" class="pref-toggle" data-field="mute_lab" <?= $prefs['mute_lab'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

        <div class="setting-row">
            <div class="setting-info">
                <span class="setting-title">Mute Teacher/Student Messages</span>
                <span class="setting-desc">If toggled on, you will not be notified of DMs from the Consult section.</span>
            </div>
            <label class="switch">
                <input type="checkbox" class="pref-toggle" data-field="mute_dms" <?= $prefs['mute_dms'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>

    </div>
</div>

<div class="toast" id="toastMsg">Preferences Updated!</div>

<script src="theme.js"></script>
<script>
document.querySelectorAll('.pref-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const field = this.getAttribute('data-field');
        const val = this.checked ? 1 : 0;
        
        const fd = new FormData();
        fd.append('field', field);
        fd.append('val', val);

        fetch('manage_notifications.php', {
            method: 'POST',
            body: fd
        })
        .then(res => res.text())
        .then(data => {
            if(data === 'OK') {
                const toast = document.getElementById('toastMsg');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 3000);
            }
        });
    });
});
</script>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
