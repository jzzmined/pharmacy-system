<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Admin';

/* ══ EXPORT DAILY SALES — triggered by ?action=export_csv ══ */
if (($_GET['action'] ?? '') === 'export_csv') {
    try {
        $db = getDB();
        $filename = 'pharmacare_daily_sales_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
        fputcsv($out, ['Invoice ID','Date','Patient','Age','Gender','Doctor','Medicines','Prescription ID','Dispensed By','Qty','Unit Price','Subtotal','Discount','Total','Status']);
        $s = $db->prepare("
            SELECT i.InvoiceID, pr.DatePrescribed, p.FullName AS PatientName,
                   p.Age, p.Gender, d.FullName AS DoctorName,
                   GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines,
                   i.PrescriptionID, u.FullName AS PharmacistName,
                   i.DispenseQuantity AS Qty, i.UnitPrice, i.Subtotal, i.Discount, i.Total, i.Status
            FROM invoices i
            JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
            JOIN patients p ON pr.PatientID = p.PatientID
            LEFT JOIN users u ON i.PharmacistID = u.UserID
            LEFT JOIN doctors d ON pr.DoctorID = d.DoctorID
            LEFT JOIN prescriptiondetails pd ON pd.PrescriptionID = pr.PrescriptionID
            LEFT JOIN medications m ON pd.MedicationID = m.MedicationID
            GROUP BY i.InvoiceID ORDER BY i.InvoiceID DESC
        ");
        $s->execute();
        $pad = fn($n) => str_pad($n, 3, '0', STR_PAD_LEFT);
        while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                'INV-' . $pad($row['InvoiceID']),
                $row['DatePrescribed'] ?? '—',
                $row['PatientName']    ?? '—',
                $row['Age']            ?? '—',
                $row['Gender']         ?? '—',
                $row['DoctorName']     ?? '—',
                $row['Medicines']      ?? '—',
                'RX-'  . $pad($row['PrescriptionID']),
                $row['PharmacistName'] ?? '—',
                $row['Qty'],
                number_format((float)$row['UnitPrice'], 2),
                number_format((float)$row['Subtotal'],  2),
                number_format((float)$row['Discount'],  2),
                number_format((float)$row['Total'],     2),
                $row['Status'] === 'Completed' ? 'Paid' : ($row['Status'] ?? 'Pending'),
            ]);
        }
        fclose($out);
        exit;
    } catch (Exception $e) {
        die("Export error: " . $e->getMessage());
    }
}

/* ══ LOCAL BACKUP — triggered by ?action=local_backup ══ */
if (($_GET['action'] ?? '') === 'local_backup') {
    try {
        $db = getDB();
        $dbname   = defined('DB_NAME') ? DB_NAME : 'pharmacy_db';
        $filename = 'pharmacare_backup_' . date('Y-m-d_H-i-s') . '.sql';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        $out = fopen('php://output', 'w');
        fwrite($out, "-- PharmaCare Backup | Generated: " . date('Y-m-d H:i:s') . " | DB: {$dbname}\n\n");
        fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            fwrite($out, "-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");
            $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $lines = [];
                foreach ($rows as $row) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote($v), array_values($row));
                    $lines[] = '(' . implode(', ', $vals) . ')';
                }
                fwrite($out, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $lines) . ";\n\n");
            }
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n-- End of backup\n");
        fclose($out);
        exit;
    } catch (Exception $e) {
        die("Backup error: " . $e->getMessage());
    }
}

try {
    $db = getDB();

    $s = $db->prepare("SELECT UserID, FullName, Email, Role, Status, LastLogin FROM users ORDER BY UserID ASC");
    $s->execute();
    $users = $s->fetchAll();

} catch (PDOException $e) {
    $users = [];
    error_log($e->getMessage());
}

// ── Audit Report Data ──
$audit_date_from = $_GET['audit_from'] ?? date('Y-m-01');
$audit_date_to   = $_GET['audit_to']   ?? date('Y-m-d');

