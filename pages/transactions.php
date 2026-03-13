<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Invoices';

/* ══ POST — Mark as Paid / Cancelled ══ */
$txn_success = '';
$txn_error   = '';
$receipt_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);

    if ($invoice_id > 0) {
        try {
            $db = getDB();
            if ($action === 'mark_paid') {
                $amount_tendered  = (float)($_POST['amount_tendered'] ?? 0);
                $payment_method   = $_POST['payment_method'] ?? 'Cash';
                $s = $db->prepare("UPDATE invoices SET Status='Completed', AmountTendered=?, PaymentMethod=? WHERE InvoiceID=? AND Status='Pending'");
                $s->execute([$amount_tendered, $payment_method, $invoice_id]);
                /* Fetch full invoice for receipt */
                $r2 = $db->prepare("
                    SELECT i.*, p.FullName AS PatientName, p.Age AS PatientAge, p.Gender AS PatientGender,
                           d.FullName AS DoctorName, u.FullName AS PharmacistName,
                           GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines
                    FROM invoices i
                    JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
                    JOIN patients p ON pr.PatientID=p.PatientID
                    LEFT JOIN users u ON i.PharmacistID=u.UserID
                    LEFT JOIN doctors d ON pr.DoctorID=d.DoctorID
                    LEFT JOIN prescriptiondetails pd ON pd.PrescriptionID=pr.PrescriptionID
                    LEFT JOIN medications m ON pd.MedicationID=m.MedicationID
                    WHERE i.InvoiceID=?
                    GROUP BY i.InvoiceID");
                $r2->execute([$invoice_id]);
                $receipt_data = $r2->fetch(PDO::FETCH_ASSOC);
                $txn_success = "Invoice TXN-" . str_pad($invoice_id, 3, '0', STR_PAD_LEFT) . " marked as Paid.";
            } elseif ($action === 'mark_cancelled') {
                $s = $db->prepare("UPDATE invoices SET Status='Cancelled' WHERE InvoiceID=? AND Status='Pending'");
                $s->execute([$invoice_id]);
                $txn_success = "Invoice TXN-" . str_pad($invoice_id, 3, '0', STR_PAD_LEFT) . " has been cancelled.";
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>

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
            max-height: 380px;
            scrollbar-width:thin; scrollbar-color:#cbd5e1 #f1f5f9;
        }
        .inv-table-wrap::-webkit-scrollbar { width:7px; height:7px; }
        .inv-table-wrap::-webkit-scrollbar-track { background:#f1f5f9; border-radius:999px; }
        .inv-table-wrap::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
        .inv-table-wrap::-webkit-scrollbar-thumb:hover { background:#94a3b8; }
    </style>
    <style>
        /* ══ OUTFIT FONT – single source ══ */
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap');

        *, *::before, *::after { box-sizing: border-box; }
        body, input, select, button, textarea { font-family: 'Outfit', sans-serif; }

        /* ══ CONSISTENT BORDER RADIUS ══ */
        :root { --br: 10px; --br-pill: 999px; --br-card: 14px; }

        /* ══ SIDEBAR – no white box on active ══ */
        .nav-item,
        .nav-item:hover,
        .nav-item.active { background: transparent !important; box-shadow: none !important; }

        /* ══ SIDEBAR ICONS – white, sized, dimmed when inactive ══ */
        .nav-item i.bi,
        .brand-icon i.bi,
        .sidebar-footer i.bi { color: #ffffff; }
        .nav-item i.bi         { font-size: 1.6rem; display: block; line-height: 1; opacity: 0.45; transition: opacity .2s ease; }
        .nav-item.active i.bi,
        .nav-item:hover  i.bi  { opacity: 1 !important; }

        /* ══ STAT CARD ICONS ══ */
        .stat-icon i.bi { font-size: 1.7rem; color: #ffffff; }

        /* ══ CARD TITLE ICONS ══ */
        .card-title-icon i.bi {
            font-size: 1.05rem;
            display: flex; align-items: center; justify-content: center;
        }

        /* ══ ICON + TEXT GAP ══ */
        .btn-with-icon, .card-title, .audit-section-head h3,
        .backup-item, .backup-btn, .audit-btn-print, .audit-btn-send,
        .admin-toolbar-left, .backup-header {
            display: flex; align-items: center; gap: 8px;
        }
        i.bi + span, span + i.bi,
        i.bi + strong, strong + i.bi { margin-left: 6px; }

        /* ══ BUTTONS – consistent radius ══ */
        .btn-add-user, .btn-add-med, .btn-primary, .btn-secondary,
        .modal-btn-save, .modal-btn-cancel,
        .audit-btn-print, .audit-btn-send,
        .backup-btn, .audit-filter-btn,
        .btn-mark-paid, .btn-mark-cancel,
        .rx-search-btn { border-radius: var(--br) !important; }

        /* ══ SEARCH BARS + DROPDOWNS – consistent radius ══ */
        .inv-search, .inv-filter, .admin-search,
        .rx-search-input, .modal-input,
        .audit-filter-bar input[type="date"],
        .sched-field select, .sched-field input,
        .send-confirm-field input { border-radius: var(--br) !important; }

        /* ══ ADMIN TOOLBAR ICON ══ */
        i.bi.admin-toolbar-icon { font-size: 1.2rem; color: #64748b; }

        /* ══ BACKUP ICONS ══ */
        i.bi.backup-header-icon { font-size: 1.2rem; }
        .backup-item-icon i.bi  { font-size: 1.4rem; display: flex; align-items: center; justify-content: center; }
        .backup-btn i.bi        { font-size: 1rem; }

        /* ══ USER ACTION BUTTONS ══ */
        .ua-btn i.bi { font-size: 1rem; }

        /* ══ AUDIT FOOTER BUTTONS ══ */
        .audit-btn-print i.bi,
        .audit-btn-send  i.bi { font-size: .95rem; }

        /* ══ RESULT / FLASH BANNERS ══ */
        .rx-result, .txn-result, .inv-result, .admin-flash {
            display: flex; align-items: center; gap: 10px;
            border-radius: var(--br);
            padding: 12px 18px;
            font-size: .875rem; font-weight: 600;
            margin-bottom: 16px;
        }
        .rx-result.ok, .txn-result.ok, .inv-result.ok, .admin-flash-ok {
            background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;
        }
        .rx-result.err, .txn-result.err, .inv-result.err, .admin-flash-err {
            background: #fee2e2; color: #dc2626; border: 1px solid #fecaca;
        }
        .rx-result i.bi, .txn-result i.bi,
        .inv-result i.bi, .admin-flash i.bi { font-size: 1rem; flex-shrink: 0; }

        /* ══ FLASH CLOSE ══ */
        .flash-close {
            margin-left: auto; background: none; border: none;
            cursor: pointer; color: inherit; opacity: .6; font-size: .85rem;
        }
        .flash-close:hover { opacity: 1; }

        /* ══ PAGINATION ══ */
        .pagination {
            display: flex; align-items: center; gap: 4px;
            padding: 12px 18px; border-top: 1px solid #f1f5f9;
            justify-content: flex-end; flex-wrap: wrap;
        }
        .pg-btn {
            min-width: 32px; height: 32px; padding: 0 8px;
            border: 1.5px solid #e2e8f0; border-radius: var(--br);
            background: #fff; color: #475569;
            font-family: 'Outfit', sans-serif; font-size: .8rem; font-weight: 600;
            cursor: pointer; transition: all .15s;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .pg-btn:hover  { background: #f1f5f9; border-color: #cbd5e1; }
        .pg-btn.active { background: #1e2d40; color: #fff; border-color: #1e2d40; }
        .pg-btn:disabled { opacity: .4; cursor: not-allowed; }
        .pg-info { font-size: .78rem; color: #94a3b8; margin: 0 6px; }

        /* ══ CONSISTENT TABLE/CONTAINER HEIGHT ══ */
        .card .table-scroll,
        .inv-table-wrap,
        .admin-table-wrap { min-height: 280px; }
    </style>
</head>
<body>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<div class="app-layout">

    <!-- ══ SIDEBAR ══ -->
    <aside class="sidebar" id="sidebar">
                <div class="sidebar-brand">
            <span class="brand-name">Pharma<br>Care<span style="font-size:0.6em;vertical-align:super;margin-left:1px;opacity:0.7;">&#9825;</span></span>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-item" data-label="Dashboard">
                <i class="bi bi-house-door-fill"></i>
            </a>
            <a href="prescriptions.php" class="nav-item" data-label="Prescriptions">
                <i class="bi bi-file-medical-fill"></i>
            </a>
            <a href="transactions.php" class="nav-item active" data-label="Transactions">
                <i class="bi bi-receipt-cutoff"></i>
            </a>
            <a href="inventory.php" class="nav-item" data-label="Inventory">
                <i class="bi bi-capsule-pill"></i>
            </a>
            <a href="admin.php" class="nav-item" data-label="Admin">
                <i class="bi bi-shield-lock-fill"></i>
            </a>
        </nav>
        <a href="#" class="sidebar-footer" onclick="pcConfirm({title:'Log Out',body:'Are you sure you want to log out of PharmaCare?',okText:'Log Out',type:'warning',icon:'bi-box-arrow-right',onOk:()=>window.location.href='../logout.php'})" title="Logout">
            <div class="s-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'P', 0, 1)) ?></div>
        </a>
    </aside>

    <!-- ══ MAIN ══ -->
    <div class="main-area">
        <?php include __DIR__ . '/../partials/header.php'; ?>

        <div class="page-body">

            <?php if ($txn_success): ?>
                <div class="txn-result ok"><?= $txn_success ?></div>
            <?php endif; ?>
            <?php if ($txn_error): ?>
                <div class="txn-result err"><?= $txn_error ?></div>
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
                                            <form method="POST" style="margin:0" id="payForm<?= $r['InvoiceID'] ?>">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="invoice_id" value="<?= $r['InvoiceID'] ?>">
                                                <input type="hidden" name="amount_tendered" id="tendered<?= $r['InvoiceID'] ?>" value="">
                                                <input type="hidden" name="payment_method" id="method<?= $r['InvoiceID'] ?>" value="">
                                                <button type="button" class="btn-mark-paid"
                                                    onclick="openCashierModal(
                                                        <?= $r['InvoiceID'] ?>,
                                                        'TXN-<?= fmtPad($r['InvoiceID']) ?>',
                                                        <?= (float)$r['Total'] ?>,
                                                        '<?= addslashes(htmlspecialchars($r['PatientName'] ?? '')) ?>',
                                                        '<?= addslashes(htmlspecialchars($r['Medicines'] ?? '')) ?>',
                                                        <?= (float)$r['Discount'] ?>
                                                    )">
                                                    <i class="bi bi-cash-coin"></i> Paid
                                                </button>
                                            </form>
                                            <form method="POST" style="margin:0">
                                                <input type="hidden" name="action" value="mark_cancelled">
                                                <input type="hidden" name="invoice_id" value="<?= $r['InvoiceID'] ?>">
                                                <button type="button" class="btn-mark-cancel"
                                                    onclick="confirmCancel(this, 'TXN-<?= fmtPad($r['InvoiceID']) ?>')">
                                                    Cancel
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
                <div class="pagination" id="pg-invoices"></div>

            </div><!-- /invoices-card -->
        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>

<!-- ══ CASHIER PAYMENT MODAL ══ -->
<div id="cashierOverlay" class="cashier-overlay" onclick="if(event.target===this)closeCashier()">
    <div class="cashier-box">
        <div class="cashier-header">
            <div class="cashier-header-left">
                <i class="bi bi-cash-register" style="font-size:1.3rem;color:#6366f1;"></i>
                <div>
                    <div class="cashier-title">Payment Checkout</div>
                    <div class="cashier-subtitle" id="cashierInvId">TXN-001</div>
                </div>
            </div>
            <button class="cashier-close" onclick="closeCashier()"><i class="bi bi-x-lg"></i></button>
        </div>

        <!-- Patient + Medicines summary -->
        <div class="cashier-summary">
            <div class="cashier-summary-row">
                <span class="cashier-lbl"><i class="bi bi-person-fill"></i> Patient</span>
                <span class="cashier-val" id="cashierPatient">—</span>
            </div>
            <div class="cashier-summary-row">
                <span class="cashier-lbl"><i class="bi bi-capsule-pill"></i> Medicines</span>
                <span class="cashier-val" id="cashierMeds" style="text-align:right;max-width:200px;">—</span>
            </div>
        </div>

        <!-- Amount breakdown -->
        <div class="cashier-breakdown">
            <div class="cashier-breakdown-row">
                <span>Subtotal</span>
                <span id="cashierSubtotal">₱0.00</span>
            </div>
            <div class="cashier-breakdown-row discount" id="cashierDiscountRow" style="display:none">
                <span><i class="bi bi-tag-fill"></i> Senior Discount</span>
                <span id="cashierDiscount" style="color:#d97706;">−₱0.00</span>
            </div>
            <div class="cashier-breakdown-row total">
                <span>Total Due</span>
                <span id="cashierTotal">₱0.00</span>
            </div>
        </div>

        <!-- Payment Method -->
        <div class="cashier-field">
            <label class="cashier-field-label">Payment Method</label>
            <div class="cashier-methods">
                <label class="cashier-method active" data-method="Cash">
                    <input type="radio" name="payMethod" value="Cash" checked onchange="selectMethod(this)">
                    <i class="bi bi-cash-stack"></i> Cash
                </label>
                <label class="cashier-method" data-method="GCash">
                    <input type="radio" name="payMethod" value="GCash" onchange="selectMethod(this)">
                    <i class="bi bi-phone-fill"></i> GCash
                </label>
                <label class="cashier-method" data-method="Card">
                    <input type="radio" name="payMethod" value="Card" onchange="selectMethod(this)">
                    <i class="bi bi-credit-card-2-front-fill"></i> Card
                </label>
                <label class="cashier-method" data-method="PhilHealth">
                    <input type="radio" name="payMethod" value="PhilHealth" onchange="selectMethod(this)">
                    <i class="bi bi-shield-plus"></i> PhilHealth
                </label>
            </div>
        </div>

        <!-- Amount Tendered (only for Cash) -->
        <div class="cashier-field" id="cashierTenderedWrap">
            <label class="cashier-field-label">Amount Tendered (₱)</label>
            <input type="number" id="cashierTenderedInput" class="cashier-input" min="0" step="0.01"
                placeholder="Enter amount paid…" oninput="calcChange()" onkeydown="if(event.key==='Enter')submitPayment()">
            <!-- Quick denomination buttons -->
            <div class="cashier-denoms" id="cashierDenoms"></div>
        </div>

        <!-- Change display -->
        <div class="cashier-change-card" id="cashierChangeCard">
            <div class="cashier-change-lbl">Change</div>
            <div class="cashier-change-val" id="cashierChangeVal">₱0.00</div>
        </div>
        <div class="cashier-insufficient" id="cashierInsufficient" style="display:none">
            <i class="bi bi-exclamation-triangle-fill"></i> Amount tendered is less than total due.
        </div>

        <!-- Actions -->
        <div class="cashier-actions">
            <button class="cashier-btn-cancel" onclick="closeCashier()">Cancel</button>
            <button class="cashier-btn-confirm" id="cashierConfirmBtn" onclick="submitPayment()" disabled>
                <i class="bi bi-check-circle-fill"></i> Confirm Payment
            </button>
        </div>
    </div>
</div>

<!-- ══ RECEIPT MODAL ══ -->
<?php if ($receipt_data): ?>
<div id="receiptOverlay" class="cashier-overlay" style="display:flex;" onclick="if(event.target===this)closeReceipt()">
    <div class="receipt-box" id="receiptPrintArea">
        <div class="receipt-header">
            <div class="receipt-brand">PharmaCare <span style="font-size:.6em;vertical-align:super;opacity:.7;">♡</span></div>
            <div class="receipt-sub">Official Pharmacy Receipt</div>
            <div class="receipt-date"><?= date('F d, Y · h:i A') ?></div>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-row"><span>Invoice</span><span>TXN-<?= fmtPad($receipt_data['InvoiceID']) ?></span></div>
        <div class="receipt-row"><span>Patient</span><span><?= htmlspecialchars($receipt_data['PatientName']) ?></span></div>
        <div class="receipt-row"><span>Doctor</span><span><?= htmlspecialchars($receipt_data['DoctorName'] ?? '—') ?></span></div>
        <div class="receipt-row"><span>Pharmacist</span><span><?= htmlspecialchars($receipt_data['PharmacistName'] ?? '—') ?></span></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-meds"><?= htmlspecialchars($receipt_data['Medicines'] ?? '—') ?></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-row"><span>Subtotal</span><span>₱<?= number_format((float)$receipt_data['Subtotal'], 2) ?></span></div>
        <?php if ((float)$receipt_data['Discount'] > 0): ?>
        <div class="receipt-row" style="color:#d97706"><span>Senior Discount</span><span>−₱<?= number_format((float)$receipt_data['Discount'], 2) ?></span></div>
        <?php endif; ?>
        <div class="receipt-row receipt-total"><span>TOTAL</span><span>₱<?= number_format((float)$receipt_data['Total'], 2) ?></span></div>
        <div class="receipt-row"><span>Payment Method</span><span><?= htmlspecialchars($receipt_data['PaymentMethod'] ?? 'Cash') ?></span></div>
        <?php
            $tendered = (float)($receipt_data['AmountTendered'] ?? $receipt_data['Total']);
            $change   = $tendered - (float)$receipt_data['Total'];
        ?>
        <div class="receipt-row"><span>Amount Tendered</span><span>₱<?= number_format($tendered, 2) ?></span></div>
        <div class="receipt-row"><span>Change</span><span>₱<?= number_format(max(0, $change), 2) ?></span></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-footer">Thank you for choosing PharmaCare!<br><span style="font-size:.7rem;color:#94a3b8;">Please keep this receipt for your records.</span></div>
        <div class="receipt-actions no-print">
            <button class="cashier-btn-cancel" onclick="closeReceipt()">Close</button>
            <button class="cashier-btn-confirm" onclick="printReceipt()"><i class="bi bi-printer-fill"></i> Print Receipt</button>
        </div>
    </div>
</div>
<?php endif; ?>



<script>
'use strict';


/* ══ PAGINATION HELPER ══ */
function initPagination(tbodyId, pgContainerId, perPage = 5) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    const pgContainer = document.getElementById(pgContainerId);
    let currentPage = 1;

    function getVisibleRows() {
        return Array.from(tbody.querySelectorAll('tr[data-status], tr[data-id], tr[data-inv], tr:not(.no-paginate)'))
            .filter(r => r.style.display !== 'none');
    }

    function render() {
        const allRows = Array.from(tbody.querySelectorAll('tr[data-status], tr[data-id], tr[data-inv], tr:not(.no-paginate)'));
        const filteredRows = allRows.filter(r => !r._searchHidden);
        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * perPage;
        allRows.forEach(r => { r.style.display = 'none'; });
        filteredRows.slice(start, start + perPage).forEach(r => { r.style.display = ''; });
        renderControls(totalPages, total);
    }

    function renderControls(totalPages, total) {
        if (!pgContainer) return;
        pgContainer.innerHTML = '';
        if (total === 0) return;
        const info = document.createElement('span');
        info.className = 'pg-info';
        const start = (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, total);
        info.textContent = `${start}–${end} of ${total}`;
        const prev = document.createElement('button');
        prev.className = 'pg-btn'; prev.innerHTML = '<i class="bi bi-chevron-left"></i>';
        prev.disabled = currentPage === 1;
        prev.onclick = () => { if (currentPage > 1) { currentPage--; render(); } };
        pgContainer.appendChild(prev);
        // Page numbers
        for (let p = 1; p <= totalPages; p++) {
            if (totalPages > 7 && Math.abs(p - currentPage) > 2 && p !== 1 && p !== totalPages) {
                if (p === 2 || p === totalPages - 1) {
                    const dots = document.createElement('span');
                    dots.className = 'pg-info'; dots.textContent = '…';
                    pgContainer.appendChild(dots);
                }
                continue;
            }
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (p === currentPage ? ' active' : '');
            btn.textContent = p;
            btn.onclick = (pg => () => { currentPage = pg; render(); })(p);
            pgContainer.appendChild(btn);
        }
        const next = document.createElement('button');
        next.className = 'pg-btn'; next.innerHTML = '<i class="bi bi-chevron-right"></i>';
        next.disabled = currentPage === totalPages;
        next.onclick = () => { if (currentPage < totalPages) { currentPage++; render(); } };
        pgContainer.appendChild(next);
        pgContainer.appendChild(info);
    }

    // Expose reset for search/filter hooks
    window['_pgReset_' + tbodyId] = () => { currentPage = 1; render(); };
    render();
    return { reset: () => { currentPage = 1; render(); } };
}

let pgInvoices;
document.addEventListener('DOMContentLoaded', function() {
    pgInvoices = initPagination('invBody', 'pg-invoices', 5);
});
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
        row._searchHidden = !(matchSearch && matchStatus);
    });
    if (window['_pgReset_invBody']) window['_pgReset_invBody']();
}
searchInput.addEventListener('input', applyFilters);
filterSelect.addEventListener('change', applyFilters);

const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarToggle  = document.getElementById('sidebarToggle');
if (sidebarToggle) sidebarToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('show'); });
if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); });

/* ── Cashier Modal ── */
let _cashierInvoiceId = null;
let _cashierTotal     = 0;

function openCashierModal(invoiceId, txnLabel, total, patient, meds, discount) {
    _cashierInvoiceId = invoiceId;
    _cashierTotal     = total;

    document.getElementById('cashierInvId').textContent   = txnLabel;
    document.getElementById('cashierPatient').textContent = patient;
    document.getElementById('cashierMeds').textContent    = meds;

    const subtotal = total + discount;
    document.getElementById('cashierSubtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('cashierTotal').textContent    = '₱' + total.toFixed(2);

    const discRow = document.getElementById('cashierDiscountRow');
    if (discount > 0) {
        discRow.style.display = '';
        document.getElementById('cashierDiscount').textContent = '−₱' + discount.toFixed(2);
    } else {
        discRow.style.display = 'none';
    }

    /* Reset fields */
    document.getElementById('cashierTenderedInput').value = '';
    document.getElementById('cashierChangeVal').textContent = '₱0.00';
    document.getElementById('cashierInsufficient').style.display = 'none';
    document.getElementById('cashierConfirmBtn').disabled = true;

    /* Default to Cash */
    selectMethod(document.querySelector('input[name="payMethod"][value="Cash"]'));

    /* Quick denominations */
    buildDenoms(total);

    document.getElementById('cashierOverlay').classList.add('show');
    setTimeout(() => document.getElementById('cashierTenderedInput').focus(), 120);
}

function buildDenoms(total) {
    const denoms = [20,50,100,200,500,1000];
    const container = document.getElementById('cashierDenoms');
    container.innerHTML = '';
    denoms.forEach(d => {
        if (d < total) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cashier-denom-btn';
        btn.textContent = '₱' + d.toLocaleString();
        btn.onclick = () => {
            document.getElementById('cashierTenderedInput').value = d;
            calcChange();
        };
        container.appendChild(btn);
    });
    /* Exact button */
    const exact = document.createElement('button');
    exact.type = 'button';
    exact.className = 'cashier-denom-btn';
    exact.textContent = 'Exact ₱' + total.toFixed(2);
    exact.style.borderColor = '#a5b4fc';
    exact.onclick = () => {
        document.getElementById('cashierTenderedInput').value = total.toFixed(2);
        calcChange();
    };
    container.appendChild(exact);
}

function selectMethod(input) {
    document.querySelectorAll('.cashier-method').forEach(l => l.classList.remove('active'));
    if (input) {
        input.checked = true;
        input.closest('.cashier-method').classList.add('active');
    }
    const isCash = !input || input.value === 'Cash';
    document.getElementById('cashierTenderedWrap').style.display = isCash ? '' : 'none';
    document.getElementById('cashierChangeCard').style.display   = isCash ? '' : 'none';
    if (!isCash) {
        document.getElementById('cashierInsufficient').style.display = 'none';
        document.getElementById('cashierConfirmBtn').disabled = false;
    } else {
        calcChange();
    }
}

function calcChange() {
    const tendered = parseFloat(document.getElementById('cashierTenderedInput').value) || 0;
    const change   = tendered - _cashierTotal;
    const ok       = tendered >= _cashierTotal;
    document.getElementById('cashierChangeVal').textContent = ok ? '₱' + change.toFixed(2) : '₱0.00';
    document.getElementById('cashierChangeCard').style.borderColor  = ok ? '#bbf7d0' : '#fecaca';
    document.getElementById('cashierChangeCard').style.background   = ok ? '#f0fdf4' : '#fff5f5';
    document.getElementById('cashierChangeVal').style.color         = ok ? '#15803d' : '#dc2626';
    document.getElementById('cashierInsufficient').style.display    = (!ok && tendered > 0) ? '' : 'none';
    document.getElementById('cashierConfirmBtn').disabled = !ok;
}

function submitPayment() {
    const invoiceId = _cashierInvoiceId;
    const method    = document.querySelector('input[name="payMethod"]:checked')?.value || 'Cash';
    const isCash    = method === 'Cash';
    const tendered  = isCash ? parseFloat(document.getElementById('cashierTenderedInput').value) : _cashierTotal;

    if (isCash && tendered < _cashierTotal) return;

    document.getElementById('tendered' + invoiceId).value = tendered.toFixed(2);
    document.getElementById('method'   + invoiceId).value = method;
    document.getElementById('payForm'  + invoiceId).submit();
}

function closeCashier() {
    document.getElementById('cashierOverlay').classList.remove('show');
}

function closeReceipt() {
    const el = document.getElementById('receiptOverlay');
    if (el) el.style.display = 'none';
}

function printReceipt() {
    window.print();
}

/* legacy — kept for cancel confirm */
function confirmPaid(btn, txnId, total) {
    const form = btn.closest('form');
    pcConfirm({
        title:  'Confirm Payment',
        body:   `Mark <strong>${txnId}</strong> as paid?<br><span style="color:#16a34a;font-weight:700;">Total: ₱${total}</span>`,
        okText: 'Confirm Payment',
        type:   'info',
        icon:   'bi-cash-coin',
        onOk:   () => form.submit()
    });
}
function confirmCancel(btn, txnId) {
    const form = btn.closest('form');
    pcConfirm({
        title:  'Cancel Invoice',
        body:   `Cancel invoice <strong>${txnId}</strong>? This cannot be undone.`,
        okText: 'Cancel Invoice',
        type:   'danger',
        icon:   'bi-x-circle-fill',
        onOk:   () => form.submit()
    });
}

/* Auto-show receipt if just paid */
<?php if ($receipt_data): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ro = document.getElementById('receiptOverlay');
    if (ro) ro.style.display = 'flex';
});
<?php endif; ?>

function showToast(msg, type = 'ok', duration = 3200) {
    const icons = { ok:'bi-check-circle-fill', warn:'bi-exclamation-triangle-fill', err:'bi-x-circle-fill', info:'bi-info-circle-fill' };
    const tray  = document.getElementById('toastTray');
    if (!tray) return;
    const toast = document.createElement('div');
    toast.className = `toast-msg t-${type}`;
    toast.innerHTML = `<i class="bi ${icons[type]||'bi-info-circle-fill'} t-icon"></i><span>${msg}</span>`;
    tray.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'opacity .3s ease, transform .3s ease';
        toast.style.opacity    = '0';
        toast.style.transform  = 'translateY(8px) scale(.96)';
        setTimeout(() => toast.remove(), 320);
    }, duration);
}

