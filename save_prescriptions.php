<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

/* Only accept POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

/* Read JSON body */
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

/* Validate required fields */
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

    /* 1. Insert prescription */
    $stmt = $db->prepare("
        INSERT INTO prescriptions 
            (PatientName, PatientAge, PatientGender, DoctorID, MedicalCondition, ContactInfo, PrescriptionDate, ValidUntil, Status)
        VALUES 
            (:name, :age, :gender, :doctor_id, :condition, :contact, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 'Active')
    ");
    $stmt->execute([
        ':name'      => trim($data['patient_name']),
        ':age'       => (int) $data['patient_age'],
        ':gender'    => $data['patient_gender'],
        ':doctor_id' => (int) $data['doctor_id'],
        ':condition' => trim($data['medical_condition'] ?? ''),
        ':contact'   => trim($data['contact'] ?? ''),
    ]);

    $prescriptionId = $db->lastInsertId();

    /* 2. Insert prescription items & deduct stock */
    $itemStmt = $db->prepare("
        INSERT INTO prescriptionitems (PrescriptionID, MedicationID, Quantity)
        VALUES (:prescription_id, :medication_id, :quantity)
    ");

    $stockStmt = $db->prepare("
        UPDATE medicationdetails
        SET StockAvailability = StockAvailability - :qty
        WHERE MedicationID = :med_id
        AND StockAvailability >= :qty
        LIMIT 1
    ");

    foreach ($data['medicines'] as $med) {
        $medId = (int) $med['medication_id'];
        $qty   = max(1, (int) $med['quantity']);

        /* Insert item */
        $itemStmt->execute([
            ':prescription_id' => $prescriptionId,
            ':medication_id'   => $medId,
            ':quantity'        => $qty,
        ]);

        /* Deduct stock */
        $stockStmt->execute([
            ':qty'    => $qty,
            ':med_id' => $medId,
        ]);
    }

    $db->commit();

    echo json_encode([
        'success'         => true,
        'prescription_id' => $prescriptionId,
        'message'         => 'Prescription saved successfully.'
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}