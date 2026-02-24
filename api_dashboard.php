<?php
/**
 * PharmaCare — Dashboard API
 * Called by dashboard.js every 60 seconds for auto-refresh.
 * Flat file — no subfolders needed.
 */
require_once 'auth.php';
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $db = getDB();

    $s = $db->prepare("SELECT IFNULL(SUM(i.Total),0) FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
        WHERE DATE(pr.DatePrescribed)=CURDATE()");
    $s->execute(); $revenue = $s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(DISTINCT PatientID) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute(); $patients = $s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute(); $prescriptions = $s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM (
        SELECT MedicationID, SUM(StockAvailability) t
        FROM medicationdetails GROUP BY MedicationID HAVING t<=200) x");
    $s->execute(); $lowCount = $s->fetchColumn();

    $s = $db->prepare("SELECT m.GenericName, m.BrandName, m.DosageStrength,
            SUM(md.StockAvailability) AS TotalStock
        FROM medications m JOIN medicationdetails md ON m.MedicationID=md.MedicationID
        GROUP BY m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength
        HAVING TotalStock<=200 ORDER BY TotalStock ASC LIMIT 10");
    $s->execute(); $lowItems = $s->fetchAll();

    $s = $db->prepare("SELECT i.InvoiceID, p.FullName AS PatientName,
            GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines,
            i.Total, 'Completed' AS Status
        FROM invoices i
        JOIN prescriptions pr  ON i.PrescriptionID=pr.PrescriptionID
        JOIN patients p        ON pr.PatientID=p.PatientID
        JOIN prescriptiondetails pd ON pr.PrescriptionID=pd.PrescriptionID
        JOIN medications m     ON pd.MedicationID=m.MedicationID
        GROUP BY i.InvoiceID, p.FullName, i.Total
        ORDER BY i.InvoiceID DESC LIMIT 10");
    $s->execute(); $txns = $s->fetchAll();

    echo json_encode([
        'error'                     => false,
        'today_revenue'             => (float) $revenue,
        'total_patients_today'      => (int) $patients,
        'total_prescriptions_today' => (int) $prescriptions,
        'low_stock_count'           => (int) $lowCount,
        'low_stock_items'           => $lowItems,
        'recent_transactions'       => $txns,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => $e->getMessage()]);
}