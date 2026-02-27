<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Dashboard';

try {
    $db = getDB();

    $s = $db->prepare("SELECT IFNULL(SUM(i.Total),0) FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
        WHERE DATE(pr.DatePrescribed)=CURDATE()");
    $s->execute(); $revenue = (float)$s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(DISTINCT PatientID) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute(); $patients = (int)$s->fetchColumn();

    $s = $db->prepare("SELECT m.GenericName, m.BrandName, m.DosageStrength,
        SUM(md.StockAvailability) AS TotalStock
        FROM medications m JOIN medicationdetails md ON m.MedicationID=md.MedicationID
        GROUP BY m.MedicationID HAVING TotalStock <= 200 ORDER BY TotalStock ASC LIMIT 5");
    $s->execute(); $lowItems = $s->fetchAll(); $lowCount = count($lowItems);

    $s = $db->prepare("SELECT i.InvoiceID, p.FullName AS PatientName,
        GROUP_CONCAT(m.GenericName SEPARATOR ', ') AS Medicines,
        i.Total, pr.DatePrescribed
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
        JOIN patients p ON pr.PatientID=p.PatientID
        JOIN prescriptiondetails pd ON pr.PrescriptionID=pd.PrescriptionID
        JOIN medications m ON pd.MedicationID=m.MedicationID
        GROUP BY i.InvoiceID ORDER BY pr.DatePrescribed DESC LIMIT 10");
    $s->execute(); $transactions = $s->fetchAll();

    $s = $db->prepare("SELECT COUNT(*) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute(); $prescriptions = (int)$s->fetchColumn();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

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
    <link rel="stylesheet" href="assets/css/main.css">
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
            <span class="brand-name">Pharma<br>Care</span>
        </div>

        <nav class="sidebar-nav">

            <a href="dashboard.php" class="nav-item active" data-label="Dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="3" width="7" height="7" rx="1"/>
                    <rect x="14" y="14" width="7" height="7" rx="1"/>
                    <rect x="3" y="14" width="7" height="7" rx="1"/>
                </svg>
            </a>

            <a href="pages/prescriptions.php" class="nav-item" data-label="Prescriptions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                </svg>
            </a>

            <a href="pages/transactions.php" class="nav-item" data-label="Transactions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </a>

            <a href="pages/inventory.php" class="nav-item" data-label="Medications">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                </svg>
            </a>

            <a href="pages/admin.php" class="nav-item" data-label="Patients">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
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

            <!-- Welcome Banner -->
            <!-- <div class="welcome-banner">
                <div>
                    <div class="wb-title">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Pharmacist') ?> 👋</div>
                    <div class="wb-sub">Here is what's happening in your pharmacy today.</div>
                </div>
                <div class="wb-pill"><?= date('D, M j Y') ?></div>
            </div> -->

            <!-- Stat Cards -->
            <div class="stats-row">

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Total Patients Today</div>
                        <div class="stat-value" id="statPatients"><?= $patients ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                            <rect x="9" y="3" width="6" height="4" rx="2"/>
                            <line x1="9" y1="12" x2="15" y2="12"/>
                            <line x1="9" y1="16" x2="12" y2="16"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Active Prescriptions</div>
                        <div class="stat-value" id="statPrescriptions"><?= $prescriptions ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2"/>
                            <line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Total Transactions</div>
                        <div class="stat-value" id="statTransactions"><?= count($transactions) ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Total Revenue</div>
                        <div class="stat-value" id="statRevenue">₱<?= number_format($revenue, 0) ?></div>
                    </div>
                </div>

            </div><!-- /stats-row -->

            <!-- Grid -->
            <div class="dash-grid">

                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-teal">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="1" x2="12" y2="23"/>
                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                                </svg>
                            </div>
                            Recent Transactions
                        </div>
                        <!-- <div style="display:flex;gap:8px;align-items:center">
                            <input class="search-box" id="searchTxn" placeholder="Search…" type="text">
                        </div> -->
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice ID</th><th>Prescription ID</th>
                                    <th>Pharmacist ID</th><th>Subtotal</th><th>Total</th>
                                </tr>
                            </thead>
                            <tbody id="txnBody">
                            <?php if(empty($transactions)): ?>
                                <tr><td colspan="5" class="empty-state">No transactions found</td></tr>
                            <?php else: foreach($transactions as $r): ?>
                                <tr>
                                    <td class="td-id">INV-<?= fmtPad($r['InvoiceID']) ?></td>
                                    <td class="td-bold">RX-<?= fmtPad($r['InvoiceID']) ?></td>
                                    <td class="td-sm">PH-001</td>
                                    <td class="td-sm">₱<?= number_format($r['Total'] * 1.25, 2) ?></td>
                                    <td class="td-amt">₱<?= number_format($r['Total'],2) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Low Stock Alerts -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-amber">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            Low Stock Alerts
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Medicine</th><th>Stock</th>
                                </tr>
                            </thead>
                            <tbody id="stockBody">
                            <?php if(empty($lowItems)): ?>
                                <tr><td colspan="2" class="empty-state">All medications well-stocked 🎉</td></tr>
                            <?php else: foreach($lowItems as $item):
                                $qty = (int)$item['TotalStock'];
                            ?>
                                <tr>
                                    <td class="td-bold"><?= htmlspecialchars($item['GenericName']) ?> <?= htmlspecialchars($item['DosageStrength']) ?></td>
                                    <td class="td-id <?= $qty <= 50 ? 'sq-critical' : '' ?>" style="color:<?= $qty <= 50 ? '#ef4444' : '#f59e0b' ?>"><?= $qty ?> left</td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Dispensed Medicines -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-blue">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="20" x2="18" y2="10"/>
                                    <line x1="12" y1="20" x2="12" y2="4"/>
                                    <line x1="6"  y1="20" x2="6"  y2="14"/>
                                </svg>
                            </div>
                            Top Dispensed Medicines
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table class="dispensed-table">
                            <thead>
                                <tr>
                                    <th style="width:60px">Rank</th>
                                    <th>Medicine</th>
                                    <th>Qty</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $topMeds = [
                                ['name'=>'Metformin 500mg','qty'=>60],
                                ['name'=>'Paracetamol 500mg','qty'=>50],
                                ['name'=>'Ibuprofen 400mg','qty'=>40],
                            ];
                            $maxQty = $topMeds[0]['qty'];
                            foreach($topMeds as $i => $med): ?>
                                <tr>
                                    <td class="td-rank"><?= $i+1 ?></td>
                                    <td class="td-med"><?= htmlspecialchars($med['name']) ?></td>
                                    <td class="td-qty"><?= $med['qty'] ?></td>
                                    <td>
                                        <div class="dist-bar-wrap">
                                            <div class="dist-bar-bg">
                                                <div class="dist-bar-fill" style="width:<?= round(($med['qty']/$maxQty)*100) ?>%"></div>
                                            </div>
                                            <span class="dist-label"><?= $med['qty'] ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Expiring Soon -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-green">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                            </div>
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
                            <?php
                            $expiring = [
                                ['name'=>'Paracetamol 500mg','expiry'=>'Jun 30, 2026','months'=>4,'class'=>'status-warn'],
                                ['name'=>'Ciprofloxacin 500mg','expiry'=>'Jul 31, 2026','months'=>5,'class'=>'status-warn'],
                            ];
                            foreach($expiring as $e): ?>
                                <tr>
                                    <td class="td-med-name"><?= htmlspecialchars($e['name']) ?></td>
                                    <td class="td-exp-date"><?= $e['expiry'] ?></td>
                                    <td><span class="status-badge <?= $e['class'] ?>"><?= $e['months'] ?> months</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- /dash-grid -->
        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>
<script src="assets/js/dashboard.js"></script>
</body>
</html>