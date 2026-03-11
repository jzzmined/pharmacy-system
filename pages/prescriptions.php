<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Prescriptions';

try {
    $db = getDB();

    // ── Medications: use GetMedicationStockLevel() function for live stock ──
    $s = $db->prepare("
        SELECT
            md.MedDet,
            md.UnitPrice,
            m.MedicationID,
            m.GenericName,
            m.BrandName,
            m.DosageStrength,
            md.Manufacturer,
            md.ExpirationDate,
            GetMedicationStockLevel(m.MedicationID) AS StockAvailability
        FROM medicationdetails md
        JOIN medications m ON md.MedicationID = m.MedicationID
        WHERE GetMedicationStockLevel(m.MedicationID) > 0
        ORDER BY m.GenericName ASC
    ");
    $s->execute();
    $medications = $s->fetchAll();

    $s = $db->prepare("SELECT DoctorID, FullName AS DoctorName, GetTotalPrescriptionsByDoctor(DoctorID) AS TotalRx FROM doctors ORDER BY DoctorName ASC");
    $s->execute();
    $doctors = $s->fetchAll();

    $s = $db->prepare("SELECT PatientID, FullName, Age, Gender, ContactInfo, MedicalConditions FROM patients ORDER BY FullName ASC");
    $s->execute();
    $patients = $s->fetchAll();

} catch (PDOException $e) {
    $medications = [];
    $doctors     = [];
    $patients    = [];
}

/* ══════════════════════════════════════════════════════════
   POST HANDLER — uses DB stored procedures & functions
   ══════════════════════════════════════════════════════════ */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_prescription') {
    try {
        $db = getDB();

        $full_name           = trim($_POST['full_name'] ?? '');
        $age                 = (int)($_POST['age'] ?? 0);
        $gender              = $_POST['gender'] ?? 'Other';
        $condition           = trim($_POST['medical_condition'] ?? '');
        $doctor_id           = (int)($_POST['doctor_id'] ?? 0);
        $contact             = trim($_POST['contact_info'] ?? '');
        $patient_id_existing = (int)($_POST['existing_patient_id'] ?? 0);
        $med_ids             = $_POST['med_ids'] ?? [];
        $quantities          = $_POST['quantities'] ?? [];

        if (empty($full_name) || empty($med_ids)) {
            throw new Exception("Patient name and at least one medicine are required.");
        }

        if ($doctor_id <= 0) {
            throw new Exception("Please select a doctor before submitting the prescription.");
        }

        // ── STEP 1: Patient — insert or update ──
        // trg_Age_verification fires automatically on INSERT and will throw if age is invalid
        if ($patient_id_existing > 0) {
            $patient_id = $patient_id_existing;
            $s = $db->prepare("UPDATE patients SET FullName=?, Age=?, Gender=?, MedicalConditions=?, ContactInfo=? WHERE PatientID=?");
            $s->execute([$full_name, $age, $gender, $condition, $contact, $patient_id]);
        } else {
            // Trigger trg_Age_verification fires here — blocks age < 0 or > 120 automatically
            $s = $db->prepare("INSERT INTO patients (FullName, Age, Gender, MedicalConditions, ContactInfo) VALUES (?,?,?,?,?)");
            $s->execute([$full_name, $age, $gender, $condition, $contact]);
            $patient_id = $db->lastInsertId();
        }

        // ── STEP 2: Validate all medicines & collect info BEFORE inserting anything ──
        $date_prescribed = date('Y-m-d');
        $expiration_date = date('Y-m-d', strtotime('+1 month'));
        $med_data        = [];
        $subtotal        = 0;
        $dispense_qty    = 0;

        if (empty($med_ids)) {
            throw new Exception("Please select at least one medicine.");
        }

        foreach ($med_ids as $i => $med_detail_id) {
            $qty = max(1, (int)($quantities[$i] ?? 1));

            $s = $db->prepare("
                SELECT md.MedDet, md.MedicationID, md.UnitPrice,
                       m.GenericName, m.DosageStrength,
                       GetMedicationStockLevel(md.MedicationID) AS LiveStock
                FROM medicationdetails md
                JOIN medications m ON md.MedicationID = m.MedicationID
                WHERE md.MedDet = ?
            ");
            $s->execute([$med_detail_id]);
            $med = $s->fetch();
            if (!$med) continue;

            if ((int)$med['LiveStock'] < $qty) {
                throw new Exception(
                    "Insufficient stock for {$med['GenericName']} {$med['DosageStrength']}. " .
                    "Available: {$med['LiveStock']}, Requested: $qty."
                );
            }

            $med_data[] = [
                'med_id'       => $med['MedicationID'],
                'generic_name' => $med['GenericName'],
                'unit_price'   => (float)$med['UnitPrice'],
                'qty'          => $qty,
            ];
            $subtotal     += $qty * (float)$med['UnitPrice'];
            $dispense_qty += $qty;
        }

        if (empty($med_data)) {
            throw new Exception("No valid medications found. Please try again.");
        }

        // ── STEP 3: CALL CreateNewPrescription() ──
        // Creates ONE prescription + first prescriptiondetails row atomically
        $first = $med_data[0];
        $s = $db->prepare("CALL CreateNewPrescription(?, ?, ?, ?, ?, ?, ?)");
        $s->execute([
            $patient_id, $doctor_id, $date_prescribed, $expiration_date,
            $first['med_id'], $first['qty'], 'Take as directed'
        ]);
        $result = $s->fetch();
        $s->closeCursor();

        if (!$result || !$result['New_Prescription_ID']) {
            throw new Exception("Failed to create prescription record. Please try again.");
        }
        $prescription_id = (int)$result['New_Prescription_ID'];

        // ── STEP 4: Insert remaining medicines as additional prescriptiondetails rows ──
        if (count($med_data) > 1) {
            $ins = $db->prepare("
                INSERT INTO prescriptiondetails (PrescriptionID, MedicationID, QuantityPrescribed, Directions)
                VALUES (?, ?, ?, 'Take as directed')
            ");
            for ($j = 1; $j < count($med_data); $j++) {
                $ins->execute([$prescription_id, $med_data[$j]['med_id'], $med_data[$j]['qty']]);
            }
        }

        // ── STEP 5: CheckPrescriptionValidity() — must be VALID before dispensing ──
        $s = $db->prepare("SELECT CheckPrescriptionValidity(?) AS validity_status");
        $s->execute([$prescription_id]);
        $validity = $s->fetchColumn();

        if ($validity !== 'VALID') {
            throw new Exception(
                "Prescription RX-" . str_pad($prescription_id, 3, '0', STR_PAD_LEFT) .
                " is not valid and cannot be dispensed."
            );
        }

        // ── STEP 6: CalculateSeniorDiscount() — 20% if patient age >= 60 ──
        $s = $db->prepare("SELECT CalculateSeniorDiscount(?, ?) AS discount_amount");
        $s->execute([$patient_id, $subtotal]);
        $discount   = (float)$s->fetchColumn();
        $total      = $subtotal - $discount;
        $unit_price = $dispense_qty > 0 ? round($subtotal / $dispense_qty, 2) : 0;

        // ── STEP 7: CALL DispenseMedication() ──
        // Creates invoice (Status='Pending') + deducts stock FIFO by expiry
        // trg_invoice_expiry_check fires BEFORE INSERT on invoices
        // trg_After_Dispense_Stock_Check fires AFTER UPDATE on medicationdetails
        $pharmacist_id = $_SESSION['user_id'] ?? 1;
        $s = $db->prepare("CALL DispenseMedication(?, ?, ?, ?)");
        $s->execute([$prescription_id, $pharmacist_id, $dispense_qty, $unit_price]);
        $s->closeCursor();

        // ── STEP 8: Sync discount if CalculateSeniorDiscount differs ──
        if ($discount > 0) {
            $s = $db->prepare("
                UPDATE invoices SET Discount=?, Subtotal=?, Total=?
                WHERE PrescriptionID=? ORDER BY InvoiceID DESC LIMIT 1
            ");
            $s->execute([$discount, $subtotal, $total, $prescription_id]);
        }

        $rx_num  = str_pad($prescription_id, 3, '0', STR_PAD_LEFT);
        $success = "✓ Prescription RX-{$rx_num} created and dispensed successfully!" .
                   ($discount > 0 ? " Senior 20% discount of ₱" . number_format($discount, 2) . " applied." : "") .
                   " Invoice is now Pending payment — see Transactions.";
                   ($discount > 0 ? " Senior discount of ₱" . number_format($discount, 2) . " applied." : "");

    } catch (PDOException $e) {
        // Catch trigger errors (SQLSTATE 45000) with user-friendly messages
        $msg = $e->getMessage();
        if (str_contains($msg, 'Insufficient stock'))       $error = "⚠ Transaction blocked: Insufficient stock to complete this transaction.";
        elseif (str_contains($msg, 'prescription has expired')) $error = "⚠ Transaction blocked: The prescription has expired.";
        elseif (str_contains($msg, 'Invalid Age'))          $error = "⚠ Invalid age entered. Please enter an age between 0 and 120.";
        else                                                $error = "Database error: " . $msg;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

function fmtPad($n, $len = 3): string { return str_pad($n, $len, '0', STR_PAD_LEFT); }
function stockClass(int $qty): string {
    if ($qty <= 100) return 'med-crit';
    if ($qty <= 300) return 'med-low';
    return 'med-ok';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Prescriptions</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        /* ── Trigger / Procedure feedback badges ── */
        .rx-result.ok  { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:10px; padding:12px 18px; font-weight:600; }
        .rx-result.err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:12px 18px; font-weight:600; }
        .rx-validity-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .72rem; font-weight: 700; padding: 3px 9px; border-radius: 20px;
        }
        .rx-validity-badge.valid   { background:#dcfce7; color:#166534; }
        .rx-validity-badge.expired { background:#fee2e2; color:#991b1b; }
        .senior-note {
            font-size: .73rem; background: #fef9c3; color: #92400e;
            padding: 5px 10px; border-radius: 6px; margin: 0;
            display: flex; align-items: center; gap: 5px;
            line-height: 1.3; flex-shrink: 0;
        }
        .doctor-rx-count {
            font-size: .72rem; color: #94a3b8; margin-left: 6px;
        }
    </style>
</head>
<body>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<div class="app-layout">

    <!-- ══ SIDEBAR ══ -->
    <aside class="sidebar" id="sidebar">
                <div class="sidebar-brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <rect x="3" y="3" width="18" height="18" rx="3"/>
                    <line x1="12" y1="7" x2="12" y2="17"/>
                    <line x1="7"  y1="12" x2="17" y2="12"/>
                </svg>
            </div>
            <span class="brand-name">Pharma<br>Care<span style="font-size:0.6em;vertical-align:super;margin-left:1px;opacity:0.7;">&#9825;</span></span>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item" data-label="Dashboard">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/>
                    <polyline points="9 21 9 12 15 12 15 21"/>
                </svg>

            </a>
            <a href="prescriptions.php" class="nav-item active" data-label="Prescriptions">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <path d="M9 12h6M9 16h4"/>
                </svg>

            </a>
            <a href="transactions.php" class="nav-item" data-label="Transactions">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <circle cx="12" cy="15" r="3"/>
                    <polyline points="12 13.5 12 15 13 16"/>
                </svg>

            </a>
            <a href="inventory.php" class="nav-item" data-label="Inventory">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="9" width="20" height="6" rx="3"/>
                    <line x1="12" y1="9" x2="12" y2="15"/>
                    <circle cx="7" cy="12" r="2.5" fill="currentColor" stroke="none" opacity="0.3"/>
                </svg>

            </a>
            <a href="admin.php" class="nav-item" data-label="Admin">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="3"/>
                    <path d="M5 20a7 7 0 0 1 14 0"/>
                    <circle cx="19" cy="19" r="2"/>
                    <path d="M19 15v2M19 21v1M15.5 17l1.5 1M22.5 21l-1.5-1M15.5 21l1.5-1M22.5 17l-1.5 1"/>
                </svg>

            </a>
        </nav>
        <a href="../logout.php" class="sidebar-footer" onclick="return confirm('Log out?')" title="Logout">
            <div class="s-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'P', 0, 1)) ?></div>
        </a>
    </aside>

    <!-- ══ MAIN ══ -->
    <div class="main-area">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="page-body">

            <?php if ($success): ?>
                <div class="rx-result ok" style="margin-bottom:18px"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="rx-result err" style="margin-bottom:18px"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Step Wizard -->
            <div class="rx-wizard">
                <div class="rx-step active" id="step1-indicator">
                    <div class="step-num">1</div>
                    <span class="step-lbl">Patient Info</span>
                </div>
                <div class="rx-line" id="line1"></div>
                <div class="rx-step idle" id="step2-indicator">
                    <div class="step-num">2</div>
                    <span class="step-lbl">Select Medicines</span>
                </div>
                <div class="rx-line" id="line2"></div>
                <div class="rx-step idle" id="step3-indicator">
                    <div class="step-num">3</div>
                    <span class="step-lbl">Review &amp; Submit</span>
                </div>
            </div>

            <!-- Form -->
            <form method="POST" id="rxForm">
                <input type="hidden" name="action" value="create_prescription">
                <input type="hidden" name="existing_patient_id" id="existingPatientId" value="0">

                <!-- ══ STEP 1 ══ -->
                <div id="step1">
                    <div class="rx-body">

                        <!-- LEFT: Patient Form -->
                        <div class="rx-patient-card">
                            <h3>Patient Information</h3>
                            <!-- Senior discount note — shown by JS when age >= 60 -->
                            <div id="seniorNote" class="senior-note" style="display:none">
                                🏷 Senior Citizen — 20% discount will be applied automatically
                            </div>
                            <div class="rx-field">
                                <input class="rx-input" type="text" name="full_name" id="full_name" placeholder="Full Name" required>
                            </div>
                            <div class="rx-field">
                                <input class="rx-input" type="number" name="age" id="age" placeholder="Age (0–120)" min="0" max="120"
                                    oninput="checkSenior(this.value)">
                                <!-- trg_Age_verification: age must be 0–120 or DB blocks the INSERT -->
                            </div>
                            <div class="rx-field">
                                <select class="rx-select" name="gender" id="gender">
                                    <option value="" disabled selected>Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="rx-field">
                                <input class="rx-input" type="text" name="medical_condition" id="medical_condition" placeholder="Medical Condition">
                            </div>
                            <div class="rx-field">
                                <select class="rx-select" name="doctor_id" id="doctor_id" required>
                                    <option value="" disabled selected>Select Doctor</option>
                                    <?php foreach ($doctors as $d): ?>
                                        <option value="<?= $d['DoctorID'] ?>">
                                            <?= htmlspecialchars($d['DoctorName']) ?>
                                            <span>(<?= (int)$d['TotalRx'] ?> Rx)</span>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <!-- GetTotalPrescriptionsByDoctor() shown next to each doctor -->
                            </div>
                            <div class="rx-field">
                                <input class="rx-input" type="text" name="contact_info" id="contact_info" placeholder="Contact Information">
                            </div>
                            <button type="button" id="btnClearPatient"
                                style="display:none;margin-top:auto;padding:7px 14px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;color:#64748b;font-size:.8rem;cursor:pointer;flex-shrink:0;"
                                onclick="clearPatient()">
                                ✕ Clear &amp; Use New Patient
                            </button>
                        </div>

                        <!-- RIGHT: Search + Patient History -->
                        <div class="rx-right-col">
                            <div class="rx-search-card">
                                <h3>Search Existing Patient</h3>
                                <input
                                    class="patient-search-input"
                                    type="text"
                                    id="patientSearchInput"
                                    placeholder="Search by name or Patient ID…"
                                    autocomplete="off"
                                >
                                <div class="patient-search-table-wrap">
                                    <table class="patient-search-table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Full Name</th>
                                                <th>Age</th>
                                                <th>Gender</th>
                                                <th>Condition</th>
                                            </tr>
                                        </thead>
                                        <tbody id="patientSearchBody">
                                            <tr>
                                                <td colspan="5" class="patient-search-empty">Type to search patients…</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Patient History card — populated via GetPatientHistory() AJAX -->
                            <div id="patientHistoryCard" style="display:none;margin-top:16px;">
                                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                                    <div style="background:#1e293b;color:#fff;padding:10px 16px;font-size:.85rem;font-weight:700;display:flex;align-items:center;justify-content:space-between;">
                                        <span>📋 Patient Prescription History</span>
                                        <span id="historyPatientName" style="font-size:.78rem;color:#94a3b8"></span>
                                    </div>
                                    <div style="overflow-x:auto;">
                                        <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                                            <thead>
                                                <tr style="background:#f8fafc;">
                                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">RX ID</th>
                                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Date</th>
                                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Medicine</th>
                                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Doctor</th>
                                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Status</th>
                                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;">Dispensed</th>
                                                </tr>
                                            </thead>
                                            <tbody id="patientHistoryBody">
                                                <tr><td colspan="6" style="text-align:center;padding:14px;color:#94a3b8">Loading…</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div><!-- /rx-right-col -->
                    </div><!-- /rx-body -->

                    <!-- Medicine Selection -->
                    <div style="margin-top:32px;display:flex;flex-direction:column;gap:16px;">
                        <div class="rx-med-card">
                            <div class="rx-med-header">
                                <h3>Select Medicines</h3>
                                <span style="font-size:.78rem;color:#94a3b8;margin-left:8px">Stock shown via GetMedicationStockLevel()</span>
                            </div>
                            <div class="rx-search-row">
                                <input class="rx-search-input" type="text" id="medSearchInput" placeholder="Search medications…" autocomplete="off">
                            </div>
                            <div class="rx-med-table-wrap">
                                <table class="rx-med-table">
                                    <thead>
                                        <tr>
                                            <th>✓</th>
                                            <th>Generic Name</th>
                                            <th>Brand</th>
                                            <th>Live Stock</th>
                                            <th>Unit Price</th>
                                            <th>Expiry</th>
                                            <th>Manufacturer</th>
                                        </tr>
                                    </thead>
                                    <tbody id="medTableBody">
                                    <?php if (empty($medications)): ?>
                                        <tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8">No medications available</td></tr>
                                    <?php else: foreach ($medications as $m):
                                        $qty = (int)$m['StockAvailability'];
                                        $sc  = stockClass($qty);
                                    ?>
                                        <tr class="med-row"
                                            data-search="<?= strtolower($m['GenericName'] . ' ' . $m['BrandName'] . ' ' . $m['Manufacturer']) ?>"
                                            data-id="<?= $m['MedDet'] ?>"
                                            data-name="<?= htmlspecialchars($m['GenericName']) ?>"
                                            data-dose="<?= htmlspecialchars($m['DosageStrength']) ?>"
                                            data-price="<?= number_format((float)$m['UnitPrice'], 2, '.', '') ?>"
                                            data-stock="<?= $qty ?>">
                                            <td>
                                                <input class="rx-check med-checkbox" type="checkbox"
                                                    name="med_ids[]"
                                                    value="<?= $m['MedDet'] ?>"
                                                    <?= $qty <= 0 ? 'disabled' : '' ?>>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($m['GenericName']) ?></strong>
                                                <span style="font-size:.75rem;color:#94a3b8;margin-left:4px"><?= htmlspecialchars($m['DosageStrength']) ?></span>
                                            </td>
                                            <td style="color:#64748b"><?= htmlspecialchars($m['BrandName']) ?></td>
                                            <td class="<?= $sc ?>"><?= $qty ?></td>
                                            <td style="font-weight:600;color:#1e293b">₱<?= number_format((float)$m['UnitPrice'], 2) ?></td>
                                            <td style="font-size:.8rem;color:#94a3b8"><?= htmlspecialchars($m['ExpirationDate']) ?></td>
                                            <td style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($m['Manufacturer'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rx-selected-card" id="selectedCard" style="display:none">
                            <h4>Selected Medicines</h4>
                            <div id="selectedList"></div>
                            <div id="liveTotal" style="display:none">
                                <span id="liveTotalAmt" style="display:none"></span>
                                <span id="seniorDiscPreview" style="display:none"></span>
                            </div>
                        </div>
                    </div>

                </div><!-- /step1 -->

                <!-- ══ STEP 2: Review ══ -->
                <div id="step2" style="display:none">
                    <div class="rx-review-wrap">
                        <div class="rx-review-card">
                            <h3>Patient Details</h3>
                            <div id="rv-patient"></div>
                        </div>
                        <div class="rx-review-card">
                            <h3>Medicines &amp; Total</h3>
                            <div id="rv-meds"></div>
                            <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;">
                                <div style="display:flex;justify-content:space-between;align-items:center">
                                    <span style="font-weight:600;color:#64748b">Subtotal</span>
                                    <span id="rv-subtotal">₱0.00</span>
                                </div>
                                <div id="rv-discount-row" style="display:none;justify-content:space-between;align-items:center;margin-top:4px">
                                    <span style="font-weight:600;color:#d97706">Senior Discount (20%)</span>
                                    <span id="rv-discount" style="color:#d97706">−₱0.00</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9">
                                    <span style="font-weight:700;color:#1e293b">Total Amount</span>
                                    <span style="font-size:1.2rem;font-weight:800;color:#1e293b" id="rv-total">₱0.00</span>
                                </div>
                            </div>
                            <!-- DB Routines notice -->
                            <div style="margin-top:12px;padding:8px 12px;background:#f0f4ff;border-radius:8px;font-size:.75rem;color:#6366f1">
                                ℹ️ On submit: <strong>CreateNewPrescription</strong> → <strong>CheckPrescriptionValidity</strong> → <strong>CalculateSeniorDiscount</strong> → <strong>DispenseMedication</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary" id="btnBack" style="display:none" onclick="goBack()">← Back</button>
                    <button type="button" class="btn-primary"   id="btnNext"   onclick="goNext()">Review & Submit →</button>
                    <button type="submit"  class="btn-primary"  id="btnSubmit" style="display:none">✓ Confirm &amp; Create Prescription</button>
                </div>

            </form>

        </div>
    </div>
</div>

<div class="toast-tray" id="toastTray"></div>

<script>
'use strict';

const ALL_PATIENTS = <?= json_encode(array_values($patients)) ?>;

function fmtPHP(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function esc(s) {
    const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML;
}
function showToast(msg, type = 'ok') {
    const tray = document.getElementById('toastTray');
    if (!tray) return;
    const el = document.createElement('div');
    el.className = `toast-msg t-${type}`;
    el.textContent = msg;
    tray.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0'; el.style.transform = 'translateX(16px)'; el.style.transition = 'all .3s ease';
        setTimeout(() => el.remove(), 300);
    }, 3200);
}

const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarToggle  = document.getElementById('sidebarToggle');
if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('show'); });
}
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); });
}

