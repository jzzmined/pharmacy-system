<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Prescriptions';

try {
    $db = getDB();

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
            md.StockAvailability
        FROM medicationdetails md
        JOIN medications m ON md.MedicationID = m.MedicationID
        WHERE md.StockAvailability > 0
        ORDER BY m.GenericName ASC
    ");
    $s->execute();
    $medications = $s->fetchAll();

    $s = $db->prepare("SELECT DoctorID, FullName AS DoctorName FROM doctors ORDER BY DoctorName ASC");
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

$success = '';
$error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_prescription') {
    try {
        $db = getDB();
        $db->beginTransaction();

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

        if ($patient_id_existing > 0) {
            $patient_id = $patient_id_existing;
            $s = $db->prepare("UPDATE patients SET FullName=?, Age=?, Gender=?, MedicalConditions=?, ContactInfo=? WHERE PatientID=?");
            $s->execute([$full_name, $age, $gender, $condition, $contact, $patient_id]);
        } else {
            $s = $db->prepare("INSERT INTO patients (FullName, Age, Gender, MedicalConditions, ContactInfo) VALUES (?,?,?,?,?)");
            $s->execute([$full_name, $age, $gender, $condition, $contact]);
            $patient_id = $db->lastInsertId();
        }

        $s = $db->prepare("INSERT INTO prescriptions (PatientID, DoctorID, DatePrescribed, ExpirationDate) VALUES (?,?,CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH))");
        $s->execute([$patient_id, $doctor_id ?: null]);
        $prescription_id = $db->lastInsertId();

        $subtotal     = 0;
        $dispense_qty = 0;
        foreach ($med_ids as $i => $med_detail_id) {
            $qty = max(1, (int)($quantities[$i] ?? 1));
            $dispense_qty += $qty;

            $s = $db->prepare("SELECT md.StockAvailability, md.MedicationID, md.UnitPrice FROM medicationdetails md WHERE MedDet = ?");
            $s->execute([$med_detail_id]);
            $med = $s->fetch();
            if (!$med) continue;
            if ($med['StockAvailability'] < $qty) {
                throw new Exception("Insufficient stock for medication ID $med_detail_id.");
            }

            $subtotal += $qty * $med['UnitPrice'];

            $s = $db->prepare("INSERT INTO prescriptiondetails (PrescriptionID, MedicationID, QuantityPrescribed, Directions) VALUES (?,?,?,'')");
            $s->execute([$prescription_id, $med['MedicationID'], $qty]);

            $s = $db->prepare("UPDATE medicationdetails SET StockAvailability = StockAvailability - ? WHERE MedDet = ?");
            $s->execute([$qty, $med_detail_id]);
        }

        $unit_price = $dispense_qty > 0 ? round($subtotal / $dispense_qty, 2) : 0;
        $s = $db->prepare("INSERT INTO invoices (PrescriptionID, PharmacistID, DispenseQuantity, UnitPrice, Discount, Subtotal, Total, Status) VALUES (?,?,?,?,0,?,?,'Pending')");
        $s->execute([$prescription_id, $_SESSION['user_id'] ?? 1, $dispense_qty, $unit_price, $subtotal, $subtotal]);

        $db->commit();
        $success = "Prescription #RX-" . str_pad($prescription_id, 3, '0', STR_PAD_LEFT) . " created successfully!";

    } catch (Exception $e) {
        if (isset($db)) $db->rollBack();
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
</head>
<body>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<div class="app-layout">

    <!-- ══ SIDEBAR ══ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8"  y1="12" x2="16" y2="12"/>
                </svg>
            </div>
            <span class="brand-name">Pharma<br>Care</span>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item" data-label="Dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
            </a>
            <a href="prescriptions.php" class="nav-item active" data-label="Prescriptions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="12" y2="16"/>
                </svg>
            </a>
            <a href="transactions.php" class="nav-item" data-label="Transactions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <circle cx="17" cy="17" r="4"/>
                    <polyline points="17 15 17 17 18.5 18.5"/>
                </svg>
            </a>
            <a href="inventory.php" class="nav-item" data-label="Inventory">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8h1a4 4 0 0 1 0 8h-1"/>
                    <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/>
                    <line x1="6" y1="1" x2="6" y2="4"/>
                    <line x1="10" y1="1" x2="10" y2="4"/>
                    <line x1="14" y1="1" x2="14" y2="4"/>
                    <circle cx="18" cy="18" r="3"/>
                    <line x1="18" y1="16" x2="18" y2="20"/>
                    <line x1="16" y1="18" x2="20" y2="18"/>
                </svg>
            </a>
            <a href="admin.php" class="nav-item" data-label="Admin">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <circle cx="19" cy="19" r="3"/>
                    <path d="M19 16v2M16 19h2M22 19h2M17.1 17.1l1.4 1.4M17.1 20.9l1.4-1.4"/>
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
                            <div class="rx-field">
                                <input class="rx-input" type="text" name="full_name" id="full_name" placeholder="Full Name" required>
                            </div>
                            <div class="rx-field">
                                <input class="rx-input" type="number" name="age" id="age" placeholder="Age" min="0" max="120">
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
                                <select class="rx-select" name="doctor_id" id="doctor_id">
                                    <option value="" disabled selected>Select Doctor</option>
                                    <?php foreach ($doctors as $d): ?>
                                        <option value="<?= $d['DoctorID'] ?>"><?= htmlspecialchars($d['DoctorName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="rx-field">
                                <input class="rx-input" type="text" name="contact_info" id="contact_info" placeholder="Contact Information">
                            </div>
                            <button type="button" id="btnClearPatient"
                                style="display:none;margin-top:15px;padding:7px 14px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;color:#64748b;font-size:.8rem;cursor:pointer;"
                                onclick="clearPatient()">
                                ✕ Clear &amp; Use New Patient
                            </button>
                        </div>

                        <!-- RIGHT: Search + Info -->
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

                            <div class="rx-patient-info-card" id="patientInfoCard">
                                <h3>
                                    Selected Patient
                                    <span class="pid-badge" id="patientIdBadge">PID-000</span>
                                </h3>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Full Name</span>
                                        <span class="info-value" id="infoName">—</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Age</span>
                                        <span class="info-value" id="infoAge">—</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Gender</span>
                                        <span class="info-value" id="infoGender">—</span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Contact</span>
                                        <span class="info-value" id="infoContact">—</span>
                                    </div>
                                    <div class="info-item full-width">
                                        <span class="info-label">Medical Condition</span>
                                        <span class="info-value" id="infoCondition">—</span>
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
                                            <th>Stock</th>
                                            <th>Expiry</th>
                                            <th>Manufacturer</th>
                                        </tr>
                                    </thead>
                                    <tbody id="medTableBody">
                                    <?php if (empty($medications)): ?>
                                        <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8">No medications available</td></tr>
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
                            <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                                <span style="font-weight:700;color:#1e293b">Total Amount</span>
                                <span style="font-size:1.2rem;font-weight:800;color:#1e293b" id="rv-total">₱0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn-secondary" id="btnBack" style="display:none" onclick="goBack()">← Back</button>
                    <button type="button" class="btn-primary"   id="btnNext"   onclick="goNext()">Next: Select Medicines →</button>
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
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
function showToast(msg, type = 'ok') {
    const tray = document.getElementById('toastTray');
    if (!tray) return;
    const el = document.createElement('div');
    el.className = `toast-msg t-${type}`;
    el.textContent = msg;
    tray.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(16px)';
        el.style.transition = 'all .3s ease';
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

/* ── Patient Search ── */
const patientSearchInput = document.getElementById('patientSearchInput');
const patientSearchBody  = document.getElementById('patientSearchBody');
const patientInfoCard    = document.getElementById('patientInfoCard');
const btnClearPatient    = document.getElementById('btnClearPatient');

patientSearchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    if (!q) {
        patientSearchBody.innerHTML = `<tr><td colspan="5" class="patient-search-empty">Type to search patients…</td></tr>`;
        return;
    }
    const matches = ALL_PATIENTS.filter(p =>
        String(p.PatientID).includes(q) ||
        (p.FullName || '').toLowerCase().includes(q)
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
    document.getElementById('patientIdBadge').textContent  = 'PID-' + String(pid).padStart(3, '0');
    document.getElementById('infoName').textContent        = name      || '—';
    document.getElementById('infoAge').textContent         = age       || '—';
    document.getElementById('infoGender').textContent      = gender    || '—';
    document.getElementById('infoContact').textContent     = contact   || '—';
    document.getElementById('infoCondition').textContent   = condition || '—';
    patientInfoCard.classList.add('visible');
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
    showToast(`Patient "${name}" selected`, 'ok');
}

function clearPatient() {
    document.getElementById('full_name').value         = '';
    document.getElementById('age').value               = '';
    document.getElementById('medical_condition').value = '';
    document.getElementById('contact_info').value      = '';
    document.getElementById('gender').selectedIndex    = 0;
    document.getElementById('existingPatientId').value = '0';
    patientInfoCard.classList.remove('visible');
    btnClearPatient.style.display = 'none';
    patientSearchInput.value = '';
    patientSearchBody.innerHTML = `<tr><td colspan="5" class="patient-search-empty">Type to search patients…</td></tr>`;
    document.querySelectorAll('.patient-row').forEach(r => r.classList.remove('is-selected'));
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
    if (!document.getElementById('full_name').value.trim()) { showToast('Please enter the patient\'s full name.', 'warn'); return; }
    if (Object.keys(selected).length === 0) { showToast('Please select at least one medicine.', 'warn'); return; }
    buildReview();
    step1El.style.display = 'none'; step2El.style.display = 'block';
    btnBack.style.display = 'inline-flex'; btnNext.style.display = 'none'; btnSubmit.style.display = 'inline-flex';
    currentStep = 2;
    s1ind.className = 'rx-step done'; s2ind.className = 'rx-step active'; s3ind.className = 'rx-step active';
    line1.classList.add('done'); line2.classList.add('done');
}

function goBack() {
    step2El.style.display = 'none'; step1El.style.display = 'block';
    btnBack.style.display = 'none'; btnNext.style.display = 'inline-flex'; btnSubmit.style.display = 'none';
    currentStep = 1;
    s1ind.className = 'rx-step active'; s2ind.className = 'rx-step idle'; s3ind.className = 'rx-step idle';
    line1.classList.remove('done'); line2.classList.remove('done');
}

function buildReview() {
    const fields = [
        ['Full Name', document.getElementById('full_name').value],
        ['Age', document.getElementById('age').value || '—'],
        ['Gender', document.getElementById('gender').value || '—'],
        ['Medical Condition', document.getElementById('medical_condition').value || '—'],
        ['Contact', document.getElementById('contact_info').value || '—'],
    ];
    document.getElementById('rv-patient').innerHTML = fields.map(([k, v]) =>
        `<div class="review-row"><span class="review-key">${esc(k)}</span><span class="review-value">${esc(v)}</span></div>`
    ).join('');
    let total = 0;
    document.getElementById('rv-meds').innerHTML = Object.values(selected).map(s => {
        const line = s.price * s.qty; total += line;
        return `<div class="review-row">
            <span class="review-key">${esc(s.name)} <span style="font-size:.75rem;color:#94a3b8">${esc(s.dose)}</span></span>
            <span class="review-value">× ${s.qty} &nbsp; ${fmtPHP(line)}</span>
        </div>`;
    }).join('');
    document.getElementById('rv-total').textContent = fmtPHP(total);
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
    });
});

function renderSelectedList() {
    const card = document.getElementById('selectedCard');
    const list = document.getElementById('selectedList');
    const ids  = Object.keys(selected);
    if (!ids.length) { card.style.display = 'none'; return; }
    card.style.display = 'block';
    list.innerHTML = ids.map(id => {
        const s = selected[id];
        return `<div class="sel-item">
            <div><div class="sel-name">${esc(s.name)}</div><div class="sel-dose">${esc(s.dose)}</div></div>
            <div class="sel-qty">
                <button type="button" class="sel-qty-btn" onclick="adjustQty('${id}', -1)">−</button>
                <span class="sel-qty-num" id="qty-display-${id}">${s.qty}</span>
                <button type="button" class="sel-qty-btn" onclick="adjustQty('${id}', 1)">+</button>
            </div>
        </div>`;
    }).join('');
    syncQtyInputs();
}

function adjustQty(id, delta) {
    if (!selected[id]) return;
    const max = parseInt(document.querySelector(`tr[data-id="${id}"]`)?.dataset.stock || 999);
    selected[id].qty = Math.max(1, Math.min(selected[id].qty + delta, max));
    const el = document.getElementById('qty-display-' + id);
    if (el) el.textContent = selected[id].qty;
    syncQtyInputs();
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