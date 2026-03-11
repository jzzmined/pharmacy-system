<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthenticated.']);
    exit;
}

header('Content-Type: application/json');



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

$required = ['patient_name', 'patient_age', 'patient_gender', 'doctor_id', 'medicines'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
        exit;
    }
}

if (!is_array($data['medicines']) || count($data['medicines']) === 0) {
    echo json_encode(['success' => false, 'message' => 'No medicines selected.']);
    exit;
}

try {
    $db = getDB();
    $db->beginTransaction();

    /* 1. Insert patient */
    $patientStmt = $db->prepare("
        INSERT INTO patients (FullName, Age, Gender, ContactInfo, MedicalConditions)
        VALUES (?, ?, ?, ?, ?)
    ");
    $patientStmt->execute([
        trim($data['patient_name']),
        (int) $data['patient_age'],
        $data['patient_gender'],
        trim($data['contact'] ?? ''),
        trim($data['medical_condition'] ?? ''),
    ]);

    $patientId = $db->lastInsertId();

    /* 2. Build medicines JSON */
    $medicinesJson = json_encode(
        array_map(fn($med) => [
            'medication_id' => (int) $med['medication_id'],
            'quantity'      => max(1, (int) $med['quantity'])
        ], $data['medicines'])
    );

    /* 3. Insert prescription */
    $rxStmt = $db->prepare("
        INSERT INTO prescriptions (DatePrescribed, ExpirationDate, DoctorID, PatientID, Medicines)
        VALUES (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, ?, ?)
    ");
    $rxStmt->execute([
        (int) $data['doctor_id'],
        $patientId,
        $medicinesJson,
    ]);

    $prescriptionId = $db->lastInsertId();

    /* 4. Deduct stock for each medicine */
    foreach ($data['medicines'] as $med) {
        $qty   = max(1, (int) $med['quantity']);
        $medId = (int) $med['medication_id'];
        $db->prepare("
            UPDATE medicationdetails
            SET StockAvailability = StockAvailability - ?
            WHERE MedicationID = ? AND StockAvailability >= ?
            LIMIT 1
        ")->execute([$qty, $medId, $qty]);
    }

    $db->commit();

    echo json_encode([
        'success'         => true,
        'prescription_id' => $prescriptionId,
        'message'         => 'Prescription saved successfully.'
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}