/* ── Senior Citizen Detection (CalculateSeniorDiscount preview) ── */
function checkSenior(val) {
    const age = parseInt(val);
    const note = document.getElementById('seniorNote');
    const discPreview = document.getElementById('seniorDiscPreview');
    if (age >= 60) {
        note.style.display = 'inline-block';
        if (discPreview) discPreview.style.display = 'inline';
    } else {
        note.style.display = 'none';
        if (discPreview) discPreview.style.display = 'none';
    }
    updateLiveTotal();
}

/* ── Patient Search ── */
const patientSearchInput = document.getElementById('patientSearchInput');
const patientSearchBody  = document.getElementById('patientSearchBody');
const btnClearPatient    = document.getElementById('btnClearPatient');

patientSearchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    if (!q) {
        patientSearchBody.innerHTML = `<tr><td colspan="5" class="patient-search-empty">Type to search patients…</td></tr>`;
        return;
    }
    const matches = ALL_PATIENTS.filter(p =>
        String(p.PatientID).includes(q) || (p.FullName || '').toLowerCase().includes(q)
    );
    if (!matches.length) {
        patientSearchBody.innerHTML = `<tr><td colspan="5" class="patient-search-empty">No patients found.</td></tr>`;
        return;
    }
    patientSearchBody.innerHTML = matches.map(p => `
        <tr class="patient-row"
            data-pid="${p.PatientID}"
            data-name="${esc(p.FullName)}"
            data-age="${p.Age ?? ''}"
            data-gender="${esc(p.Gender ?? '')}"
            data-contact="${esc(p.ContactInfo ?? '')}"
            data-condition="${esc(p.MedicalConditions ?? '')}"
            onclick="selectPatient(this)">
            <td>PID-${String(p.PatientID).padStart(3,'0')}</td>
            <td>${esc(p.FullName)}</td>
            <td>${p.Age ?? '—'}</td>
            <td>${esc(p.Gender ?? '—')}</td>
            <td>${esc(p.MedicalConditions ?? '—')}</td>
        </tr>
    `).join('');
});

