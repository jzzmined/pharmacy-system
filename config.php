<?php
/**
 * PharmaCare — Database Connection
 * Edit DB_USER and DB_PASS to match your MySQL setup.
 */
define('DB_HOST', 'localhost');
define('DB_USER', 'root');   // ← change if needed
define('DB_PASS', '');       // ← change if needed
define('DB_NAME', 'pharmacy_db');
define('DB_PORT', 3306);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn  = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            throw $e;
        }
    }
    return $pdo;
}