<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

$page_title = 'Prescription';

/* ── DB data ── */
try {
    $db = getDB();

    $doctors = $db->query(
        "SELECT DoctorID, FullName FROM doctors ORDER BY FullName"
    )->fetchAll();

    $medicines = $db->query(
        "SELECT m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength,
                m.Manufacturer,
                IFNULL(SUM(md.StockAvailability),0) AS Stock,
                MIN(md.ExpirationDate) AS NearestExpiry
         FROM medications m
         LEFT JOIN medicationdetails md ON m.MedicationID=md.MedicationID
         GROUP BY m.MedicationID
         ORDER BY m.GenericName"
    )->fetchAll();

} catch (Throwable $e) {
    /* demo fallback so page renders even without DB */
    $doctors = [
        ['DoctorID'=>1,'FullName'=>'Dr. Maria Santos'],
        ['DoctorID'=>2,'FullName'=>'Dr. Jose Reyes'],
    ];
    $medicines = [
        ['MedicationID'=>1,'GenericName'=>'Phenylephrine',  'BrandName'=>'Phenylephrine',  'DosageStrength'=>'10mg','Manufacturer'=>'Novartis','Stock'=>89, 'NearestExpiry'=>'2026-05-15'],
        ['MedicationID'=>2,'GenericName'=>'Pseudoephedrine','BrandName'=>'Pseudoephedrine','DosageStrength'=>'60mg','Manufacturer'=>'GSK',     'Stock'=>144,'NearestExpiry'=>'2026-08-15'],
        ['MedicationID'=>3,'GenericName'=>'Cetirizine',     'BrandName'=>'Cetirizine',     'DosageStrength'=>'10mg','Manufacturer'=>'Bayer',   'Stock'=>59, 'NearestExpiry'=>'2026-07-15'],
        ['MedicationID'=>4,'GenericName'=>'Amoxicillin',    'BrandName'=>'Amoxil',         'DosageStrength'=>'500mg','Manufacturer'=>'GSK',    'Stock'=>210,'NearestExpiry'=>'2026-12-01'],
        ['MedicationID'=>5,'GenericName'=>'Metformin',      'BrandName'=>'Glucophage',     'DosageStrength'=>'500mg','Manufacturer'=>'Merck',  'Stock'=>38, 'NearestExpiry'=>'2025-11-20'],
    ];
}

