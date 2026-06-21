<?php
// authority_portal.php - Authority Login & Registration Portal
session_start();
require_once 'config.php';
require_once 'includes/auth.php';

// Redirect if already logged in as authority
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'authority') {
        header('Location: authority_dashboard.php');
    } else {
        header('Location: landing.php');
    }
    exit();
}

$error = '';
$success = '';
$action = $_GET['action'] ?? 'login'; // 'login' or 'register'

$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register_btn'])) {
        $action = 'register';
        $fullName = trim($_POST['full_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($fullName) || empty($email) || empty($password)) {
            $error = 'All fields are required for registration.';
        } elseif (!preg_match('/^[a-zA-Z0-9._%+-]+@authority\.com$/i', $email)) {
            $error = 'Access Denied: Registration strictly requires an @authority.com email address.';
        } else {
            try {
                // Check unique
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'An authority account with this email address already exists.';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $userId = 'auth_' . bin2hex(random_bytes(8));
                    $department = 'Authority';
                    $role = 'authority';

                    $insertStmt = $pdo->prepare("INSERT INTO users (full_name, email, user_id, password, department, role) VALUES (?, ?, ?, ?, ?, ?)");
                    $insertStmt->execute([$fullName, $email, $userId, $hashedPassword, $department, $role]);

                    $_SESSION['user_pk'] = $pdo->lastInsertId();
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $_SESSION['department'] = $department;
                    $_SESSION['role'] = $role;

                    header('Location: authority_dashboard.php');
                    exit();
                }
            } catch (PDOException $e) {
                $error = 'Database Error: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['login_btn'])) {
        $action = 'login';
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please fill in all fields.';
        } else {
            try {
                $user = findUserByLogin($pdo, $email);

                if ($user && password_verify($password, $user['password'])) {
                    if ($user['role'] !== 'authority') {
                        $error = 'Access Denied: This account is not registered as an authority member.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_pk']    = $user['id'];
                        $_SESSION['user_id']     = $user['user_id'];
                        $_SESSION['full_name']   = $user['full_name'];
                        $_SESSION['email']      = $user['email'];
                        $_SESSION['department'] = $user['department'];
                        $_SESSION['role']       = $user['role'];

                        header('Location: authority_dashboard.php');
                        exit();
                    }
                } else {
                    $error = 'Invalid credentials. Please verify your email and password.';
                }
            } catch (PDOException $e) {
                $error = 'Database Error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Authority Portal - BRACU Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="responsive.css?v=3">
    <style>
        .portal-toggle {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .toggle-tab {
            flex: 1;
            text-align: center;
            padding: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: 0.2s;
        }
        .toggle-tab.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
        }
        .form-section {
            display: none;
        }
        .form-section.active {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .authority-badge {
            background: rgba(236, 72, 153, 0.12);
            border: 1px solid rgba(236, 72, 153, 0.25);
            color: #ec4899;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .theme-switch-container.theme-fixed {
            position: fixed;
            top: 25px;
            right: 25px;
            z-index: 1000;
        }
    </style>
</head>
<body>

    <!-- Ambient background glows -->
    <div class="ambient-glow-1" style="background: var(--accent-primary);"></div>
    <div class="ambient-glow-2" style="background: #ec4899;"></div>

    <!-- Theme Switch Toggle -->
    <div class="theme-switch-container theme-fixed">
        <button id="themeToggleBtn" class="theme-btn" aria-label="Toggle Light/Dark Theme">
            <svg class="sun-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.01c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/>
            </svg>
            <svg class="moon-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12.3 22h-.1c-5.5 0-10-4.5-10-10 0-4.7 3.3-8.8 8-9.7.3-.1.6 0 .8.2.2.2.3.6.1.8-1.5 2.1-1.1 5.1.9 6.8 1.8 1.6 4.7 1.6 6.5-.1.2-.2.5-.2.8-.1.2.2.3.5.2.8-.9 4.7-5 8-9.7 8z"/>
            </svg>
            <span>Theme Toggle</span>
        </button>
    </div>

    <!-- Auth Container -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo">ONE LMS</div>
            <div style="text-align: center;">
                <span class="authority-badge">Institution Authority</span>
            </div>
            <h2 class="auth-title">Administrative Terminal</h2>
            <p class="auth-subtitle">Verify administrative status to proceed</p>

            <?php if (!empty($error)): ?>
                <div class="alert-box alert-error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Tab Toggles -->
            <div class="portal-toggle">
                <div class="toggle-tab <?= $action === 'login' ? 'active' : '' ?>" id="tabLogin" onclick="setPortalAction('login')">Sign In</div>
                <div class="toggle-tab <?= $action === 'register' ? 'active' : '' ?>" id="tabRegister" onclick="setPortalAction('register')">Register</div>
            </div>

            <!-- Login Form Section -->
            <div class="form-section <?= $action === 'login' ? 'active' : '' ?>" id="sectionLogin">
                <form action="authority_portal.php?action=login" method="POST" autocomplete="off">
                    <div class="form-group">
                        <label class="form-label" for="login_email">Administrative Email</label>
                        <div class="input-wrapper">
                            <input class="form-input" type="email" id="login_email" name="email" placeholder="admin@authority.com" value="<?= $action === 'login' ? htmlspecialchars($email) : '' ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="login_password">Password</label>
                        <div class="input-wrapper">
                            <input class="form-input" type="password" id="login_password" name="password" placeholder="••••••••" required>
                        </div>
                    </div>

                    <button type="submit" name="login_btn" class="btn-primary" style="background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);">
                        Establish Connection
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </form>
            </div>

            <!-- Registration Form Section -->
            <div class="form-section <?= $action === 'register' ? 'active' : '' ?>" id="sectionRegister">
                <form action="authority_portal.php?action=register" method="POST" autocomplete="off">
                    <div class="form-group">
                        <label class="form-label" for="reg_full_name">Full Name</label>
                        <div class="input-wrapper">
                            <input class="form-input" type="text" id="reg_full_name" name="full_name" placeholder="Administrator Name" value="<?= $action === 'register' ? htmlspecialchars($fullName) : '' ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reg_email">Administrative Email</label>
                        <div class="input-wrapper">
                            <input class="form-input" type="email" id="reg_email" name="email" placeholder="name@authority.com" value="<?= $action === 'register' ? htmlspecialchars($email) : '' ?>" required>
                        </div>
                        <div class="form-helper">
                            Required domain suffix: <strong>@authority.com</strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reg_password">Secure Password</label>
                        <div class="input-wrapper">
                            <input class="form-input" type="password" id="reg_password" name="password" placeholder="••••••••" required>
                        </div>
                    </div>

                    <button type="submit" name="register_btn" class="btn-primary" style="background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);">
                        Create Admin Account
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </form>
            </div>

            <div class="auth-footer" style="margin-top: 28px;">
                Unauthorized access attempts are audited.<br>
                <a href="login.php" class="auth-link" style="color: #ec4899;">Return to Public Terminal</a>
            </div>
        </div>
    </div>

    <script>
        function setPortalAction(act) {
            document.querySelectorAll('.toggle-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));

            if (act === 'login') {
                document.getElementById('tabLogin').classList.add('active');
                document.getElementById('sectionLogin').classList.add('active');
                window.history.replaceState({}, '', 'authority_portal.php?action=login');
            } else {
                document.getElementById('tabRegister').classList.add('active');
                document.getElementById('sectionRegister').classList.add('active');
                window.history.replaceState({}, '', 'authority_portal.php?action=register');
            }
        }
    </script>
</body>
</html>
