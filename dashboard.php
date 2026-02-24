<?php
// Pointing to the new folder location
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

$page_title = 'Dashboard';

try {
    $db = getDB();

    // 1. Revenue Today
    $s = $db->prepare("SELECT IFNULL(SUM(i.Total),0) FROM invoices i 
        JOIN prescriptions pr ON i.PrescriptionID=pr.PrescriptionID 
        WHERE DATE(pr.DatePrescribed)=CURDATE()");
    $s->execute(); 
    $revenue = (float) $s->fetchColumn();

    // 2. Patients Today
    $s = $db->prepare("SELECT COUNT(DISTINCT PatientID) FROM prescriptions WHERE DATE(DatePrescribed)=CURDATE()");
    $s->execute(); 
    $patients = (int) $s->fetchColumn();

    // 3. Low Stock Items
    $s = $db->prepare("SELECT m.GenericName, m.BrandName, m.DosageStrength, SUM(md.StockAvailability) AS TotalStock 
        FROM medications m JOIN medicationdetails md ON m.MedicationID=md.MedicationID 
        GROUP BY m.MedicationID HAVING TotalStock <= 200 ORDER BY TotalStock ASC LIMIT 5");
    $s->execute(); 
    $lowItems = $s->fetchAll();

} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Helper functions for stock UI
function barClass($q) { return $q <= 50 ? 'pc-bar-red' : ($q <= 150 ? 'pc-bar-orange' : 'pc-bar-teal'); }
function sqClass($q)  { return $q <= 50 ? 'pc-stock-critical' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — <?= $page_title ?></title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body class="pc-body-bg">

<div class="pc-layout">
    <?php include 'header.php'; ?>

    <main class="pc-main-content">
        <div class="pc-container">
            
            <div class="pc-stats-grid">
                <div class="pc-card pc-stat-card">
                    <h3>Today's Revenue</h3>
                    <p class="pc-stat-value">₱<?= number_format($revenue, 2) ?></p>
                </div>
                <div class="pc-card pc-stat-card">
                    <h3>Patients Served</h3>
                    <p class="pc-stat-value"><?= $patients ?></p>
                </div>
            </div>

            <div class="pc-card pc-inventory-card">
                <div class="pc-card-header">
                    <h2>Inventory Alerts (Low Stock)</h2>
                </div>
                <div class="pc-stock-list">
                    <?php if(empty($lowItems)): ?>
                        <p>All items are well-stocked.</p>
                    <?php else: ?>
                        <?php foreach($lowItems as $item): 
                            $qty = (int)$item['TotalStock'];
                            $pct = min(round(($qty/200)*100), 100);
                        ?>
                            <div class="pc-stock-item">
                                <div class="pc-stock-info">
                                    <strong><?= htmlspecialchars($item['GenericName']) ?></strong>
                                    <span><?= $qty ?> units left</span>
                                </div>
                                <div class="pc-progress-bg">
                                    <div class="pc-progress-fill <?= barClass($qty) ?>" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<div id="toastTray" class="pc-toast-tray"></div>
<script src="dashboard.js"></script>
</body>
</html>