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

// ── Archive / Return to Manufacturer ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_medication') {
    try {
        $db        = getDB();
        $id        = (int)($_POST['medication_id'] ?? 0);
        $is_return = trim($_POST['is_return'] ?? 'no');

        if ($id > 0) {
            $nm = $db->prepare("SELECT m.GenericName, m.DosageStrength, m.BrandName,
                                       MIN(md.Manufacturer) AS Manufacturer,
                                       GetMedicationStockLevel(m.MedicationID) AS LiveStock
                                FROM medications m
                                LEFT JOIN medicationdetails md ON md.MedicationID = m.MedicationID
                                WHERE m.MedicationID = ?
                                GROUP BY m.MedicationID");
            $nm->execute([$id]);
            $med     = $nm->fetch();
            $medName = $med ? htmlspecialchars($med['GenericName'] . ' ' . $med['DosageStrength']) : "This medication";

            // Soft-delete regardless of return choice
            $db->prepare("UPDATE medications SET IsActive = 0 WHERE MedicationID=?")->execute([$id]);

            if ($is_return === 'yes') {
                $return_reason  = trim($_POST['return_reason']  ?? 'Expired');
                $units_returned = (int)($_POST['units_returned'] ?? 0);
                $notes          = trim($_POST['return_notes']   ?? '');
                $returned_by    = $_SESSION['user_id'] ?? 1;
                $actualStock    = (int)($med['LiveStock'] ?? 0);
                if ($units_returned <= 0) $units_returned = $actualStock;

                // Auto-create table if needed
                $db->exec("CREATE TABLE IF NOT EXISTS manufacturer_returns (
                    ReturnID        INT AUTO_INCREMENT PRIMARY KEY,
                    MedicationID    INT NOT NULL,
                    ReturnReason    VARCHAR(100) NOT NULL DEFAULT 'Expired',
                    UnitsReturned   INT NOT NULL DEFAULT 0,
                    Manufacturer    VARCHAR(150),
                    Notes           TEXT,
                    ReturnedBy      INT,
                    ReturnedAt      DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_med (MedicationID)
                )");

                $ins = $db->prepare("INSERT INTO manufacturer_returns
                                     (MedicationID, ReturnReason, UnitsReturned, Manufacturer, Notes, ReturnedBy)
                                     VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([
                    $id, $return_reason, $units_returned,
                    $med['Manufacturer'] ?? '', $notes, $returned_by
                ]);

                $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> "
                         . "\"{$medName}\" archived and returned to manufacturer. "
                         . "<strong>{$units_returned} unit(s)</strong> logged — Reason: <strong>{$return_reason}</strong>.";
            } else {
                $success = "<i class=\"bi bi-check-circle-fill\" style=\"color:#16a34a\"></i> "
                         . "\"{$medName}\" has been archived and removed from active inventory.";
            }
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

    // Fetch archived medications + latest return log entry
    // Gracefully falls back if manufacturer_returns table doesn't exist yet
    try {
        $s = $db->prepare("
            SELECT
                m.MedicationID,
                m.GenericName,
                m.BrandName,
                m.DosageStrength,
                MIN(md.UnitPrice)       AS UnitPrice,
                MIN(md.ExpirationDate)  AS ExpirationDate,
                c.CategoryName,
                sup.SupplierName,
                mr.ReturnReason,
                mr.UnitsReturned,
                mr.Manufacturer         AS ReturnManufacturer,
                mr.Notes                AS ReturnNotes,
                mr.ReturnedAt,
                u.FullName              AS ReturnedByName
            FROM medications m
            LEFT JOIN medicationdetails md ON md.MedicationID = m.MedicationID
            LEFT JOIN categories c         ON c.CategoryID    = m.CategoryID
            LEFT JOIN suppliers  sup       ON sup.SupplierID  = m.SupplierID
            LEFT JOIN (
                SELECT mr2.*
                FROM manufacturer_returns mr2
                INNER JOIN (
                    SELECT MedicationID, MAX(ReturnID) AS MaxID
                    FROM manufacturer_returns
                    GROUP BY MedicationID
                ) latest ON mr2.ReturnID = latest.MaxID
            ) mr ON mr.MedicationID = m.MedicationID
            LEFT JOIN users u ON u.UserID = mr.ReturnedBy
            WHERE m.IsActive = 0
            GROUP BY m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength,
                     c.CategoryName, sup.SupplierName,
                     mr.ReturnReason, mr.UnitsReturned, mr.Manufacturer, mr.Notes,
                     mr.ReturnedAt, u.FullName
            ORDER BY mr.ReturnedAt DESC, m.GenericName ASC
        ");
        $s->execute();
        $archived = $s->fetchAll();
    } catch (PDOException $e) {
        // manufacturer_returns table may not exist yet — fall back to simple query
        $s = $db->prepare("
            SELECT m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength,
                   MIN(md.UnitPrice) AS UnitPrice, MIN(md.ExpirationDate) AS ExpirationDate,
                   c.CategoryName, sup.SupplierName,
                   NULL AS ReturnReason, NULL AS UnitsReturned, NULL AS ReturnManufacturer,
                   NULL AS ReturnNotes, NULL AS ReturnedAt, NULL AS ReturnedByName
            FROM medications m
            LEFT JOIN medicationdetails md ON md.MedicationID = m.MedicationID
            LEFT JOIN categories c         ON c.CategoryID    = m.CategoryID
            LEFT JOIN suppliers  sup       ON sup.SupplierID  = m.SupplierID
            WHERE m.IsActive = 0
            GROUP BY m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength, c.CategoryName, sup.SupplierName
            ORDER BY m.GenericName ASC
        ");
        $s->execute();
        $archived = $s->fetchAll();
    }

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
                                    style="padding:8px 16px;font-size:.8rem;font-weight:700;font-family:'Outfit',sans-serif;border:none;cursor:pointer;background:#1e2d40;color:#fff;display:flex;align-items:center;gap:5px;">
                                    <i class="bi bi-capsule-pill"></i> Active
                                </button>
                                <button id="viewArchived" onclick="switchView('archived')"
                                    style="padding:8px 16px;font-size:.8rem;font-weight:700;font-family:'Outfit',sans-serif;border:none;cursor:pointer;background:#fff;color:#64748b;display:flex;align-items:center;gap:5px;">
                                    <i class="bi bi-archive-fill"></i> Archived
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
                                                    <button onclick="confirmArchive(<?= $m['MedicationID'] ?>, '<?= htmlspecialchars($m['GenericName'], ENT_QUOTES) ?>', <?= (int)($m['LiveStock'] ?? 0) ?>, '<?= htmlspecialchars($m['Manufacturer'] ?? '', ENT_QUOTES) ?>')" title="Archive"
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

                    <!-- ══ Returned to Manufacturer Panel ══ -->
                    <div id="archivedPanel" style="display:none;">

                        <!-- Panel header -->
                        <div style="padding:14px 20px 12px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #fde8d8;background:linear-gradient(90deg,#fff7f0 0%,#fff 100%);">
                            <div style="width:34px;height:34px;background:#fff0e6;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi bi-truck" style="color:#ea580c;font-size:1rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:.9rem;font-weight:700;color:#0f172a;line-height:1.2;">Returned to Manufacturer</div>
                                <div style="font-size:.73rem;color:#94a3b8;margin-top:1px;">
                                    <?= count($archived) ?> medication(s) returned &nbsp;·&nbsp; Stock removed from active inventory
                                </div>
                            </div>
                            <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
                                <?php
                                $totalUnitsReturned = array_sum(array_column($archived, 'UnitsReturned'));
                                $reasonCounts = array_count_values(array_filter(array_column($archived, 'ReturnReason')));
                                ?>
                                <?php if ($totalUnitsReturned > 0): ?>
                                <div style="background:#fff0e6;border:1.5px solid #fed7aa;border-radius:8px;padding:5px 12px;text-align:center;">
                                    <div style="font-size:.65rem;color:#ea580c;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Total Units</div>
                                    <div style="font-size:.95rem;font-weight:800;color:#c2410c;"><?= number_format($totalUnitsReturned) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php foreach ($reasonCounts as $rsn => $cnt): ?>
                                <div style="background:#fafafa;border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 12px;text-align:center;">
                                    <div style="font-size:.65rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?= htmlspecialchars($rsn) ?></div>
                                    <div style="font-size:.95rem;font-weight:800;color:#334155;"><?= $cnt ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Return log cards -->
                        <div class="archived-cards-scroll" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;max-height:480px;overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:#fed7aa #fff7ed;">

                            <?php if (empty($archived)): ?>
                            <div style="text-align:center;padding:48px 20px;color:#94a3b8;">
                                <i class="bi bi-truck" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.4;"></i>
                                <div style="font-weight:700;font-size:.95rem;margin-bottom:4px;">No returns yet</div>
                                <div style="font-size:.82rem;">Medications returned to manufacturers will appear here.</div>
                            </div>
                            <?php else: ?>

                            <?php foreach ($archived as $a):
                                $isReturned  = !empty($a['ReturnReason']);
                                $reasonColor = match($a['ReturnReason'] ?? '') {
                                    'Expired'         => ['bg'=>'#fff0e6','border'=>'#fed7aa','text'=>'#c2410c','icon'=>'bi-clock-history'],
                                    'Damaged'         => ['bg'=>'#fff0f0','border'=>'#fecaca','text'=>'#b91c1c','icon'=>'bi-exclamation-triangle-fill'],
                                    'Recalled'        => ['bg'=>'#fdf4ff','border'=>'#e9d5ff','text'=>'#7c3aed','icon'=>'bi-megaphone-fill'],
                                    'Overstock'       => ['bg'=>'#eff6ff','border'=>'#bfdbfe','text'=>'#1d4ed8','icon'=>'bi-box-seam'],
                                    'Quality Issue'   => ['bg'=>'#fefce8','border'=>'#fde68a','text'=>'#92400e','icon'=>'bi-shield-exclamation'],
                                    default           => ['bg'=>'#f8fafc','border'=>'#e2e8f0','text'=>'#64748b','icon'=>'bi-archive-fill'],
                                };
                                $expiry      = $a['ExpirationDate'] ?? null;
                                $isExpired   = $expiry && strtotime($expiry) < time();
                                $returnedAt  = $a['ReturnedAt'] ? date('M d, Y', strtotime($a['ReturnedAt'])) : null;

                                // Stripe config: truck=returned, archive=just archived
                                $stripeIcon  = $isReturned ? 'bi-truck'        : 'bi-archive-fill';
                                $stripeBg    = $isReturned ? '#fff0e6'         : '#f1f5f9';
                                $stripeBord  = $isReturned ? '#fed7aa'         : '#e2e8f0';
                                $stripeColor = $isReturned ? '#ea580c'         : '#64748b';
                            ?>
                            <div style="border:1.5px solid <?= $isReturned ? '#fed7aa' : '#e2e8f0' ?>;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.04);">

                                <!-- Card top row -->
                                <div style="display:flex;align-items:stretch;gap:0;">

                                    <!-- Left stripe: truck = returned to manufacturer, archive = just archived -->
                                    <div style="width:56px;background:<?= $stripeBg ?>;border-right:1.5px solid <?= $stripeBord ?>;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:14px 0;flex-shrink:0;">
                                        <i class="bi <?= $stripeIcon ?>" style="font-size:1.3rem;color:<?= $stripeColor ?>;"></i>
                                        <span style="font-size:.55rem;font-weight:800;color:<?= $stripeColor ?>;text-transform:uppercase;letter-spacing:.04em;text-align:center;line-height:1.2;padding:0 4px;">
                                            <?= $isReturned ? 'Returned' : 'Archived' ?>
                                        </span>
                                    </div>

                                    <!-- Main info -->
                                    <div style="flex:1;padding:14px 16px;display:flex;flex-direction:column;gap:6px;">
                                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                            <span style="font-size:.7rem;font-weight:700;color:#94a3b8;background:#f1f5f9;padding:2px 8px;border-radius:6px;">MED-<?= fmtPad($a['MedicationID']) ?></span>
                                            <span style="font-size:.95rem;font-weight:700;color:#0f172a;"><?= htmlspecialchars($a['GenericName']) ?></span>
                                            <?php if ($a['DosageStrength']): ?><span style="font-size:.78rem;color:#64748b;"><?= htmlspecialchars($a['DosageStrength']) ?></span><?php endif; ?>
                                            <?php if ($a['BrandName']): ?><span style="font-size:.73rem;color:#94a3b8;">(<?= htmlspecialchars($a['BrandName']) ?>)</span><?php endif; ?>

                                            <!-- Status badge: returned with reason OR just archived -->
                                            <?php if ($isReturned): ?>
                                            <span style="margin-left:auto;font-size:.7rem;font-weight:700;color:<?= $reasonColor['text'] ?>;background:<?= $reasonColor['bg'] ?>;border:1.5px solid <?= $reasonColor['border'] ?>;padding:3px 10px;border-radius:999px;display:inline-flex;align-items:center;gap:4px;">
                                                <i class="bi bi-truck"></i> Returned · <?= htmlspecialchars($a['ReturnReason']) ?>
                                            </span>
                                            <?php else: ?>
                                            <span style="margin-left:auto;font-size:.7rem;font-weight:700;color:#64748b;background:#f1f5f9;border:1.5px solid #e2e8f0;padding:3px 10px;border-radius:999px;display:inline-flex;align-items:center;gap:4px;">
                                                <i class="bi bi-archive-fill"></i> Archived Only
                                            </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Meta row -->
                                        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                                            <?php if ($a['CategoryName']): ?>
                                            <span style="font-size:.75rem;color:#64748b;display:flex;align-items:center;gap:4px;">
                                                <i class="bi bi-tag" style="color:#94a3b8;"></i><?= htmlspecialchars($a['CategoryName']) ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($a['ReturnManufacturer'] ?? $a['SupplierName']): ?>
                                            <span style="font-size:.75rem;color:#64748b;display:flex;align-items:center;gap:4px;">
                                                <i class="bi bi-building" style="color:#94a3b8;"></i><?= htmlspecialchars($a['ReturnManufacturer'] ?? $a['SupplierName']) ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($expiry): ?>
                                            <span style="font-size:.75rem;color:<?= $isExpired ? '#dc2626' : '#64748b' ?>;display:flex;align-items:center;gap:4px;font-weight:<?= $isExpired ? '700' : '400' ?>;">
                                                <i class="bi bi-calendar-x" style="color:<?= $isExpired ? '#dc2626' : '#94a3b8' ?>;"></i>
                                                Exp: <?= htmlspecialchars($expiry) ?><?= $isExpired ? ' <span style="color:#dc2626;">· EXPIRED</span>' : '' ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($a['UnitPrice']): ?>
                                            <span style="font-size:.75rem;color:#64748b;display:flex;align-items:center;gap:4px;">
                                                <i class="bi bi-currency-exchange" style="color:#94a3b8;"></i>&#8369;<?= number_format((float)$a['UnitPrice'], 2) ?>/unit
                                            </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($a['ReturnNotes']): ?>
                                        <div style="font-size:.77rem;color:#64748b;background:#f8fafc;border-radius:8px;padding:7px 12px;border-left:3px solid #cbd5e1;margin-top:2px;font-style:italic;">
                                            "<?= htmlspecialchars($a['ReturnNotes']) ?>"
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Right panel: units + date + actions -->
                                    <div style="border-left:1.5px solid #f1f5f9;padding:14px 16px;display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;gap:8px;min-width:160px;flex-shrink:0;">
                                        <?php if ($a['UnitsReturned'] > 0): ?>
                                        <div style="text-align:right;">
                                            <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;font-weight:700;letter-spacing:.05em;">Units Returned</div>
                                            <div style="font-size:1.4rem;font-weight:800;color:#ea580c;line-height:1.1;"><?= number_format($a['UnitsReturned']) ?></div>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($returnedAt): ?>
                                        <div style="text-align:right;">
                                            <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;font-weight:700;letter-spacing:.05em;">Returned On</div>
                                            <div style="font-size:.78rem;font-weight:600;color:#475569;"><?= $returnedAt ?></div>
                                            <?php if ($a['ReturnedByName']): ?>
                                            <div style="font-size:.7rem;color:#94a3b8;">by <?= htmlspecialchars($a['ReturnedByName']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Restore button -->
                                        <form method="POST" action="?view=archived">
                                            <input type="hidden" name="action" value="restore_medication">
                                            <input type="hidden" name="medication_id" value="<?= $a['MedicationID'] ?>">
                                            <button type="submit" title="Manufacturer returned stock — restore to active inventory"
                                                style="display:inline-flex;align-items:center;gap:5px;padding:0 14px;height:32px;border-radius:8px;border:1.5px solid #bbf7d0;background:#f0fdf4;color:#15803d;cursor:pointer;font-size:.75rem;font-weight:700;font-family:'Outfit',sans-serif;white-space:nowrap;">
                                                <i class="bi bi-arrow-counterclockwise"></i> Stock Received Back
                                            </button>
                                        </form>
                                    </div>

                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>

                        </div><!-- /cards -->
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

    <!-- ══ Archive / Return to Manufacturer Modal ══ -->
    <div class="modal-overlay" id="archiveMedModal">
        <div style="background:#fff;border-radius:16px;padding:0;width:min(520px,94vw);max-height:90vh;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.18);display:flex;flex-direction:column;">

            <!-- Header — fixed -->
            <div style="flex-shrink:0;display:flex;align-items:center;gap:12px;padding:20px 24px 16px;border-bottom:1.5px solid #fde8d8;background:linear-gradient(90deg,#fff7f0 0%,#fff 100%);">
                <div style="width:42px;height:42px;background:#fff0e6;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1.5px solid #fed7aa;">
                    <i class="bi bi-archive-fill" style="font-size:1.2rem;color:#ea580c;"></i>
                </div>
                <div>
                    <div style="font-size:1rem;font-weight:700;color:#0f172a;line-height:1.2;">Archive Medication</div>
                    <div style="font-size:.75rem;color:#94a3b8;margin-top:2px;">This medication will be removed from active inventory</div>
                </div>
                <button onclick="closeArchiveModal()" style="margin-left:auto;width:32px;height:32px;border-radius:8px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#64748b;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">&#x2715;</button>
            </div>

            <!-- Medication name banner — fixed -->
            <div style="flex-shrink:0;padding:12px 24px;background:#fafafa;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;">
                <i class="bi bi-capsule-pill" style="color:#64748b;font-size:1rem;"></i>
                <span style="font-size:.82rem;color:#475569;">Medication:</span>
                <span style="font-size:.92rem;font-weight:700;color:#0f172a;" id="archiveMedName"></span>
                <span style="margin-left:auto;font-size:.75rem;font-weight:700;color:#ea580c;background:#fff0e6;border:1.5px solid #fed7aa;padding:3px 10px;border-radius:20px;white-space:nowrap;">
                    <i class="bi bi-box-seam" style="margin-right:3px;"></i>
                    <span id="archiveStockDisplay">— units in stock</span>
                </span>
            </div>

            <form method="POST" id="archiveForm" style="display:flex;flex-direction:column;flex:1;min-height:0;">
                <input type="hidden" name="action" value="archive_medication">
                <input type="hidden" name="medication_id" id="archiveMedId">
                <input type="hidden" name="units_returned" id="archiveUnitsHidden">

                <!-- Scrollable body -->
                <div class="return-modal-body">

                    <!-- ── Step 1: Return to manufacturer question ── -->
                    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:16px 18px;">
                        <div style="font-size:.85rem;font-weight:700;color:#0f172a;margin-bottom:12px;display:flex;align-items:center;gap:7px;">
                            <i class="bi bi-truck" style="color:#ea580c;"></i>
                            Will this medication be returned to the manufacturer, or archived for now?
                        </div>
                        <div style="display:flex;gap:10px;">
                            <label style="flex:1;cursor:pointer;">
                                <input type="radio" name="is_return" value="no" id="returnNo" onchange="toggleReturnFields(false)" checked style="display:none;">
                                <div id="pillNo" style="border:2px solid #fed7aa;border-radius:10px;padding:14px 10px;text-align:center;background:#fff7ed;transition:all .15s;">
                                    <i class="bi bi-archive-fill" style="display:block;font-size:1.4rem;margin-bottom:6px;color:#ea580c;"></i>
                                    <span style="font-size:.8rem;font-weight:700;color:#c2410c;">Archive for Now</span>
                                </div>
                            </label>
                            <label style="flex:1;cursor:pointer;">
                                <input type="radio" name="is_return" value="yes" id="returnYes" onchange="toggleReturnFields(true)" style="display:none;">
                                <div id="pillYes" style="border:2px solid #e2e8f0;border-radius:10px;padding:14px 10px;text-align:center;background:#fff;transition:all .15s;">
                                    <i class="bi bi-truck" style="display:block;font-size:1.4rem;margin-bottom:6px;color:#94a3b8;"></i>
                                    <span style="font-size:.8rem;font-weight:700;color:#64748b;">Return to Manufacturer</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- ── Step 2: Return details (shown only when Yes is selected) ── -->
                    <div id="returnFields" style="display:none;flex-direction:column;gap:14px;">

                        <!-- Divider -->
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                            <span style="font-size:.72rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;">Return Details</span>
                            <div style="flex:1;height:1px;background:#e2e8f0;"></div>
                        </div>

                        <!-- Return reason pills -->
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Return Reason *</label>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;" id="reasonPills">
                                <?php foreach (['Expired','Damaged','Recalled','Overstock','Quality Issue','Other'] as $reason):
                                    $icons = ['Expired'=>'bi-clock-history','Damaged'=>'bi-exclamation-triangle-fill','Recalled'=>'bi-megaphone-fill','Overstock'=>'bi-box-seam','Quality Issue'=>'bi-shield-exclamation','Other'=>'bi-three-dots'];
                                ?>
                                <label style="cursor:pointer;">
                                    <input type="radio" name="return_reason" value="<?= $reason ?>" <?= $reason==='Expired'?'checked':'' ?> style="display:none;" onchange="updateReasonPills(this)">
                                    <div class="reason-pill <?= $reason==='Expired'?'reason-active':'' ?>" style="border:1.5px solid <?= $reason==='Expired'?'#fed7aa':'#e2e8f0' ?>;border-radius:9px;padding:10px 8px;text-align:center;background:<?= $reason==='Expired'?'#fff7ed':'#f8fafc' ?>;transition:all .15s;">
                                        <i class="bi <?= $icons[$reason] ?>" style="display:block;font-size:1.1rem;margin-bottom:4px;color:<?= $reason==='Expired'?'#ea580c':'#94a3b8' ?>;"></i>
                                        <span style="font-size:.72rem;font-weight:700;color:<?= $reason==='Expired'?'#c2410c':'#64748b' ?>;"><?= $reason ?></span>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Units to return -->
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Units Being Returned</label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="number" min="1" id="archiveUnitsInput" placeholder="e.g. 200"
                                    oninput="document.getElementById('archiveUnitsHidden').value=this.value"
                                    style="flex:1;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;box-sizing:border-box;">
                                <button type="button" onclick="useAllStock()"
                                    style="padding:10px 16px;border-radius:10px;border:1.5px solid #fed7aa;background:#fff7ed;color:#ea580c;font-size:.8rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                                    All Stock
                                </button>
                            </div>
                            <div style="font-size:.72rem;color:#94a3b8;">Leave blank to default to full current stock.</div>
                        </div>

                        <!-- Notes -->
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Notes / Reference No. <span style="font-weight:400;text-transform:none;">(optional)</span></label>
                            <textarea name="return_notes" rows="2" placeholder="e.g. DR No. 2024-1023 — batch recalled by manufacturer…"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.85rem;background:#f8fafc;outline:none;resize:vertical;width:100%;box-sizing:border-box;font-family:'Outfit',sans-serif;"></textarea>
                        </div>

                        <!-- Info note -->
                        <div style="display:flex;align-items:flex-start;gap:10px;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:12px 14px;">
                            <i class="bi bi-info-circle-fill" style="color:#ea580c;flex-shrink:0;margin-top:1px;"></i>
                            <div style="font-size:.78rem;color:#92400e;line-height:1.5;">
                                Return will be logged in the <strong>Returned to Manufacturer</strong> ledger.
                                If replacement stock arrives, use <strong>"Stock Received Back"</strong> to restore it.
                            </div>
                        </div>

                    </div><!-- /returnFields -->

                </div><!-- /return-modal-body -->

                <!-- Footer — fixed -->
                <div style="flex-shrink:0;display:flex;gap:10px;padding:14px 24px 20px;border-top:1.5px solid #f1f5f9;background:#fff;">
                    <button type="button" onclick="closeArchiveModal()"
                        style="flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.9rem;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit" id="archiveSubmitBtn"
                        style="flex:2;padding:11px;border-radius:10px;border:none;background:#ea580c;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="bi bi-archive-fill" id="archiveSubmitIcon"></i>
                        <span id="archiveSubmitLabel">Archive for Now</span>
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

        /* ── Archive / Return to Manufacturer Modal ── */
        let _archiveStock = 0;
        function confirmArchive(id, name, stock, manufacturer) {
            _archiveStock = stock || 0;
            document.getElementById('archiveMedId').value        = id;
            document.getElementById('archiveMedName').textContent = name;
            document.getElementById('archiveStockDisplay').textContent = _archiveStock + ' unit(s) in stock';
            document.getElementById('archiveUnitsInput').value   = '';
            document.getElementById('archiveUnitsHidden').value  = '';
            document.getElementById('archiveUnitsInput').placeholder = 'e.g. ' + _archiveStock;
            // Reset to "No, Just Archive" default
            document.getElementById('returnNo').checked  = true;
            document.getElementById('returnYes').checked = false;
            toggleReturnFields(false);
            // Reset reason pills
            document.querySelectorAll('#reasonPills input[type=radio]').forEach(r => {
                r.checked = (r.value === 'Expired');
                updateReasonPills(r);
            });
            document.getElementById('archiveMedModal').classList.add('show');
        }
        function closeArchiveModal() {
            document.getElementById('archiveMedModal').classList.remove('show');
        }
        function toggleReturnFields(isReturn) {
            const fields     = document.getElementById('returnFields');
            const pillYes    = document.getElementById('pillYes');
            const pillNo     = document.getElementById('pillNo');
            const submitIcon = document.getElementById('archiveSubmitIcon');
            const submitLbl  = document.getElementById('archiveSubmitLabel');

            fields.style.display = isReturn ? 'flex' : 'none';

            // Style Yes pill
            pillYes.style.borderColor = isReturn ? '#fed7aa' : '#e2e8f0';
            pillYes.style.background  = isReturn ? '#fff7ed' : '#fff';
            pillYes.querySelector('i').style.color   = isReturn ? '#ea580c' : '#94a3b8';
            pillYes.querySelector('span').style.color = isReturn ? '#c2410c' : '#64748b';

            // Style No pill
            pillNo.style.borderColor = isReturn ? '#e2e8f0' : '#fed7aa';
            pillNo.style.background  = isReturn ? '#fff'    : '#fff7ed';
            pillNo.querySelector('i').style.color   = isReturn ? '#94a3b8' : '#ea580c';
            pillNo.querySelector('span').style.color = isReturn ? '#64748b' : '#c2410c';

            // Update submit button label
            if (isReturn) {
                submitIcon.className = 'bi bi-truck';
                submitLbl.textContent = 'Archive & Return to Manufacturer';
            } else {
                submitIcon.className = 'bi bi-archive-fill';
                submitLbl.textContent = 'Archive for Now';
            }
        }
        function useAllStock() {
            document.getElementById('archiveUnitsInput').value  = _archiveStock;
            document.getElementById('archiveUnitsHidden').value = _archiveStock;
        }
        function updateReasonPills(radio) {
            document.querySelectorAll('#reasonPills label .reason-pill').forEach(pill => {
                const inp    = pill.closest('label').querySelector('input');
                const active = inp.checked;
                pill.style.borderColor = active ? '#fed7aa' : '#e2e8f0';
                pill.style.background  = active ? '#fff7ed' : '#f8fafc';
                const icon = pill.querySelector('i');
                const lbl  = pill.querySelector('span');
                if (icon) icon.style.color = active ? '#ea580c' : '#94a3b8';
                if (lbl)  lbl.style.color  = active ? '#c2410c' : '#64748b';
            });
        }

        document.getElementById('archiveMedModal').addEventListener('click', e => {
            if (e.target === document.getElementById('archiveMedModal')) closeArchiveModal();
        });

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