function selectPatient(row) {
    document.querySelectorAll('.patient-row').forEach(r => r.classList.remove('is-selected'));
    row.classList.add('is-selected');
    const pid = row.dataset.pid, name = row.dataset.name, age = row.dataset.age,
          gender = row.dataset.gender, contact = row.dataset.contact, condition = row.dataset.condition;

    document.getElementById('full_name').value         = name;
    document.getElementById('age').value               = age;
    document.getElementById('medical_condition').value = condition;
    document.getElementById('contact_info').value      = contact;
    const genderEl = document.getElementById('gender');
    for (let i = 0; i < genderEl.options.length; i++) {
        if (genderEl.options[i].value === gender) { genderEl.selectedIndex = i; break; }
    }
    document.getElementById('existingPatientId').value = pid;
    btnClearPatient.style.display = 'block';
    checkSenior(age);
    showToast(`Patient "${name}" selected`, 'ok');

    // ── Load patient history via GetPatientHistory() AJAX ──
    loadPatientHistory(pid, name);
}

function clearPatient() {
    document.getElementById('full_name').value         = '';
    document.getElementById('age').value               = '';
    document.getElementById('medical_condition').value = '';
    document.getElementById('contact_info').value      = '';
    document.getElementById('gender').selectedIndex    = 0;
    document.getElementById('existingPatientId').value = '0';
    btnClearPatient.style.display = 'none';
    patientSearchInput.value = '';
    patientSearchBody.innerHTML = `<tr><td colspan="5" class="patient-search-empty">Type to search patients…</td></tr>`;
    document.querySelectorAll('.patient-row').forEach(r => r.classList.remove('is-selected'));
    document.getElementById('patientHistoryCard').style.display = 'none';
    document.getElementById('seniorNote').style.display = 'none';
}

