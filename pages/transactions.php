<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Invoices';

/* ══ POST — Mark as Paid / Cancelled ══ */
$txn_success = '';
$txn_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    if ($invoice_id > 0) {
        try {
            $db = getDB();
            if ($action === 'mark_paid') {
                $s = $db->prepare("UPDATE invoices SET Status='Completed' WHERE InvoiceID=? AND Status='Pending'");
                $s->execute([$invoice_id]);
                $txn_success = "✓ Invoice TXN-" . str_pad($invoice_id, 3, '0', STR_PAD_LEFT) . " marked as Paid.";
            } elseif ($action === 'mark_cancelled') {
                $s = $db->prepare("UPDATE invoices SET Status='Cancelled' WHERE InvoiceID=? AND Status='Pending'");
                $s->execute([$invoice_id]);
                $txn_success = "✓ Invoice TXN-" . str_pad($invoice_id, 3, '0', STR_PAD_LEFT) . " has been cancelled.";
            }
        } catch (PDOException $e) {
            $txn_error = "Database error: " . $e->getMessage();
        }
    }
}

/* ══ FETCH — Full audit-trail invoice list ══ */
try {
    $db = getDB();

    $s = $db->prepare("
        SELECT
            i.InvoiceID,
            i.PrescriptionID,
            i.PharmacistID,
            u.FullName          AS PharmacistName,
            i.DispenseQuantity  AS DispenseQty,
            i.UnitPrice,
            i.Discount,
            i.Subtotal,
            i.Total,
            i.Status,
            pr.DatePrescribed,
            p.FullName          AS PatientName,
            p.Age               AS PatientAge,
            p.Gender            AS PatientGender,
            d.FullName          AS DoctorName,
            GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines
        FROM invoices i
        JOIN prescriptions pr           ON i.PrescriptionID  = pr.PrescriptionID
        JOIN patients p                 ON pr.PatientID       = p.PatientID
        LEFT JOIN users u               ON i.PharmacistID     = u.UserID
        LEFT JOIN doctors d             ON pr.DoctorID        = d.DoctorID
        LEFT JOIN prescriptiondetails pd ON pd.PrescriptionID = pr.PrescriptionID
        LEFT JOIN medications m         ON pd.MedicationID    = m.MedicationID
        GROUP BY i.InvoiceID
        ORDER BY i.InvoiceID DESC
    ");
    $s->execute();
    $invoices = $s->fetchAll();

    /* Summary stats */
    $s = $db->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN Status='Completed' THEN 1 ELSE 0 END) AS paid,
        SUM(CASE WHEN Status='Pending'   THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN Status='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
        IFNULL(SUM(CASE WHEN Status='Completed' THEN Total   ELSE 0 END), 0) AS total_revenue,
        IFNULL(SUM(Discount), 0) AS total_discounts
        FROM invoices");
    $s->execute();
    $stats = $s->fetch();

} catch (PDOException $e) {
    $invoices = [];
    $stats = ['total'=>0,'paid'=>0,'pending'=>0,'cancelled'=>0,'total_revenue'=>0,'total_discounts'=>0];
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
    <style>
        .txn-result.ok  { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:10px; padding:12px 18px; font-weight:600; margin-bottom:16px; }
        .txn-result.err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:12px 18px; font-weight:600; margin-bottom:16px; }

        /* Summary strip */
        .txn-stats-strip { display:grid; grid-template-columns:repeat(5,1fr); border-bottom:1px solid #f1f5f9; }
        .txn-stat-item { padding:14px 18px; border-right:1px solid #f1f5f9; display:flex; flex-direction:column; gap:3px; }
        .txn-stat-item:last-child { border-right:none; }
        .txn-stat-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; }
        .txn-stat-val { font-size:1.35rem; font-weight:800; color:#111827; line-height:1; }
        .txn-stat-val.green { color:#16a34a; }
        .txn-stat-val.amber { color:#d97706; }
        .txn-stat-val.red   { color:#dc2626; }
        .txn-stat-val.blue  { color:#2563eb; }

        /* Action buttons */
        .btn-mark-paid {
            display:inline-flex; align-items:center; gap:4px;
            padding:4px 11px; border-radius:999px;
            background:#dcfce7; color:#15803d;
            border:1px solid #bbf7d0;
            font-size:.70rem; font-weight:700; cursor:pointer; transition:all .15s;
        }
        .btn-mark-paid:hover { background:#16a34a; color:#fff; border-color:#16a34a; }
        .btn-mark-cancel {
            display:inline-flex; align-items:center;
            padding:4px 9px; border-radius:999px;
            background:#f1f5f9; color:#64748b;
            border:1px solid #e2e8f0;
            font-size:.70rem; font-weight:700; cursor:pointer; transition:all .15s;
        }
        .btn-mark-cancel:hover { background:#fee2e2; color:#dc2626; border-color:#fecaca; }

        /* Cell styles */
        .inv-col-patient { font-weight:600; color:#1e293b; font-size:.82rem; }
        .inv-col-patient .sub { font-size:.70rem; color:#94a3b8; font-weight:400; }
        .inv-col-doctor  { font-size:.78rem; color:#475569; }
        .inv-col-meds    { font-size:.73rem; color:#64748b; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .inv-col-date    { font-size:.78rem; color:#64748b; white-space:nowrap; }
        .inv-col-ph-name { font-size:.68rem; color:#94a3b8; }

        /* Table header color */
        .inv-table thead th { background:#0F2854 !important; position:sticky; top:0; z-index:2; }

        /* Scrollable */
        .inv-table-wrap {
            overflow-x:auto; overflow-y:auto;
            max-height:calc(100vh - 320px);
            scrollbar-width:thin; scrollbar-color:#cbd5e1 #f1f5f9;
        }
        .inv-table-wrap::-webkit-scrollbar { width:7px; height:7px; }
        .inv-table-wrap::-webkit-scrollbar-track { background:#f1f5f9; border-radius:999px; }
        .inv-table-wrap::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
        .inv-table-wrap::-webkit-scrollbar-thumb:hover { background:#94a3b8; }
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
            <a href="prescriptions.php" class="nav-item" data-label="Prescriptions">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                    <path d="M9 12h6M9 16h4"/>
                </svg>

            </a>
            <a href="transactions.php" class="nav-item active" data-label="Transactions">
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

            <?php if ($txn_success): ?>
                <div class="txn-result ok"><?= htmlspecialchars($txn_success) ?></div>
            <?php endif; ?>
            <?php if ($txn_error): ?>
                <div class="txn-result err"><?= htmlspecialchars($txn_error) ?></div>
            <?php endif; ?>

            <div class="invoices-card">

                <!-- Summary Stats Strip -->
                <div class="txn-stats-strip">
                    <div class="txn-stat-item">
                        <div class="txn-stat-label">Total Invoices</div>
                        <div class="txn-stat-val"><?= (int)$stats['total'] ?></div>
                    </div>
                    <div class="txn-stat-item">
                        <div class="txn-stat-label">Paid</div>
                        <div class="txn-stat-val green"><?= (int)$stats['paid'] ?></div>
                    </div>
                    <div class="txn-stat-item">
                        <div class="txn-stat-label">Pending Payment</div>
                        <div class="txn-stat-val amber"><?= (int)$stats['pending'] ?></div>
                    </div>
                    <div class="txn-stat-item">
                        <div class="txn-stat-label">Cancelled</div>
                        <div class="txn-stat-val red"><?= (int)$stats['cancelled'] ?></div>
                    </div>
                    <div class="txn-stat-item">
                        <div class="txn-stat-label">Revenue Collected</div>
                        <div class="txn-stat-val blue">₱<?= number_format((float)$stats['total_revenue'], 2) ?></div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="invoices-toolbar">
                    <div class="invoices-title">
                        All Invoices
                        <span style="font-size:.70rem;background:#e0e7ff;color:#6366f1;padding:2px 9px;border-radius:8px;font-weight:700;margin-left:8px;vertical-align:middle;">
                            Dispensing Audit Trail
                        </span>
                    </div>
                    <div class="invoices-controls">
                        <input class="inv-search" id="invSearch" type="text" placeholder="Search patient, invoice, doctor…" autocomplete="off">
                        <select class="inv-filter" id="invFilter">
                            <option value="">All Status</option>
                            <option value="completed">Paid</option>
                            <option value="pending">Pending</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <!-- Table -->
                <div class="inv-table-wrap">
                    <table class="inv-table" id="invTable" style="width:100%;table-layout:fixed;">
                        <colgroup>
                            <col style="width:90px">   <!-- Invoice ID -->
                            <col style="width:100px">  <!-- Date -->
                            <col style="width:160px">  <!-- Patient -->
                            <col style="width:140px">  <!-- Doctor -->
                            <col style="width:160px">  <!-- Medicines -->
                            <col style="width:70px;text-align:center">  <!-- Qty -->
                            <col style="width:90px">   <!-- Total -->
                            <col style="width:90px">   <!-- Status -->
                            <col style="width:110px">  <!-- Action -->
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Invoice ID</th>
                                <th>Date</th>
                                <th>Patient</th>
                                <th>Doctor</th>
                                <th>Medicines</th>
                                <th style="text-align:center">Qty</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="invBody">
                        <?php if (empty($invoices)): ?>
                            <tr><td colspan="9" class="inv-empty">No invoices found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $r):
                                $status    = strtolower($r['Status'] ?? 'pending');
                                $isPending = $status === 'pending';
                                $hasSenior = (float)$r['Discount'] > 0;
                                $age       = (int)$r['PatientAge'];
                            ?>
                            <tr
                                data-inv="<?= strtolower('txn-' . fmtPad($r['InvoiceID'])) ?>"
                                data-rx="<?= strtolower('rx-' . fmtPad($r['PrescriptionID'])) ?>"
                                data-patient="<?= strtolower(htmlspecialchars($r['PatientName'] ?? '')) ?>"
                                data-doctor="<?= strtolower(htmlspecialchars($r['DoctorName'] ?? '')) ?>"
                                data-status="<?= $status ?>"
                            >
                                <!-- Invoice ID -->
                                <td class="inv-col-id" style="font-size:.78rem;font-weight:700;color:#6366f1;">TXN-<?= fmtPad($r['InvoiceID']) ?></td>

                                <!-- Date -->
                                <td class="inv-col-date"><?= htmlspecialchars($r['DatePrescribed'] ?? '—') ?></td>

                                <!-- Patient -->
                                <td class="inv-col-patient">
                                    <?= htmlspecialchars($r['PatientName'] ?? '—') ?>
                                    <div class="sub">
                                        <?= $age > 0 ? $age . ' y/o · ' : '' ?><?= htmlspecialchars($r['PatientGender'] ?? '') ?>
                                        <?php if ($age >= 60): ?> · <span style="color:#d97706;font-weight:700">Senior</span><?php endif; ?>
                                    </div>
                                </td>

                                <!-- Doctor -->
                                <td class="inv-col-doctor"><?= htmlspecialchars($r['DoctorName'] ?? '—') ?></td>

                                <!-- Medicines -->
                                <td class="inv-col-meds" title="<?= htmlspecialchars($r['Medicines'] ?? '—') ?>">
                                    <?= htmlspecialchars($r['Medicines'] ?? '—') ?>
                                </td>

                                <!-- Qty -->
                                <td class="inv-col-qty" style="text-align:center;font-weight:700"><?= (int)$r['DispenseQty'] ?></td>

                                <!-- Total -->
                                <td class="inv-col-total" style="font-weight:800">
                                    ₱<?= number_format((float)$r['Total'], 2) ?>
                                    <?php if ($hasSenior): ?>
                                        <div style="font-size:.65rem;color:#d97706;">−₱<?= number_format((float)$r['Discount'], 2) ?> discount</div>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td>
                                    <span class="inv-status <?= statusClass($r['Status']) ?>">
                                        <?= $status === 'completed' ? 'Paid' : ucfirst($r['Status'] ?? 'Pending') ?>
                                    </span>
                                </td>

                                <!-- Action -->
                                <td>
                                    <?php if ($isPending): ?>
                                        <div style="display:flex;gap:4px;align-items:center">
                                            <form method="POST" style="margin:0">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="invoice_id" value="<?= $r['InvoiceID'] ?>">
                                                <button type="submit" class="btn-mark-paid"
                                                    onclick="return confirm('Confirm payment for TXN-<?= fmtPad($r['InvoiceID']) ?>?\n\nTotal: ₱<?= number_format((float)$r['Total'], 2) ?>')">
                                                    ✓ Paid
                                                </button>
                                            </form>
                                            <form method="POST" style="margin:0">
                                                <input type="hidden" name="action" value="mark_cancelled">
                                                <input type="hidden" name="invoice_id" value="<?= $r['InvoiceID'] ?>">
                                                <button type="submit" class="btn-mark-cancel"
                                                    onclick="return confirm('Cancel invoice TXN-<?= fmtPad($r['InvoiceID']) ?>?')">
                                                    ✕
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size:.72rem;color:#94a3b8">—</span>
                                    <?php endif; ?>
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
const searchInput  = document.getElementById('invSearch');
const filterSelect = document.getElementById('invFilter');
const rows         = document.querySelectorAll('#invBody tr[data-status]');

function applyFilters() {
    const q      = searchInput.value.toLowerCase().trim();
    const status = filterSelect.value.toLowerCase();
    rows.forEach(row => {
        const matchSearch = !q ||
            (row.dataset.inv     || '').includes(q) ||
            (row.dataset.rx      || '').includes(q) ||
            (row.dataset.patient || '').includes(q) ||
            (row.dataset.doctor  || '').includes(q);
        const matchStatus = !status || (row.dataset.status || '') === status;
        row.style.display = matchSearch && matchStatus ? '' : 'none';
    });
}
searchInput.addEventListener('input', applyFilters);
filterSelect.addEventListener('change', applyFilters);

const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarToggle  = document.getElementById('sidebarToggle');
if (sidebarToggle) sidebarToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('show'); });
if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); });
</script>
</body>
</html>