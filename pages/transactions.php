<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Invoices';

try {
    $db = getDB();

    $s = $db->prepare("
        SELECT
            i.InvoiceID,
            i.PrescriptionID,
            i.PharmacistID,
            i.DispenseQuantity AS DispenseQty,
            i.UnitPrice,
            i.Discount,
            i.Subtotal,
            i.Total,
            i.Status,
            pr.DatePrescribed
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
        ORDER BY pr.DatePrescribed DESC
    ");
    $s->execute();
    $invoices = $s->fetchAll();

} catch (PDOException $e) {
    $invoices = [];
    die('<pre style="color:red">DB ERROR: ' . $e->getMessage() . '</pre>');
}

function fmtPad($n, $len = 3) { return str_pad($n, $len, '0', STR_PAD_LEFT); }
function statusClass($s) {
    return match(strtolower($s ?? '')) {
        'completed' => 'completed',
        'pending'   => 'pending',
        'partial'   => 'partial',
        'cancelled' => 'cancelled',
        default     => 'pending',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Invoices</title>
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

            <a href="transactions.php" class="nav-item active" data-label="Transactions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <line x1="2" y1="10" x2="22" y2="10"/>
                </svg>
            </a>

            <a href="inventory.php" class="nav-item" data-label="Medications">
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

        </nav>

        <a href="../logout.php" class="sidebar-footer" onclick="return confirm('Log out?')" title="Logout">
            <div class="s-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'P', 0, 1)) ?></div>
        </a>

    </aside>

    <!-- ══ MAIN ══ -->
    <div class="main-area">

        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="page-body">

            <!-- Invoices Card -->
            <div class="invoices-card">

                <!-- Toolbar -->
                <div class="invoices-toolbar">
                    <div class="invoices-title">All Invoices</div>
                    <div class="invoices-controls">
                        <input
                            class="inv-search"
                            id="invSearch"
                            type="text"
                            placeholder="Search…"
                            autocomplete="off"
                        >
                        <select class="inv-filter" id="invFilter">
                            <option value="">All Status</option>
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <!-- Table -->
                <div class="inv-table-wrap">
                    <table class="inv-table" id="invTable">
                        <thead>
                            <tr>
                                <th>Invoice ID</th>
                                <th>Prescription ID</th>
                                <th>Pharmacist ID</th>
                                <th style="text-align:center">Dispense Qty</th>
                                <th>Unit Price</th>
                                <th>Discount</th>
                                <th>Subtotal</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="invBody">
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="9" class="inv-empty">No invoices found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $r): ?>
                            <tr
                                data-inv="<?= strtolower('TXN-' . fmtPad($r['InvoiceID'])) ?>"
                                data-rx="<?= strtolower('RX-' . fmtPad($r['PrescriptionID'])) ?>"
                                data-ph="<?= strtolower('PH-' . fmtPad($r['PharmacistID'])) ?>"
                                data-status="<?= strtolower($r['Status'] ?? 'pending') ?>"
                            >
                                <td class="inv-col-id">TXN-<?= fmtPad($r['InvoiceID']) ?></td>
                                <td class="inv-col-rx">RX-<?= fmtPad($r['PrescriptionID']) ?></td>
                                <td class="inv-col-ph">PH-<?= fmtPad($r['PharmacistID']) ?></td>
                                <td class="inv-col-qty"><?= (int)$r['DispenseQty'] ?></td>
                                <td class="inv-col-price">₱<?= number_format((float)$r['UnitPrice'], 2) ?></td>
                                <td class="inv-col-disc">₱<?= number_format((float)$r['Discount'], 2) ?></td>
                                <td class="inv-col-sub">₱<?= number_format((float)$r['Subtotal'], 2) ?></td>
                                <td class="inv-col-total">₱<?= number_format((float)$r['Total'], 2) ?></td>
                                <td>
                                    <span class="inv-status <?= statusClass($r['Status']) ?>">
                                        <?= ucfirst($r['Status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div><!-- /invoices-card -->

        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>

<script>
'use strict';

// ── Live search + status filter ──
const searchInput  = document.getElementById('invSearch');
const filterSelect = document.getElementById('invFilter');
const rows         = document.querySelectorAll('#invBody tr[data-status]');

function applyFilters() {
    const q      = searchInput.value.toLowerCase().trim();
    const status = filterSelect.value.toLowerCase();

    rows.forEach(row => {
        const inv   = row.dataset.inv    || '';
        const rx    = row.dataset.rx     || '';
        const ph    = row.dataset.ph     || '';
        const rowSt = row.dataset.status || '';

        const matchSearch = !q || inv.includes(q) || rx.includes(q) || ph.includes(q);
        const matchStatus = !status || rowSt === status;

        row.style.display = matchSearch && matchStatus ? '' : 'none';
    });
}

searchInput.addEventListener('input', applyFilters);
filterSelect.addEventListener('change', applyFilters);

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