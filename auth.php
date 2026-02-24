<?php
/**
 * PharmaCare — Auth Guard
 * Include at the top of every protected page.
 * Redirects to login.php if not logged in.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

// Handy shortcuts
define('SESS_NAME',     $_SESSION['full_name'] ?? 'Pharmacist');
define('SESS_USERNAME', $_SESSION['username']  ?? 'user');