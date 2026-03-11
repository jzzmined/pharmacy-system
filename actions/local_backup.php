<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

try {
    $db = getDB();

    // Get DB credentials from config
    $host     = defined('DB_HOST') ? DB_HOST : 'localhost';
    $dbname   = defined('DB_NAME') ? DB_NAME : 'pharmacy_db';
    $user     = defined('DB_USER') ? DB_USER : 'root';
    $pass     = defined('DB_PASS') ? DB_PASS : '';

    $filename = 'pharmacare_backup_' . date('Y-m-d_H-i-s') . '.sql';

    // Set download headers
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');

    // ── Header ──
    fwrite($out, "-- ============================================\n");
    fwrite($out, "-- PharmaCare Local Backup\n");
    fwrite($out, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- Database:  {$dbname}\n");
    fwrite($out, "-- ============================================\n\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($out, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    // ── Get all tables ──
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Table structure
        $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($out, "-- ----------------------------\n");
        fwrite($out, "-- Table: {$table}\n");
        fwrite($out, "-- ----------------------------\n");
        fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($out, $create[1] . ";\n\n");

        // Table data
        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
            fwrite($out, "INSERT INTO `{$table}` ({$cols}) VALUES\n");
            $lines = [];
            foreach ($rows as $row) {
                $vals = array_map(function($v) use ($db) {
                    if ($v === null) return 'NULL';
                    return $db->quote($v);
                }, array_values($row));
                $lines[] = '(' . implode(', ', $vals) . ')';
            }
            fwrite($out, implode(",\n", $lines) . ";\n\n");
        }
    }

    fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($out, "-- ============================================\n");
    fwrite($out, "-- End of backup\n");
    fwrite($out, "-- ============================================\n");

    fclose($out);
    exit;

} catch (Exception $e) {
    // Redirect back with error
    header('Location: ../pages/admin.php?backup_error=' . urlencode($e->getMessage()));
    exit;
}