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
        $contraindications = trim($_POST['contraindications'] ?? '');
        $precautions = trim($_POST['precautions'] ?? '');
        $existing_med_id = (int) ($_POST['existing_med_id'] ?? 0);

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
            $success = "✓ Stock batch added via AddMedicationStock(). Batch ID: BATCH-" . str_pad($batch_id, 3, '0', STR_PAD_LEFT) .
                " | Total stock now: " . $total_stock;

        } else {
            // New medication: insert into medications first, then CALL AddMedicationStock()
            $s = $db->prepare("INSERT INTO medications (GenericName, BrandName, DosageStrength) VALUES (?,?,?)");
            $s->execute([$generic_name, $brand_name, $dosage_strength]);
            $new_med_id = $db->lastInsertId();

            // Now call procedure for the medicationdetails row
            $s = $db->prepare("CALL AddMedicationStock(?, ?, ?, ?)");
            $s->execute([$new_med_id, $manufacturer, $expiration_date, $stock_qty]);
            $result = $s->fetch();
            $s->closeCursor();

            // Set unit price and extra fields
            if ($result && $result['Stock_Batch_ID']) {
                $s = $db->prepare("UPDATE medicationdetails SET UnitPrice=?, Contraindications=?, Precautions=? WHERE MedDet=?");
                $s->execute([$unit_price, $contraindications, $precautions, $result['Stock_Batch_ID']]);
            }

            $success = "✓ New medication added via AddMedicationStock(). MED-" . str_pad($new_med_id, 3, '0', STR_PAD_LEFT) .
                " | Initial stock: " . $stock_qty;
        }

    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Insufficient stock')) {
            $error = "⚠ Stock check failed: Insufficient stock to complete this transaction.";
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
        $contra = trim($_POST['contraindications'] ?? '');
        $prec = trim($_POST['precautions'] ?? '');
        if ($id > 0 && $generic !== '') {
            $s = $db->prepare("UPDATE medications SET GenericName=?, BrandName=?, DosageStrength=? WHERE MedicationID=?");
            $s->execute([$generic, $brand, $dosage, $id]);
            $s = $db->prepare("UPDATE medicationdetails SET UnitPrice=?, Contraindications=?, Precautions=? WHERE MedicationID=?");
            $s->execute([$unit_price, $contra, $prec, $id]);
            $success = "✓ Medication MED-" . str_pad($id, 3, '0', STR_PAD_LEFT) . " updated successfully.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// ── Merge duplicate medicationdetails rows ──
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
            ? "✓ Merged duplicates for {$merged} medication(s). Stock totals have been combined."
            : "✓ No duplicates found — inventory is already clean.";

    } catch (PDOException $e) {
        $error = "Merge error: " . $e->getMessage();
    }
}

