<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function attemptLogin(string $license, string $fullname): bool {
    try {
        $db = getDB();
        $s  = $db->prepare("
            SELECT PharmacistID, FullName, LicenseNumber, Workplace
            FROM pharmacists
            WHERE LicenseNumber = ? AND FullName = ?
            LIMIT 1
        ");
        $s->execute([trim($license), trim($fullname)]);
        $pharmacist = $s->fetch(PDO::FETCH_ASSOC);

        if ($pharmacist) {
            $_SESSION['user_id']   = $pharmacist['PharmacistID'];
            $_SESSION['full_name'] = $pharmacist['FullName'];
            $_SESSION['license']   = $pharmacist['LicenseNumber'];
            $_SESSION['workplace'] = $pharmacist['Workplace'];
            $_SESSION['logged_in'] = true;
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