<?php
/**
 * PharmaCare — Dashboard API
 * Called by dashboard.js every 60 seconds for live refresh.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

try {
    $db = getDB();

    // Today's revenue — from invoices where prescription was today (DateInvoiced after patch, fallback to join)
    $s = $db->prepare("
        SELECT IFNULL(SUM(i.Total), 0)
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
        WHERE DATE(pr.DatePrescribed) = CURDATE()
        AND i.Status = 'Completed'
    ");
    $s->execute();
    $today_revenue = (float)$s->fetchColumn();

    // Today's patients
    $s = $db->prepare("SELECT COUNT(DISTINCT PatientID) FROM prescriptions WHERE DATE(DatePrescribed) = CURDATE()");
    $s->execute();
    $total_patients_today = (int)$s->fetchColumn();

    // Today's prescriptions
    $s = $db->prepare("SELECT COUNT(*) FROM prescriptions WHERE DATE(DatePrescribed) = CURDATE()");
    $s->execute();
    $total_prescriptions_today = (int)$s->fetchColumn();

    // Low stock count
    $s = $db->prepare("
        SELECT COUNT(DISTINCT m.MedicationID)
        FROM medications m
        JOIN medicationdetails md ON m.MedicationID = md.MedicationID
        GROUP BY m.MedicationID
        HAVING SUM(md.StockAvailability) <= 200
    ");
    $s->execute();
    $low_stock_count = (int)$s->rowCount();

    // Recent transactions (last 10)
    $s = $db->prepare("
        SELECT i.InvoiceID, p.FullName AS PatientName,
               GROUP_CONCAT(m.GenericName SEPARATOR ', ') AS Medicines,
               i.Total, i.Status, pr.DatePrescribed
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
        JOIN patients p ON pr.PatientID = p.PatientID
        LEFT JOIN prescriptiondetails pd ON pr.PrescriptionID = pd.PrescriptionID
        LEFT JOIN medications m ON pd.MedicationID = m.MedicationID
        GROUP BY i.InvoiceID
        ORDER BY i.InvoiceID DESC
        LIMIT 10
    ");
    $s->execute();
    $recent_transactions = $s->fetchAll(PDO::FETCH_ASSOC);

    // Low stock items
    $s = $db->prepare("
        SELECT m.GenericName, m.BrandName, m.DosageStrength,
               SUM(md.StockAvailability) AS TotalStock
        FROM medications m
        JOIN medicationdetails md ON m.MedicationID = md.MedicationID
        GROUP BY m.MedicationID
        HAVING TotalStock <= 200
        ORDER BY TotalStock ASC
        LIMIT 5
    ");
    $s->execute();
    $low_stock_items = $s->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'                    => true,
        'today_revenue'              => $today_revenue,
        'total_patients_today'       => $total_patients_today,
        'total_prescriptions_today'  => $total_prescriptions_today,
        'low_stock_count'            => $low_stock_count,
        'recent_transactions'        => $recent_transactions,
        'low_stock_items'            => $low_stock_items,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}