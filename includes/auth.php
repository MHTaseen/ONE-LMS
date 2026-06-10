<?php
// includes/auth.php — shared authentication helpers

function findUserByLogin(PDO $pdo, string $loginInput): ?array {
    $loginInput = trim($loginInput);
    if ($loginInput === '') {
        return null;
    }

    if (strpos($loginInput, '@') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1");
        $stmt->execute([$loginInput]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE TRIM(user_id) = ? LIMIT 1");
    $stmt->execute([$loginInput]);
    $user = $stmt->fetch();
    return $user ?: null;
}
