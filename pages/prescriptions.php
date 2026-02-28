<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Prescriptions';

try {
    $db = getDB();

    // Fetch all available medications with stock details
    $s = $db->prepare("
        SELECT
            md.MedDet,
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

    // Fetch doctors for the dropdown
    $s = $db->prepare("SELECT DoctorID, FullName AS DoctorName FROM doctors ORDER BY DoctorName ASC");
    $s->execute();
    $doctors = $s->fetchAll();

} catch (PDOException $e) {
    $medications = [];
    $doctors = [];
}

// Handle form submission
$success = '';
$error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_prescription') {
    try {
        $db = getDB();
        $db->beginTransaction();

        // Insert or find patient
        $full_name   = trim($_POST['full_name'] ?? '');
        $age         = (int)($_POST['age'] ?? 0);
        $gender      = $_POST['gender'] ?? 'Other';
        $condition   = trim($_POST['medical_condition'] ?? '');
        $doctor_id   = (int)($_POST['doctor_id'] ?? 0);
        $contact     = trim($_POST['contact_info'] ?? '');
        $med_ids     = $_POST['med_ids'] ?? [];
        $quantities  = $_POST['quantities'] ?? [];

        if (empty($full_name) || empty($med_ids)) {
            throw new Exception("Patient name and at least one medicine are required.");
        }

        // Insert patient
        $s = $db->prepare("INSERT INTO patients (FullName, Age, Gender, MedicalCondition, ContactInformation) VALUES (?,?,?,?,?)");
        $s->execute([$full_name, $age, $gender, $condition, $contact]);
        $patient_id = $db->lastInsertId();

        // Insert prescription
        $s = $db->prepare("INSERT INTO prescriptions (PatientID, DoctorID, DatePrescribed, PharmacistID) VALUES (?,?,CURDATE(),?)");
        $s->execute([$patient_id, $doctor_id ?: null, $_SESSION['user_id'] ?? 1]);
        $prescription_id = $db->lastInsertId();

        // Insert prescription details + update stock
        $subtotal = 0;
        foreach ($med_ids as $i => $med_detail_id) {
            $qty = max(1, (int)($quantities[$i] ?? 1));

            // Get unit price and check stock
            $s = $db->prepare("SELECT md.StockAvailability, md.MedicationID FROM medicationdetails md WHERE MedDet = ?");
            $s->execute([$med_detail_id]);
            $med = $s->fetch();
            if (!$med) continue;
            if ($med['StockAvailability'] < $qty) {
                throw new Exception("Insufficient stock for medication ID $med_detail_id.");
            }

            $subtotal += $qty; // UnitPrice not in DB; using qty as placeholder

            $s = $db->prepare("INSERT INTO prescriptiondetails (PrescriptionID, MedicationID, QuantityPrescribed, Directions) VALUES (?,?,?,'')");
            $s->execute([$prescription_id, $med['MedicationID'], $qty]);

            // Deduct stock
            $s = $db->prepare("UPDATE medicationdetails SET StockAvailability = StockAvailability - ? WHERE MedDet = ?");
            $s->execute([$qty, $med_detail_id]);
        }

        // Insert invoice
        $s = $db->prepare("INSERT INTO invoices (PrescriptionID, PharmacistID, Subtotal, Total, Status) VALUES (?,?,?,?,'Pending')");
        $s->execute([$prescription_id, $_SESSION['user_id'] ?? 1, $subtotal, $subtotal]);

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
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8"  y1="12" x2="16" y2="12"/>
                </svg>
            </div>
            <span class="brand-name">Pharma<br>Care</span>
        </div>

        <nav class="sidebar-nav">

            <a href="dashboard.php" class="nav-item" data-label="Dashboard">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
            </a>

            <a href="prescriptions.php" class="nav-item active" data-label="Prescriptions">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <line x1="9" y1="12" x2="15" y2="12"/>
                    <line x1="9" y1="16" x2="12" y2="16"/>
                </svg>
            </a>

            <a href="transactions.php" class="nav-item" data-label="Transactions">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <line x1="2" y1="10" x2="22" y2="10"/>
                </svg>
            </a>

            <a href="inventory.php" class="nav-item" data-label="Inventory">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                </svg>
            </a>

            <a href="admin.php" class="nav-item" data-label="Patients">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </a>

            <!-- <a href="users.php" class="nav-item" data-label="Users">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                </svg>
            </a> -->

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

            <!-- Multi-step Form -->
            <form method="POST" id="rxForm">
                <input type="hidden" name="action" value="create_prescription">

                <!-- ══ STEP 1: Patient Info ══ -->
                <div id="step1" class="rx-body">

                    <!-- Patient Details Card -->
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
                    </div>

                    <!-- Medicine Selection Card -->
                    <div style="display:flex;flex-direction:column;gap:16px;">
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
                                            data-price="0"
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

                        <!-- Selected Medicines Summary -->
                        <div class="rx-selected-card" id="selectedCard" style="display:none">
                            <h4>Selected Medicines</h4>
                            <div id="selectedList"></div>
                        </div>
                    </div>

                </div><!-- /step1 -->

                <!-- ══ STEP 2: Review ══ -->
                <div id="step2" style="display:none">
                    <div class="rx-review-wrap">

                        <!-- Patient summary -->
                        <div class="rx-review-card">
                            <h3>Patient Details</h3>
                            <div id="rv-patient"></div>
                        </div>

                        <!-- Medicine summary -->
                        <div class="rx-review-card">
                            <h3>Medicines &amp; Total</h3>
                            <div id="rv-meds"></div>
                            <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
                                <span style="font-weight:700;color:#1e293b">Total Amount</span>
                                <span style="font-size:1.2rem;font-weight:800;color:#1e293b" id="rv-total">₱0.00</span>
                            </div>
                        </div>

                    </div>
                </div><!-- /step2 -->

                <!-- ── Navigation Buttons ── -->
                <div class="rx-actions" style="margin-top:20px;display:flex;justify-content:flex-end;position:relative;z-index:0;">
                    <button type="button" class="btn-secondary" id="btnBack" style="display:none" onclick="goBack()">← Back</button>
                    <button type="button" class="btn-primary" id="btnNext" onclick="goNext()">Next: Select Medicines →</button>
                    <button type="submit" class="btn-primary" id="btnSubmit" style="display:none">✓ Confirm &amp; Create Prescription</button>
                </div>

            </form>

        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>

<script>
// ── State ──
let currentStep = 1;
const selected = {}; // { medDetailId: { name, dose, price, qty } }

// ── DOM refs ──
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

// ── Wizard nav ──
function goNext() {
    if (currentStep === 1) {
        // Validate patient name
        if (!document.getElementById('full_name').value.trim()) {
            showToast('Please enter the patient\'s full name.', 'warn'); return;
        }
        if (Object.keys(selected).length === 0) {
            showToast('Please select at least one medicine.', 'warn'); return;
        }
        buildReview();
        step1El.style.display = 'none';
        step2El.style.display = 'block';
        btnBack.style.display = 'inline-flex';
        btnNext.style.display = 'none';
        btnSubmit.style.display = 'inline-flex';
        currentStep = 2;
        s1ind.className = 'rx-step done';
        s2ind.className = 'rx-step active';
        s3ind.className = 'rx-step active';
        line1.classList.add('done');
        line2.classList.add('done');
        btnNext.textContent = 'Review & Submit →';
    }
}

function goBack() {
    step2El.style.display = 'none';
    step1El.style.display = 'grid';
    btnBack.style.display = 'none';
    btnNext.style.display = 'inline-flex';
    btnSubmit.style.display = 'none';
    currentStep = 1;
    s1ind.className = 'rx-step active';
    s2ind.className = 'rx-step idle';
    s3ind.className = 'rx-step idle';
    line1.classList.remove('done');
    line2.classList.remove('done');
}

// ── Build review panel ──
function buildReview() {
    // Patient details
    const fields = [
        ['Full Name',          document.getElementById('full_name').value],
        ['Age',                document.getElementById('age').value || '—'],
        ['Gender',             document.getElementById('gender').value || '—'],
        ['Medical Condition',  document.getElementById('medical_condition').value || '—'],
        ['Contact',            document.getElementById('contact_info').value || '—'],
    ];
    const rvPat = document.getElementById('rv-patient');
    rvPat.innerHTML = fields.map(([k,v]) =>
        `<div class="review-row"><span class="review-key">${k}</span><span class="review-value">${v}</span></div>`
    ).join('');

    // Medicines
    let total = 0;
    const rvMeds = document.getElementById('rv-meds');
    const rows = Object.values(selected).map(s => {
        const line = s.price * s.qty;
        total += line;
        return `<div class="review-row">
            <span class="review-key">${s.name} <span style="font-size:.75rem;color:#94a3b8">${s.dose}</span></span>
            <span class="review-value">× ${s.qty} &nbsp; ₱${line.toFixed(2)}</span>
        </div>`;
    });
    rvMeds.innerHTML = rows.join('');
    document.getElementById('rv-total').textContent = '₱' + total.toFixed(2);
}

// ── Checkbox logic ──
document.querySelectorAll('.med-checkbox').forEach(cb => {
    cb.addEventListener('change', function () {
        const row   = this.closest('tr');
        const id    = this.value;
        if (this.checked) {
            row.classList.add('is-selected');
            selected[id] = {
                name:  row.dataset.name,
                dose:  row.dataset.dose,
                price: 0,
                qty:   1,
                input: null,
            };
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

    if (ids.length === 0) { card.style.display = 'none'; return; }
    card.style.display = 'block';

    list.innerHTML = ids.map(id => {
        const s = selected[id];
        return `<div class="sel-item">
            <div>
                <div class="sel-name">${s.name}</div>
                <div class="sel-dose">${s.dose}</div>
            </div>
            <div class="sel-qty">
                <button type="button" class="sel-qty-btn" onclick="adjustQty('${id}', -1)">−</button>
                <span class="sel-qty-num" id="qty-display-${id}">${s.qty}</span>
                <button type="button" class="sel-qty-btn" onclick="adjustQty('${id}', 1)">+</button>
            </div>
        </div>`;
    }).join('');

    // Sync hidden quantity inputs
    syncQtyInputs();
}

function adjustQty(id, delta) {
    if (!selected[id]) return;
    const maxStock = parseInt(document.querySelector(`tr[data-id="${id}"]`)?.dataset.stock || 999);
    selected[id].qty = Math.max(1, Math.min(selected[id].qty + delta, maxStock));
    const display = document.getElementById('qty-display-' + id);
    if (display) display.textContent = selected[id].qty;
    syncQtyInputs();
}

function syncQtyInputs() {
    // Remove old hidden quantity inputs
    document.querySelectorAll('.qty-hidden').forEach(el => el.remove());
    const form = document.getElementById('rxForm');
    Object.entries(selected).forEach(([id, s]) => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'quantities[]';
        inp.value = s.qty;
        inp.className = 'qty-hidden';
        form.appendChild(inp);
    });
}

// ── Medicine search ──
document.getElementById('medSearchInput').addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.med-row').forEach(row => {
        row.style.display = !q || row.dataset.search.includes(q) ? '' : 'none';
    });
});

// ── Toast helper ──
function showToast(msg, type = 'ok') {
    const tray = document.getElementById('toastTray');
    const t = document.createElement('div');
    t.className = `toast-msg t-${type}`;
    t.textContent = msg;
    tray.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// ── Sidebar toggle (mobile) ──
const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarToggle  = document.getElementById('sidebarToggle');
if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        sidebarOverlay.classList.toggle('show');
    });
}
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('show');
    });
}
</script>

</body>
</html>