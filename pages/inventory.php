<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Inventory';

/* ══════════════════════════════════════════════════════
   POST — Add stock via CALL AddMedicationStock()
   ══════════════════════════════════════════════════════ */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_stock') {
    try {
        $db = getDB();

        $generic_name = trim($_POST['generic_name'] ?? '');
        $brand_name = trim($_POST['brand_name'] ?? '');
        $dosage_strength = trim($_POST['dosage_strength'] ?? '');
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $unit_price = (float) ($_POST['unit_price'] ?? 0);
        $stock_qty = (int) ($_POST['stock_availability'] ?? 0);
        $expiration_date = $_POST['expiration_date'] ?? '';
        $existing_med_id = (int) ($_POST['existing_med_id'] ?? 0);
        $category_id = (int) ($_POST['category_id'] ?? 0) ?: null;

        if ($existing_med_id > 0) {
            // Adding to existing — only need stock qty and expiry
            if ($stock_qty <= 0 || empty($expiration_date)) {
                throw new Exception("Stock quantity and expiration date are required.");
            }
        } else {
            // New medication — need all fields
            if (empty($generic_name) || $stock_qty <= 0 || empty($expiration_date)) {
                throw new Exception("Generic name, stock quantity, and expiration date are required.");
            }
        }

        // If adding stock to existing medication, use AddMedicationStock() procedure
        if ($existing_med_id > 0) {
            // CALL AddMedicationStock(p_MedicationID, p_Manufacturer, p_ExpirationDate, p_Quantity)
            // Procedure handles: INSERT into medicationdetails + returns batch summary
            // Trigger trg_After_Dispense_Stock_Check fires on stock UPDATE automatically
            $s = $db->prepare("CALL AddMedicationStock(?, ?, ?, ?)");
            $s->execute([$existing_med_id, $manufacturer, $expiration_date, $stock_qty]);
            $result = $s->fetch();
            $s->closeCursor();

            // Update unit price on the new batch
            if ($unit_price > 0 && $result && $result['Stock_Batch_ID']) {
                $s = $db->prepare("UPDATE medicationdetails SET UnitPrice = ? WHERE MedDet = ?");
                $s->execute([$unit_price, $result['Stock_Batch_ID']]);
            }

            $total_stock = $result['Total_Stock_Available'] ?? $stock_qty;
            $batch_id = $result['Stock_Batch_ID'] ?? '?';
            $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> Stock batch added via AddMedicationStock(). Batch ID: BATCH-" . str_pad($batch_id, 3, '0', STR_PAD_LEFT) .
                " | Total stock now: " . $total_stock;

        } else {
            // New medication: check for duplicate (same GenericName + DosageStrength) first
            $chk = $db->prepare("SELECT MedicationID FROM medications WHERE GenericName = ? AND DosageStrength = ? LIMIT 1");
            $chk->execute([$generic_name, $dosage_strength]);
            $existing = $chk->fetch();
            if ($existing) {
                throw new Exception("A medication named \"" . htmlspecialchars($generic_name) . "\" with dosage \"" . htmlspecialchars($dosage_strength) . "\" already exists (MED-" . str_pad($existing['MedicationID'], 3, '0', STR_PAD_LEFT) . "). Use \"Add Stock to Existing\" tab to add stock to it.");
            }

            // Insert into medications
            $s = $db->prepare("INSERT INTO medications (GenericName, BrandName, DosageStrength, CategoryID) VALUES (?,?,?,?)");
            $s->execute([$generic_name, $brand_name, $dosage_strength, $category_id]);
            $new_med_id = $db->lastInsertId();

            // Now call procedure for the medicationdetails row
            $s = $db->prepare("CALL AddMedicationStock(?, ?, ?, ?)");
            $s->execute([$new_med_id, $manufacturer, $expiration_date, $stock_qty]);
            $result = $s->fetch();
            $s->closeCursor();

            // Set unit price
            if ($result && $result['Stock_Batch_ID']) {
                $s = $db->prepare("UPDATE medicationdetails SET UnitPrice=? WHERE MedDet=?");
                $s->execute([$unit_price, $result['Stock_Batch_ID']]);
            }

            $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> New medication added via AddMedicationStock(). MED-" . str_pad($new_med_id, 3, '0', STR_PAD_LEFT) .
                " | Initial stock: " . $stock_qty;
        }

    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Insufficient stock')) {
            $error = "<i class=\"bi bi-exclamation-triangle-fill\" style=\"color:#d97706\"></i> Stock check failed: Insufficient stock to complete this transaction.";
        } else {
            $error = "Database error: " . $msg;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ── Edit medication ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_medication') {
    try {
        $db = getDB();
        $id = (int)($_POST['medication_id'] ?? 0);
        $generic = trim($_POST['generic_name'] ?? '');
        $brand = trim($_POST['brand_name'] ?? '');
        $dosage = trim($_POST['dosage_strength'] ?? '');
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        if ($id > 0 && $generic !== '') {
            $s = $db->prepare("UPDATE medications SET GenericName=?, BrandName=?, DosageStrength=? WHERE MedicationID=?");
            $s->execute([$generic, $brand, $dosage, $id]);
            $s = $db->prepare("UPDATE medicationdetails SET UnitPrice=? WHERE MedicationID=?");
            $s->execute([$unit_price, $id]);
            $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> Medication MED-" . str_pad($id, 3, '0', STR_PAD_LEFT) . " updated successfully.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// ── Restore archived medication ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_medication') {
    try {
        $db = getDB();
        $id = (int)($_POST['medication_id'] ?? 0);
        if ($id > 0) {
            $nm = $db->prepare("SELECT GenericName, DosageStrength FROM medications WHERE MedicationID=?");
            $nm->execute([$id]);
            $med = $nm->fetch();
            $medName = $med ? htmlspecialchars($med['GenericName'] . ' ' . $med['DosageStrength']) : "This medication";

            $s = $db->prepare("UPDATE medications SET IsActive = 1 WHERE MedicationID=?");
            $s->execute([$id]);
            $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> \"{$medName}\" has been restored to active inventory.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge_duplicates') {
    try {
        $db = getDB();
        // Find MedicationIDs that have more than 1 medicationdetails row
        $dupes = $db->query("
            SELECT MedicationID, COUNT(*) AS cnt
            FROM medicationdetails
            GROUP BY MedicationID
            HAVING cnt > 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        $merged = 0;
        foreach ($dupes as $d) {
            $mid = (int)$d['MedicationID'];
            // Get all detail rows for this medication, ordered by MedDet ASC (keep first)
            $rows = $db->prepare("SELECT * FROM medicationdetails WHERE MedicationID=? ORDER BY MedDet ASC");
            $rows->execute([$mid]);
            $all = $rows->fetchAll(PDO::FETCH_ASSOC);

            $keep   = $all[0]; // keep the first row
            $keepId = $keep['MedDet'];

            // Sum all stock into the kept row
            $totalStock = array_sum(array_column($all, 'StockAvailability'));
            // Use earliest expiry date
            $expiries = array_filter(array_column($all, 'ExpirationDate'));
            sort($expiries);
            $earliestExpiry = $expiries[0] ?? $keep['ExpirationDate'];

            // Update the kept row with merged stock + earliest expiry
            $upd = $db->prepare("UPDATE medicationdetails SET StockAvailability=?, ExpirationDate=? WHERE MedDet=?");
            $upd->execute([$totalStock, $earliestExpiry, $keepId]);

            // Delete the duplicate rows (all except the kept one)
            $dupIds = array_slice(array_column($all, 'MedDet'), 1);
            foreach ($dupIds as $dupId) {
                $db->prepare("DELETE FROM medicationdetails WHERE MedDet=?")->execute([$dupId]);
            }
            $merged++;
        }

        $success = $merged > 0
            ? "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> Merged duplicates for {$merged} medication(s). Stock totals have been combined."
            : "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> No duplicates found — inventory is already clean.";

    } catch (PDOException $e) {
        $error = "Merge error: " . $e->getMessage();
    }
}

// ── Delete medication (hard delete) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_medication') {
    try {
        $db = getDB();
        $id = (int)($_POST['medication_id'] ?? 0);
        if ($id > 0) {
            $nm = $db->prepare("SELECT GenericName, DosageStrength FROM medications WHERE MedicationID=?");
            $nm->execute([$id]);
            $med = $nm->fetch();
            $medName = $med ? htmlspecialchars($med['GenericName'] . ' ' . $med['DosageStrength']) : "This medication";

            // Delete child rows first to avoid FK constraint errors
            $db->prepare("DELETE FROM medicationdetails WHERE MedicationID=?")->execute([$id]);
            $db->prepare("DELETE FROM medications WHERE MedicationID=?")->execute([$id]);

            $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> \"{$medName}\" has been permanently deleted.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// ── Archive medication (soft delete) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_medication') {
    try {
        $db = getDB();
        $id = (int)($_POST['medication_id'] ?? 0);
        if ($id > 0) {
            $nm = $db->prepare("SELECT GenericName, DosageStrength FROM medications WHERE MedicationID=?");
            $nm->execute([$id]);
            $med = $nm->fetch();
            $medName = $med ? htmlspecialchars($med['GenericName'] . ' ' . $med['DosageStrength']) : "This medication";

            $s = $db->prepare("UPDATE medications SET IsActive = 0 WHERE MedicationID=?");
            $s->execute([$id]);
            $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> \"{$medName}\" has been archived and removed from active inventory.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

try {
    $db = getDB();

    // Use GetMedicationStockLevel() function for accurate live stock on each medication
    $s = $db->prepare("
        SELECT
            m.MedicationID,
            m.GenericName,
            m.BrandName,
            m.DosageStrength,
            MIN(md.Manufacturer)                     AS Manufacturer,
            MIN(md.ExpirationDate)                   AS ExpirationDate,
            MIN(md.UnitPrice)                        AS UnitPrice,
            GetMedicationStockLevel(m.MedicationID)  AS LiveStock,
            SUM(md.StockAvailability)                AS BatchStock,
            MIN(md.MedDet)                           AS MedDet,
            c.CategoryID,
            c.CategoryName,
            s.SupplierID,
            s.SupplierName
        FROM medications m
        LEFT JOIN medicationdetails md ON md.MedicationID = m.MedicationID
        LEFT JOIN categories c ON c.CategoryID = m.CategoryID
        LEFT JOIN suppliers  s ON s.SupplierID = m.SupplierID
        WHERE m.IsActive = 1
        GROUP BY m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength,
                 c.CategoryID, c.CategoryName, s.SupplierID, s.SupplierName
        ORDER BY c.CategoryName ASC, m.GenericName ASC
    ");
    $s->execute();
    $medications = $s->fetchAll();

    // Fetch categories for filter tabs & add form
    $s = $db->query("SELECT CategoryID, CategoryName FROM categories ORDER BY CategoryName ASC");
    $categories = $s->fetchAll();

    // Also fetch medication list for "add stock to existing" dropdown
    $s = $db->prepare("SELECT MedicationID, GenericName, BrandName FROM medications WHERE IsActive = 1 ORDER BY GenericName ASC");
    $s->execute();
    $med_list = $s->fetchAll();

    // Fetch archived medications
    $s = $db->prepare("
        SELECT
            m.MedicationID,
            m.GenericName,
            m.BrandName,
            m.DosageStrength,
            MIN(md.UnitPrice)       AS UnitPrice,
            MIN(md.ExpirationDate)  AS ExpirationDate,
            c.CategoryName,
            s.SupplierName
        FROM medications m
        LEFT JOIN medicationdetails md ON md.MedicationID = m.MedicationID
        LEFT JOIN categories c ON c.CategoryID = m.CategoryID
        LEFT JOIN suppliers  s ON s.SupplierID = m.SupplierID
        WHERE m.IsActive = 0
        GROUP BY m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength,
                 c.CategoryName, s.SupplierName
        ORDER BY m.GenericName ASC
    ");
    $s->execute();
    $archived = $s->fetchAll();

} catch (PDOException $e) {
    $medications = [];
    $med_list    = [];
    $categories  = [];
    $archived    = [];
}

function stockStatus(int $qty, string $expiry): string
{
    $daysLeft = (strtotime($expiry) - time()) / 86400;
    if ($qty <= 100)
        return 'critical';
    if ($daysLeft <= 90)
        return 'expiring';
    if ($qty <= 300)
        return 'low';
    return 'adequate';
}
function stockClass(string $status): string
{
    return match ($status) {
        'adequate' => 'stock-adequate',
        'expiring' => 'stock-expiring',
        'low' => 'stock-low',
        'critical' => 'stock-critical',
        default => 'stock-low',
    };
}
function fmtPad($n, $len = 3): string
{
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .inv-result.ok {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .inv-result.err {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .live-stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .7rem;
            background: #e0e7ff;
            color: #6366f1;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 700;
            margin-left: 4px;
            vertical-align: middle;
        }

        .tab-btns {
            display: flex;
            gap: 8px;
            margin-bottom: 0;
        }

        .tab-btn {
            padding: 7px 18px;
            border-radius: 8px 8px 0 0;
            border: 1.5px solid #e2e8f0;
            border-bottom: none;
            background: #f8fafc;
            color: #64748b;
            font-size: .84rem;
            cursor: pointer;
            font-weight: 600;
        }

        .tab-btn.active {
            background: #fff;
            color: #6366f1;
            border-color: #c7d2fe;
        }

        .med-col-live {
            font-weight: 700;
        }

        .med-col-batch {
            color: #94a3b8;
            font-size: .78rem;
        }
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
                <a href="transactions.php" class="nav-item" data-label="Transactions">
                <i class="bi bi-receipt-cutoff"></i>
                </a>
                <a href="inventory.php" class="nav-item active" data-label="Inventory">
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

                <?php if ($success): ?>
                    <div class="inv-result ok"><?= $success ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="inv-result err"><?= $error ?></div>
                <?php endif; ?>

                <!-- Inventory Card -->
                <div class="inventory-card">

                    <!-- Toolbar -->
                    <div class="inventory-toolbar">
                        <div class="inventory-toolbar-left">
                            <div class="inventory-title">
                                Inventory &amp; Stocks
                                <span class="live-stock-badge">Live via GetMedicationStockLevel()</span>
                            </div>
                        </div>
                        <div class="inventory-controls">
                            <!-- View Toggle -->
                            <div style="display:flex;border:1.5px solid #e2e8f0;border-radius:10px;overflow:hidden;flex-shrink:0;">
                                <button id="viewActive" onclick="switchView('active')"
                                    style="padding:8px 14px;font-size:.8rem;font-weight:700;font-family:'Outfit',sans-serif;border:none;cursor:pointer;background:#1e2d40;color:#fff;display:flex;align-items:center;gap:5px;">
                                    <i class="bi bi-capsule-pill"></i> Active
                                </button>
                                <button id="viewArchived" onclick="switchView('archived')"
                                    style="padding:8px 14px;font-size:.8rem;font-weight:700;font-family:'Outfit',sans-serif;border:none;cursor:pointer;background:#fff;color:#64748b;display:flex;align-items:center;gap:5px;">
                                    <i class="bi bi-archive"></i> Archived
                                    <?php if (!empty($archived)): ?>
                                    <span style="background:#ea580c;color:#fff;border-radius:20px;padding:1px 7px;font-size:.65rem;"><?= count($archived) ?></span>
                                    <?php endif; ?>
                                </button>
                            </div>
                            <input class="inv-search" id="medSearch" type="text" placeholder="Search…" autocomplete="off">
                            <select class="inv-filter" id="stockFilter"
                                style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.84rem;color:#334155;outline:none;">
                                <option value="">All Status</option>
                                <option value="critical">Critical</option>
                                <option value="low">Low</option>
                                <option value="expiring">Expiring</option>
                                <option value="adequate">Adequate</option>
                            </select>
                            <button class="btn-add-med" id="openModal"><i class="bi bi-plus-circle-fill"></i>
                                Add Medicine
                            </button>
                        </div>
                    </div>

                    <!-- ══ Category Filter Tabs ══ -->
                    <div id="activePanel">
                    <?php if (!empty($categories)): ?>
                    <div class="cat-tab-bar" id="catTabBar">
                        <button class="cat-tab active" id="catAll" onclick="filterCat('all',this)">
                            <i class="bi bi-grid-fill"></i> All
                        </button>
                        <?php
                        $tabMeta = [
                            'prescription' => ['cls'=>'rx',   'icon'=>'bi-prescription2',  'color'=>'#6d28d9'],
                            'vitamin'      => ['cls'=>'vit',  'icon'=>'bi-capsule',         'color'=>'#15803d'],
                            'resp'         => ['cls'=>'resp', 'icon'=>'bi-lungs-fill',       'color'=>'#1d4ed8'],
                        ];
                        foreach ($categories as $cat):
                            $lc  = strtolower($cat['CategoryName']);
                            $key = str_contains($lc,'prescription') ? 'prescription'
                                 : (str_contains($lc,'vitamin')      ? 'vitamin' : 'resp');
                            $m   = $tabMeta[$key];
                        ?>
                        <button class="cat-tab <?= $m['cls'] ?>"
                                data-catid="<?= $cat['CategoryID'] ?>"
                                onclick="filterCat(<?= $cat['CategoryID'] ?>,this)">
                            <i class="bi <?= $m['icon'] ?>"></i>
                            <?= htmlspecialchars($cat['CategoryName']) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Table -->

                    <div class="med-table-wrap">
                        <table class="med-table" id="medTable">
                            <thead>
                                <tr>
                                    <th>Med ID</th>
                                    <th>Generic Name</th>
                                    <th>Brand</th>
                                    <th>Dosage</th>
                                    <th>Category</th>
                                    <th>Expiry</th>
                                    <th>Unit Price</th>
                                    <th>Total Stock</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="medBody">
                                <?php if (empty($medications)): ?>
                                    <tr>
                                        <td colspan="10" class="med-empty">No medications found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($medications as $m):
                                        $liveStock  = (int) $m['LiveStock'];
                                        $hasDetails = $m['MedDet'] !== null;
                                        $exp        = $m['ExpirationDate'] ?? null;
                                        $status     = $hasDetails ? stockStatus($liveStock, $exp ?? date('Y-m-d', strtotime('+2 years'))) : 'critical';
                                        $sClass     = stockClass($status);
                                        // Category
                                        $catName = $m['CategoryName'] ?? '';
                                    ?>
                                        <tr data-search="<?= strtolower('MED-' . fmtPad($m['MedicationID']) . ' ' . $m['GenericName'] . ' ' . $m['BrandName'] . ' ' . $catName) ?>"
                                            data-status="<?= $status ?>"
                                            data-catid="<?= (int)($m['CategoryID'] ?? 0) ?>">
                                            <td class="med-col-id">MED-<?= fmtPad($m['MedicationID']) ?></td>
                                            <td class="med-col-name"><?= htmlspecialchars($m['GenericName']) ?></td>
                                            <td class="med-col-brand"><?= htmlspecialchars($m['BrandName']) ?></td>
                                            <td class="med-col-dose"><?= htmlspecialchars($m['DosageStrength']) ?></td>
                                            <td style="font-size:.78rem;color:#475569;">
                                                <?= $catName ? htmlspecialchars($catName) : '<span style="color:#94a3b8;">—</span>' ?>
                                            </td>
                                            <td class="med-col-expiry" style="<?= !$hasDetails ? 'color:#94a3b8;font-style:italic;' : '' ?>">
                                                <?= $hasDetails ? htmlspecialchars($exp) : '—' ?>
                                            </td>
                                            <td class="med-col-price" style="<?= !$hasDetails ? 'color:#94a3b8;font-style:italic;' : '' ?>">
                                                <?= $hasDetails ? '&#8369;' . number_format((float)$m['UnitPrice'], 2) : '—' ?>
                                            </td>
                                            <td class="med-col-live <?= $sClass ?>" style="font-weight:700">
                                                <?= $hasDetails ? $liveStock : '—' ?>
                                            </td>
                                            <td>
                                                <?php if (!$hasDetails): ?>
                                                    <span class="med-status critical" title="No stock details — click Add Stock to set up this medicine">No Stock Info</span>
                                                <?php else: ?>
                                                    <span class="med-status <?= $status ?>"><?= ucfirst($status) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display:flex;gap:4px;align-items:center;">
                                                    <?php if (!$hasDetails): ?>
                                                        <button onclick="openAddStockForMed(<?= $m['MedicationID'] ?>, '<?= htmlspecialchars($m['GenericName']) ?>')" title="Add stock info"
                                                            style="width:auto;padding:0 10px;height:32px;display:inline-flex;align-items:center;gap:5px;border-radius:8px;border:1.5px solid #bbf7d0;background:#dcfce7;color:#15803d;cursor:pointer;font-size:.75rem;font-weight:700;">
                                                            + Add Stock
                                                        </button>
                                                    <?php else: ?>
                                                        <button onclick="openEditModal(<?= $m['MedicationID'] ?>, <?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)" title="Edit"
                                                            style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#334155;cursor:pointer;padding:0;">
                                                            <i class="bi bi-pencil-fill"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="confirmArchive(<?= $m['MedicationID'] ?>, '<?= htmlspecialchars($m['GenericName'], ENT_QUOTES) ?>')" title="Archive"
                                                        style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1.5px solid #fed7aa;background:#fff7ed;color:#ea580c;cursor:pointer;padding:0;">
                                                        <i class="bi bi-archive-fill"></i>
                                                    </button>
                                                    <button onclick="confirmDelete(<?= $m['MedicationID'] ?>, '<?= htmlspecialchars($m['GenericName'], ENT_QUOTES) ?>')" title="Delete permanently"
                                                        style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1.5px solid #fecaca;background:#fff0f0;color:#dc2626;cursor:pointer;padding:0;">
                                                        <i class="bi bi-trash-fill"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    </div><!-- /activePanel -->

                    <!-- ══ Archived Medicines Panel ══ -->
                    <div id="archivedPanel" style="display:none;">

                        <!-- Archived subheader -->
                        <div style="padding:10px 16px 8px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #f1f5f9;">
                            <i class="bi bi-archive-fill" style="color:#ea580c;font-size:.95rem;"></i>
                            <span style="font-size:.88rem;font-weight:700;color:#0f172a;">Archived Medicines</span>
                            <span style="font-size:.75rem;font-weight:600;color:#94a3b8;"><?= count($archived) ?> record(s)</span>
                            <span style="font-size:.75rem;color:#94a3b8;margin-left:4px;">— Restore to make them available again.</span>
                        </div>

                        <div class="med-table-wrap">
                            <table class="med-table" id="archivedTable">
                                <thead>
                                    <tr>
                                        <th>Med ID</th>
                                        <th>Generic Name</th>
                                        <th>Brand</th>
                                        <th>Dosage</th>
                                        <th>Category</th>
                                        <th>Unit Price</th>
                                        <th>Expiry</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($archived)): ?>
                                        <tr><td colspan="8" class="med-empty">
                                            <i class="bi bi-archive" style="font-size:1.5rem;color:#cbd5e1;display:block;margin-bottom:8px;"></i>
                                            No archived medicines yet.
                                        </td></tr>
                                    <?php else: ?>
                                        <?php foreach ($archived as $a): ?>
                                        <tr style="opacity:.8;">
                                            <td class="med-col-id">MED-<?= fmtPad($a['MedicationID']) ?></td>
                                            <td class="med-col-name" style="color:#94a3b8;"><?= htmlspecialchars($a['GenericName']) ?></td>
                                            <td class="med-col-brand" style="color:#94a3b8;"><?= htmlspecialchars($a['BrandName']) ?></td>
                                            <td class="med-col-dose" style="color:#94a3b8;"><?= htmlspecialchars($a['DosageStrength']) ?></td>
                                            <td style="font-size:.78rem;color:#94a3b8;">
                                                <?= !empty($a['CategoryName']) ? htmlspecialchars($a['CategoryName']) : '—' ?>
                                            </td>
                                            <td style="color:#94a3b8;"><?= $a['UnitPrice'] ? '&#8369;' . number_format((float)$a['UnitPrice'], 2) : '—' ?></td>
                                            <td style="color:#94a3b8;"><?= htmlspecialchars($a['ExpirationDate'] ?? '—') ?></td>
                                            <td>
                                                <form method="POST" action="?view=archived" style="display:inline;">
                                                    <input type="hidden" name="action" value="restore_medication">
                                                    <input type="hidden" name="medication_id" value="<?= $a['MedicationID'] ?>">
                                                    <button type="submit" title="Restore to active inventory"
                                                        style="display:inline-flex;align-items:center;gap:5px;padding:0 12px;height:32px;border-radius:8px;border:1.5px solid #bbf7d0;background:#dcfce7;color:#15803d;cursor:pointer;font-size:.75rem;font-weight:700;font-family:'Outfit',sans-serif;">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div><!-- /archivedPanel -->

                </div><!-- /inventory-card -->

            </div><!-- /page-body -->
        </div><!-- /main-area -->
    </div><!-- /app-layout -->

        <!-- ══ Add Medicine Modal — uses AddMedicationStock() procedure ══ -->
    <div class="modal-overlay" id="addMedModal">
        <div class="modal-box" style="width:min(600px,95vw);max-height:90vh;overflow-y:auto;border-radius:16px;padding:0;">

            <!-- Header -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1.5px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;background:#e0e7ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-info-circle-fill" style="font-size:1.1rem;color:#6366f1"></i>
                    </div>
                    <div>
                        <div style="font-size:1rem;font-weight:700;color:#0f172a;line-height:1.2;">Add Medicine Stock</div>
                        <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">Fill in the details below</div>
                    </div>
                </div>
                <button class="modal-close" id="closeModal" title="Close"
                    style="width:32px;height:32px;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">&#x2715;</button>
            </div>

            <!-- Tab buttons -->
            <div style="padding:14px 28px 0;display:flex;gap:8px;border-bottom:1.5px solid #e2e8f0;">
                <button class="tab-btn active" id="tabNew" onclick="switchTab('new')"
                    style="padding:8px 20px;border-radius:8px 8px 0 0;border:1.5px solid #e2e8f0;border-bottom:2px solid #fff;margin-bottom:-1.5px;background:#fff;color:#6366f1;font-size:.84rem;font-weight:600;cursor:pointer;">New Medication</button>
                <button class="tab-btn" id="tabExisting" onclick="switchTab('existing')"
                    style="padding:8px 20px;border-radius:8px 8px 0 0;border:1.5px solid transparent;background:transparent;color:#64748b;font-size:.84rem;font-weight:600;cursor:pointer;">Add Stock to Existing</button>
            </div>

            <form method="POST" id="addMedForm">
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="existing_med_id" id="existingMedId" value="0">

                <div style="padding:22px 28px;display:flex;flex-direction:column;gap:16px;">

                    <!-- New medication fields -->
                    <div id="newMedFields">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Generic Name *</label>
                                <input type="text" id="genericName" name="generic_name" placeholder="e.g. Paracetamol"
                                    style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;transition:border-color .2s;">
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Brand Name</label>
                                <input type="text" name="brand_name" placeholder="e.g. Biogesic"
                                    style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Dosage / Strength</label>
                                <input type="text" name="dosage_strength" placeholder="e.g. 500mg"
                                    style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Category</label>
                                <select name="category_id"
                                    style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                                    <option value="">— Select category —</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['CategoryID'] ?>"><?= htmlspecialchars($cat['CategoryName']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Existing medication selector -->
                    <div id="existingMedFields" style="display:none;">
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Select Existing Medication *</label>
                            <select id="existingMedSelect" name="existing_med_id_select"
                                onchange="document.getElementById('existingMedId').value=this.value"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                                <option value="0">— Select medication —</option>
                                <?php foreach ($med_list as $m): ?>
                                    <option value="<?= $m['MedicationID'] ?>">
                                        MED-<?= fmtPad($m['MedicationID']) ?> — <?= htmlspecialchars($m['GenericName']) ?>
                                        (<?= htmlspecialchars($m['BrandName']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Divider -->
                    <div style="border-top:1.5px dashed #e2e8f0;"></div>

                    <!-- Shared fields -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Manufacturer</label>
                            <input type="text" name="manufacturer" placeholder="e.g. Unilab"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Unit Price (&#8369;)</label>
                            <input type="number" step="0.01" min="0" name="unit_price" placeholder="0.00"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Stock Quantity *</label>
                            <input type="number" min="1" name="stock_availability" placeholder="e.g. 500" required
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Expiration Date *</label>
                            <input type="date" name="expiration_date" required
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                    </div>

                    <!-- Procedure note -->
                    <div style="display:flex;align-items:flex-start;gap:10px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:12px 16px;">
                        <i class="bi bi-info-circle-fill" style="font-size:1rem;color:#3b82f6;flex-shrink:0;margin-top:1px;"></i>
                        <span style="font-size:.78rem;color:#1d4ed8;line-height:1.5;">Calls <strong>AddMedicationStock(MedicationID, Manufacturer, ExpirationDate, Quantity)</strong> procedure</span>
                    </div>

                </div>

                <!-- Footer actions -->
                <div style="display:flex;justify-content:flex-end;gap:10px;padding:16px 28px;border-top:1.5px solid #f1f5f9;">
                    <button type="button" class="modal-btn-cancel" id="cancelModal"
                        style="padding:10px 24px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.9rem;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit" class="modal-btn-save"
                        style="padding:10px 28px;border-radius:10px;border:none;background:#0f172a;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;letter-spacing:.02em;">Add Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ Delete Confirm Modal ══ -->
    <div class="modal-overlay" id="deleteMedModal">
        <div style="background:#fff;border-radius:16px;padding:0;width:min(420px,90vw);overflow:hidden;">
            <div style="padding:28px 28px 20px;text-align:center;">
                <div style="width:52px;height:52px;background:#fff0f0;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="bi bi-trash-fill" style="font-size:1.4rem;color:#dc2626"></i>
                </div>
                <div style="font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:8px;">Delete Medication?</div>
                <div style="font-size:.88rem;color:#64748b;line-height:1.5;">
                    <strong id="deleteMedName"></strong> will be <span style="color:#dc2626;font-weight:700;">permanently deleted</span> along with all its stock records. This action <strong>cannot be undone</strong>.
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_medication">
                <input type="hidden" name="medication_id" id="deleteMedId">
                <div style="display:flex;gap:10px;padding:0 28px 24px;">
                    <button type="button" onclick="closeDeleteModal()"
                        style="flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.9rem;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit"
                        style="flex:1;padding:11px;border-radius:10px;border:none;background:#dc2626;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;">
                        <i class="bi bi-trash-fill" style="margin-right:5px;"></i>Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-tray" id="toastTray"></div>

    <!-- ══ Edit Medicine Modal ══ -->
    <div class="modal-overlay" id="editMedModal">
        <div class="modal-box" style="width:min(600px,95vw);max-height:90vh;overflow-y:auto;border-radius:16px;padding:0;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1.5px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem;color:#d97706"></i>
                    </div>
                    <div>
                        <div style="font-size:1rem;font-weight:700;color:#0f172a;line-height:1.2;">Edit Medicine</div>
                        <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">Update medication details</div>
                    </div>
                </div>
                <button onclick="closeEditModal()" style="width:32px;height:32px;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">&#x2715;</button>
            </div>
            <form method="POST" id="editMedForm">
                <input type="hidden" name="action" value="edit_medication">
                <input type="hidden" name="medication_id" id="editMedId">
                <div style="padding:22px 28px;display:flex;flex-direction:column;gap:16px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Generic Name *</label>
                            <input type="text" name="generic_name" id="editGenericName" required
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Brand Name</label>
                            <input type="text" name="brand_name" id="editBrandName"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Dosage / Strength</label>
                            <input type="text" name="dosage_strength" id="editDosage"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Unit Price (&#8369;)</label>
                            <input type="number" step="0.01" min="0" name="unit_price" id="editUnitPrice"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;padding:16px 28px;border-top:1.5px solid #f1f5f9;">
                    <button type="button" onclick="closeEditModal()"
                        style="padding:10px 24px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.9rem;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit"
                        style="padding:10px 28px;border-radius:10px;border:none;background:#d97706;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ Archive Confirm Modal ══ -->
    <div class="modal-overlay" id="archiveMedModal">
        <div style="background:#fff;border-radius:16px;padding:0;width:min(420px,90vw);overflow:hidden;">
            <div style="padding:28px 28px 20px;text-align:center;">
                <div style="width:52px;height:52px;background:#fff7ed;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="bi bi-archive-fill" style="font-size:1.4rem;color:#ea580c"></i>
                </div>
                <div style="font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:8px;">Archive Medication?</div>
                <div style="font-size:.88rem;color:#64748b;line-height:1.5;">
                    <strong id="archiveMedName"></strong> will be removed from the active inventory but all records will be preserved. You can restore it later if needed.
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="archive_medication">
                <input type="hidden" name="medication_id" id="archiveMedId">
                <div style="display:flex;gap:10px;padding:0 28px 24px;">
                    <button type="button" onclick="closeArchiveModal()"
                        style="flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.9rem;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit"
                        style="flex:1;padding:11px;border-radius:10px;border:none;background:#ea580c;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;">
                        <i class="bi bi-archive-fill" style="margin-right:5px;"></i>Yes, Archive
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        'use strict';

        /* ── Active / Archived view toggle ── */
        function switchView(view) {
            const activePanel   = document.getElementById('activePanel');
            const archivedPanel = document.getElementById('archivedPanel');
            const btnActive     = document.getElementById('viewActive');
            const btnArchived   = document.getElementById('viewArchived');
            const catTabBar     = document.getElementById('catTabBar');

            if (view === 'archived') {
                activePanel.style.display   = 'none';
                archivedPanel.style.display = 'block';
                if (catTabBar) catTabBar.style.display = 'none';
                btnActive.style.cssText   = 'padding:8px 14px;font-size:.8rem;font-weight:700;font-family:\'Outfit\',sans-serif;border:none;cursor:pointer;background:#fff;color:#64748b;display:flex;align-items:center;gap:5px;';
                btnArchived.style.cssText = 'padding:8px 14px;font-size:.8rem;font-weight:700;font-family:\'Outfit\',sans-serif;border:none;cursor:pointer;background:#ea580c;color:#fff;display:flex;align-items:center;gap:5px;';
            } else {
                activePanel.style.display   = 'block';
                archivedPanel.style.display = 'none';
                if (catTabBar) catTabBar.style.display = 'flex';
                btnActive.style.cssText   = 'padding:8px 14px;font-size:.8rem;font-weight:700;font-family:\'Outfit\',sans-serif;border:none;cursor:pointer;background:#1e2d40;color:#fff;display:flex;align-items:center;gap:5px;';
                btnArchived.style.cssText = 'padding:8px 14px;font-size:.8rem;font-weight:700;font-family:\'Outfit\',sans-serif;border:none;cursor:pointer;background:#fff;color:#64748b;display:flex;align-items:center;gap:5px;';
            }
        }

        /* ── Tab switching (Add Stock modal) ── */
        function switchTab(tab) {
            const newFields = document.getElementById('newMedFields');
            const existFields = document.getElementById('existingMedFields');
            const tabNew = document.getElementById('tabNew');
            const tabExisting = document.getElementById('tabExisting');
            const genericNameInp = document.getElementById('genericName');
            const existingMedId = document.getElementById('existingMedId');
            if (tab === 'new') {
                newFields.style.display = 'block'; existFields.style.display = 'none';
                tabNew.classList.add('active'); tabExisting.classList.remove('active');
                tabNew.style.cssText += ';color:#6366f1;border-color:#e2e8f0;border-bottom-color:#fff;background:#fff;';
                tabExisting.style.cssText += ';color:#64748b;border-color:transparent;background:transparent;';
                genericNameInp.required = true; existingMedId.value = '0';
                document.getElementById('existingMedSelect').value = '0';
            } else {
                newFields.style.display = 'none'; existFields.style.display = 'block';
                tabExisting.classList.add('active'); tabNew.classList.remove('active');
                tabExisting.style.cssText += ';color:#6366f1;border-color:#e2e8f0;border-bottom-color:#fff;background:#fff;';
                tabNew.style.cssText += ';color:#64748b;border-color:transparent;background:transparent;';
                genericNameInp.required = false;
            }
        }

        /* ── Search & filter ── */
        const medSearch    = document.getElementById('medSearch');
        const stockFilter  = document.getElementById('stockFilter');
        const medRows      = document.querySelectorAll('#medBody tr[data-status]');
        let activeCatId    = 'all';

        function filterCat(catId, btn) {
            activeCatId = catId;
            document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            applyFilters();
        }

        function applyFilters() {
            const q      = medSearch.value.toLowerCase().trim();
            const status = stockFilter.value;
            medRows.forEach(row => {
                const matchQ   = !q      || row.dataset.search.includes(q);
                const matchS   = !status || row.dataset.status === status;
                const matchCat = activeCatId === 'all' || String(row.dataset.catid) === String(activeCatId);
                row.style.display = (matchQ && matchS && matchCat) ? '' : 'none';
            });
        }
        medSearch.addEventListener('input',  applyFilters);
        stockFilter.addEventListener('change', applyFilters);

        /* ── Add Stock Modal ── */
        const modal = document.getElementById('addMedModal');
        document.getElementById('openModal').addEventListener('click', () => modal.classList.add('show'));
        document.getElementById('closeModal').addEventListener('click', () => modal.classList.remove('show'));
        document.getElementById('cancelModal').addEventListener('click', () => modal.classList.remove('show'));
        modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });

        /* ── Edit Modal ── */
        function openAddStockForMed(medId, medName) {
            modal.classList.add('show');
            switchTab('existing');
            const sel = document.getElementById('existingMedSelect');
            if (sel) {
                for (let i = 0; i < sel.options.length; i++) {
                    if (parseInt(sel.options[i].value) === medId) {
                        sel.selectedIndex = i;
                        document.getElementById('existingMedId').value = medId;
                        sel.dispatchEvent(new Event('change'));
                        break;
                    }
                }
            }
        }

        function openEditModal(id, data) {
            document.getElementById('editMedId').value = id;
            document.getElementById('editGenericName').value = data.GenericName || '';
            document.getElementById('editBrandName').value = data.BrandName || '';
            document.getElementById('editDosage').value = data.DosageStrength || '';
            document.getElementById('editUnitPrice').value = data.UnitPrice || '';
            document.getElementById('editMedModal').classList.add('show');
        }
        function closeEditModal() {
            document.getElementById('editMedModal').classList.remove('show');
        }
        document.getElementById('editMedModal').addEventListener('click', e => {
            if (e.target === document.getElementById('editMedModal')) closeEditModal();
        });

        /* ── Archive Modal ── */
        function confirmArchive(id, name) {
            document.getElementById('archiveMedId').value = id;
            document.getElementById('archiveMedName').textContent = name;
            document.getElementById('archiveMedModal').classList.add('show');
        }
        function closeArchiveModal() {
            document.getElementById('archiveMedModal').classList.remove('show');
        }
        document.getElementById('archiveMedModal').addEventListener('click', e => {
            if (e.target === document.getElementById('archiveMedModal')) closeArchiveModal();
        });

        /* ── Delete Modal ── */
        function confirmDelete(id, name) {
            document.getElementById('deleteMedId').value = id;
            document.getElementById('deleteMedName').textContent = name;
            document.getElementById('deleteMedModal').classList.add('show');
        }
        function closeDeleteModal() {
            document.getElementById('deleteMedModal').classList.remove('show');
        }
        document.getElementById('deleteMedModal').addEventListener('click', e => {
            if (e.target === document.getElementById('deleteMedModal')) closeDeleteModal();
        });

        /* ── Auto-switch view if needed ── */
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('view') === 'archived') switchView('archived');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('show'); });
        }
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); });
        }
    </script>

</body>

</html>