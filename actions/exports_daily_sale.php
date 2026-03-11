<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

try {
    $db = getDB();

    $filename = 'pharmacare_daily_sales_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');

    // BOM for Excel UTF-8 compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // ── Header row ──
    fputcsv($out, [
        'Invoice ID',
        'Date',
        'Patient Name',
        'Patient Age',
        'Patient Gender',
        'Doctor',
        'Medicines Dispensed',
        'Prescription ID',
        'Dispensed By',
        'Qty',
        'Unit Price',
        'Subtotal',
        'Discount',
        'Total',
        'Status',
    ]);

    // ── Data ──
    $s = $db->prepare("
        SELECT
            i.InvoiceID,
            pr.DatePrescribed,
            p.FullName          AS PatientName,
            p.Age               AS PatientAge,
            p.Gender            AS PatientGender,
            d.FullName          AS DoctorName,
            GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines,
            i.PrescriptionID,
            u.FullName          AS PharmacistName,
            i.DispenseQuantity  AS Qty,
            i.UnitPrice,
            i.Subtotal,
            i.Discount,
            i.Total,
            i.Status
        FROM invoices i
        JOIN prescriptions pr            ON i.PrescriptionID  = pr.PrescriptionID
        JOIN patients p                  ON pr.PatientID       = p.PatientID
        LEFT JOIN users u                ON i.PharmacistID     = u.UserID
        LEFT JOIN doctors d              ON pr.DoctorID        = d.DoctorID
        LEFT JOIN prescriptiondetails pd ON pd.PrescriptionID  = pr.PrescriptionID
        LEFT JOIN medications m          ON pd.MedicationID    = m.MedicationID
        GROUP BY i.InvoiceID
        ORDER BY i.InvoiceID DESC
    ");
    $s->execute();

    $pad = fn($n, $len = 3) => str_pad($n, $len, '0', STR_PAD_LEFT);

    while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            'INV-' . $pad($row['InvoiceID']),
            $row['DatePrescribed'] ?? '—',
            $row['PatientName']    ?? '—',
            $row['PatientAge']     ?? '—',
            $row['PatientGender']  ?? '—',
            $row['DoctorName']     ?? '—',
            $row['Medicines']      ?? '—',
            'RX-' . $pad($row['PrescriptionID']),
            $row['PharmacistName'] ?? '—',
            $row['Qty'],
            number_format((float)$row['UnitPrice'],  2),
            number_format((float)$row['Subtotal'],   2),
            number_format((float)$row['Discount'],   2),
            number_format((float)$row['Total'],      2),
            $row['Status'] === 'Completed' ? 'Paid' : ($row['Status'] ?? 'Pending'),
        ]);
    }

    fclose($out);
    exit;

} catch (Exception $e) {
    header('Location: ../pages/admin.php?backup_error=' . urlencode($e->getMessage()));
    exit;
}