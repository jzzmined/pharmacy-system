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

            <a href="dashboard.php" class="nav-item active" data-label="Dashboard">
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

            <a href="transactions.php" class="nav-item" data-label="Transactions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <circle cx="12" cy="15" r="3"/>
                    <polyline points="12 13.5 12 15 13 16"/>
                </svg>
            </a>

            <a href="inventory.php" class="nav-item" data-label="Medications">
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

            <!-- Stat Cards -->
            <div class="stats-row">

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="9" cy="7" r="3"/>
                            <path d="M2 20a7 7 0 0 1 14 0"/>
                            <circle cx="17" cy="8" r="2.5"/>
                            <path d="M22 20a5 5 0 0 0-5-5"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statPatients"><?= $patients ?></div>
                        <div class="stat-label">Total Patients</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                            <rect x="9" y="3" width="6" height="4" rx="2"/>
                            <text x="8" y="16" font-size="6" stroke="none" fill="currentColor" font-weight="bold">Rx</text>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statPrescriptions"><?= $prescriptions ?></div>
                        <div class="stat-label">Active Prescriptions</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <circle cx="12" cy="15" r="3"/>
                            <polyline points="12 13.5 12 15 13 16"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value" id="statTransactions"><?= count($transactions) ?></div>
                        <div class="stat-label">Total Transactions</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <line x1="12" y1="9" x2="12" y2="15"/>
                            <path d="M15 9.5H10.5a1.5 1.5 0 0 0 0 3h3a1.5 1.5 0 0 1 0 3H9"/>
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
                            <div class="card-title-icon cti-teal">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                                    <line x1="9" y1="12" x2="15" y2="12"/>
                                    <line x1="9" y1="16" x2="13" y2="16"/>
                                </svg>
                            </div>
                            Recent Transactions
                        </div>
                        <a href="transactions.php" style="font-size:.75rem;color:#6366f1;font-weight:600;text-decoration:none;">View All →</a>
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
                                    <th>Medicine</th>
                                    <th>Stock</th>
                                </tr>
                            </thead>
                            <tbody id="stockBody">
                            <?php if(empty($lowItems)): ?>
                                <tr><td colspan="2" class="empty-state">All medications well-stocked 🎉</td></tr>
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
                </div>

                <!-- Top Dispensed Medicines -->
                <div class="card">
                    <div class="card-head">
                        <div class="card-title">
                            <div class="card-title-icon cti-blue">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3"  y="12" width="4" height="8" rx="1"/>
                                    <rect x="10" y="7"  width="4" height="13" rx="1"/>
                                    <rect x="17" y="3"  width="4" height="17" rx="1"/>
                                </svg>
                            </div>
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
                </div>

            </div><!-- /dash-grid -->
        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<div class="toast-tray" id="toastTray"></div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>