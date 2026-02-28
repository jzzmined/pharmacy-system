<?php
/**
 * PharmaCare — Dashboard API
 * Called by dashboard.js every 60 seconds.
 */
// Fix the paths to the includes folder
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    
    // Fetch stats for the auto-refresh
    $s = $db->prepare("SELECT IFNULL(SUM(Total),0) FROM invoices WHERE DATE(DateInvoiced)=CURDATE()");
    $s->execute();
    $rev = $s->fetchColumn();

    echo json_encode([
        'success' => true,
        'revenue' => (float)$rev
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}