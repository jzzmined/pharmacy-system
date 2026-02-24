<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

$page_title = 'Dashboard';

try {
    $db = getDB();

    // 1. Today's Revenue
    $s = $db->prepare("SELECT IFNULL(SUM(i.Total),0) FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
        WHERE DATE(pr.DatePrescribed)=CURDATE()");
    $s->execute();
    $revenue = (float) $s->fetchColumn();

    // 2. Patients Today
    $s = $db->prepare("SELECT COUNT(DISTINCT PatientID) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute();
    $patients = (int) $s->fetchColumn();

    // 3. Low Stock Items (threshold: 200)
    $s = $db->prepare("SELECT m.GenericName, m.BrandName, m.DosageStrength,
        SUM(md.StockAvailability) AS TotalStock
        FROM medications m JOIN medicationdetails md ON m.MedicationID=md.MedicationID
        GROUP BY m.MedicationID HAVING TotalStock <= 200 ORDER BY TotalStock ASC LIMIT 5");
    $s->execute();
    $lowItems = $s->fetchAll();
    $lowCount = count($lowItems);

    // 4. Recent Transactions
    $s = $db->prepare("SELECT i.InvoiceID,
        p.FullName AS PatientName,
        GROUP_CONCAT(m.GenericName SEPARATOR ', ') AS Medicines,
        i.Total,
        pr.DatePrescribed
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID
        JOIN patients p ON pr.PatientID=p.PatientID
        JOIN prescriptiondetails pd ON pr.PrescriptionID=pd.PrescriptionID
        JOIN medications m ON pd.MedicationID=m.MedicationID
        GROUP BY i.InvoiceID
        ORDER BY pr.DatePrescribed DESC LIMIT 10");
    $s->execute();

    $transactions = $s->fetchAll();

    // 5. Total Prescriptions Today
    $s = $db->prepare("SELECT COUNT(*) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute();
    $prescriptions = (int) $s->fetchColumn();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

function barClass($q) { return $q <= 50 ? 'sb-red' : 'sb-amber'; }
function sqClass($q)  { return $q <= 50 ? 'sq-critical' : 'sq-low'; }
function fmtPad($n, $len = 3) { return str_pad($n, $len, '0', STR_PAD_LEFT); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Dashboard</title>
    <link rel="stylesheet" href="dashboard.css">
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
                    <line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>
                </svg>
            </div>
            <span>
                <span class="brand-name">PharmaCare</span>
                <span class="brand-sub">Pharmacy System</span>
            </span>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main</div>

            <a href="dashboard.php" class="nav-item active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                Dashboard
            </a>

            <a href="prescriptions.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                    <rect x="9" y="3" width="6" height="4" rx="2"/>
                </svg>
                Prescriptions
            </a>

            <a href="transactions.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
                Transactions
            </a>

            <a href="medications.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                </svg>
                Medications
            </a>

            <a href="patients.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Patients
            </a>

            <div class="nav-label" style="margin-top:10px;">Admin</div>

            <a href="users.php" class="nav-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                </svg>
                Users
            </a>
        </nav>

        <a href="logout.php" class="sidebar-footer" onclick="return confirm('Log out?')">
            <div class="s-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'P', 0, 1)) ?></div>
            <span>
                <span class="s-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Pharmacist') ?></span>
                <span class="s-role">Pharmacist</span>
            </span>
            <svg class="s-logout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </a>
    </aside>

    <!-- ══ MAIN AREA ══ -->
    <div class="main-area">

        <!-- Topbar -->
        <?php include 'header.php'; ?>

        <!-- Page Body -->
        <div class="page-body">

            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div>
                    <div class="wb-title">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Pharmacist') ?> 👋</div>
                    <div class="wb-sub">Here is what's happening in your pharmacy today.</div>
                </div>
                <div class="wb-pill"><?= date('D, M j Y') ?></div>
            </div>

            <!-- Stat Cards -->
            <div class="stats-row">
                <div class="stat-card c-teal">
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

                <div class="stat-card c-blue">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                            <rect x="9" y="3" width="6" height="4" rx="2"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Prescriptions Today</div>
                        <div class="stat-value" id="statPrescriptions"><?= $prescriptions ?></div>
                    </div>
                </div>

                <div class="stat-card c-amber">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Low Stock Alerts</div>
                        <div class="stat-value" id="statLowStock"><?= $lowCount ?></div>
                        <?php if($lowCount > 0): ?>
                        <span class="stat-badge sb-warn">⚠ Needs restock</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="stat-card c-green">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-label">Today's Revenue</div>
                        <div class="stat-value" id="statRevenue">₱<?= number_format($revenue, 2) ?></div>
                    </div>
                </div>
            </div>

            <!-- Main Grid: Transactions + Low Stock -->
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
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input class="search-box" id="searchTxn" placeholder="Search…" type="text">
                            <a href="transactions.php" class="card-link">View all</a>
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>TXN ID</th>
                                    <th>Patient</th>
                                    <th>Medicines</th>
                                    <th>Total</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="txnBody">
                            <?php if(empty($transactions)): ?>
                                <tr><td colspan="5" class="empty-state">No transactions found</td></tr>
                            <?php else: ?>
                                <?php foreach($transactions as $r): ?>
                                <tr>
                                    <td class="td-id">INV-<?= fmtPad($r['InvoiceID']) ?></td>
                                    <td class="td-bold"><?= htmlspecialchars($r['PatientName']) ?></td>
                                    <td class="td-sm"><?= htmlspecialchars($r['Medicines']) ?></td>
                                    <td class="td-amt">₱<?= number_format($r['Total'], 2) ?></td>
                                    <td class="td-sm"><?= date('M j, Y', strtotime($r['DatePrescribed'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Low Stock Panel -->
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
                        <a href="medications.php" class="card-link">Manage</a>
                    </div>
                    <div class="stock-list" id="stockList">
                    <?php if(empty($lowItems)): ?>
                        <div class="empty-state">All medications are well-stocked 🎉</div>
                    <?php else: ?>
                        <?php foreach($lowItems as $item):
                            $qty = (int)$item['TotalStock'];
                            $pct = min(round(($qty / 200) * 100), 100);
                        ?>
                        <div class="stock-item">
                            <div class="stock-row">
                                <span class="stock-name"><?= htmlspecialchars($item['GenericName']) ?> <?= htmlspecialchars($item['DosageStrength']) ?></span>
                                <span class="stock-qty <?= sqClass($qty) ?>"><?= $qty ?> left</span>
                            </div>
                            <div class="stock-bar-bg">
                                <div class="stock-bar <?= barClass($qty) ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div class="stock-brand"><?= htmlspecialchars($item['BrandName']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>

            </div><!-- /.dash-grid -->
        </div><!-- /.page-body -->
    </div><!-- /.main-area -->
</div><!-- /.app-layout -->

<!-- Toast Tray -->
<div class="toast-tray" id="toastTray"></div>

<script src="dashboard.js"></script>
</body>
</html>