// ── Delete medication ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_medication') {
    try {
        $db = getDB();
        $id = (int)($_POST['medication_id'] ?? 0);
        if ($id > 0) {
            // Check if medication is referenced in prescriptiondetails
            $chk = $db->prepare("SELECT COUNT(*) FROM prescriptiondetails WHERE MedicationID=?");
            $chk->execute([$id]);
            $usedInRx = (int)$chk->fetchColumn();

            // Check if medication is referenced in invoices (via prescriptions)
            $chk2 = $db->prepare("SELECT COUNT(*) FROM invoices i
                JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
                JOIN prescriptiondetails pd ON pd.PrescriptionID = pr.PrescriptionID
                WHERE pd.MedicationID=?");
            $chk2->execute([$id]);
            $usedInInvoices = (int)$chk2->fetchColumn();

            if ($usedInRx > 0 || $usedInInvoices > 0) {
                // Get medication name for friendly message
                $nm = $db->prepare("SELECT GenericName, DosageStrength FROM medications WHERE MedicationID=?");
                $nm->execute([$id]);
                $med = $nm->fetch();
                $medName = $med ? htmlspecialchars($med['GenericName'] . ' ' . $med['DosageStrength']) : "This medication";
                $error = "⚠ Cannot delete \"{$medName}\" — it is referenced in {$usedInRx} prescription(s). 
                          You can edit its details, but records must be preserved for audit purposes.";
            } else {
                // Safe to delete — no FK references
                $s = $db->prepare("DELETE FROM medicationdetails WHERE MedicationID=?");
                $s->execute([$id]);
                $s = $db->prepare("DELETE FROM medications WHERE MedicationID=?");
                $s->execute([$id]);
                $success = "✓ Medication deleted successfully.";
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
            MIN(md.Contraindications)                AS Contraindications,
            MIN(md.Precautions)                      AS Precautions,
            MIN(md.MedDet)                           AS MedDet
        FROM medications m
        LEFT JOIN medicationdetails md ON md.MedicationID = m.MedicationID
        GROUP BY m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength
        ORDER BY m.GenericName ASC
    ");
    $s->execute();
    $medications = $s->fetchAll();

    // Also fetch medication list for "add stock to existing" dropdown
    $s = $db->prepare("SELECT MedicationID, GenericName, BrandName FROM medications ORDER BY GenericName ASC");
    $s->execute();
    $med_list = $s->fetchAll();

} catch (PDOException $e) {
    $medications = [];
    $med_list = [];
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
                <a href="transactions.php" class="nav-item" data-label="Transactions">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <circle cx="12" cy="15" r="3"/>
                    <polyline points="12 13.5 12 15 13 16"/>
                </svg>

                </a>
                <a href="inventory.php" class="nav-item active" data-label="Inventory">
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

                <?php if ($success): ?>
                    <div class="inv-result ok"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="inv-result err"><?= htmlspecialchars($error) ?></div>
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
                            <div class="stock-legend">
                                <span class="legend-item"><span class="legend-dot ld-adequate"></span> Adequate
                                    (&gt;300)</span>
                                <span class="legend-item"><span class="legend-dot ld-expiring"></span> Expiring
                                    Soon</span>
                                <span class="legend-item"><span class="legend-dot ld-low"></span> Low (≤300)</span>
                                <span class="legend-item"><span class="legend-dot ld-critical"></span> Critical
                                    (≤100)</span>
                            </div>
                        </div>
                        <div class="inventory-controls">
                            <input class="inv-search" id="medSearch" type="text" placeholder="Search…"
                                autocomplete="off">
                            <select class="inv-filter" id="stockFilter"
                                style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.84rem;color:#334155;outline:none;">
                                <option value="">All Status</option>
                                <option value="critical">Critical</option>
                                <option value="low">Low</option>
                                <option value="expiring">Expiring</option>
                                <option value="adequate">Adequate</option>
                            </select>
                            <button class="btn-add-med" id="openModal">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <line x1="12" y1="5" x2="12" y2="19" />
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>
                                Add Medicine
                            </button>
                        </div>
                    </div>

                    <!-- Table -->

                    <div class="med-table-wrap">
                        <table class="med-table" id="medTable">
                            <thead>
                                <tr>
                                    <th>Med ID</th>
                                    <th>Generic Name</th>
                                    <th>Brand</th>
                                    <th>Dosage</th>
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
                                        <td colspan="9" class="med-empty">No medications found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($medications as $m):
                                        $liveStock  = (int) $m['LiveStock'];
                                        $hasDetails = $m['MedDet'] !== null; // false if no medicationdetails row
                                        $exp        = $m['ExpirationDate'] ?? null;
                                        $status     = $hasDetails ? stockStatus($liveStock, $exp ?? date('Y-m-d', strtotime('+2 years'))) : 'critical';
                                        $sClass     = stockClass($status);
                                        ?>
                                        <tr data-search="<?= strtolower('MED-' . fmtPad($m['MedicationID']) . ' ' . $m['GenericName'] . ' ' . $m['BrandName']) ?>"
                                            data-status="<?= $status ?>">
                                            <td class="med-col-id">MED-<?= fmtPad($m['MedicationID']) ?></td>
                                            <td class="med-col-name"><?= htmlspecialchars($m['GenericName']) ?></td>
                                            <td class="med-col-brand"><?= htmlspecialchars($m['BrandName']) ?></td>
                                            <td class="med-col-dose"><?= htmlspecialchars($m['DosageStrength']) ?></td>
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
                                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                            </svg>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="confirmDelete(<?= $m['MedicationID'] ?>, '<?= htmlspecialchars($m['GenericName']) ?>')" title="Delete"
                                                        style="width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;border:1.5px solid #fecaca;background:#fff5f5;color:#ef4444;cursor:pointer;padding:0;">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                                            <polyline points="3 6 5 6 21 6"/>
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                            <path d="M10 11v6M14 11v6"/>
                                                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

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
                    <div style="width:38px;height:38px;background:#e0e7ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.2">
                            <rect x="3" y="3" width="18" height="18" rx="3"/>
                            <line x1="12" y1="7" x2="12" y2="17"/>
                            <line x1="7" y1="12" x2="17" y2="12"/>
                        </svg>
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
                            <div></div>
                            <div style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;">
                                <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Contraindications</label>
                                <input type="text" name="contraindications" placeholder="e.g. Severe liver disease"
                                    style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;">
                                <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Precautions</label>
                                <input type="text" name="precautions" placeholder="e.g. Do not exceed 4g/day"
                                    style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
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
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" style="flex-shrink:0;margin-top:1px;">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
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

    <div class="toast-tray" id="toastTray"></div>

    <!-- ══ Edit Medicine Modal ══ -->
    <div class="modal-overlay" id="editMedModal">
        <div class="modal-box" style="width:min(600px,95vw);max-height:90vh;overflow-y:auto;border-radius:16px;padding:0;">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:22px 28px 18px;border-bottom:1.5px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
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
                        <div style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Contraindications</label>
                            <input type="text" name="contraindications" id="editContra"
                                style="padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;background:#f8fafc;outline:none;width:100%;box-sizing:border-box;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;">
                            <label style="font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#64748b;">Precautions</label>
                            <input type="text" name="precautions" id="editPrecautions"
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

    <!-- ══ Delete Confirm Modal ══ -->
    <div class="modal-overlay" id="deleteMedModal">
        <div style="background:#fff;border-radius:16px;padding:0;width:min(420px,90vw);overflow:hidden;">
            <div style="padding:28px 28px 20px;text-align:center;">
                <div style="width:52px;height:52px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                </div>
                <div style="font-size:1.05rem;font-weight:700;color:#0f172a;margin-bottom:8px;">Delete Medication?</div>
                <div style="font-size:.88rem;color:#64748b;line-height:1.5;">You are about to delete <strong id="deleteMedName"></strong>. This action cannot be undone.</div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_medication">
                <input type="hidden" name="medication_id" id="deleteMedId">
                <div style="display:flex;gap:10px;padding:0 28px 24px;">
                    <button type="button" onclick="closeDeleteModal()"
                        style="flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.9rem;font-weight:600;cursor:pointer;">Cancel</button>
                    <button type="submit"
                        style="flex:1;padding:11px;border-radius:10px;border:none;background:#ef4444;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;">Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        'use strict';

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
        const medSearch = document.getElementById('medSearch');
        const stockFilter = document.getElementById('stockFilter');
        const medRows = document.querySelectorAll('#medBody tr[data-status]');
        function applyFilters() {
            const q = medSearch.value.toLowerCase().trim();
            const status = stockFilter.value;
            medRows.forEach(row => {
                const matchQ = !q || row.dataset.search.includes(q);
                const matchS = !status || row.dataset.status === status;
                row.style.display = matchQ && matchS ? '' : 'none';
            });
        }
        medSearch.addEventListener('input', applyFilters);
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
            document.getElementById('editContra').value = data.Contraindications || '';
            document.getElementById('editPrecautions').value = data.Precautions || '';
            document.getElementById('editMedModal').classList.add('show');
        }
        function closeEditModal() {
            document.getElementById('editMedModal').classList.remove('show');
        }
        document.getElementById('editMedModal').addEventListener('click', e => {
            if (e.target === document.getElementById('editMedModal')) closeEditModal();
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

        /* ── Sidebar ── */
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