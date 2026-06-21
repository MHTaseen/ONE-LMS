<?php
// login.php - Sign in portal logic and template
session_start();
require_once 'config.php';
require_once 'includes/auth.php';

// If user is already logged in, redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'authority') {
        header('Location: authority_dashboard.php');
    } else {
        header('Location: landing.php');
    }
    exit();
}

$error = '';
$loginInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['login_input'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($loginInput === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $user = findUserByLogin($pdo, $loginInput);

            if ($user && password_verify($password, $user['password'])) {
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                        ->execute([$newHash, $user['id']]);
                }

                session_regenerate_id(true);
                $_SESSION['user_pk']    = $user['id'];
                $_SESSION['user_id']     = $user['user_id'];
                $_SESSION['full_name']   = $user['full_name'];
                $_SESSION['email']      = $user['email'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['role']       = $user['role'];

                if ($user['role'] === 'authority') {
                    header('Location: authority_dashboard.php');
                } else {
                    header('Location: landing.php');
                }
                exit();
            }

            $error = 'Invalid authentication credentials. Use your registered email or ID and check that Caps Lock is off.';
        } catch (PDOException $e) {
            $error = 'Database error occurred: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login - BRACU Thesis Prototype</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>

    <!-- Ambient glowing shapes for futuristic aesthetic -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- Theme Switch Toggle -->
    <div class="theme-switch-container theme-fixed">
        <button id="themeToggleBtn" class="theme-btn" aria-label="Toggle Light/Dark Theme">
            <!-- Sun Icon -->
            <svg class="sun-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.01c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/>
            </svg>
            <!-- Moon Icon -->
            <svg class="moon-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12.3 22h-.1c-5.5 0-10-4.5-10-10 0-4.7 3.3-8.8 8-9.7.3-.1.6 0 .8.2.2.2.3.6.1.8-1.5 2.1-1.1 5.1.9 6.8 1.8 1.6 4.7 1.6 6.5-.1.2-.2.5-.2.8-.1.2.2.3.5.2.8-.9 4.7-5 8-9.7 8z"/>
            </svg>
            <span>Theme Toggle</span>
        </button>
    </div>

    <!-- Login Card Form -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo">ONE LMS</div>
            <h2 class="auth-title">System Sign In</h2>
            <p class="auth-subtitle">Verify clearance to access secure repository</p>

            <!-- Show PHP error if set -->
            <?php if (!empty($error)): ?>
                <div class="alert-box alert-error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" autocomplete="off">
                <input type="text" name="fake_username" autocomplete="username" style="display:none;">
                <input type="password" name="fake_password" autocomplete="current-password" style="display:none;">
                <!-- Username / ID Input -->
                <div class="form-group">
                    <label class="form-label" for="login_input">Institutional Email or ID</label>
                    <div class="input-wrapper">
                        <input class="form-input" type="text" id="login_input" name="login_input" placeholder="e.g. 21101234 or user@g.bracu.ac.bd" value="" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="email" required>
                    </div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrapper" style="position:relative;">
                        <input class="form-input" type="password" id="password" name="password" placeholder="••••••••" value="" autocomplete="new-password" required style="padding-right:44px;">
                        <button type="button" id="togglePassword" aria-label="Show password" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:4px; min-height:auto;">
                            <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-primary">
                    Initiate Connection
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <div class="auth-footer">
                Unauthorized access is logged.<br>
                <a href="register.php" class="auth-link">Register</a>
                &nbsp;·&nbsp;
                <a href="reset_password.php" class="auth-link">Reset password</a>
                &nbsp;·&nbsp;
                <a href="authority_portal.php" class="auth-link" style="color: var(--accent-secondary);">Authority Portal</a>
            </div>
        </div>
    </div>

<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const pw = document.getElementById('password');
    if (!pw) return;
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

window.addEventListener('pageshow', function () {
    const loginInput = document.getElementById('login_input');
    const passwordInput = document.getElementById('password');
    if (loginInput) loginInput.value = '';
    if (passwordInput) passwordInput.value = '';
});
</script>
</body>
</html>
