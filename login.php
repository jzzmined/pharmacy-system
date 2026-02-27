<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Already logged in? Go straight to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: pages/dashboard.php");
    exit;
}

require_once __DIR__ . '/includes/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $db = getDB();
            $s  = $db->prepare("SELECT * FROM users WHERE Email = ? LIMIT 1");
            $s->execute([$email]);
            $user = $s->fetch();

            if ($user && password_verify($password, $user['Password'])) {
                $_SESSION['logged_in'] = true;
                $_SESSION['full_name'] = $user['FullName'];
                $_SESSION['username']  = $user['Username'];
                $_SESSION['user_id']   = $user['UserID'];
                $_SESSION['role']      = $user['Role'];

                header("Location: dashboard.php");
                exit;
            } else {
                $error = 'Invalid email or password. Please try again.';
            }
        } catch (Throwable $e) {
            $error = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>

    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700&display=swap">
    <!-- Your Custom CSS -->
    <link rel="stylesheet" href="assets/css/login.css">

</head>

<body>

    <div class="cons">
        <div class="Log_card">

            <h1 class="title">Login</h1>
            <p class="tag">The care you count on...</p>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form class="form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="post" autocomplete="on">

                <div class="inputs">
                    <input type="email" id="email" name="email" required placeholder=" ">
                    <label for="email">Email address:</label>
                </div>

                <div class="pass">
                    <input type="password" id="password" name="password" required placeholder=" ">
                    <label for="password">Password:</label>
                </div>

                <div class="opts">
                    <label>
                        <input type="checkbox" name="remember">
                        Remember password
                    </label>
                    <a class="forgot" href="">Forgot password?</a>
                </div>

                <button type="submit">Login</button>

            </form>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>