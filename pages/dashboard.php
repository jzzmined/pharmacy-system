<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Dashboard';

try {
    $db = getDB();

    $s = $db->prepare("SELECT IFNULL(SUM(Total),0) FROM invoices WHERE Status='Completed'");
    $s->execute(); $revenue = (float)$s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM patients");
    $s->execute(); $patients = (int)$s->fetchColumn();

    $s = $db->prepare("
        SELECT m.MedicationID,
               m.GenericName,
               m.BrandName,
               m.DosageStrength,
               GetMedicationStockLevel(m.MedicationID) AS LiveStock
        FROM medications m
        WHERE GetMedicationStockLevel(m.MedicationID) <= 300
        ORDER BY GetMedicationStockLevel(m.MedicationID) ASC
        LIMIT 8
    ");
    $s->execute(); $lowItems = $s->fetchAll(); $lowCount = count($lowItems);

    $s = $db->prepare("SELECT i.InvoiceID, i.PharmacistID, i.Status,
        p.FullName AS PatientName,
        GROUP_CONCAT(DISTINCT m.GenericName SEPARATOR ', ') AS Medicines,
        i.Total, pr.DatePrescribed
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
        JOIN patients p ON pr.PatientID=p.PatientID
        LEFT JOIN prescriptiondetails pd ON pr.PrescriptionID=pd.PrescriptionID
        LEFT JOIN medications m ON pd.MedicationID=m.MedicationID
        GROUP BY i.InvoiceID ORDER BY i.InvoiceID DESC LIMIT 10");
    $s->execute(); $transactions = $s->fetchAll();

    $s = $db->prepare("SELECT COUNT(*) FROM prescriptions");
    $s->execute(); $prescriptions = (int)$s->fetchColumn();

    // Top Dispensed Medicines — real data from prescriptiondetails + invoices (Completed only)
    $s = $db->prepare("
        SELECT CONCAT(m.GenericName, ' ', m.DosageStrength) AS MedName,
               SUM(i.DispenseQuantity) AS TotalQty
        FROM invoices i
        JOIN prescriptions pr      ON i.PrescriptionID  = pr.PrescriptionID
        JOIN prescriptiondetails pd ON pd.PrescriptionID = pr.PrescriptionID
        JOIN medications m          ON pd.MedicationID   = m.MedicationID
        WHERE i.Status = 'Completed'
        GROUP BY m.MedicationID
        ORDER BY TotalQty DESC
        LIMIT 5
    ");
    $s->execute(); $topMedsData = $s->fetchAll();

    // Expiring Soon — medications expiring within 6 months, grouped by medication
    $s = $db->prepare("
        SELECT CONCAT(m.GenericName, ' ', m.DosageStrength) AS MedName,
               MIN(md.ExpirationDate) AS EarliestExpiry,
               TIMESTAMPDIFF(MONTH, CURDATE(), MIN(md.ExpirationDate)) AS MonthsLeft
        FROM medicationdetails md
        JOIN medications m ON md.MedicationID = m.MedicationID
        WHERE md.ExpirationDate IS NOT NULL
          AND md.ExpirationDate >= CURDATE()
          AND md.ExpirationDate <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY m.MedicationID
        ORDER BY EarliestExpiry ASC
        LIMIT 8
    ");
    $s->execute(); $expiringData = $s->fetchAll();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
$topMedsData  = $topMedsData  ?? [];
$expiringData = $expiringData ?? [];

function barClass($q) { return $q <= 50 ? 'sb-red' : 'sb-amber'; }
function sqClass($q)  { return $q <= 50 ? 'sq-critical' : 'sq-low'; }
function fmtPad($n, $len=3) { return str_pad($n, $len, '0', STR_PAD_LEFT); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Dashboard</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

            <a href="dashboard.php" class="nav-item active" data-label="Dashboard">
                <i class="bi bi-house-door-fill"></i>
            </a>

            <a href="prescriptions.php" class="nav-item" data-label="Prescriptions">
                <i class="bi bi-file-medical-fill"></i>
            </a>

            <a href="transactions.php" class="nav-item" data-label="Transactions">
                <i class="bi bi-receipt-cutoff"></i>
            </a>

            <a href="inventory.php" class="nav-item" data-label="Medications">
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

            <!-- Stat Cards -->
            <div class="stats-row">

                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statPatients"><?= $patients ?></div>
                        <div class="stat-label">Total Patients</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-file-medical-fill"></i>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statPrescriptions"><?= $prescriptions ?></div>
                        <div class="stat-label">Active Prescriptions</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-receipt-cutoff"></i>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statTransactions"><?= count($transactions) ?></div>
                        <div class="stat-label">Total Transactions</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon"><i class="bi bi-cash-coin"></i>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statRevenue">₱<?= number_format($revenue, 0) ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                </div>

            </div><!-- /stats-row -->

            <!-- Grid -->
            <div class="dash-grid">

                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-teal"><i class="bi bi-receipt-cutoff"></i>                            </div>
                            Recent Transactions
                        </div>
                        <a href="transactions.php" style="font-size:.75rem;color:#6366f1;font-weight:600;text-decoration:none;">View All <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="table-scroll" style="max-height:420px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#cbd5e1 #f1f5f9;">
                        <table style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Invoice ID</th>
                                    <th>Patient</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="txnBody">
                            <?php if(empty($transactions)): ?>
                                <tr><td colspan="4" class="empty-state">No transactions found</td></tr>
                            <?php else: foreach($transactions as $r):
                                $st = strtolower($r['Status'] ?? 'pending');
                            ?>
                                <tr>
                                    <td class="td-id" style="font-weight:700;color:#6366f1;font-size:.78rem;">INV-<?= fmtPad($r['InvoiceID']) ?></td>
                                    <td class="td-bold" style="font-weight:600;color:#1e293b;font-size:.85rem;"><?= htmlspecialchars($r['PatientName']) ?></td>
                                    <td class="td-amt" style="font-weight:700;color:#1e293b;">₱<?= number_format((float)$r['Total'], 2) ?></td>
                                    <td>
                                        <span style="display:inline-block;padding:3px 12px;border-radius:999px;font-size:.72rem;font-weight:700;
                                            <?= $st==='completed' ? 'background:#dcfce7;color:#15803d;' : ($st==='cancelled' ? 'background:#fee2e2;color:#dc2626;' : 'background:#fef9c3;color:#92400e;') ?>">
                                            <?= $st === 'completed' ? 'Paid' : ucfirst($st) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="pg-txn"></div>
                </div>

                <!-- Low Stock Alerts -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-amber"><i class="bi bi-exclamation-triangle-fill"></i>                            </div>
                            Low Stock Alerts
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Stock</th>
                                </tr>
                            </thead>
                            <tbody id="stockBody">
                            <?php if(empty($lowItems)): ?>
                                <tr><td colspan="2" class="empty-state">All medications well-stocked</td></tr>
                            <?php else: foreach($lowItems as $item):
                                $qty = (int)$item['LiveStock'];
                                $isCritical = $qty <= 100;
                            ?>
                                <tr>
                                    <td class="td-bold"><?= htmlspecialchars($item['GenericName']) ?> <?= htmlspecialchars($item['DosageStrength']) ?></td>
                                    <td>
                                        <span style="font-weight:700;color:<?= $isCritical ? '#ef4444' : '#f59e0b' ?>"><?= $qty ?> left</span>
                                        <span style="margin-left:6px;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:999px;
                                            <?= $isCritical ? 'background:#fee2e2;color:#dc2626;' : 'background:#fef3c7;color:#d97706;' ?>">
                                            <?= $isCritical ? 'Critical' : 'Low' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="pg-stock"></div>
                </div>

                <!-- Top Dispensed Medicines -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-blue"><i class="bi bi-bar-chart-fill"></i>                            </div>
                            Top Dispensed Medicines
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="dispensed-table">
                            <thead>
                                <tr>
                                    <th style="width:60px;text-align:center">Rank</th>
                                    <th>Medicine</th>
                                    <th>Qty</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($topMedsData)): ?>
                                <tr><td colspan="4" class="empty-state" style="text-align:center;padding:20px;color:#94a3b8;">No dispensed medicines yet.</td></tr>
                            <?php else:
                                $maxQty = (int)$topMedsData[0]['TotalQty'];
                                foreach($topMedsData as $i => $med): ?>
                                <tr>
                                    <td class="td-rank"><?= $i + 1 ?></td>
                                    <td class="td-med"><?= htmlspecialchars($med['MedName']) ?></td>
                                    <td class="td-qty"><?= (int)$med['TotalQty'] ?></td>
                                    <td>
                                        <div class="dist-bar-wrap">
                                            <div class="dist-bar-bg">
                                                <div class="dist-bar-fill" style="width:<?= $maxQty > 0 ? round(((int)$med['TotalQty'] / $maxQty) * 100) : 0 ?>%"></div>
                                            </div>
                                            <span class="dist-label"><?= (int)$med['TotalQty'] ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="pg-dispensed"></div>
                </div>

                <!-- Expiring Soon -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-green"><i class="bi bi-clock-history"></i>                            </div>
                            Expiring Soon
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="expiry-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>Expiry</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($expiringData)): ?>
                                <tr><td colspan="3" class="empty-state" style="text-align:center;padding:20px;color:#94a3b8;">No medications expiring within 6 months.</td></tr>
                            <?php else: foreach($expiringData as $e):
                                $months = (int)$e['MonthsLeft'];
                                $badgeClass = $months <= 1 ? 'status-danger' : ($months <= 3 ? 'status-warn' : 'status-ok');
                                $label = $months <= 0 ? 'This month' : $months . ' month' . ($months > 1 ? 's' : '');
                            ?> <tr>
                                    <td class="td-med-name"><?= htmlspecialchars($e['MedName']) ?></td>
                                    <td class="td-exp-date"><?= date('M d, Y', strtotime($e['EarliestExpiry'])) ?></td>
                                    <td><span class="status-badge <?= $badgeClass ?>"><?= $label ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="pg-expiry"></div>
                </div>

            </div><!-- /dash-grid -->
        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>
<script>

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

document.addEventListener('DOMContentLoaded', function() {
    initPagination('txnBody',   'pg-txn',       5);
    initPagination('stockBody', 'pg-stock',     5);
});
</script>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>