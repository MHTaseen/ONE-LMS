<?php
// register.php - Registration handling and interface
session_start();
require_once 'config.php';

// If user is already logged in, redirect to landing
if (isset($_SESSION['user_id'])) {
    header('Location: landing.php');
    exit();
}

$error = '';
$fullName = '';
$email = '';
$userId = '';
$department = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    // Simple backend validation
    if (empty($fullName) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } else {
        // Enforce role assignment and domains
        if (preg_match('/^[a-zA-Z0-9._%+-]+@(g\.)?bracu\.ac\.bd$/i', $email)) {
            $error = 'Access Denied: Institutional email domains (@g.bracu.ac.bd and @bracu.ac.bd) are restricted. Student and Teacher accounts can only be created by the Institution Authority.';
        } else {
            $role = 'guest';
            $userId = 'guest_' . bin2hex(random_bytes(8));
            $department = 'N/A';
            // Check database for unique constraint violations (Email and User ID)
            try {
                $stmt = $pdo->prepare("SELECT email, user_id FROM users WHERE email = ? OR user_id = ? LIMIT 1");
                $stmt->execute([$email, $userId]);
                $existingUser = $stmt->fetch();

                if ($existingUser) {
                    if (strcasecmp($existingUser['email'], $email) === 0) {
                        $error = 'An account with this email address already exists.';
                    } else {
                        $error = 'An account with this student/teacher ID already exists.';
                    }
                } else {
                    // Password encryption using robust bcrypt algorithm
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                    // Insert the user into the database
                    $insertStmt = $pdo->prepare("INSERT INTO users (full_name, email, user_id, password, department, role) VALUES (?, ?, ?, ?, ?, ?)");
                    $insertStmt->execute([$fullName, $email, $userId, $hashedPassword, $department, $role]);

                    // Automatically sign in the user by defining the session
                    $_SESSION['user_pk'] = $pdo->lastInsertId();
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['email'] = $email;
                    $_SESSION['department'] = $department;
                    $_SESSION['role'] = $role;

                    // Redirect to the Landing page
                    header('Location: landing.php');
                    exit();
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
    <title>Register - BRACU Thesis Prototype</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>

    <!-- Ambient glowing shapes in the background for a modern futuristic aesthetic -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- Theme Switch Toggle -->
    <div class="theme-switch-container">
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

    <!-- Registration Card Form -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-logo">ONE LMS</div>
            <h2 class="auth-title">Create Account</h2>
            <p class="auth-subtitle">Join the secure thesis network portal</p>

            <!-- Show PHP validation error if any -->
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

            <form action="register.php" method="POST" autocomplete="off">
                <!-- Full Name Input -->
                <div class="form-group">
                    <label class="form-label" for="full_name">Full Name</label>
                    <div class="input-wrapper">
                        <input class="form-input" type="text" id="full_name" name="full_name" placeholder="John Doe" value="<?= htmlspecialchars($fullName) ?>" required>
                    </div>
                </div>

                <!-- Email Input -->
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <div class="input-wrapper">
                        <input class="form-input" type="email" id="email" name="email" placeholder="example@gmail.com" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    <div class="form-helper">
                        Register with your personal email (e.g., @gmail.com) as a Guest. Student/Teacher signup is managed by the authority.
                    </div>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-wrapper">
                        <input class="form-input" type="password" id="password" name="password" placeholder="••••••••" autocomplete="new-password" required>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-primary">
                    Create Account
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </button>
            </form>

            <div class="auth-footer">
                Already registered? <a href="login.php" class="auth-link">Access Terminal</a>
            </div>
        </div>
    </div>

</body>
</html>
