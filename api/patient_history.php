<?php
// api/patient_history.php
// Called by prescriptions.php via AJAX — uses CALL GetPatientHistory(p_PatientID)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$patient_id = (int)($_GET['patient_id'] ?? 0);
if ($patient_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $db = getDB();

    // CALL GetPatientHistory(p_PatientID)
    // Returns: PrescriptionID, DatePrescribed, ExpirationDate, Days_Until_Expiry,
    //          Prescription_Status, Doctor_Name, Doctor_License, GenericName,
    //          BrandName, DosageStrength, QuantityPrescribed, Directions, Dispensing_Status
    $s = $db->prepare("CALL GetPatientHistory(?)");
    $s->execute([$patient_id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $s->closeCursor();

    echo json_encode($rows);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}