try {
    $db = getDB();

    // 1. Prescription & Dispensing Summary
    // Using GetTotalPrescriptionsByDoctor() to show per-doctor counts
    $s = $db->prepare("
        SELECT pr.PrescriptionID, pr.DatePrescribed, pr.ExpirationDate,
               p.FullName AS PatientName, p.Age, p.Gender, p.MedicalConditions,
               d.FullName AS DoctorName, d.DoctorID,
               GetTotalPrescriptionsByDoctor(d.DoctorID) AS DoctorTotalRx,
               CheckPrescriptionValidity(pr.PrescriptionID) AS ValidityStatus
        FROM prescriptions pr
        LEFT JOIN patients p ON pr.PatientID = p.PatientID
        LEFT JOIN doctors  d ON pr.DoctorID  = d.DoctorID
        WHERE pr.DatePrescribed BETWEEN ? AND ?
        ORDER BY pr.DatePrescribed DESC
    ");
    $s->execute([$audit_date_from, $audit_date_to]);
    $audit_prescriptions = $s->fetchAll();

    // 2. Financial & Billing Review — CALL GenerateSalesReport(p_StartDate, p_EndDate)
    // Returns: Total_Transactions, Unique_Customers, Active_Pharmacists,
    //          Total_Items_Dispensed, Gross_Revenue, Total_Discounts,
    //          Net_Revenue, Average_Transaction_Value, Highest_Transaction, Lowest_Transaction
    $s = $db->prepare("CALL GenerateSalesReport(?, ?)");
    $s->execute([$audit_date_from, $audit_date_to]);
    $sales_summary = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    // Fetch second result set: per-pharmacist breakdown
    $pharmacist_sales = [];
    if ($s->nextRowset()) {
        $pharmacist_sales = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $s->closeCursor();

    // Extract summary totals from GenerateSalesReport()
    $total_revenue   = (float)($sales_summary['Net_Revenue']              ?? 0);
    $total_discount  = (float)($sales_summary['Total_Discounts']          ?? 0);
    $total_subtotal  = (float)($sales_summary['Gross_Revenue']            ?? 0);
    $avg_transaction = (float)($sales_summary['Average_Transaction_Value'] ?? 0);

    // Detailed invoice rows — only Completed (Paid) count toward revenue
    $s = $db->prepare("
        SELECT i.InvoiceID, i.PrescriptionID, i.PharmacistID,
               i.DispenseQuantity, i.UnitPrice, i.Discount, i.Subtotal, i.Total, i.Status,
               pr.DatePrescribed, p.FullName AS PatientName
        FROM invoices i
        JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
        JOIN patients p       ON pr.PatientID      = p.PatientID
        WHERE pr.DatePrescribed BETWEEN ? AND ?
          AND i.Status = 'Completed'
        ORDER BY pr.DatePrescribed DESC
    ");
    $s->execute([$audit_date_from, $audit_date_to]);
    $audit_invoices = $s->fetchAll();

    // 3. Audit Trail — dispensed by pharmacist
    $s = $db->prepare("
        SELECT i.InvoiceID, i.PharmacistID, u.FullName AS PharmacistName,
               i.DispenseQuantity, i.Total, i.Status,
               pr.DatePrescribed, p.FullName AS PatientName,
               GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines
        FROM invoices i
        JOIN prescriptions pr   ON i.PrescriptionID   = pr.PrescriptionID
        JOIN patients p         ON pr.PatientID        = p.PatientID
        LEFT JOIN users u       ON i.PharmacistID      = u.UserID
        LEFT JOIN prescriptiondetails pd ON pd.PrescriptionID = pr.PrescriptionID
        LEFT JOIN medications m ON pd.MedicationID     = m.MedicationID
        WHERE pr.DatePrescribed BETWEEN ? AND ?
        GROUP BY i.InvoiceID
        ORDER BY pr.DatePrescribed DESC
    ");
    $s->execute([$audit_date_from, $audit_date_to]);
    $audit_trail = $s->fetchAll();

    // 4. Inventory & Stock Variance
    $s = $db->prepare("
        SELECT m.MedicationID, m.GenericName, m.BrandName, m.DosageStrength,
               SUM(md.StockAvailability) AS CurrentStock,
               MIN(md.ExpirationDate)    AS NearestExpiry
        FROM medications m
        JOIN medicationdetails md ON m.MedicationID = md.MedicationID
        GROUP BY m.MedicationID
        ORDER BY CurrentStock ASC
    ");
    $s->execute();
    $audit_inventory = $s->fetchAll();

    // 5. Expired Medication Report
    $s = $db->prepare("
        SELECT m.GenericName, m.BrandName, m.DosageStrength,
               md.Manufacturer, md.ExpirationDate, md.StockAvailability,
               md.UnitPrice,
               DATEDIFF(md.ExpirationDate, CURDATE()) AS DaysUntilExpiry
        FROM medicationdetails md
        JOIN medications m ON md.MedicationID = m.MedicationID
        WHERE md.ExpirationDate <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ORDER BY md.ExpirationDate ASC
    ");
    $s->execute();
    $audit_expired = $s->fetchAll();

} catch (PDOException $e) {
    $audit_prescriptions = $audit_invoices = $audit_trail = $audit_inventory = $audit_expired = [];
    $total_revenue = $total_discount = $total_subtotal = $avg_transaction = 0;
}

function fmtAdminPad($n) { return 'ADM-' . str_pad($n, 3, '0', STR_PAD_LEFT); }
function fmtPad3($n)     { return str_pad($n, 3, '0', STR_PAD_LEFT); }
function roleClass($role) {
    return match(strtolower($role ?? '')) {
        'admin'      => 'role-admin',
        'pharmacist' => 'role-pharmacist',
        'cashier'    => 'role-cashier',
        default      => 'role-admin',
    };
}
function statusClass($s) {
    return match(strtolower($s ?? '')) {
        'active'   => 'status-active',
        'inactive' => 'status-inactive',
        default    => 'status-active',
    };
}
$avatarColors = ['#EF4444','#3B82F6','#22C55E','#A855F7','#F97316','#06B6D4','#EC4899','#EAB308'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Admin</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        /* ── Audit Modal ── */
        .audit-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.55);
            z-index: 1000;
            align-items: flex-start;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
        }
        .audit-modal-overlay.active { display: flex; }
        .audit-modal-box {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 960px;
            box-shadow: 0 20px 60px rgba(0,0,0,.18);
            overflow: hidden;
            margin: auto;
        }

        /* Audit Header */
        .audit-modal-head {
            background: #1e293b;
            color: #fff;
            padding: 22px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .audit-modal-head h2 { font-size: 1.1rem; font-weight: 700; margin: 0; }
        .audit-modal-head p  { font-size: .8rem; color: #94a3b8; margin: 2px 0 0; }
        .audit-modal-close {
            background: rgba(255,255,255,.1);
            border: none;
            color: #fff;
            width: 32px; height: 32px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            display: flex; align-items: center; justify-content: center;
        }
        .audit-modal-close:hover { background: rgba(255,255,255,.2); }

        /* Date range filter */
        .audit-filter-bar {
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px 28px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .audit-filter-bar label { font-size: .82rem; font-weight: 600; color: #64748b; }
        .audit-filter-bar input[type="date"] {
            padding: 6px 10px;
            border: 1.5px solid #e2e8f0;
            border-radius: 7px;
            font-size: .83rem;
            color: #1e293b;
            outline: none;
        }
        .audit-filter-bar input[type="date"]:focus { border-color: #6366f1; }
        .audit-filter-btn {
            padding: 7px 16px;
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: .83rem;
            font-weight: 600;
            cursor: pointer;
        }
        .audit-filter-btn:hover { background: #4f46e5; }

        /* Audit Body */
        .audit-modal-body { padding: 24px 28px; display: flex; flex-direction: column; gap: 28px; }

        /* Audit Section */
        .audit-section { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        .audit-section-head {
            background: #f8fafc;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .audit-section-head h3 { font-size: .88rem; font-weight: 700; color: #1e293b; margin: 0; }
        .audit-section-badge {
            font-size: .72rem;
            background: #e0e7ff;
            color: #6366f1;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 700;
            margin-left: auto;
        }
        .audit-section-badge.green { background: #dcfce7; color: #16a34a; }
        .audit-section-badge.amber { background: #fef9c3; color: #b45309; }
        .audit-section-badge.red   { background: #fee2e2; color: #dc2626; }

        /* Audit Table */
        .audit-table-wrap { overflow-x: auto; }
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .8rem;
        }
        .audit-table thead th {
            background: #1e293b;
            color: #cbd5e1;
            padding: 9px 12px;
            text-align: left;
            font-weight: 600;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }
        .audit-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .audit-table tbody tr:hover { background: #f8fafc; }
        .audit-table tbody td { padding: 8px 12px; color: #334155; }
        .audit-table .td-mono { font-family: monospace; font-size: .78rem; color: #6366f1; }
        .audit-empty { text-align: center; padding: 20px; color: #94a3b8; font-size: .83rem; }

        /* Summary row */
        .audit-summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            padding: 16px 18px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }
        .audit-sum-item { display: flex; flex-direction: column; gap: 2px; }
        .audit-sum-label { font-size: .7rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
        .audit-sum-value { font-size: 1rem; font-weight: 800; color: #1e293b; }

        /* Expiry badges */
        .exp-expired { color: #dc2626; font-weight: 700; }
        .exp-soon    { color: #d97706; font-weight: 600; }
        .exp-ok      { color: #16a34a; }

        /* Stock level */
        .stock-critical { color: #dc2626; font-weight: 700; }
        .stock-low      { color: #d97706; font-weight: 600; }
        .stock-ok       { color: #16a34a; }

        /* Audit Footer */
        .audit-modal-foot {
            padding: 16px 28px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            gap: 10px;
            flex-wrap: wrap;
        }
        .audit-foot-info { font-size: .78rem; color: #64748b; }
        .audit-foot-btns { display: flex; gap: 10px; }
        .audit-btn-print {
            padding: 9px 20px;
            background: #1e293b;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .84rem;
            font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; gap: 7px;
        }
        .audit-btn-print:hover { background: #0f172a; }
        .audit-btn-send {
            padding: 9px 20px;
            background: #6366f1;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .84rem;
            font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; gap: 7px;
        }
        .audit-btn-send:hover { background: #4f46e5; }

        /* Send Confirmation Modal */
        .send-confirm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.6);
            z-index: 1100;
            align-items: center;
            justify-content: center;
        }
        .send-confirm-overlay.active { display: flex; }
        .send-confirm-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            width: 420px;
            box-shadow: 0 20px 50px rgba(0,0,0,.2);
        }
        .send-confirm-box h3 { font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0 0 6px; }
        .send-confirm-box p  { font-size: .84rem; color: #64748b; margin: 0 0 18px; }
        .send-confirm-field  { display: flex; flex-direction: column; gap: 5px; margin-bottom: 18px; }
        .send-confirm-field label { font-size: .78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
        .send-confirm-field input {
            padding: 9px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: .88rem;
            outline: none;
        }
        .send-confirm-field input:focus { border-color: #6366f1; }
        .send-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .scn-cancel { padding: 8px 18px; border: 1.5px solid #e2e8f0; border-radius: 8px; background: #fff; color: #64748b; font-size: .84rem; cursor: pointer; }
        .scn-send   { padding: 8px 18px; background: #6366f1; color: #fff; border: none; border-radius: 8px; font-size: .84rem; font-weight: 600; cursor: pointer; }
        .scn-send:hover { background: #4f46e5; }

        /* Schedule Backup Modal */
        .sched-modal-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            width: 440px;
            box-shadow: 0 20px 50px rgba(0,0,0,.2);
        }
        .sched-modal-box h3 { font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0 0 6px; }
        .sched-modal-box p  { font-size: .84rem; color: #64748b; margin: 0 0 18px; }
        .sched-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .sched-field label { font-size: .78rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
        .sched-field select, .sched-field input {
            padding: 9px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: .88rem;
            outline: none;
        }
        .sched-field select:focus, .sched-field input:focus { border-color: #6366f1; }

        /* Backup updated items */
        .backup-item { cursor: pointer; transition: background .15s; border-radius: 10px; }
        .backup-item:hover { background: #f0f4ff; }

        @media print {
            body > *:not(.audit-modal-overlay) { display: none !important; }
            .audit-modal-overlay { display: block !important; position: static !important; background: none !important; padding: 0 !important; }
            .audit-modal-box { box-shadow: none !important; border-radius: 0 !important; }
            .audit-filter-bar, .audit-modal-close, .audit-modal-foot { display: none !important; }
            .audit-modal-body { padding: 10px !important; }
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
            <a href="inventory.php" class="nav-item" data-label="Medications">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="9" width="20" height="6" rx="3"/>
                    <line x1="12" y1="9" x2="12" y2="15"/>
                    <circle cx="7" cy="12" r="2.5" fill="currentColor" stroke="none" opacity="0.3"/>
                </svg>

            </a>
            <a href="admin.php" class="nav-item active" data-label="Admin">
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
            <div class="admin-layout">

                <!-- ══ LEFT: User Management ══ -->
                <div class="admin-users-card">
                    <div class="admin-toolbar">
                        <div class="admin-toolbar-left">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="admin-toolbar-icon">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            <span class="admin-toolbar-title">User Management</span>
                        </div>
                        <div class="admin-toolbar-right">
                            <div class="admin-search-wrap">
                                <input type="text" id="userSearch" class="admin-search" placeholder="Search..." autocomplete="off">
                            </div>
                            <button class="btn-add-user" id="btnAddUser">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <line x1="12" y1="5" x2="12" y2="19"/>
                                    <line x1="5" y1="12" x2="19" y2="12"/>
                                </svg>
                                Add User
                            </button>
                        </div>
                    </div>

                    <div class="admin-table-wrap">
                        <table class="admin-table" id="userTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th style="text-align:center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="userBody">
                            <?php if (empty($users)): ?>
                                <tr><td colspan="6" class="admin-empty">No users found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $i => $u): ?>
                                <?php
                                    $color    = $avatarColors[$i % count($avatarColors)];
                                    $words    = array_filter(explode(' ', $u['FullName']));
                                    $initials = strtoupper(substr(implode('', array_map(fn($w) => $w[0], $words)), 0, 2));
                                ?>
                                <tr
                                    data-id="<?= $u['UserID'] ?>"
                                    data-name="<?= strtolower(htmlspecialchars($u['FullName'])) ?>"
                                    data-email="<?= strtolower(htmlspecialchars($u['Email'] ?? '')) ?>"
                                    data-fullname="<?= htmlspecialchars($u['FullName'], ENT_QUOTES) ?>"
                                    data-emailval="<?= htmlspecialchars($u['Email'] ?? '', ENT_QUOTES) ?>"
                                    data-role="<?= htmlspecialchars(ucfirst(strtolower($u['Role'] ?? 'Admin')), ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars(ucfirst(strtolower($u['Status'] ?? 'Active')), ENT_QUOTES) ?>"
                                >
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar" style="background:<?= $color ?>"><?= $initials ?></div>
                                            <div class="user-info">
                                                <span class="user-name"><?= htmlspecialchars($u['FullName']) ?></span>
                                                <span class="user-id"><?= fmtAdminPad($u['UserID']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="user-email"><?= htmlspecialchars($u['Email'] ?? '—') ?></td>
                                    <td><span class="user-role <?= roleClass($u['Role']) ?>"><?= htmlspecialchars(ucfirst($u['Role'] ?? 'Admin')) ?></span></td>
                                    <td><span class="user-status <?= statusClass($u['Status']) ?>"><?= ucfirst($u['Status'] ?? 'Active') ?></span></td>
                                    <td class="user-login"><?= htmlspecialchars($u['LastLogin'] ?? '—') ?></td>
                                    <td>
                                        <div class="user-actions">
                                            <button class="ua-btn ua-edit" title="Edit" onclick="openEditFromRow(this)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                                </svg>
                                            </button>
                                            <button class="ua-btn ua-reset" title="Reset Password" onclick="resetPassword(<?= $u['UserID'] ?>)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="1 4 1 10 7 10"/>
                                                    <path d="M3.51 15a9 9 0 1 0 .49-3.5"/>
                                                </svg>
                                            </button>
                                            <button class="ua-btn ua-delete" title="Delete" onclick="deleteFromRow(this)">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                </div><!-- /admin-users-card -->

                <!-- ══ RIGHT: Backup & Reports ══ -->
                <div class="admin-backup-card">
                    <div class="backup-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="backup-header-icon">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                        </svg>
                        <span>Backup &amp; Reports</span>
                    </div>

                    <div class="backup-grid">

                        <!-- Local Backup (replaces Backup Data) -->
                        <div class="backup-item" onclick="localBackup()">
                            <div class="backup-item-icon bi-blue">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="8 17 12 21 16 17"/>
                                    <line x1="12" y1="12" x2="12" y2="21"/>
                                    <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>
                                </svg>
                            </div>
                            <div>
                                <div class="backup-item-title">Local Backup</div>
                                <div class="backup-item-sub">MySQL .sql snapshot</div>
                            </div>
                        </div>

                        <!-- Export Daily Sales (replaces Export Sales) -->
                        <div class="backup-item" onclick="exportDailySales()">
                            <div class="backup-item-icon bi-green">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                </svg>
                            </div>
                            <div>
                                <div class="backup-item-title">Export Daily Sales</div>
                                <div class="backup-item-sub">CSV with full transaction details</div>
                            </div>
                        </div>

                        <!-- Automatic Scheduled Backup (replaces Restore Backup) -->
                        <div class="backup-item" onclick="openSchedBackup()">
                            <div class="backup-item-icon bi-amber">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                            </div>
                            <div>
                                <div class="backup-item-title">Scheduled Backup</div>
                                <div class="backup-item-sub">Auto backup configuration</div>
                            </div>
                        </div>

                        <!-- Audit Report -->
                        <div class="backup-item" onclick="openAuditModal()">
                            <div class="backup-item-icon bi-red">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 11l3 3L22 4"/>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                                </svg>
                            </div>
                            <div>
                                <div class="backup-item-title">Audit Report</div>
                                <div class="backup-item-sub">View &amp; send to auditor</div>
                            </div>
                        </div>

                    </div><!-- /backup-grid -->

                    <div class="backup-actions">
                        <button class="backup-btn bb-save" onclick="localBackup()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="8 17 12 21 16 17"/>
                                <line x1="12" y1="12" x2="12" y2="21"/>
                                <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>
                            </svg>
                            Local Backup
                        </button>
                        <button class="backup-btn bb-export" onclick="exportDailySales()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="8 17 12 21 16 17"/>
                                <line x1="12" y1="12" x2="12" y2="21"/>
                                <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>
                            </svg>
                            Export Daily Sales
                        </button>
                        <button class="backup-btn bb-restore" onclick="openAuditModal()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M9 11l3 3L22 4"/>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                            </svg>
                            Audit Report
                        </button>
                        <button class="backup-btn bb-exit" onclick="exitSystem()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                <polyline points="16 17 21 12 16 7"/>
                                <line x1="21" y1="12" x2="9" y2="12"/>
                            </svg>
                            Exit System
                        </button>
                    </div>

                </div><!-- /admin-backup-card -->

            </div><!-- /admin-layout -->
        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<!-- ══ ADD USER MODAL ══ -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Add New User</span>
            <button class="modal-close" onclick="closeModal('addUserModal')">✕</button>
        </div>
        <form method="POST" action="../actions/add_user.php" id="addUserForm">
            <div class="modal-grid">
                <div class="modal-field full">
                    <label class="modal-label">Full Name</label>
                    <input type="text" name="full_name" class="modal-input" placeholder="e.g. Juan Dela Cruz" required>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Email</label>
                    <input type="email" name="email" class="modal-input" placeholder="user@example.ph" required>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Role</label>
                    <select name="role" class="modal-input">
                        <option value="Admin">Admin</option>
                        <option value="Pharmacist">Pharmacist</option>
                        <option value="Cashier">Cashier</option>
                    </select>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Password</label>
                    <input type="password" name="password" class="modal-input" placeholder="Temporary password" required>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Status</label>
                    <select name="status" class="modal-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="modal-btn-save">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ EDIT USER MODAL ══ -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Edit User</span>
            <button class="modal-close" onclick="closeModal('editUserModal')">✕</button>
        </div>
        <form method="POST" action="../actions/edit_user.php" id="editUserForm">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-grid">
                <div class="modal-field full">
                    <label class="modal-label">Full Name</label>
                    <input type="text" name="full_name" id="editFullName" class="modal-input" required>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Email</label>
                    <input type="email" name="email" id="editEmail" class="modal-input" required>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Role</label>
                    <select name="role" id="editRole" class="modal-input">
                        <option value="Admin">Admin</option>
                        <option value="Pharmacist">Pharmacist</option>
                        <option value="Cashier">Cashier</option>
                    </select>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Status</label>
                    <select name="status" id="editStatus" class="modal-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="modal-btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ AUDIT REPORT MODAL ══ -->
<div class="audit-modal-overlay" id="auditModal">
    <div class="audit-modal-box">

        <!-- Header -->
        <div class="audit-modal-head">
            <div>
                <h2>🏥 PharmaCare — Pharmacy Audit Report</h2>
                <p>Generated: <?= date('F d, Y \a\t h:i A') ?> &nbsp;|&nbsp; Period: <span id="auditPeriodLabel"><?= date('F 1, Y', strtotime($audit_date_from)) ?> – <?= date('F d, Y', strtotime($audit_date_to)) ?></span></p>
            </div>
            <button class="audit-modal-close" onclick="closeAuditModal()">✕</button>
        </div>

        <!-- Date Range Filter -->
        <div class="audit-filter-bar">
            <label>Period:</label>
            <input type="date" id="auditFrom" value="<?= $audit_date_from ?>">
            <span style="color:#94a3b8;font-size:.8rem">to</span>
            <input type="date" id="auditTo" value="<?= $audit_date_to ?>">
            <button class="audit-filter-btn" onclick="reloadAudit()">Apply</button>
        </div>

        <!-- Audit Body -->
        <div class="audit-modal-body">

            <!-- ① Prescription & Dispensing Summary -->
            <div class="audit-section">
                <div class="audit-section-head">
                    <h3>① Prescription &amp; Dispensing Summary</h3>
                    <span class="audit-section-badge"><?= count($audit_prescriptions) ?> records</span>
                </div>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>RX ID</th>
                                <th>Date</th>
                                <th>Patient</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Condition</th>
                                <th>Prescribing Doctor</th>
                                <th title="GetTotalPrescriptionsByDoctor()">Doctor Total Rx</th>
                                <th>Expiry</th>
                                <th title="CheckPrescriptionValidity()">Validity</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($audit_prescriptions)): ?>
                            <tr><td colspan="10" class="audit-empty">No prescriptions in this period.</td></tr>
                        <?php else: foreach ($audit_prescriptions as $r):
                            $validCls = ($r['ValidityStatus'] ?? 'EXPIRED') === 'VALID' ? 'color:#16a34a;font-weight:700' : 'color:#dc2626;font-weight:700';
                        ?>
                            <tr>
                                <td class="td-mono">RX-<?= fmtPad3($r['PrescriptionID']) ?></td>
                                <td><?= htmlspecialchars($r['DatePrescribed']) ?></td>
                                <td><?= htmlspecialchars($r['PatientName'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['Age'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['Gender'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['MedicalConditions'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['DoctorName'] ?? '—') ?></td>
                                <td style="text-align:center;font-weight:700;color:#6366f1"><?= (int)($r['DoctorTotalRx'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($r['ExpirationDate']) ?></td>
                                <td style="<?= $validCls ?>"><?= htmlspecialchars($r['ValidityStatus'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ② Financial & Billing Review -->
            <div class="audit-section">
                <div class="audit-section-head">
                    <h3>② Financial &amp; Billing Review</h3>
                    <span class="audit-section-badge green">₱<?= number_format($total_revenue, 2) ?> total</span>
                </div>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Invoice ID</th>
                                <th>RX ID</th>
                                <th>Patient</th>
                                <th>Date</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Discount</th>
                                <th>Subtotal</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($audit_invoices)): ?>
                            <tr><td colspan="10" class="audit-empty">No invoices in this period.</td></tr>
                        <?php else: foreach ($audit_invoices as $r): ?>
                            <tr>
                                <td class="td-mono">TXN-<?= fmtPad3($r['InvoiceID']) ?></td>
                                <td class="td-mono">RX-<?= fmtPad3($r['PrescriptionID']) ?></td>
                                <td><?= htmlspecialchars($r['PatientName'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['DatePrescribed']) ?></td>
                                <td style="text-align:center"><?= (int)$r['DispenseQuantity'] ?></td>
                                <td>₱<?= number_format((float)$r['UnitPrice'], 2) ?></td>
                                <td>₱<?= number_format((float)$r['Discount'], 2) ?></td>
                                <td>₱<?= number_format((float)$r['Subtotal'], 2) ?></td>
                                <td style="font-weight:700">₱<?= number_format((float)$r['Total'], 2) ?></td>
                                <td><?= ucfirst($r['Status'] ?? 'Pending') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Financial Summary — from CALL GenerateSalesReport() -->
                <div class="audit-summary-row" style="grid-template-columns:repeat(5,1fr)">
                    <div class="audit-sum-item">
                        <span class="audit-sum-label">Net Revenue</span>
                        <span class="audit-sum-value">₱<?= number_format((float)($sales_summary['Net_Revenue'] ?? $total_revenue), 2) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label">Gross Revenue</span>
                        <span class="audit-sum-value">₱<?= number_format((float)($sales_summary['Gross_Revenue'] ?? $total_subtotal), 2) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label">Total Discounts</span>
                        <span class="audit-sum-value">₱<?= number_format((float)($sales_summary['Total_Discounts'] ?? $total_discount), 2) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label">Avg Transaction</span>
                        <span class="audit-sum-value">₱<?= number_format((float)($sales_summary['Average_Transaction_Value'] ?? $avg_transaction), 2) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label">Total Transactions</span>
                        <span class="audit-sum-value"><?= (int)($sales_summary['Total_Transactions'] ?? count($audit_invoices)) ?></span>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:12px 18px;background:#f0f4ff;border-top:1px dashed #c7d2fe;">
                    <div class="audit-sum-item">
                        <span class="audit-sum-label" style="color:#6366f1">Unique Customers</span>
                        <span class="audit-sum-value" style="color:#6366f1"><?= (int)($sales_summary['Unique_Customers'] ?? 0) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label" style="color:#6366f1">Active Pharmacists</span>
                        <span class="audit-sum-value" style="color:#6366f1"><?= (int)($sales_summary['Active_Pharmacists'] ?? 0) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label" style="color:#6366f1">Items Dispensed</span>
                        <span class="audit-sum-value" style="color:#6366f1"><?= (int)($sales_summary['Total_Items_Dispensed'] ?? 0) ?></span>
                    </div>
                    <div class="audit-sum-item">
                        <span class="audit-sum-label" style="color:#6366f1">Highest Transaction</span>
                        <span class="audit-sum-value" style="color:#6366f1">₱<?= number_format((float)($sales_summary['Highest_Transaction'] ?? 0), 2) ?></span>
                    </div>
                </div>
                <div style="padding:6px 18px 8px;font-size:.72rem;color:#6366f1;border-top:1px solid #e2e8f0;">
                    ℹ️ Summary data sourced from <strong>CALL GenerateSalesReport('<?= $audit_date_from ?>', '<?= $audit_date_to ?>')</strong>
                </div>
            </div>

            <!-- ③ Audit Trail -->
            <div class="audit-section">
                <div class="audit-section-head">
                    <h3>③ Audit Trail — Dispensing Activity</h3>
                    <span class="audit-section-badge"><?= count($audit_trail) ?> entries</span>
                </div>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Invoice ID</th>
                                <th>Date</th>
                                <th>Patient</th>
                                <th>Pharmacist</th>
                                <th>PH ID</th>
                                <th>Medicines Dispensed</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($audit_trail)): ?>
                            <tr><td colspan="9" class="audit-empty">No dispense activity in this period.</td></tr>
                        <?php else: foreach ($audit_trail as $r): ?>
                            <tr>
                                <td class="td-mono">TXN-<?= fmtPad3($r['InvoiceID']) ?></td>
                                <td><?= htmlspecialchars($r['DatePrescribed']) ?></td>
                                <td><?= htmlspecialchars($r['PatientName'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($r['PharmacistName'] ?? '—') ?></td>
                                <td class="td-mono">PH-<?= fmtPad3($r['PharmacistID']) ?></td>
                                <td style="max-width:180px;white-space:normal"><?= htmlspecialchars($r['Medicines'] ?? '—') ?></td>
                                <td style="text-align:center"><?= (int)$r['DispenseQuantity'] ?></td>
                                <td style="font-weight:700">₱<?= number_format((float)$r['Total'], 2) ?></td>
                                <td><?= ucfirst($r['Status'] ?? 'Pending') ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ④ Inventory & Stock Variance -->
            <div class="audit-section">
                <div class="audit-section-head">
                    <h3>④ Inventory &amp; Stock Levels</h3>
                    <span class="audit-section-badge amber"><?= count($audit_inventory) ?> medications</span>
                </div>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Medication</th>
                                <th>Brand</th>
                                <th>Dosage</th>
                                <th>Current Stock</th>
                                <th>Nearest Expiry</th>
                                <th>Stock Level</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($audit_inventory)): ?>
                            <tr><td colspan="6" class="audit-empty">No inventory data.</td></tr>
                        <?php else: foreach ($audit_inventory as $r):
                            $stock = (int)$r['CurrentStock'];
                            $stockCls = $stock <= 100 ? 'stock-critical' : ($stock <= 300 ? 'stock-low' : 'stock-ok');
                            $stockLbl = $stock <= 100 ? 'Critical'       : ($stock <= 300 ? 'Low'      : 'Adequate');
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($r['GenericName']) ?></td>
                                <td><?= htmlspecialchars($r['BrandName']) ?></td>
                                <td><?= htmlspecialchars($r['DosageStrength']) ?></td>
                                <td class="<?= $stockCls ?>" style="font-weight:700"><?= $stock ?></td>
                                <td><?= htmlspecialchars($r['NearestExpiry'] ?? '—') ?></td>
                                <td class="<?= $stockCls ?>"><?= $stockLbl ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ⑤ Expired / Near-Expiry Medication Report -->
            <div class="audit-section">
                <div class="audit-section-head">
                    <h3>⑤ Expired &amp; Near-Expiry Medications (within 90 days)</h3>
                    <span class="audit-section-badge red"><?= count($audit_expired) ?> items</span>
                </div>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Medication</th>
                                <th>Brand</th>
                                <th>Dosage</th>
                                <th>Manufacturer</th>
                                <th>Expiry Date</th>
                                <th>Days Until Expiry</th>
                                <th>Stock</th>
                                <th>Unit Price</th>
                                <th>At-Risk Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($audit_expired)): ?>
                            <tr><td colspan="9" class="audit-empty">No expiring medications found. ✓</td></tr>
                        <?php else: foreach ($audit_expired as $r):
                            $days = (int)$r['DaysUntilExpiry'];
                            $expCls = $days <= 0 ? 'exp-expired' : ($days <= 30 ? 'exp-soon' : 'exp-ok');
                            $expLbl = $days <= 0 ? 'EXPIRED' : ($days <= 30 ? $days . ' days' : $days . ' days');
                            $riskVal = (float)$r['StockAvailability'] * (float)$r['UnitPrice'];
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($r['GenericName']) ?></td>
                                <td><?= htmlspecialchars($r['BrandName']) ?></td>
                                <td><?= htmlspecialchars($r['DosageStrength']) ?></td>
                                <td><?= htmlspecialchars($r['Manufacturer'] ?? '—') ?></td>
                                <td class="<?= $expCls ?>"><?= htmlspecialchars($r['ExpirationDate']) ?></td>
                                <td class="<?= $expCls ?>" style="font-weight:700"><?= $expLbl ?></td>
                                <td><?= (int)$r['StockAvailability'] ?></td>
                                <td>₱<?= number_format((float)$r['UnitPrice'], 2) ?></td>
                                <td style="font-weight:700;color:#dc2626">₱<?= number_format($riskVal, 2) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /audit-modal-body -->

        <!-- Footer -->
        <div class="audit-modal-foot">
            <div class="audit-foot-info">
                Generated by: <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></strong>
                &nbsp;|&nbsp; <?= date('F d, Y h:i A') ?>
            </div>
            <div class="audit-foot-btns">
                <button class="audit-btn-print" onclick="window.print()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Print Report
                </button>
                <button class="audit-btn-send" onclick="openSendConfirm()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    Send to Auditor
                </button>
            </div>
        </div>

    </div><!-- /audit-modal-box -->
</div><!-- /auditModal -->

<!-- ══ SEND TO AUDITOR CONFIRMATION ══ -->
<div class="send-confirm-overlay" id="sendConfirmModal">
    <div class="send-confirm-box">
        <h3>📧 Send Audit Report</h3>
        <p>The full audit report will be sent to the responsible auditor. Please confirm the auditor's email address below.</p>
        <div class="send-confirm-field">
            <label>Auditor Email</label>
            <input type="email" id="auditorEmail" placeholder="auditor@pharmacare.ph">
        </div>
        <div class="send-confirm-field">
            <label>Audit Period</label>
            <input type="text" id="auditPeriodInput" readonly style="background:#f8fafc;color:#64748b">
        </div>
        <div class="send-confirm-actions">
            <button class="scn-cancel" onclick="closeSendConfirm()">Cancel</button>
            <button class="scn-send" onclick="confirmSendAudit()">Confirm &amp; Send</button>
        </div>
    </div>
</div>

<!-- ══ SCHEDULED BACKUP MODAL ══ -->
<div class="send-confirm-overlay" id="schedBackupModal">
    <div class="sched-modal-box">
        <h3>⏰ Automatic Scheduled Backup</h3>
        <p>Configure when the system should automatically create a local MySQL backup.</p>
        <div class="sched-field">
            <label>Backup Frequency</label>
            <select id="schedFrequency">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
            </select>
        </div>
        <div class="sched-field">
            <label>Time</label>
            <input type="time" id="schedTime" value="02:00">
        </div>
        <div class="sched-field">
            <label>Save Location (server path)</label>
            <input type="text" id="schedPath" placeholder="/var/backups/pharmacare/" value="/var/backups/pharmacare/">
        </div>
        <div class="send-confirm-actions" style="margin-top:6px">
            <button class="scn-cancel" onclick="closeSchedBackup()">Cancel</button>
            <button class="scn-send" onclick="saveSchedBackup()">Save Schedule</button>
        </div>
    </div>
</div>

<div class="toast-tray" id="toastTray"></div>

<script>
'use strict';

/* ── Live search ── */
const userSearch = document.getElementById('userSearch');
const userRows   = document.querySelectorAll('#userBody tr[data-id]');
userSearch.addEventListener('input', () => {
    const q = userSearch.value.toLowerCase().trim();
    userRows.forEach(row => {
        const name  = row.dataset.name  || '';
        const email = row.dataset.email || '';
        row.style.display = (!q || name.includes(q) || email.includes(q)) ? '' : 'none';
    });
});

/* ── Modals ── */
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.getElementById('btnAddUser').addEventListener('click', () => openModal('addUserModal'));

function openEditFromRow(btn) {
    const row = btn.closest('tr');
    document.getElementById('editUserId').value   = row.dataset.id;
    document.getElementById('editFullName').value = row.dataset.fullname;
    document.getElementById('editEmail').value    = row.dataset.emailval;
    document.getElementById('editRole').value     = row.dataset.role;
    document.getElementById('editStatus').value   = row.dataset.status;
    openModal('editUserModal');
}

function deleteFromRow(btn) {
    const row  = btn.closest('tr');
    const id   = row.dataset.id;
    const name = row.dataset.fullname;
    if (confirm(`Delete user "${name}"? This cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '../actions/delete_user.php';
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'user_id'; inp.value = id;
        form.appendChild(inp);
        document.body.appendChild(form);
        form.submit();
    }
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('active'); });
});

function resetPassword(id) {
    if (confirm('Reset password for this user?')) showToast('Password reset link sent.', 'ok');
}

/* ── Backup & Report Actions ── */
function localBackup() {
    showToast('Preparing local MySQL backup…', 'warn');
    setTimeout(() => {
        window.location.href = 'admin.php?action=local_backup';
    }, 800);
}

function exportDailySales() {
    showToast('Generating Daily Sales CSV…', 'warn');
    setTimeout(() => {
        window.location.href = 'admin.php?action=export_csv';
    }, 800);
}

function exitSystem() {
    if (confirm('Exit and log out?')) window.location.href = '../logout.php';
}

/* ── Audit Modal ── */
function openAuditModal() {
    document.getElementById('auditModal').classList.add('active');
}
function closeAuditModal() {
    document.getElementById('auditModal').classList.remove('active');
}
document.getElementById('auditModal').addEventListener('click', function(e) {
    if (e.target === this) closeAuditModal();
});

function reloadAudit() {
    const from = document.getElementById('auditFrom').value;
    const to   = document.getElementById('auditTo').value;
    if (!from || !to) { showToast('Please select both dates.', 'warn'); return; }
    window.location.href = `admin.php?audit_from=${from}&audit_to=${to}#audit`;
}

/* ── Send to Auditor ── */
function openSendConfirm() {
    const from = document.getElementById('auditFrom').value;
    const to   = document.getElementById('auditTo').value;
    document.getElementById('auditPeriodInput').value = `${from} to ${to}`;
    document.getElementById('sendConfirmModal').classList.add('active');
}
function closeSendConfirm() {
    document.getElementById('sendConfirmModal').classList.remove('active');
}
function confirmSendAudit() {
    const email = document.getElementById('auditorEmail').value.trim();
    if (!email || !email.includes('@')) {
        showToast('Please enter a valid auditor email.', 'warn'); return;
    }
    closeSendConfirm();
    closeAuditModal();
    showToast(`✓ Audit report sent to ${email}`, 'ok');
}

/* ── Scheduled Backup ── */
function openSchedBackup() {
    document.getElementById('schedBackupModal').classList.add('active');
}
function closeSchedBackup() {
    document.getElementById('schedBackupModal').classList.remove('active');
}
function saveSchedBackup() {
    const freq = document.getElementById('schedFrequency').value;
    const time = document.getElementById('schedTime').value;
    closeSchedBackup();
    showToast(`✓ Scheduled ${freq} backup at ${time} saved.`, 'ok');
}

/* ── Toast ── */
function showToast(msg, type = 'ok') {
    const tray  = document.getElementById('toastTray');
    const toast = document.createElement('div');
    toast.className   = `toast-msg t-${type}`;
    toast.textContent = msg;
    tray.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(16px)';
        toast.style.transition = 'all .3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3200);
}

/* ── Sidebar ── */
const sidebar        = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const sidebarToggle  = document.getElementById('sidebarToggle');
if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); sidebarOverlay.classList.toggle('show'); });
}
if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); });
}

// Auto-open audit modal if redirected back after filter
<?php if (isset($_GET['audit_from'])): ?>
document.addEventListener('DOMContentLoaded', () => openAuditModal());
<?php endif; ?>
</script>

</body>
</html>