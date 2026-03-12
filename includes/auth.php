<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/config.php';

function attemptLogin($email, $password) {
    try {
        $db = getDB();
        $s  = $db->prepare("SELECT * FROM users WHERE Email = ? AND Status = 'Active' LIMIT 1");
        $s->execute([$email]);
        $user = $s->fetch();

        if ($user && password_verify($password, $user['Password'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id']   = $user['UserID'];
            $_SESSION['full_name'] = $user['FullName'];
            $_SESSION['role']      = $user['Role'];

            // Update last login
            $u = $db->prepare("UPDATE users SET LastLogin = CURDATE() WHERE UserID = ?");
            $u->execute([$user['UserID']]);

            return true;
        }
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

function isLoggedIn(): bool {
    return !empty($_SESSION['logged_in']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /pharmacy-system/login.php');
        exit;
    }
}

// NOTE: requireLogin() is NOT called here automatically.
// Call it manually at the top of each protected page.