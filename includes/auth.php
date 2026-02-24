<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if the session variable isn't set
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
?>