function stockClass(int $s): string {
    if ($s <= 50)  return 'med-crit';
    if ($s <= 100) return 'med-low';
    return 'med-ok';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Prescription</title>
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<div class="app-layout">

    <!-- ══ SIDEBAR — cyan icon rail ══ -->
    <aside class="sidebar" id="sidebar">

        <div class="sidebar-brand">
            <div class="brand-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/>
                    <line x1="12" y1="8" x2="12" y2="16"/>
                    <line x1="8"  y1="12" x2="16" y2="12"/>
                </svg>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="dashboard.php"     class="nav-item"        data-label="Dashboard">
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
                </svg>
            </a>
            <a href="transactions.php"  class="nav-item"        data-label="Transactions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </a>
            <a href="medications.php"   class="nav-item"        data-label="Medications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                </svg>
            </a>
            <a href="patients.php"      class="nav-item"        data-label="Patients">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </a>
            <a href="users.php"         class="nav-item"        data-label="Users">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                </svg>
            </a>
        </nav>

        <a href="logout.php" class="sidebar-footer" onclick="return confirm('Log out?')" title="Logout">
            <div class="s-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'P', 0, 1)) ?></div>
        </a>

    </aside>

    <!-- ══ MAIN ══ -->
    <div class="main-area">

        <?php include 'header.php'; ?>

        <div class="page-body">

            <!-- ══ STEP WIZARD — matches wireframe exactly ══ -->
            <div class="rx-wizard">
                <div class="rx-step active" id="ind1">
                    <div class="step-num">1</div>
                    <span class="step-lbl">Patient Info</span>
                </div>
                <div class="rx-line" id="line1"></div>
                <div class="rx-step idle" id="ind2">
                    <div class="step-num">2</div>
                    <span class="step-lbl">Select Medicines</span>
                </div>
                <div class="rx-line" id="line2"></div>
                <div class="rx-step idle" id="ind3">
                    <div class="step-num">3</div>
                    <span class="step-lbl">Review &amp; Submit</span>
                </div>
            </div>

            <!-- ════════════════════════════
                 STEP 1 — Patient + Medicines
                 ════════════════════════════ -->
            <div id="rxStep1">
                <div class="rx-body">

                    <!-- LEFT: Patient Information -->
                    <div class="rx-patient-card">
                        <h3>Patient Information</h3>

                        <div class="rx-field">
                            <input type="text" class="rx-input" id="ptName"
                                   placeholder="Full name" autocomplete="off">
                        </div>
                        <div class="rx-field">
                            <input type="number" class="rx-input" id="ptAge"
                                   placeholder="Age" min="1" max="120">
                        </div>
                        <div class="rx-field">
                            <select class="rx-select" id="ptGender">
                                <option value="">Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="rx-field">
                            <select class="rx-select" id="ptDoctor">
                                <option value="">Doctor</option>
                                <?php foreach($doctors as $d): ?>
                                <option value="<?= $d['DoctorID'] ?>">
                                    <?= htmlspecialchars($d['FullName']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="rx-field">
                            <input type="text" class="rx-input" id="ptCondition"
                                   placeholder="Diagnosis / Condition">
                        </div>
                        <div class="rx-field">
                            <input type="text" class="rx-input" id="ptContact"
                                   placeholder="Contact number">
                        </div>
                    </div>

                    <!-- RIGHT: Medicine selector -->
                    <div>
                        <div class="rx-med-card">
                            <div class="rx-med-header">
                                <h3>Select Medicines</h3>
                            </div>

                            <div class="rx-search-row">
                                <input type="text" class="rx-search-input" id="medSearch"
                                       placeholder="Search medicines…">
                                <button class="rx-search-btn" onclick="filterMeds()">Search</button>
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
                                    <tbody id="medTbody">
                                    <?php foreach($medicines as $m): ?>
                                    <tr class="med-row"
                                        data-search="<?= strtolower(htmlspecialchars($m['GenericName'].' '.$m['BrandName'])) ?>">
                                        <td>
                                            <input type="checkbox" class="rx-check"
                                                value="<?= $m['MedicationID'] ?>"
                                                data-name="<?= htmlspecialchars($m['GenericName']) ?>"
                                                data-brand="<?= htmlspecialchars($m['BrandName']) ?>"
                                                data-strength="<?= htmlspecialchars($m['DosageStrength']) ?>"
                                                data-stock="<?= (int)$m['Stock'] ?>"
                                                onchange="toggleMed(this)">
                                        </td>
                                        <td>
                                            <span style="font-weight:600;color:#1e293b"><?= htmlspecialchars($m['GenericName']) ?></span>
                                            <span style="font-size:.78rem;color:#94a3b8;margin-left:4px"><?= htmlspecialchars($m['DosageStrength']) ?></span>
                                        </td>
                                        <td style="color:#94a3b8;font-size:.83rem"><?= htmlspecialchars($m['BrandName']) ?></td>
                                        <td class="<?= stockClass((int)$m['Stock']) ?>"><?= (int)$m['Stock'] ?></td>
                                        <td style="font-size:.81rem;color:#94a3b8"><?= htmlspecialchars($m['NearestExpiry'] ?? '—') ?></td>
                                        <td style="font-size:.81rem;color:#94a3b8"><?= htmlspecialchars($m['Manufacturer'] ?? '—') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Selected medicines summary -->
                        <div class="rx-selected-card" id="selCard" style="display:none">
                            <h4>Selected Medicines (<span id="selCount">0</span>)</h4>
                            <div id="selList"></div>
                        </div>
                    </div>

                </div><!-- /rx-body -->

                <div class="rx-actions">
                    <button class="btn-primary" onclick="goStep2()">
                        Next: Review &amp; Submit →
                    </button>
                </div>
            </div><!-- /#rxStep1 -->

            <!-- ════════════════════════════
                 STEP 2 — Review & Submit
                 ════════════════════════════ -->
            <div id="rxStep2" style="display:none">
                <div class="rx-review-wrap">

                    <div class="rx-review-card">
                        <h3>Patient Details</h3>
                        <div class="review-row"><span class="review-key">Full Name</span>    <span class="review-value" id="rv-name">—</span></div>
                        <div class="review-row"><span class="review-key">Age</span>           <span class="review-value" id="rv-age">—</span></div>
                        <div class="review-row"><span class="review-key">Gender</span>        <span class="review-value" id="rv-gender">—</span></div>
                        <div class="review-row"><span class="review-key">Doctor</span>        <span class="review-value" id="rv-doctor">—</span></div>
                        <div class="review-row"><span class="review-key">Condition</span>     <span class="review-value" id="rv-condition">—</span></div>
                        <div class="review-row"><span class="review-key">Contact</span>       <span class="review-value" id="rv-contact">—</span></div>
                    </div>

                    <div class="rx-review-card">
                        <h3>Medicines Prescribed</h3>
                        <div id="rv-meds"></div>
                        <div class="review-row" style="margin-top:12px">
                            <span class="review-key">Date</span>
                            <span class="review-value"><?= date('F j, Y') ?></span>
                        </div>
                        <div class="review-row">
                            <span class="review-key">Valid Until</span>
                            <span class="review-value"><?= date('F j, Y', strtotime('+30 days')) ?></span>
                        </div>

                        <div id="rxResult"></div>
                    </div>

                </div>

                <div class="rx-actions">
                    <button class="btn-secondary" onclick="goBack()">← Back</button>
                    <button class="btn-primary" id="submitBtn" onclick="doSubmit()">
                        ✓ Confirm &amp; Save
                    </button>
                </div>
            </div><!-- /#rxStep2 -->

        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>
<script src="dashboard.js"></script>

<script>
/* ════════════════════════════════════════
   Prescription Wizard JS
   ════════════════════════════════════════ */
const sel = {}; // { id: { name, brand, strength, stock, qty } }

/* Search / filter */
function filterMeds() {
    const q = document.getElementById('medSearch').value.toLowerCase().trim();
    document.querySelectorAll('.med-row').forEach(r => {
        r.style.display = (!q || r.dataset.search.includes(q)) ? '' : 'none';
    });
}
document.getElementById('medSearch').addEventListener('input', filterMeds);
document.getElementById('medSearch').addEventListener('keydown', e => { if(e.key==='Enter') filterMeds(); });

/* Toggle medicine selection */
function toggleMed(cb) {
    const id = cb.value;
    const row = cb.closest('tr');
    if (cb.checked) {
        sel[id] = {
            name:     cb.dataset.name,
            brand:    cb.dataset.brand,
            strength: cb.dataset.strength,
            stock:    +cb.dataset.stock,
            qty:      1
        };
        row.classList.add('is-selected');
    } else {
        delete sel[id];
        row.classList.remove('is-selected');
    }
    renderSel();
}

/* Render selected panel */
function renderSel() {
    const card  = document.getElementById('selCard');
    const list  = document.getElementById('selList');
    const count = Object.keys(sel).length;
    document.getElementById('selCount').textContent = count;
    if (!count) { card.style.display = 'none'; return; }
    card.style.display = 'block';
    list.innerHTML = Object.entries(sel).map(([id, m]) => `
        <div class="sel-item">
            <div>
                <div class="sel-name">${esc(m.name)}</div>
                <div class="sel-dose">${esc(m.brand)} · ${esc(m.strength)}</div>
            </div>
            <input type="number" class="sel-qty" value="${m.qty}"
                   min="1" max="${m.stock}"
                   onchange="sel['${id}'].qty = Math.max(1,+this.value)">
        </div>`
    ).join('');
}

/* Step navigation */
function setStep(n) {
    [1,2,3].forEach(i => {
        const el = document.getElementById('ind'+i);
        el.className = 'rx-step ' + (i < n ? 'done' : i === n ? 'active' : 'idle');
    });
    const l1 = document.getElementById('line1');
    const l2 = document.getElementById('line2');
    l1.classList.toggle('done', n >= 2);
    l2.classList.toggle('done', n >= 3);
}

function goStep2() {
    const name   = document.getElementById('ptName').value.trim();
    const age    = document.getElementById('ptAge').value.trim();
    const gender = document.getElementById('ptGender').value;
    const doctor = document.getElementById('ptDoctor');

    if (!name || !age || !gender || !doctor.value) {
        showToast('Please fill in Name, Age, Gender and Doctor.', 'warn'); return;
    }
    if (!Object.keys(sel).length) {
        showToast('Please select at least one medicine.', 'warn'); return;
    }

    /* Populate review */
    document.getElementById('rv-name').textContent      = name;
    document.getElementById('rv-age').textContent       = age + ' years old';
    document.getElementById('rv-gender').textContent    = gender;
    document.getElementById('rv-doctor').textContent    = doctor.options[doctor.selectedIndex].text;
    document.getElementById('rv-condition').textContent = document.getElementById('ptCondition').value.trim() || '—';
    document.getElementById('rv-contact').textContent   = document.getElementById('ptContact').value.trim() || '—';
    document.getElementById('rv-meds').innerHTML = Object.values(sel).map(m =>
        `<div class="review-row">
            <span class="review-key">${esc(m.name)} ${esc(m.strength)}</span>
            <span class="review-value">Qty: ${m.qty}</span>
         </div>`
    ).join('');
    document.getElementById('rxResult').innerHTML = '';
    document.getElementById('submitBtn').style.display = '';

    document.getElementById('rxStep1').style.display = 'none';
    document.getElementById('rxStep2').style.display = '';
    setStep(2);
    window.scrollTo({top:0, behavior:'smooth'});
}

function goBack() {
    document.getElementById('rxStep2').style.display = 'none';
    document.getElementById('rxStep1').style.display = '';
    setStep(1);
}

/* Submit */
async function doSubmit() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.textContent = 'Saving…';

    const payload = {
        patient_name:      document.getElementById('ptName').value.trim(),
        patient_age:       document.getElementById('ptAge').value.trim(),
        patient_gender:    document.getElementById('ptGender').value,
        doctor_id:         document.getElementById('ptDoctor').value,
        medical_condition: document.getElementById('ptCondition').value.trim(),
        contact:           document.getElementById('ptContact').value.trim(),
        medicines: Object.entries(sel).map(([id,m]) => ({ medication_id: id, quantity: m.qty }))
    };

    try {
        const res  = await fetch('save_prescription.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        const box  = document.getElementById('rxResult');

        if (data.success) {
            box.className = 'rx-result ok';
            box.textContent = `✓ Prescription saved! ID: ${data.prescription_id ?? 'N/A'}`;
            btn.style.display = 'none';
            setStep(3);
            showToast('Prescription saved!', 'ok');
        } else {
            box.className = 'rx-result err';
            box.textContent = '✗ ' + (data.message ?? 'Unknown error');
            btn.disabled = false; btn.textContent = '✓ Confirm & Save';
        }
    } catch(e) {
        const box = document.getElementById('rxResult');
        box.className = 'rx-result err';
        box.textContent = '⚠ Could not reach server.';
        btn.disabled = false; btn.textContent = '✓ Confirm & Save';
    }
}

/* Utility */
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
</script>

</body>
</html>