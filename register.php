<?php
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $valid_email    = 'user@example.com';
    $valid_password = 'password123';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif ($email === $valid_email && $password === $valid_password) {
        $success = 'Login successful! Welcome back.';
    } else {
        $error = 'Invalid email or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>

    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Google Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700&display=swap">
    <!-- Your Custom CSS -->
    <link rel="stylesheet" href="register.css">

</head>
<body>

    <div class="container">

        <h2 class="title">Sign Up</h2>
        <p class="subhead">The care you count on...</p>

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

            <!-- Floating Label: Full Name -->
            <div class="floating-group">
                <input
                    type="text"
                    id="name"
                    name="name"
                    class="input-email"
                    placeholder=" "
                    autocomplete="name"
                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                    required
                >
                <label for="name">Full Name</label>
            </div>

            <!-- Floating Label: Email -->
            <div class="floating-group">
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="input-email"
                    placeholder=" "
                    autocomplete="email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    required
                >
                <label for="email">Email Address</label>
            </div>
            
            <!-- Floating Label: Username -->
            <div class="floating-group">
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="input-email"
                    placeholder=" "
                    autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    required
                >
                <label for="username">Username</label>
            </div>

            <!-- Floating Label: Password -->
            <div class="floating-group">
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="input-pass"
                    placeholder=" "
                    autocomplete="current-password"
                    required
                >
                <label for="password">Password</label>
            </div>

            <!-- Floating Label: Confirm Password -->
            <div class="floating-group">
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    class="input-pass"
                    placeholder=" "
                    autocomplete="current-password"
                    required
                >
                <label for="confirm_password">Confirm Password</label>
            </div>


            <button type="submit" class="signup_btn">Sign Up</button>
        </form>

        <p class="text">
                Already have an account?
            <a href="login.php" class="link">Login</a>
        </p>

    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>