/* ── GetPatientHistory() — AJAX fetch ── */
function loadPatientHistory(patientId, patientName) {
    const card = document.getElementById('patientHistoryCard');
    const body = document.getElementById('patientHistoryBody');
    const nameEl = document.getElementById('historyPatientName');
    card.style.display = 'block';
    nameEl.textContent = patientName;
    body.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:14px;color:#94a3b8">Loading history…</td></tr>`;

    fetch(`../api/patient_history.php?patient_id=${patientId}`)
        .then(r => r.json())
        .then(rows => {
            if (!rows.length) {
                body.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:14px;color:#94a3b8">No prescription history found.</td></tr>`;
                return;
            }
            body.innerHTML = rows.map(r => {
                const statusCls = r.Prescription_Status === 'VALID' ? 'color:#16a34a;font-weight:700' : 'color:#dc2626;font-weight:700';
                const dispCls   = r.Dispensing_Status === 'DISPENSED' ? 'color:#6366f1;font-weight:600' : 'color:#94a3b8';
                return `<tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:7px 10px;font-family:monospace;color:#6366f1">RX-${String(r.PrescriptionID).padStart(3,'0')}</td>
                    <td style="padding:7px 10px">${esc(r.DatePrescribed)}</td>
                    <td style="padding:7px 10px">${esc(r.GenericName)} <span style="color:#94a3b8;font-size:.75rem">${esc(r.DosageStrength)}</span></td>
                    <td style="padding:7px 10px">${esc(r.Doctor_Name)}</td>
                    <td style="padding:7px 10px;${statusCls}">${esc(r.Prescription_Status)}</td>
                    <td style="padding:7px 10px;${dispCls}">${esc(r.Dispensing_Status)}</td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            body.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:14px;color:#dc2626">Failed to load history.</td></tr>`;
        });
}

/* ── Wizard ── */
let currentStep = 1;
const selected  = {};
const step1El   = document.getElementById('step1');
const step2El   = document.getElementById('step2');
const btnBack   = document.getElementById('btnBack');
const btnNext   = document.getElementById('btnNext');
const btnSubmit = document.getElementById('btnSubmit');
const s1ind = document.getElementById('step1-indicator');
const s2ind = document.getElementById('step2-indicator');
const s3ind = document.getElementById('step3-indicator');
const line1 = document.getElementById('line1');
const line2 = document.getElementById('line2');

function goNext() {
    if (currentStep !== 1) return;
    const nameVal = document.getElementById('full_name').value.trim();
    const docVal  = document.getElementById('doctor_id').value;
    if (!nameVal)                          { showToast('Please enter the patient\'s full name.', 'warn'); return; }
    if (!docVal)                           { showToast('Please select a doctor.', 'warn'); return; }
    if (Object.keys(selected).length === 0){ showToast('Please select at least one medicine.', 'warn'); return; }
    syncQtyInputs();
    buildReview();
    step1El.style.display = 'none';
    step2El.style.display = 'block';
    btnBack.style.display   = 'inline-flex';
    btnNext.style.display   = 'none';
    btnSubmit.style.display = 'inline-flex';
    currentStep = 2;
    s1ind.className = 'rx-step done';
    s2ind.className = 'rx-step active';
    s3ind.className = 'rx-step active';
    line1.classList.add('done');
    line2.classList.add('done');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBack() {
    step2El.style.display = 'none'; step1El.style.display = 'block';
    btnBack.style.display = 'none'; btnNext.style.display = 'inline-flex'; btnSubmit.style.display = 'none';
    currentStep = 1;
    s1ind.className = 'rx-step active'; s2ind.className = 'rx-step idle'; s3ind.className = 'rx-step idle';
    line1.classList.remove('done'); line2.classList.remove('done');
}

function buildReview() {
    const age = parseInt(document.getElementById('age').value) || 0;
    const isSenior = age >= 60;
    const fields = [
        ['Full Name', document.getElementById('full_name').value],
        ['Age', document.getElementById('age').value || '—'],
        ['Gender', document.getElementById('gender').value || '—'],
        ['Medical Condition', document.getElementById('medical_condition').value || '—'],
        ['Contact', document.getElementById('contact_info').value || '—'],
    ];
    if (isSenior) fields.push(['Senior Discount', '20% (CalculateSeniorDiscount)']);

    document.getElementById('rv-patient').innerHTML = fields.map(([k, v]) =>
        `<div class="review-row"><span class="review-key">${esc(k)}</span><span class="review-value" style="${k==='Senior Discount'?'color:#d97706;font-weight:700':''}">${esc(v)}</span></div>`
    ).join('');

    let subtotal = 0;
    document.getElementById('rv-meds').innerHTML = Object.values(selected).map(s => {
        const line = s.price * s.qty; subtotal += line;
        return `<div class="review-row">
            <span class="review-key">${esc(s.name)} <span style="font-size:.75rem;color:#94a3b8">${esc(s.dose)}</span></span>
            <span class="review-value">× ${s.qty} &nbsp; ${fmtPHP(line)}</span>
        </div>`;
    }).join('');

    const discount = isSenior ? subtotal * 0.20 : 0;
    const total    = subtotal - discount;
    document.getElementById('rv-subtotal').textContent = fmtPHP(subtotal);
    document.getElementById('rv-total').textContent    = fmtPHP(total);
    const discRow = document.getElementById('rv-discount-row');
    if (discount > 0) {
        discRow.style.display = 'flex';
        document.getElementById('rv-discount').textContent = '−' + fmtPHP(discount);
    } else {
        discRow.style.display = 'none';
    }
}

/* ── Medicine checkboxes ── */
document.querySelectorAll('.med-checkbox').forEach(cb => {
    cb.addEventListener('change', function () {
        const row = this.closest('tr'), id = this.value;
        if (this.checked) {
            row.classList.add('is-selected');
            selected[id] = { name: row.dataset.name, dose: row.dataset.dose, price: parseFloat(row.dataset.price) || 0, qty: 1 };
        } else {
            row.classList.remove('is-selected');
            delete selected[id];
        }
        renderSelectedList();
        updateLiveTotal();
    });
});

function updateLiveTotal() {
    const age = parseInt(document.getElementById('age').value) || 0;
    const isSenior = age >= 60;
    let subtotal = Object.values(selected).reduce((sum, s) => sum + s.price * s.qty, 0);
    const discount = isSenior ? subtotal * 0.20 : 0;
    const total = subtotal - discount;
    const liveTotalEl = document.getElementById('liveTotal');
    const liveTotalAmt = document.getElementById('liveTotalAmt');
    const discPreview  = document.getElementById('seniorDiscPreview');
    if (Object.keys(selected).length > 0) {
        liveTotalEl.style.display = 'flex';
        liveTotalAmt.textContent = fmtPHP(subtotal);
        if (discPreview) {
            discPreview.textContent = isSenior ? `→ After senior discount: ${fmtPHP(total)}` : '';
            discPreview.style.display = isSenior ? 'inline' : 'none';
        }
    } else {
        liveTotalEl.style.display = 'none';
    }
}

function renderSelectedList() {
    const card = document.getElementById('selectedCard');
    const list = document.getElementById('selectedList');
    const ids  = Object.keys(selected);
    if (!ids.length) { card.style.display = 'none'; return; }
    card.style.display = 'block';
    list.innerHTML = ids.map(id => {
        const s = selected[id];
        const max = document.querySelector(`tr[data-id="${id}"]`)?.dataset.stock || 999;
        return `<div class="sel-item" style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f1f5f9;">
            <div>
                <div class="sel-name" style="font-weight:600;color:#1e293b;font-size:.9rem;">${esc(s.name)}</div>
                <div class="sel-dose" style="font-size:.75rem;color:#94a3b8;">${esc(s.dose)}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" onclick="adjustQty('${id}', -1)"
                    style="width:28px;height:28px;border-radius:6px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#334155;font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">−</button>
                <span id="qty-display-${id}" style="min-width:24px;text-align:center;font-weight:700;font-size:.95rem;color:#1e293b;">${s.qty}</span>
                <button type="button" onclick="adjustQty('${id}', 1)"
                    style="width:28px;height:28px;border-radius:6px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#334155;font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">+</button>
                <button type="button" onclick="removeMed('${id}')"
                    style="width:28px;height:28px;border-radius:6px;border:1.5px solid #fecaca;background:#fff5f5;color:#ef4444;font-size:.85rem;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-left:4px;">✕</button>
            </div>
        </div>`;
    }).join('');
    syncQtyInputs();
}

function removeMed(id) {
    delete selected[id];
    const cb = document.querySelector(`.med-checkbox[value="${id}"]`);
    if (cb) { cb.checked = false; cb.closest('tr')?.classList.remove('is-selected'); }
    renderSelectedList();
    updateLiveTotal();
}

function adjustQty(id, delta) {
    if (!selected[id]) return;
    const max = parseInt(document.querySelector(`tr[data-id="${id}"]`)?.dataset.stock || 999);
    selected[id].qty = Math.max(1, Math.min(selected[id].qty + delta, max));
    const el = document.getElementById('qty-display-' + id);
    if (el) el.textContent = selected[id].qty;
    syncQtyInputs();
    updateLiveTotal();
}

function syncQtyInputs() {
    document.querySelectorAll('.qty-hidden').forEach(el => el.remove());
    const form = document.getElementById('rxForm');
    Object.entries(selected).forEach(([id, s]) => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'quantities[]'; inp.value = s.qty; inp.className = 'qty-hidden';
        form.appendChild(inp);
    });
}

document.getElementById('medSearchInput').addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.med-row').forEach(row => {
        row.style.display = !q || row.dataset.search.includes(q) ? '' : 'none';
    });
});
</script>

</body>
</html>