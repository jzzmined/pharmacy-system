<?php

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → go to dashboard
if (isLoggedIn()) {
    header("Location: /pharmacy-system/pages/dashboard.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter both your Email and Password.';
    } elseif (attemptLogin($email, $password)) {
        header('Location: /pharmacy-system/pages/dashboard.php');
        exit;
    } else {
        $error = 'Invalid Email or Password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=DM+Serif+Display&display=swap">
    <link rel="stylesheet" href="assets/css/login.css">
</head>

<body>

    <!-- ═══ LEFT: Brand Wordmark ═══ -->
    <div class="brand-side">
        <div class="brand-name">
            Pharma<br>
            <span class="heart">♥</span>Care
        </div>
    </div>

    <!-- ═══ RIGHT: Login Card ═══ -->
    <div class="cons">
        <div class="Log_card">

            <h1>Admin Login</h1>
            <p class="tag">The care you can count on...</p>

            <?php if ($error): ?>
                <div class="login-error">⚠ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">

                <!-- Input MUST come before label for the CSS floating label to work -->
                <div class="inputs">
                    <input type="email" id="email" name="email" placeholder=" "
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="username" required>
                    <label for="email">Email Address</label>
                </div>

                <div class="pass">
                    <input type="password" id="password" name="password" placeholder=" " autocomplete="current-password"
                        required>
                    <label for="password">Password</label>
                </div>

                <div class="opts">
                    <label>
                        <input type="checkbox" name="remember"> Remember Me
                    </label>
                    <a href="#">Forgot Password?</a>
                </div>

                <button type="submit">Login</button>

            </form>

        </div>
    </div>

</body>

</html>