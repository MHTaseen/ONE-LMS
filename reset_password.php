<?php
// reset_password.php — Prototype password reset (no email verification)
session_start();
require_once 'config.php';
require_once 'includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: landing.php');
    exit();
}

$error = '';
$success = '';
$loginInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput        = trim($_POST['login_input'] ?? '');
    $password          = $_POST['password'] ?? '';
    $confirm           = $_POST['confirm_password'] ?? '';

    if ($loginInput === '' || $password === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        try {
            $user = findUserByLogin($pdo, $loginInput);
            if (!$user) {
                $error = 'No account found for that email or ID.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([$hash, $user['id']]);
                $success = 'Password updated. You can now sign in with your new password.';
                $loginInput = '';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Reset Password - BRACU Thesis Prototype</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <div class="theme-switch-container theme-fixed">
        <button id="themeToggleBtn" class="theme-btn" aria-label="Toggle Light/Dark Theme">
            <svg class="sun-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5z"/></svg>
            <svg class="moon-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12.3 22h-.1c-5.5 0-10-4.5-10-10 0-4.7 3.3-8.8 8-9.7.3-.1.6 0 .8.2.2.2.3.6.1.8-1.5 2.1-1.1 5.1.9 6.8 1.8 1.6 4.7 1.6 6.5-.1.2-.2.5-.2.8-.1.2.2.3.5.2.8-.9 4.7-5 8-9.7 8z"/></svg>
            <span>Theme Toggle</span>
        </button>
    </div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo">ONE LMS</div>
            <h2 class="auth-title">Reset Password</h2>
            <p class="auth-subtitle">Set a new password for your account</p>

            <?php if ($error): ?>
                <div class="alert-box alert-error"><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert-box" style="background:rgba(16,185,129,0.1); border:1px solid #10b981; color:#10b981; padding:12px; border-radius:10px; margin-bottom:16px;">
                    <?= htmlspecialchars($success) ?>
                    <a href="login.php" class="auth-link" style="display:block; margin-top:8px;">Go to Login</a>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="POST" autocomplete="on">
                <div class="form-group">
                    <label class="form-label" for="login_input">Institutional Email or ID</label>
                    <input class="form-input" type="text" id="login_input" name="login_input" value="<?= htmlspecialchars($loginInput) ?>" autocomplete="username" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <input class="form-input" type="password" id="password" name="password" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input class="form-input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn-primary">Update Password</button>
            </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="login.php" class="auth-link">Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
