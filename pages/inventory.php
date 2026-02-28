<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Inventory';

try {
    $db = getDB();

    // Fetch all medication batches with stock details
    $s = $db->prepare("
        SELECT
            md.MedDet,
            md.MedicationID,
            m.GenericName,
            m.BrandName,
            m.DosageStrength,
            md.Manufacturer,
            md.ExpirationDate,
            md.StockAvailability,
            md.Contraindications,
            md.Precautions
        FROM medicationdetails md
        JOIN medications m ON md.MedicationID = m.MedicationID
        ORDER BY md.MedDet ASC
    ");
    $s->execute();
    $medications = $s->fetchAll();

} catch (PDOException $e) {
    $medications = [];
}

/**
 * Determine stock status based on quantity and expiry.
 * Mirrors the Figma legend: Adequate >300, Expiring Soon, Low ≤300, Critical ≤100
 */
function stockStatus(int $qty, string $expiry): string {
    $daysLeft = (strtotime($expiry) - time()) / 86400;
    if ($qty <= 100)  return 'critical';
    if ($daysLeft <= 90) return 'expiring';
    if ($qty <= 300)  return 'low';
    return 'adequate';
}

function stockClass(string $status): string {
    return match($status) {
        'adequate' => 'stock-adequate',
        'expiring' => 'stock-expiring',
        'low'      => 'stock-low',
        'critical' => 'stock-critical',
        default    => 'stock-low',
    };
}

function fmtPad($n, $len = 3): string {
    return str_pad($n, $len, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Inventory</title>
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

            <a href="prescriptions.php" class="nav-item" data-label="Prescriptions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                </svg>
            </a>

            <a href="transactions.php" class="nav-item" data-label="Transactions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <line x1="2" y1="10" x2="22" y2="10"/>
                </svg>
            </a>

            <a href="inventory.php" class="nav-item active" data-label="Inventory">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                </svg>
            </a>

            <a href="admin.php" class="nav-item" data-label="Patients">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </a>

            <!-- <a href="users.php" class="nav-item" data-label="Users">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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

            <!-- Inventory Card -->
            <div class="inventory-card">

                <!-- Toolbar -->
                <div class="inventory-toolbar">
                    <div class="inventory-toolbar-left">
                        <div class="inventory-title">Inventory &amp; Stocks</div>
                        <div class="stock-legend">
                            <span class="legend-item"><span class="legend-dot ld-adequate"></span> Adequate (&gt;300)</span>
                            <span class="legend-item"><span class="legend-dot ld-expiring"></span> Expiring Soon</span>
                            <span class="legend-item"><span class="legend-dot ld-low"></span> Low (≤300)</span>
                            <span class="legend-item"><span class="legend-dot ld-critical"></span> Critical (≤100)</span>
                        </div>
                    </div>
                    <div class="inventory-controls">
                        <input
                            class="inv-search"
                            id="medSearch"
                            type="text"
                            placeholder="Search…"
                            autocomplete="off"
                        >
                        <button class="btn-add-med" id="openModal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                            Add Medicine
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="med-table-wrap">
                    <table class="med-table" id="medTable">
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Medication ID</th>
                                <th>Generic Name</th>
                                <th>Brand Name</th>
                                <th>Dosage</th>
                                <th>Manufacturer</th>
                                <th>Expiration Date</th>
                                <th>Stock</th>
                                <th>Contraindications</th>
                                <th>Precautions</th>
                                <th>Stock Status</th>
                            </tr>
                        </thead>
                        <tbody id="medBody">
                        <?php if (empty($medications)): ?>
                            <tr><td colspan="11" class="med-empty">No medications found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($medications as $i => $m):
                                $qty    = (int)$m['StockAvailability'];
                                $exp    = $m['ExpirationDate'] ?? date('Y-m-d', strtotime('+2 years'));
                                $status = stockStatus($qty, $exp);
                                $sClass = stockClass($status);
                            ?>
                            <tr
                                data-search="<?= strtolower(
                                    'BATCH-' . fmtPad($m['MedDet']) . ' ' .
                                    'MED-'   . fmtPad($m['MedicationID']) . ' ' .
                                    $m['GenericName'] . ' ' . $m['BrandName']
                                ) ?>"
                                data-status="<?= $status ?>"
                            >
                                <td class="med-col-batch">BATCH-<?= fmtPad($m['MedDet']) ?></td>
                                <td class="med-col-id">MED-<?= fmtPad($m['MedicationID']) ?></td>
                                <td class="med-col-name"><?= htmlspecialchars($m['GenericName']) ?></td>
                                <td class="med-col-brand"><?= htmlspecialchars($m['BrandName']) ?></td>
                                <td class="med-col-dose"><?= htmlspecialchars($m['DosageStrength']) ?></td>
                                <td class="med-col-mfr"><?= htmlspecialchars($m['Manufacturer'] ?? '—') ?></td>
                                <td class="med-col-expiry"><?= htmlspecialchars($exp) ?></td>
                                <td class="med-col-stock <?= $sClass ?>"><?= $qty ?></td>
                                <td class="med-col-contra"><?= htmlspecialchars($m['Contraindications'] ?? '—') ?></td>
                                <td class="med-col-prec"><?= htmlspecialchars($m['Precautions'] ?? '—') ?></td>
                                <td>
                                    <span class="med-status <?= $status ?>">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div><!-- /inventory-card -->

        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<!-- ══ Add Medicine Modal ══ -->
<div class="modal-overlay" id="addMedModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">Add New Medicine</div>
            <button class="modal-close" id="closeModal" title="Close">✕</button>
        </div>
        <form method="POST" action="../api/save_medication.php" id="addMedForm">
            <div class="modal-grid">

                <div class="modal-field">
                    <label class="modal-label" for="genericName">Generic Name</label>
                    <input class="modal-input" type="text" id="genericName" name="generic_name" placeholder="e.g. Paracetamol" required>
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="brandName">Brand Name</label>
                    <input class="modal-input" type="text" id="brandName" name="brand_name" placeholder="e.g. Biogesic" required>
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="dosage">Dosage / Strength</label>
                    <input class="modal-input" type="text" id="dosage" name="dosage_strength" placeholder="e.g. 500mg">
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="manufacturer">Manufacturer</label>
                    <input class="modal-input" type="text" id="manufacturer" name="manufacturer" placeholder="e.g. Unilab">
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="unitPrice">Unit Price (₱)</label>
                    <input class="modal-input" type="number" step="0.01" min="0" id="unitPrice" name="unit_price" placeholder="0.00">
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="stock">Initial Stock</label>
                    <input class="modal-input" type="number" min="0" id="stock" name="stock_availability" placeholder="0" required>
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="expirationDate">Expiration Date</label>
                    <input class="modal-input" type="date" id="expirationDate" name="expiration_date" required>
                </div>

                <div class="modal-field">
                    <label class="modal-label" for="drugClass">Drug Class</label>
                    <input class="modal-input" type="text" id="drugClass" name="drug_class" placeholder="e.g. Analgesic">
                </div>

                <div class="modal-field full">
                    <label class="modal-label" for="contraindications">Contraindications</label>
                    <input class="modal-input" type="text" id="contraindications" name="contraindications" placeholder="e.g. Severe liver disease">
                </div>

                <div class="modal-field full">
                    <label class="modal-label" for="precautions">Precautions</label>
                    <input class="modal-input" type="text" id="precautions" name="precautions" placeholder="e.g. Do not exceed 4g/day">
                </div>

            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" id="cancelModal">Cancel</button>
                <button type="submit" class="modal-btn-save">Save Medicine</button>
            </div>
        </form>
    </div>
</div>

<div class="toast-tray" id="toastTray"></div>

<script>
// ── Live search ──
const medSearch = document.getElementById('medSearch');
const medRows   = document.querySelectorAll('#medBody tr[data-search]');

medSearch.addEventListener('input', () => {
    const q = medSearch.value.toLowerCase().trim();
    medRows.forEach(row => {
        row.style.display = !q || row.dataset.search.includes(q) ? '' : 'none';
    });
});

// ── Modal ──
const modal       = document.getElementById('addMedModal');
const openBtn     = document.getElementById('openModal');
const closeBtn    = document.getElementById('closeModal');
const cancelBtn   = document.getElementById('cancelModal');

openBtn.addEventListener('click',  () => modal.classList.add('show'));
closeBtn.addEventListener('click', () => modal.classList.remove('show'));
cancelBtn.addEventListener('click',() => modal.classList.remove('show'));
modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });

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