function pcConfirm({ title='Are you sure?', body='', okText='Confirm', type='warning', icon=null, onOk }) {
    const iconMap = {
        danger:  { bi:'bi-exclamation-triangle-fill', cls:'danger'  },
        warning: { bi:'bi-exclamation-circle-fill',   cls:'warning' },
        info:    { bi:'bi-info-circle-fill',           cls:'info'    },
    };
    const ic = iconMap[type] || iconMap.warning;
    const usedIcon = icon || ic.bi;
    let overlay = document.getElementById('pcConfirmOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id        = 'pcConfirmOverlay';
        overlay.className = 'pc-confirm-overlay';
        overlay.innerHTML = `
            <div class="pc-confirm-box">
                <div class="pc-confirm-icon" id="pcConfirmIcon"><i class="bi" id="pcConfirmIconI"></i></div>
                <div class="pc-confirm-title" id="pcConfirmTitle"></div>
                <div class="pc-confirm-body"  id="pcConfirmBody"></div>
                <div class="pc-confirm-btns">
                    <button class="pc-confirm-cancel" id="pcConfirmCancel">Cancel</button>
                    <button class="pc-confirm-ok"     id="pcConfirmOk"></button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.addEventListener('click', e => { if (e.target === overlay) closeConfirm(); });
        document.getElementById('pcConfirmCancel').addEventListener('click', closeConfirm);
    }
    document.getElementById('pcConfirmIcon').className    = `pc-confirm-icon ${ic.cls}`;
    document.getElementById('pcConfirmIconI').className   = `bi ${usedIcon}`;
    document.getElementById('pcConfirmTitle').textContent = title;
    document.getElementById('pcConfirmBody').innerHTML    = body;
    const okBtn = document.getElementById('pcConfirmOk');
    okBtn.textContent = okText;
    okBtn.className   = `pc-confirm-ok ${type}`;
    okBtn.onclick     = () => { closeConfirm(); if (onOk) onOk(); };
    requestAnimationFrame(() => overlay.classList.add('show'));
}
function closeConfirm() {
    const ov = document.getElementById('pcConfirmOverlay');
    if (ov) ov.classList.remove('show');
}
</script>
</body>
</html>