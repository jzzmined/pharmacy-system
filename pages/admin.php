<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

requireLogin();

$page_title = 'Admin';

/* ══ ADD USER — triggered by POST ?action=add_user ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $role      = trim($_POST['role']      ?? 'Admin');
    $password  = $_POST['password']       ?? '';
    $status    = trim($_POST['status']    ?? 'Active');
    if (!$full_name || !$email || !$password) {
        $_SESSION['error'] = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email address.';
    } else {
        try {
            $db = getDB();
            $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE Email = ?");
            $chk->execute([$email]);
            if ((int)$chk->fetchColumn() > 0) {
                $_SESSION['error'] = "Email \"{$email}\" is already in use.";
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $db->prepare("INSERT INTO users (FullName, Email, Role, Password, Status) VALUES (?,?,?,?,?)")
                   ->execute([$full_name, $email, $role, $hashed, $status]);
                $_SESSION['success'] = "User \"{$full_name}\" added successfully.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
    header('Location: admin.php'); exit;
}

/* ══ EDIT USER — triggered by POST ?action=edit_user ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $user_id   = (int)($_POST['user_id']  ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $role      = trim($_POST['role']      ?? 'Admin');
    $status    = trim($_POST['status']    ?? 'Active');
    if (!$user_id || !$full_name || !$email) {
        $_SESSION['error'] = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email address.';
    } else {
        try {
            $db = getDB();
            $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE Email = ? AND UserID != ?");
            $chk->execute([$email, $user_id]);
            if ((int)$chk->fetchColumn() > 0) {
                $_SESSION['error'] = "Email \"{$email}\" is already used by another account.";
            } else {
                $db->prepare("UPDATE users SET FullName=?, Email=?, Role=?, Status=? WHERE UserID=?")
                   ->execute([$full_name, $email, $role, $status, $user_id]);
                $_SESSION['success'] = "User \"{$full_name}\" updated successfully.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
    header('Location: admin.php'); exit;
}

/* ══ DELETE USER — triggered by POST ?action=delete_user ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $user_id = (int)($_POST['user_id'] ?? 0);
    if (!$user_id) {
        $_SESSION['error'] = 'Invalid user ID.';
    } elseif ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
        $_SESSION['error'] = 'You cannot delete your own account.';
    } else {
        try {
            $db = getDB();
            $nm = $db->prepare("SELECT FullName FROM users WHERE UserID = ?");
            $nm->execute([$user_id]);
            $name = $nm->fetchColumn() ?: 'User';
            $db->prepare("DELETE FROM users WHERE UserID = ?")->execute([$user_id]);
            $_SESSION['success'] = "User \"{$name}\" deleted successfully.";
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Database error: ' . $e->getMessage();
        }
    }
    header('Location: admin.php'); exit;
}

/* ══ CHANGE PASSWORD — triggered by POST admin.php?action=reset_password (AJAX/JSON) ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reset_password') {
    header('Content-Type: application/json');

    // ── Auth check ──
    if (empty($_SESSION['logged_in']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in as an admin.']); exit;
    }

    $data            = json_decode(file_get_contents('php://input'), true) ?? [];
    $target_id       = (int)($data['user_id']        ?? 0);
    $current_password = trim($data['current_password'] ?? '');
    $new_password    = trim($data['new_password']     ?? '');

    // ── Basic presence checks ──
    if ($target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID.']); exit;
    }
    if ($current_password === '') {
        echo json_encode(['success' => false, 'message' => 'Current password is required.']); exit;
    }
    if ($new_password === '') {
        echo json_encode(['success' => false, 'message' => 'New password is required.']); exit;
    }

    // ── Password requirements (must match frontend rules exactly) ──
    $errors = [];
    if (strlen($new_password) < 8)               $errors[] = 'at least 8 characters';
    if (!preg_match('/[A-Z]/', $new_password))    $errors[] = 'at least one uppercase letter (A–Z)';
    if (!preg_match('/[a-z]/', $new_password))    $errors[] = 'at least one lowercase letter (a–z)';
    if (!preg_match('/[0-9]/', $new_password))    $errors[] = 'at least one number (0–9)';
    if (!preg_match('/[^A-Za-z0-9]/', $new_password)) $errors[] = 'at least one special character';

    if (!empty($errors)) {
        $list = implode(', ', $errors);
        echo json_encode(['success' => false, 'message' => "New password must contain: {$list}."]); exit;
    }

    try {
        $db = getDB();

        // ── Fetch stored hash for the target user ──
        $stmt = $db->prepare("SELECT Password FROM users WHERE UserID = ?");
        $stmt->execute([$target_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'User not found.']); exit;
        }

        // ── Verify current password ──
        if (!password_verify($current_password, $row['Password'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect current password. Please try again.']); exit;
        }

        // ── Prevent reuse: new password must differ from current ──
        if (password_verify($new_password, $row['Password'])) {
            echo json_encode(['success' => false, 'message' => 'New password must be different from the current password.']); exit;
        }

        // ── All checks passed — update ──
        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET Password = ? WHERE UserID = ?")
           ->execute([$hashed, $target_id]);

        echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}


/* ══ EXPORT DAILY SALES — Excel XML Spreadsheet ══ */
if (($_GET['action'] ?? '') === 'export_csv') {
    try {
        $db = getDB();
        $filename = 'pharmacare_daily_sales_' . date('Y-m-d') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

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
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $pad  = fn($n) => str_pad($n, 3, '0', STR_PAD_LEFT);

        $xe = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // ── Header style ──
        $headers = ['Invoice ID','Date','Patient','Age','Gender','Doctor',
                    'Medicines','Prescription ID','Dispensed By','Qty',
                    'Unit Price (₱)','Subtotal (₱)','Discount (₱)','Total (₱)','Status'];

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
        ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:x="urn:schemas-microsoft-com:office:excel">
 <Styles>
  <Style ss:ID="header">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#0F2854"/>
   </Borders>
   <Font ss:Bold="1" ss:Size="11" ss:Color="#FFFFFF" ss:FontName="Calibri"/>
   <Interior ss:Color="#0F2854" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="row_odd">
   <Alignment ss:Vertical="Center"/>
   <Font ss:Size="10" ss:FontName="Calibri"/>
   <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="row_even">
   <Alignment ss:Vertical="Center"/>
   <Font ss:Size="10" ss:FontName="Calibri"/>
   <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="status_paid">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:Bold="1" ss:Size="10" ss:Color="#15803D" ss:FontName="Calibri"/>
   <Interior ss:Color="#DCFCE7" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="status_pending">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:Bold="1" ss:Size="10" ss:Color="#92400E" ss:FontName="Calibri"/>
   <Interior ss:Color="#FEF9C3" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="status_cancelled">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:Bold="1" ss:Size="10" ss:Color="#DC2626" ss:FontName="Calibri"/>
   <Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="num">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:Size="10" ss:FontName="Calibri"/>
  </Style>
  <Style ss:ID="num_odd">
   <Alignment ss:Horizontal="Right" ss:Vertical="Center"/>
   <Font ss:Size="10" ss:FontName="Calibri"/>
   <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="center">
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
   <Font ss:Size="10" ss:FontName="Calibri"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Daily Sales">
  <Table ss:DefaultRowHeight="18">
   <Column ss:Width="75"/>  <!-- Invoice ID -->
   <Column ss:Width="90"/>  <!-- Date -->
   <Column ss:Width="130"/> <!-- Patient -->
   <Column ss:Width="40"/>  <!-- Age -->
   <Column ss:Width="60"/>  <!-- Gender -->
   <Column ss:Width="130"/> <!-- Doctor -->
   <Column ss:Width="120"/> <!-- Medicines -->
   <Column ss:Width="90"/>  <!-- Prescription ID -->
   <Column ss:Width="130"/> <!-- Dispensed By -->
   <Column ss:Width="40"/>  <!-- Qty -->
   <Column ss:Width="80"/>  <!-- Unit Price -->
   <Column ss:Width="80"/>  <!-- Subtotal -->
   <Column ss:Width="70"/>  <!-- Discount -->
   <Column ss:Width="80"/>  <!-- Total -->
   <Column ss:Width="75"/>  <!-- Status -->
   <Row ss:Height="24">
<?php foreach ($headers as $h): ?>
    <Cell ss:StyleID="header"><Data ss:Type="String"><?= $xe($h) ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php foreach ($rows as $i => $row):
    $baseStyle = ($i % 2 === 0) ? 'row_even' : 'row_odd';
    $numStyle  = ($i % 2 === 0) ? 'num' : 'num_odd';
    $status    = strtolower($row['Status'] ?? 'pending');
    $statusLabel = $status === 'completed' ? 'Paid' : ucfirst($status);
    $statusStyle = match($status) {
        'completed' => 'status_paid',
        'cancelled' => 'status_cancelled',
        default     => 'status_pending',
    };
?>
   <Row ss:Height="18">
    <Cell ss:StyleID="<?= $baseStyle ?>"><Data ss:Type="String">INV-<?= $xe($pad($row['InvoiceID'])) ?></Data></Cell>
    <Cell ss:StyleID="<?= $baseStyle ?>"><Data ss:Type="String"><?= $xe($row['DatePrescribed'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $baseStyle ?>"><Data ss:Type="String"><?= $xe($row['PatientName'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="Number"><?= (int)$row['Age'] ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="String"><?= $xe($row['Gender'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $baseStyle ?>"><Data ss:Type="String"><?= $xe($row['DoctorName'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $baseStyle ?>"><Data ss:Type="String"><?= $xe($row['Medicines'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="String">RX-<?= $xe($pad($row['PrescriptionID'])) ?></Data></Cell>
    <Cell ss:StyleID="<?= $baseStyle ?>"><Data ss:Type="String"><?= $xe($row['PharmacistName'] ?? '') ?></Data></Cell>
    <Cell ss:StyleID="center"><Data ss:Type="Number"><?= (int)$row['Qty'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $numStyle ?>"><Data ss:Type="Number"><?= number_format((float)$row['UnitPrice'], 2, '.', '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $numStyle ?>"><Data ss:Type="Number"><?= number_format((float)$row['Subtotal'],  2, '.', '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $numStyle ?>"><Data ss:Type="Number"><?= number_format((float)$row['Discount'],  2, '.', '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $numStyle ?>"><Data ss:Type="Number"><?= number_format((float)$row['Total'],     2, '.', '') ?></Data></Cell>
    <Cell ss:StyleID="<?= $statusStyle ?>"><Data ss:Type="String"><?= $xe($statusLabel) ?></Data></Cell>
   </Row>
<?php endforeach; ?>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <FreezePanes/>
   <FrozenNoSplit/>
   <SplitHorizontal>1</SplitHorizontal>
   <TopRowBottomPane>1</TopRowBottomPane>
   <ActivePane>2</ActivePane>
  </WorksheetOptions>
 </Worksheet>
</Workbook>
<?php
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

/* ══ RECENT RECEIPTS ══ */
try {
    $db = getDB();
    $s = $db->prepare("
        SELECT i.InvoiceID, i.Total, i.Subtotal, i.Discount,
               i.AmountTendered, i.PaymentMethod,
               i.DispenseQuantity AS DispenseQty,
               pr.DatePrescribed,
               p.FullName AS PatientName, p.Age AS PatientAge, p.Gender AS PatientGender,
               d.FullName AS DoctorName,
               u.FullName AS PharmacistName,
               GROUP_CONCAT(m.GenericName ORDER BY m.GenericName SEPARATOR ', ') AS Medicines
        FROM invoices i
        JOIN prescriptions pr           ON i.PrescriptionID  = pr.PrescriptionID
        JOIN patients p                 ON pr.PatientID       = p.PatientID
        LEFT JOIN users u               ON i.PharmacistID     = u.UserID
        LEFT JOIN doctors d             ON pr.DoctorID        = d.DoctorID
        LEFT JOIN prescriptiondetails pd ON pd.PrescriptionID = pr.PrescriptionID
        LEFT JOIN medications m         ON pd.MedicationID    = m.MedicationID
        WHERE i.Status = 'Completed'
        GROUP BY i.InvoiceID
        ORDER BY i.InvoiceID DESC
        LIMIT 5
    ");
    $s->execute();
    $recent_receipts = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_receipts = [];
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
    $sales_summary    = [];
    $pharmacist_sales = [];
    try {
        $s = $db->prepare("CALL GenerateSalesReport(?, ?)");
        $s->execute([$audit_date_from, $audit_date_to]);
        $sales_summary = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($s->nextRowset()) {
            $pharmacist_sales = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        $s->closeCursor();
    } catch (PDOException $e) {
        // SP may not exist; fall through with empty summary
        $sales_summary = [];
    }

    // Extract summary totals from GenerateSalesReport()
    $total_revenue   = (float)($sales_summary['Net_Revenue']               ?? 0);
    $total_discount  = (float)($sales_summary['Total_Discounts']           ?? 0);
    $total_subtotal  = (float)($sales_summary['Gross_Revenue']             ?? 0);
    $avg_transaction = (float)($sales_summary['Average_Transaction_Value'] ?? 0);

    // If SP returned nothing, compute manually from invoices
    if ($total_revenue === 0.0 && $total_subtotal === 0.0) {
        $sf = $db->prepare("
            SELECT
                COUNT(*)                            AS Total_Transactions,
                COALESCE(SUM(i.Subtotal), 0)        AS Gross_Revenue,
                COALESCE(SUM(i.Discount), 0)        AS Total_Discounts,
                COALESCE(SUM(i.Total), 0)           AS Net_Revenue,
                COALESCE(AVG(i.Total), 0)           AS Average_Transaction_Value,
                COALESCE(MAX(i.Total), 0)           AS Highest_Transaction,
                COUNT(DISTINCT pr.PatientID)        AS Unique_Customers,
                COUNT(DISTINCT i.PharmacistID)      AS Active_Pharmacists,
                COALESCE(SUM(i.DispenseQuantity), 0) AS Total_Items_Dispensed
            FROM invoices i
            JOIN prescriptions pr ON i.PrescriptionID = pr.PrescriptionID
            WHERE pr.DatePrescribed BETWEEN ? AND ?
              AND i.Status = 'Completed'
        ");
        $sf->execute([$audit_date_from, $audit_date_to]);
        $fallback = $sf->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($fallback)) {
            $sales_summary   = $fallback;
            $total_revenue   = (float)($fallback['Net_Revenue']               ?? 0);
            $total_discount  = (float)($fallback['Total_Discounts']           ?? 0);
            $total_subtotal  = (float)($fallback['Gross_Revenue']             ?? 0);
            $avg_transaction = (float)($fallback['Average_Transaction_Value'] ?? 0);
        }
    }

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
    $sales_summary    = [];
    $pharmacist_sales = [];
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

// ── Flash messages from add_user / edit_user / delete_user actions ──
$flash_success = '';
$flash_error   = '';
if (!empty($_SESSION['success'])) {
    $flash_success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (!empty($_SESSION['error'])) {
    $flash_error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Admin</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ── Admin flash messages ── */
        .admin-flash {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 18px;
            border-radius: 10px;
            font-size: .875rem;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .admin-flash-ok  { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .admin-flash-err { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
        .admin-flash i.bi { font-size: 1rem; flex-shrink: 0; }
        .flash-close {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            opacity: .6;
            font-size: .85rem;
            padding: 2px 4px;
        }
        .flash-close:hover { opacity: 1; }

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
        .pg-info { font-size: .8rem; color: #64748b; font-weight: 600; }
        .pagination { justify-content: space-between; }

        /* ══ CONSISTENT TABLE/CONTAINER HEIGHT ══ */
        .card .table-scroll,
        .inv-table-wrap,
        .admin-table-wrap { min-height: 280px; }

        /* ══ ADMIN PAGE – Bootstrap Icon fixes ══ */
        /* Make .modal-overlay visible when .active is toggled by JS */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5);
            z-index: 999; align-items: center; justify-content: center; }
        .modal-overlay.active { display: flex; }

        /* Backup item icons (Bootstrap Icons) */
        .backup-item-icon { width: 42px; height: 42px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .backup-item-icon i.bi { font-size: 1.3rem; }
        .bi-blue  i.bi { color: #1d4ed8; }
        .bi-green i.bi { color: #15803d; }
        .bi-amber i.bi { color: #b45309; }
        .bi-red   i.bi { color: #b91c1c; }

        /* Backup button Bootstrap Icons */
        .backup-btn { display: flex; align-items: center; gap: 8px; }
        .backup-btn i.bi { font-size: .95rem; }
        .bb-save i.bi, .bb-export i.bi, .bb-restore i.bi, .bb-exit i.bi { color: #fff; }

        /* Add User button Bootstrap Icon */
        .btn-add-user { display: inline-flex; align-items: center; gap: 7px; }
        .btn-add-user i.bi { font-size: .95rem; color: #fff; }

        /* User action buttons Bootstrap Icons */
        .ua-btn { width: 32px; height: 32px; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 8px; border: 1.5px solid #e2e8f0;
            background: #f8fafc; cursor: pointer; transition: all .15s; }
        .ua-btn i.bi { font-size: .9rem; line-height: 1; }

        /* Admin search icon wrapper */
        .admin-search-wrap { position: relative; }
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
            <a href="inventory.php" class="nav-item" data-label="Medications">
                <i class="bi bi-capsule-pill"></i>
            </a>
            <a href="admin.php" class="nav-item active" data-label="Admin">
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

            <?php if ($flash_success): ?>
            <div class="admin-flash admin-flash-ok" id="adminFlash">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($flash_success) ?>
                <button onclick="document.getElementById('adminFlash').remove()" class="flash-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
            <div class="admin-flash admin-flash-err" id="adminFlash">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($flash_error) ?>
                <button onclick="document.getElementById('adminFlash').remove()" class="flash-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php endif; ?>

            <div class="admin-layout">

                <!-- ══ LEFT: User Management ══ -->
                <div class="admin-users-card">
                    <div class="admin-toolbar">
                        <div class="admin-toolbar-left">
                            <i class="bi bi-people-fill admin-toolbar-icon" style="color:#111827;font-size:1.2rem;opacity:1"></i>
                            <span class="admin-toolbar-title">User Management</span>
                        </div>
                        <div class="admin-toolbar-right">
                            <div class="admin-search-wrap">
                                <input type="text" id="userSearch" class="admin-search" placeholder="Search..." autocomplete="off">
                            </div>
                            <button class="btn-add-user" id="btnAddUser">
                                <i class="bi bi-person-plus-fill"></i>
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
                                            <button class="ua-btn ua-edit" title="Edit" onclick="openEditFromRow(this)"><i class="bi bi-pencil-fill"></i>                                            </button>
                                            <button class="ua-btn ua-delete" title="Delete" onclick="deleteFromRow(this)"><i class="bi bi-trash3-fill"></i>                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination" id="pg-users"></div>
                </div><!-- /admin-users-card -->

                <!-- ══ RIGHT: Backup & Reports ══ -->
                <div class="admin-backup-card">
                    <div class="backup-header">
                        <i class="bi bi-database-fill-gear backup-header-icon"></i>
                        <span>Backup &amp; Reports</span>
                    </div>

                    <div class="backup-grid">

                        <!-- Local Backup (replaces Backup Data) -->
                        <div class="backup-item" onclick="localBackup()">
                            <div class="backup-item-icon bi-blue"><i class="bi bi-cloud-arrow-down-fill"></i>                            </div>
                            <div>
                                <div class="backup-item-title">Local Backup</div>
                                <div class="backup-item-sub">MySQL .sql snapshot</div>
                            </div>
                        </div>

                        <!-- Export Daily Sales (replaces Export Sales) -->
                        <div class="backup-item" onclick="exportDailySales()">
                            <div class="backup-item-icon bi-green"><i class="bi bi-file-earmark-spreadsheet-fill"></i>                            </div>
                            <div>
                                <div class="backup-item-title">Export Daily Sales</div>
                                <div class="backup-item-sub">Excel spreadsheet with full transaction details</div>
                            </div>
                        </div>

                        <!-- Automatic Scheduled Backup (replaces Restore Backup) -->
                        <div class="backup-item" onclick="openSchedBackup()">
                            <div class="backup-item-icon bi-amber"><i class="bi bi-clock-history"></i>                            </div>
                            <div>
                                <div class="backup-item-title">Scheduled Backup</div>
                                <div class="backup-item-sub">Auto backup configuration</div>
                            </div>
                        </div>

                        <!-- Audit Report -->
                        <div class="backup-item" onclick="openAuditModal()">
                            <div class="backup-item-icon bi-red"><i class="bi bi-clipboard2-check-fill"></i>                            </div>
                            <div>
                                <div class="backup-item-title">Audit Report</div>
                                <div class="backup-item-sub">View &amp; send to auditor</div>
                            </div>
                        </div>

                    </div><!-- /backup-grid -->

                    <div class="backup-actions">
                        <button class="backup-btn bb-save" onclick="localBackup()">
                            <i class="bi bi-cloud-arrow-down-fill"></i>
                            Local Backup
                        </button>
                        <button class="backup-btn bb-export" onclick="exportDailySales()">
                            <i class="bi bi-file-earmark-spreadsheet-fill"></i>
                            Export Daily Sales
                        </button>
                        <button class="backup-btn bb-restore" onclick="openAuditModal()">
                            <i class="bi bi-clipboard2-check-fill"></i>
                            Audit Report
                        </button>
                        <button class="backup-btn bb-exit" onclick="exitSystem()">
                            <i class="bi bi-box-arrow-right"></i>
                            Exit System
                        </button>
                    </div>

                </div><!-- /admin-backup-card -->

            </div><!-- /admin-layout -->

            <!-- ══ RECENT RECEIPTS ══ -->
            <div class="admin-backup-card" style="margin-top:20px;">
                <div class="backup-header">
                    <i class="bi bi-receipt backup-header-icon"></i>
                    <span>Recent Receipts</span>
                    <a href="transactions.php" style="margin-left:auto;font-size:.75rem;font-weight:700;color:#6366f1;background:#eef2ff;padding:4px 12px;border-radius:999px;text-decoration:none;">
                        View All
                    </a>
                </div>

                <?php if (empty($recent_receipts)): ?>
                    <div style="padding:32px;text-align:center;color:#94a3b8;font-size:.875rem;">No completed transactions yet.</div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="admin-table" style="min-width:600px;">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Patient</th>
                                <th>Medicines</th>
                                <th>Method</th>
                                <th>Total</th>
                                <th style="text-align:center">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_receipts as $rc): ?>
                            <tr>
                                <td style="font-weight:700;color:#6366f1;font-size:.78rem;">TXN-<?= fmtPad3($rc['InvoiceID']) ?></td>
                                <td style="font-size:.78rem;color:#64748b;"><?= htmlspecialchars($rc['DatePrescribed'] ?? '—') ?></td>
                                <td>
                                    <div style="font-weight:600;font-size:.82rem;color:#1e293b;"><?= htmlspecialchars($rc['PatientName'] ?? '—') ?></div>
                                    <div style="font-size:.70rem;color:#94a3b8;"><?= (int)$rc['PatientAge'] > 0 ? $rc['PatientAge'].' y/o · ' : '' ?><?= htmlspecialchars($rc['PatientGender'] ?? '') ?></div>
                                </td>
                                <td style="font-size:.73rem;color:#64748b;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($rc['Medicines'] ?? '') ?>">
                                    <?= htmlspecialchars($rc['Medicines'] ?? '—') ?>
                                </td>
                                <td>
                                    <span style="font-size:.73rem;font-weight:700;padding:3px 9px;border-radius:999px;
                                        background:<?= ($rc['PaymentMethod'] ?? 'Cash') === 'Cash' ? '#dcfce7' : (($rc['PaymentMethod'] ?? '') === 'GCash' ? '#dbeafe' : '#fef3c7') ?>;
                                        color:<?= ($rc['PaymentMethod'] ?? 'Cash') === 'Cash' ? '#15803d' : (($rc['PaymentMethod'] ?? '') === 'GCash' ? '#1d4ed8' : '#b45309') ?>;">
                                        <?= htmlspecialchars($rc['PaymentMethod'] ?? 'Cash') ?>
                                    </span>
                                </td>
                                <td style="font-weight:800;color:#1e293b;">₱<?= number_format((float)$rc['Total'], 2) ?></td>
                                <td style="text-align:center;">
                                    <button class="ua-btn" title="View Receipt" style="color:#6366f1;"
                                        onclick="viewReceipt(<?= htmlspecialchars(json_encode($rc), ENT_QUOTES) ?>)">
                                        <i class="bi bi-receipt"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div><!-- /recent-receipts -->
        </div><!-- /page-body -->
    </div><!-- /main-area -->
</div><!-- /app-layout -->

<!-- ══ ADD USER MODAL ══ -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Add New User</span>
            <button class="modal-close" onclick="closeModal('addUserModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" action="admin.php" id="addUserForm">
            <input type="hidden" name="action" value="add_user">
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
            <button class="modal-close" onclick="closeModal('editUserModal');rpmCollapseSection()"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" action="admin.php" id="editUserForm">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="modal-grid">
                <div class="modal-field full">
                    <label class="modal-label">Full Name</label>
                    <input type="text" name="full_name" id="editFullName" class="modal-input" required oninput="euClearFieldErr('editFullName','err-fullname')">
                    <span id="err-fullname" style="display:none;font-size:.75rem;color:#dc2626;font-weight:500;margin-top:3px;gap:4px;align-items:center"><i class="bi bi-exclamation-circle-fill" style="font-size:.7rem"></i> <span id="err-fullname-text"></span></span>
                </div>
                <div class="modal-field">
                    <label class="modal-label">Email</label>
                    <input type="email" name="email" id="editEmail" class="modal-input" required oninput="euClearFieldErr('editEmail','err-email')">
                    <span id="err-email" style="display:none;font-size:.75rem;color:#dc2626;font-weight:500;margin-top:3px;gap:4px;align-items:center"><i class="bi bi-exclamation-circle-fill" style="font-size:.7rem"></i> <span id="err-email-text"></span></span>
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

            <!-- ── Change Password Section ── -->
            <div style="margin:0;border-top:1px solid #e2e8f0;padding:14px 24px 0">
                <button type="button" id="rpm-toggle-btn"
                        onclick="rpmToggleSection()"
                        style="display:flex;align-items:center;gap:7px;background:none;border:none;cursor:pointer;font-size:.82rem;font-weight:600;color:#1a56db;padding:0;margin-bottom:0">
                    <i class="bi bi-key-fill"></i>
                    <span id="rpm-toggle-label">Change Password</span>
                    <i class="bi bi-chevron-down" id="rpm-chevron" style="font-size:.7rem;transition:transform .2s"></i>
                </button>
                <div id="rpm-section" style="display:none;margin-top:14px;flex-direction:column;gap:12px">

                    <!-- Current Password -->
                    <div class="modal-field full">
                        <label class="modal-label">Current Password</label>
                        <div style="position:relative;display:flex;align-items:center">
                            <input type="password" id="rpm-current-pw" class="modal-input"
                                   placeholder="Enter current password"
                                   oninput="rpmOnInput()"
                                   autocomplete="current-password"
                                   style="width:100%;padding-right:2.6rem">
                            <button type="button" onclick="rpmToggleEye('rpm-current-pw','rpm-eye-current')"
                                    style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:1rem;line-height:1;display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-eye-slash-fill" id="rpm-eye-current"></i>
                            </button>
                        </div>
                        <span id="rpm-current-error" style="display:none;font-size:.75rem;color:#dc2626;font-weight:500;margin-top:3px;gap:4px;align-items:center"><i class="bi bi-exclamation-circle-fill" style="font-size:.7rem"></i> <span id="rpm-current-error-text"></span></span>
                    </div>

                    <!-- New Password -->
                    <div class="modal-field full">
                        <label class="modal-label">New Password</label>
                        <div style="position:relative;display:flex;align-items:center">
                            <input type="password" id="rpm-new-pw" class="modal-input"
                                   placeholder="Enter new password"
                                   oninput="rpmOnInput()" autocomplete="new-password"
                                   style="width:100%;padding-right:2.6rem">
                            <button type="button" onclick="rpmToggleEye('rpm-new-pw','rpm-eye-new')"
                                    style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:1rem;line-height:1;display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-eye-slash-fill" id="rpm-eye-new"></i>
                            </button>
                        </div>
                        <!-- Strength bar -->
                        <div style="height:5px;border-radius:99px;background:#eee;overflow:hidden;margin-top:4px">
                            <div id="rpm-strength-bar" style="height:100%;width:0;border-radius:99px;transition:width .3s,background .3s"></div>
                        </div>
                        <span id="rpm-strength-label" style="font-size:.73rem;font-weight:600;display:block;margin-top:2px"></span>
                        <!-- Requirements checklist -->
                        <ul id="rpm-requirements" style="margin:6px 0 0 2px;padding:0;list-style:none;display:flex;flex-direction:column;gap:3px">
                            <li id="req-length"  style="font-size:.75rem;color:#94a3b8;display:flex;align-items:center;gap:5px"><i class="bi bi-circle" style="font-size:.6rem"></i> At least 8 characters</li>
                            <li id="req-upper"   style="font-size:.75rem;color:#94a3b8;display:flex;align-items:center;gap:5px"><i class="bi bi-circle" style="font-size:.6rem"></i> At least one uppercase letter (A–Z)</li>
                            <li id="req-lower"   style="font-size:.75rem;color:#94a3b8;display:flex;align-items:center;gap:5px"><i class="bi bi-circle" style="font-size:.6rem"></i> At least one lowercase letter (a–z)</li>
                            <li id="req-number"  style="font-size:.75rem;color:#94a3b8;display:flex;align-items:center;gap:5px"><i class="bi bi-circle" style="font-size:.6rem"></i> At least one number (0–9)</li>
                            <li id="req-special" style="font-size:.75rem;color:#94a3b8;display:flex;align-items:center;gap:5px"><i class="bi bi-circle" style="font-size:.6rem"></i> At least one special character (!@#$…)</li>
                        </ul>
                        <span id="rpm-new-error" style="display:none;font-size:.75rem;color:#dc2626;font-weight:500;margin-top:3px;gap:4px;align-items:center"><i class="bi bi-exclamation-circle-fill" style="font-size:.7rem"></i> <span id="rpm-new-error-text"></span></span>
                    </div>

                    <!-- Confirm Password -->
                    <div class="modal-field full">
                        <label class="modal-label">Confirm New Password</label>
                        <div style="position:relative;display:flex;align-items:center">
                            <input type="password" id="rpm-confirm-pw" class="modal-input"
                                   placeholder="Re-enter new password"
                                   oninput="rpmOnInput()" autocomplete="new-password"
                                   style="width:100%;padding-right:2.6rem">
                            <button type="button" onclick="rpmToggleEye('rpm-confirm-pw','rpm-eye-confirm')"
                                    style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:1rem;line-height:1;display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-eye-slash-fill" id="rpm-eye-confirm"></i>
                            </button>
                        </div>
                        <span id="rpm-match-label" style="font-size:.73rem;font-weight:600;display:block;margin-top:2px"></span>
                        <span id="rpm-confirm-error" style="display:none;font-size:.75rem;color:#dc2626;font-weight:500;margin-top:3px;gap:4px;align-items:center"><i class="bi bi-exclamation-circle-fill" style="font-size:.7rem"></i> <span id="rpm-confirm-error-text"></span></span>
                    </div>

                    <div id="rpm-error" style="display:none;background:#fff0f0;border:1px solid #fca5a5;border-radius:8px;padding:.6rem .85rem;font-size:.82rem;color:#b91c1c;font-weight:500;display:flex;align-items:center;gap:6px">
                        <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;font-size:.85rem"></i>
                        <span id="rpm-error-text"></span>
                    </div>

                    <!-- Change Password Button -->
                    <button type="button" id="rpm-save-btn" onclick="rpmSubmit()"
                            style="display:flex;align-items:center;justify-content:center;gap:7px;padding:.68rem 1.2rem;border-radius:10px;border:none;background:#1a56db;color:#fff;font-size:.875rem;font-weight:600;cursor:not-allowed;opacity:.6;width:100%"
                            disabled>
                        <i class="bi bi-key-fill"></i>
                        <span id="rpm-btn-text">Change Password</span>
                        <span id="rpm-btn-spinner" style="display:none;width:13px;height:13px;border-radius:50%;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;animation:rpm-spin .6s linear infinite"></span>
                    </button>
                </div>
            </div>
            <style>@keyframes rpm-spin{to{transform:rotate(360deg)}}</style>

            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeModal('editUserModal');rpmCollapseSection()">Cancel</button>
                <button type="button" class="modal-btn-save" id="editSaveBtn" onclick="editUserSave()">Save Changes</button>
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
                <h2>PharmaCare — Pharmacy Audit Report</h2>
                <p>Generated: <?= date('F d, Y \a\t h:i A') ?> &nbsp;|&nbsp; Period: <span id="auditPeriodLabel"><?= date('F 1, Y', strtotime($audit_date_from)) ?> – <?= date('F d, Y', strtotime($audit_date_to)) ?></span></p>
            </div>
            <button class="audit-modal-close" onclick="closeAuditModal()"><i class="bi bi-x-lg"></i></button>
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
                    <i class="bi bi-info-circle-fill" style="color:#6366f1"></i> Summary data sourced from <strong>CALL GenerateSalesReport('<?= $audit_date_from ?>', '<?= $audit_date_to ?>')</strong>
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
                            <tr><td colspan="9" class="audit-empty">No expiring medications found. <i class="bi bi-check-circle-fill" style="color:#16a34a"></i></td></tr>
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
                    <i class="bi bi-printer-fill"></i>
                    Print Report
                </button>
                <button class="audit-btn-send" onclick="openSendConfirm()">
                    <i class="bi bi-send-fill"></i>
                    Send to Auditor
                </button>
            </div>
        </div>

    </div><!-- /audit-modal-box -->
</div><!-- /auditModal -->

<!-- ══ SEND TO AUDITOR CONFIRMATION ══ -->
<div class="send-confirm-overlay" id="sendConfirmModal">
    <div class="send-confirm-box">
        <h3><i class="bi bi-envelope-fill"></i> Send Audit Report</h3>
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
        <h3><i class="bi bi-alarm-fill"></i> Automatic Scheduled Backup</h3>
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

<!-- ══ RECEIPT VIEW MODAL ══ -->
<div id="receiptViewOverlay" class="cashier-overlay" onclick="if(event.target===this)closeReceiptView()">
    <div class="receipt-box" id="receiptViewBox">
        <div class="receipt-header">
            <div class="receipt-brand">PharmaCare <span style="font-size:.6em;vertical-align:super;opacity:.7;">♡</span></div>
            <div class="receipt-sub">Official Pharmacy Receipt</div>
            <div class="receipt-date" id="rv-date"></div>
        </div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-row"><span>Invoice</span><span id="rv-inv"></span></div>
        <div class="receipt-row"><span>Patient</span><span id="rv-patient"></span></div>
        <div class="receipt-row"><span>Doctor</span><span id="rv-doctor"></span></div>
        <div class="receipt-row"><span>Pharmacist</span><span id="rv-pharmacist"></span></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-meds" id="rv-meds"></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-row"><span>Subtotal</span><span id="rv-subtotal"></span></div>
        <div class="receipt-row" id="rv-discount-row" style="color:#d97706;display:none"><span>Senior Discount</span><span id="rv-discount"></span></div>
        <div class="receipt-row receipt-total"><span>TOTAL</span><span id="rv-total"></span></div>
        <div class="receipt-row"><span>Payment Method</span><span id="rv-method"></span></div>
        <div class="receipt-row"><span>Amount Tendered</span><span id="rv-tendered"></span></div>
        <div class="receipt-row"><span>Change</span><span id="rv-change"></span></div>
        <div class="receipt-divider">- - - - - - - - - - - - - - - - - - - - -</div>
        <div class="receipt-footer">Thank you for choosing PharmaCare!<br><span style="font-size:.7rem;color:#94a3b8;">Please keep this receipt for your records.</span></div>
        <div class="receipt-actions no-print">
            <button class="cashier-btn-cancel" onclick="closeReceiptView()">Close</button>
            <button class="cashier-btn-confirm" onclick="printReceiptView()"><i class="bi bi-printer-fill"></i> Print Receipt</button>
        </div>
    </div>
</div>

<div class="toast-tray" id="toastTray"></div>

<script>
'use strict';

/* ══ PAGINATION HELPER ══ */
function initPagination(tbodyId, pgContainerId, perPage = 5) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    const pgContainer = document.getElementById(pgContainerId);
    let currentPage = 1;

    // Each row carries a data-filtered="true" flag when hidden by search/filter
    function render() {
        const allRows = Array.from(tbody.querySelectorAll('tr[data-status], tr[data-id], tr[data-inv], tr:not(.no-paginate)'));
        // Rows not filtered out by search
        const filteredRows = allRows.filter(r => !r._searchHidden);
        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * perPage;
        // Hide all, then show only current page slice of filtered rows
        allRows.forEach(r => { r.style.display = 'none'; });
        filteredRows.slice(start, start + perPage).forEach(r => { r.style.display = ''; });
        renderControls(totalPages, total);
    }

    function renderControls(totalPages, total) {
        if (!pgContainer) return;
        pgContainer.innerHTML = '';
        if (total === 0) return;
        // Left: "Page X of Y"
        const info = document.createElement('span');
        info.className = 'pg-info';
        info.style.cssText = 'margin-right:auto;margin-left:0;font-size:.8rem;color:#64748b;font-weight:600;';
        info.textContent = `Page ${currentPage} of ${totalPages}`;
        pgContainer.appendChild(info);
        // Right: prev arrow
        const prev = document.createElement('button');
        prev.className = 'pg-btn'; prev.innerHTML = '<i class="bi bi-chevron-left"></i>';
        prev.disabled = currentPage === 1;
        prev.onclick = () => { if (currentPage > 1) { currentPage--; render(); } };
        pgContainer.appendChild(prev);
        // Right: next arrow
        const next = document.createElement('button');
        next.className = 'pg-btn'; next.innerHTML = '<i class="bi bi-chevron-right"></i>';
        next.disabled = currentPage === totalPages;
        next.onclick = () => { if (currentPage < totalPages) { currentPage++; render(); } };
        pgContainer.appendChild(next);
    }

    // Expose reset for search/filter hooks
    window['_pgReset_' + tbodyId] = () => { currentPage = 1; render(); };
    render();
    return { reset: () => { currentPage = 1; render(); } };
}

let pgUsers;
document.addEventListener('DOMContentLoaded', function() {
    pgUsers = initPagination('userBody', 'pg-users', 5);
});

/* ── Live search ── */
const userSearch = document.getElementById('userSearch');
const userRows   = document.querySelectorAll('#userBody tr[data-id]');
userSearch.addEventListener('input', () => {
    const q = userSearch.value.toLowerCase().trim();
    userRows.forEach(row => {
        const name  = (row.dataset.name  || '').toLowerCase();
        const email = (row.dataset.email || '').toLowerCase();
        row._searchHidden = !(!q || name.includes(q) || email.includes(q));
    });
    if (window['_pgReset_userBody']) window['_pgReset_userBody']();
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

    // Reset the password section to collapsed/clean state
    rpmTargetId = parseInt(row.dataset.id);
    rpmCollapseSection();

    openModal('editUserModal');
}

/* ── Add User & Edit User: native form submit (action files handle redirect) ── */

/* Shared field-error helpers used by both Save Changes and Change Password */
function euMarkErr(inputId, errSpanId, errTextId, msg) {
    const inp = document.getElementById(inputId);
    const spn = document.getElementById(errSpanId);
    const txt = document.getElementById(errTextId);
    if (inp) { inp.style.borderColor = '#f87171'; inp.style.boxShadow = '0 0 0 3px rgba(248,113,113,.15)'; }
    if (txt) txt.textContent = msg;
    if (spn) spn.style.display = 'flex';
}
function euClearFieldErr(inputId, errSpanId) {
    const inp = document.getElementById(inputId);
    const spn = document.getElementById(errSpanId);
    if (inp) { inp.style.borderColor = ''; inp.style.boxShadow = ''; }
    if (spn) spn.style.display = 'none';
}

/*
 * validateDialog()
 * Always checks Full Name + Email.
 * When the Change Password section is open, also checks all 3 password fields.
 * Highlights every failing field and returns false if anything is invalid.
 */
function validateDialog() {
    let ok = true;

    /* Full Name */
    const name = (document.getElementById('editFullName').value || '').trim();
    if (!name) {
        euMarkErr('editFullName', 'err-fullname', 'err-fullname-text', 'Full name is required.');
        ok = false;
    } else {
        euClearFieldErr('editFullName', 'err-fullname');
    }

    /* Email */
    const email = (document.getElementById('editEmail').value || '').trim();
    if (!email) {
        euMarkErr('editEmail', 'err-email', 'err-email-text', 'Email is required.');
        ok = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        euMarkErr('editEmail', 'err-email', 'err-email-text', 'Enter a valid email address.');
        ok = false;
    } else {
        euClearFieldErr('editEmail', 'err-email');
    }

    /* Password fields — only when the section is expanded */
    if (rpmSectionOpen) {
        const curpw   = (document.getElementById('rpm-current-pw').value  || '').trim();
        const pw      = (document.getElementById('rpm-new-pw').value      || '').trim();
        const confirm = (document.getElementById('rpm-confirm-pw').value  || '').trim();

        /* Current password */
        if (!curpw) {
            euMarkErr('rpm-current-pw', 'rpm-current-error', 'rpm-current-error-text', 'Current password is required.');
            ok = false;
        } else {
            euClearFieldErr('rpm-current-pw', 'rpm-current-error');
        }

        /* New password — all 5 requirements */
        if (!pw) {
            euMarkErr('rpm-new-pw', 'rpm-new-error', 'rpm-new-error-text', 'New password is required.');
            ok = false;
        } else {
            const miss = [];
            if (pw.length < 8)            miss.push('8+ characters');
            if (!/[A-Z]/.test(pw))        miss.push('uppercase letter');
            if (!/[a-z]/.test(pw))        miss.push('lowercase letter');
            if (!/[0-9]/.test(pw))        miss.push('number');
            if (!/[^A-Za-z0-9]/.test(pw)) miss.push('special character');
            if (miss.length) {
                euMarkErr('rpm-new-pw', 'rpm-new-error', 'rpm-new-error-text', 'Missing: ' + miss.join(', ') + '.');
                ok = false;
            } else {
                euClearFieldErr('rpm-new-pw', 'rpm-new-error');
            }
        }

        /* Confirm password */
        const matchEl = document.getElementById('rpm-match-label');
        if (!confirm) {
            euMarkErr('rpm-confirm-pw', 'rpm-confirm-error', 'rpm-confirm-error-text', 'Please confirm your new password.');
            if (matchEl) matchEl.textContent = '';
            ok = false;
        } else if (pw !== confirm) {
            euMarkErr('rpm-confirm-pw', 'rpm-confirm-error', 'rpm-confirm-error-text', 'Passwords do not match.');
            if (matchEl) { matchEl.textContent = '✗ Passwords do not match'; matchEl.style.color = '#dc2626'; }
            ok = false;
        } else {
            euClearFieldErr('rpm-confirm-pw', 'rpm-confirm-error');
            if (matchEl) { matchEl.textContent = '✓ Passwords match'; matchEl.style.color = '#16a34a'; }
        }
    }

    return ok;
}

/* Save Changes — blocked until the whole dialog is valid */
function editUserSave() {
    if (!validateDialog()) return;
    document.getElementById('editUserForm').submit();
}

function deleteFromRow(btn) {
    const row  = btn.closest('tr');
    const id   = row.dataset.id;
    const name = row.dataset.fullname;
    pcConfirm({
        title: 'Delete User',
        body: `You are about to permanently delete <strong>${name}</strong>.<br>This action cannot be undone.`,
        okText: 'Delete',
        type: 'danger',
        icon: 'bi-person-x-fill',
        onOk: () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin.php';
        const actInp = document.createElement('input');
        actInp.type = 'hidden'; actInp.name = 'action'; actInp.value = 'delete_user';
        form.appendChild(actInp);
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'user_id'; inp.value = id;
        form.appendChild(inp);
        document.body.appendChild(form);
            form.submit();
        }
    });
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) {
            overlay.classList.remove('active');
            if (overlay.id === 'editUserModal') rpmCollapseSection();
        }
    });
});

/* ── Reset Password (inside Edit User modal) ── */
let rpmTargetId = null;
let rpmSectionOpen = false;

function rpmCollapseSection() {
    rpmSectionOpen = false;
    const sec     = document.getElementById('rpm-section');
    const chevron = document.getElementById('rpm-chevron');
    const label   = document.getElementById('rpm-toggle-label');
    if (sec)     sec.style.display = 'none';
    if (chevron) chevron.style.transform = 'rotate(0deg)';
    if (label)   label.textContent = 'Change Password';

    // Clear password inputs + remove any red borders
    ['rpm-current-pw','rpm-new-pw','rpm-confirm-pw'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.value = ''; el.type = 'password'; el.style.borderColor = ''; el.style.boxShadow = ''; }
    });
    // Reset eye icons
    ['rpm-eye-current','rpm-eye-new','rpm-eye-confirm'].forEach(id => {
        const ic = document.getElementById(id);
        if (ic) ic.className = 'bi bi-eye-slash-fill';
    });
    // Hide all inline error messages
    ['rpm-error','rpm-current-error','rpm-new-error','rpm-confirm-error'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    // Clear top-field errors too
    euClearFieldErr('editFullName', 'err-fullname');
    euClearFieldErr('editEmail',    'err-email');
    // Strength bar + labels
    const bar = document.getElementById('rpm-strength-bar');
    const lbl = document.getElementById('rpm-strength-label');
    const mlb = document.getElementById('rpm-match-label');
    if (bar) bar.style.width = '0';
    if (lbl) lbl.textContent = '';
    if (mlb) mlb.textContent = '';
    // Reset requirement bullets
    ['req-length','req-upper','req-lower','req-number','req-special'].forEach(id => {
        const li = document.getElementById(id);
        if (li) { li.style.color = '#94a3b8'; li.querySelector('i').className = 'bi bi-circle'; li.querySelector('i').style.fontSize = '.6rem'; }
    });
    // Reset Change Password button
    const sbt     = document.getElementById('rpm-save-btn');
    const btnText = document.getElementById('rpm-btn-text');
    const spinner = document.getElementById('rpm-btn-spinner');
    if (sbt)     { sbt.disabled = true; sbt.style.opacity = '.6'; sbt.style.cursor = 'not-allowed'; sbt.style.background = ''; }
    if (btnText) btnText.textContent = 'Change Password';
    if (spinner) spinner.style.display = 'none';
}

function rpmToggleSection() {
    rpmSectionOpen = !rpmSectionOpen;
    const sec     = document.getElementById('rpm-section');
    const chevron = document.getElementById('rpm-chevron');
    const label   = document.getElementById('rpm-toggle-label');
    if (rpmSectionOpen) {
        sec.style.display = 'flex';
        chevron.style.transform = 'rotate(180deg)';
        label.textContent = 'Cancel';
        setTimeout(() => document.getElementById('rpm-current-pw').focus(), 60);
    } else {
        rpmCollapseSection();
    }
}

function rpmToggleEye(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
        inp.type = 'text';
        if (icon) { icon.className = 'bi bi-eye-fill'; }
    } else {
        inp.type = 'password';
        if (icon) { icon.className = 'bi bi-eye-slash-fill'; }
    }
}
function rpmCheckStrength(pw) {
    let score = 0;
    if (pw.length >= 8)           score++;
    if (pw.length >= 12)          score++;
    if (/[A-Z]/.test(pw))         score++;
    if (/[0-9]/.test(pw))         score++;
    if (/[^A-Za-z0-9]/.test(pw))  score++;
    const levels = [
        { label: '',            color: '',        pct: '0%'   },
        { label: 'Weak',        color: '#ef4444', pct: '25%'  },
        { label: 'Fair',        color: '#f97316', pct: '50%'  },
        { label: 'Good',        color: '#eab308', pct: '75%'  },
        { label: 'Strong',      color: '#22c55e', pct: '100%' },
        { label: 'Very Strong', color: '#16a34a', pct: '100%' },
    ];
    const lvl = levels[Math.min(score, levels.length - 1)];
    const bar = document.getElementById('rpm-strength-bar');
    const lbl = document.getElementById('rpm-strength-label');
    bar.style.width = lvl.pct; bar.style.background = lvl.color;
    lbl.textContent = pw.length > 0 ? lvl.label : ''; lbl.style.color = lvl.color;
    return score;
}

function rpmSetReq(id, met) {
    const li = document.getElementById(id);
    if (!li) return;
    const ic = li.querySelector('i');
    if (met) {
        li.style.color = '#16a34a';
        ic.className = 'bi bi-check-circle-fill';
        ic.style.fontSize = '.72rem';
    } else {
        li.style.color = '#94a3b8';
        ic.className = 'bi bi-circle';
        ic.style.fontSize = '.6rem';
    }
}

function rpmOnInput() {
    const curpw   = document.getElementById('rpm-current-pw').value;
    const pw      = document.getElementById('rpm-new-pw').value;
    const confirm = document.getElementById('rpm-confirm-pw').value;
    const matchEl = document.getElementById('rpm-match-label');
    const btn     = document.getElementById('rpm-save-btn');
    rpmCheckStrength(pw);

    // Clear per-field errors while the user is actively typing
    if (curpw.trim()) euClearFieldErr('rpm-current-pw', 'rpm-current-error');
    if (pw.trim())    euClearFieldErr('rpm-new-pw',      'rpm-new-error');

    // Update requirement bullets
    const hasLength  = pw.length >= 8;
    const hasUpper   = /[A-Z]/.test(pw);
    const hasLower   = /[a-z]/.test(pw);
    const hasNumber  = /[0-9]/.test(pw);
    const hasSpecial = /[^A-Za-z0-9]/.test(pw);
    rpmSetReq('req-length',  hasLength);
    rpmSetReq('req-upper',   hasUpper);
    rpmSetReq('req-lower',   hasLower);
    rpmSetReq('req-number',  hasNumber);
    rpmSetReq('req-special', hasSpecial);

    // Live confirm-match feedback
    const curValid = curpw.trim().length > 0;
    const pwValid  = hasLength && hasUpper && hasLower && hasNumber && hasSpecial;
    let confirmValid = false;
    if (confirm.length > 0) {
        if (pw === confirm) {
            matchEl.textContent = '✓ Passwords match'; matchEl.style.color = '#16a34a';
            euClearFieldErr('rpm-confirm-pw', 'rpm-confirm-error');
            confirmValid = true;
        } else {
            matchEl.textContent = '✗ Passwords do not match'; matchEl.style.color = '#dc2626';
        }
    } else {
        matchEl.textContent = '';
        euClearFieldErr('rpm-confirm-pw', 'rpm-confirm-error');
    }

    // Change Password button enabled only when all three password fields are fully valid
    const ok = curValid && pwValid && confirmValid;
    btn.disabled = !ok; btn.style.opacity = ok ? '1' : '.6'; btn.style.cursor = ok ? 'pointer' : 'not-allowed';
}

async function rpmSubmit() {
    // Block if ANYTHING in the dialog is invalid — top fields included
    if (!validateDialog()) return;

    const curpw   = document.getElementById('rpm-current-pw').value.trim();
    const pw      = document.getElementById('rpm-new-pw').value.trim();
    const errEl   = document.getElementById('rpm-error');
    const errTxt  = document.getElementById('rpm-error-text');
    const btn     = document.getElementById('rpm-save-btn');
    const btnText = document.getElementById('rpm-btn-text');
    const spinner = document.getElementById('rpm-btn-spinner');

    function showErr(msg) {
        errTxt.textContent = msg; errEl.style.display = 'flex';
        btn.disabled = false; btn.style.opacity = '1';
        btnText.textContent = 'Change Password'; spinner.style.display = 'none';
    }
    errEl.style.display = 'none';

    btn.disabled = true; btn.style.opacity = '.7';
    btnText.textContent = 'Saving…'; spinner.style.display = 'inline-block';

    try {
        const res = await fetch('admin.php?action=reset_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: rpmTargetId, current_password: curpw, new_password: pw }),
        });
        const data = await res.json();
        if (data.success) {
            btn.style.background = '#16a34a'; btn.style.opacity = '1';
            btnText.textContent = '✓ Password Changed!'; spinner.style.display = 'none';
            setTimeout(() => {
                closeModal('editUserModal');
                rpmCollapseSection();
                showToast('Password changed successfully.', 'ok');
                btn.style.background = '';
            }, 1300);
        } else {
            showErr(data.message || 'An error occurred. Please try again.');
            // Surface server-side credential error on the current-password field too
            if ((data.message || '').toLowerCase().match(/incorrect|current/)) {
                euMarkErr('rpm-current-pw', 'rpm-current-error', 'rpm-current-error-text', data.message);
            }
        }
    } catch (e) {
        showErr('Network error. Please check your connection and try again.');
    }
}

/* ── Backup & Report Actions ── */
function localBackup() {
    showToast('Preparing local MySQL backup…', 'warn');
    setTimeout(() => {
        window.location.href = 'admin.php?action=local_backup';
    }, 800);
}

function exportDailySales() {
    showToast('Generating Daily Sales Excel file…', 'warn');
    setTimeout(() => {
        window.location.href = 'admin.php?action=export_csv';
    }, 800);
}

function exitSystem() {
    pcConfirm({
        title: 'Exit System',
        body: 'You will be logged out and redirected to the login page.',
        okText: 'Exit & Log Out',
        type: 'warning',
        icon: 'bi-power',
        onOk: () => window.location.href = '../logout.php'
    });
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
    showToast(`Audit report sent to ${email}`, 'ok');
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
    showToast(`Scheduled ${freq} backup at ${time} saved.`, 'ok');
}

/* ── Toast ── */
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
    const ic       = iconMap[type] || iconMap.warning;
    const usedIcon = icon || ic.bi;
    let overlay    = document.getElementById('pcConfirmOverlay');
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
    document.getElementById('pcConfirmIcon').className  = `pc-confirm-icon ${ic.cls}`;
    document.getElementById('pcConfirmIconI').className = `bi ${usedIcon}`;
    document.getElementById('pcConfirmTitle').textContent = title;
    document.getElementById('pcConfirmBody').innerHTML    = body;
    const okBtn   = document.getElementById('pcConfirmOk');
    okBtn.textContent = okText;
    okBtn.className   = `pc-confirm-ok ${type}`;
    okBtn.onclick     = () => { closeConfirm(); if (onOk) onOk(); };
    requestAnimationFrame(() => overlay.classList.add('show'));
}
/* ── Recent Receipts ── */
function viewReceipt(rc) {
    const fmt  = n => '₱' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const pad  = n => 'TXN-' + String(n).padStart(3, '0');

    document.getElementById('rv-date').textContent       = rc.DatePrescribed || '—';
    document.getElementById('rv-inv').textContent        = pad(rc.InvoiceID);
    document.getElementById('rv-patient').textContent    = rc.PatientName || '—';
    document.getElementById('rv-doctor').textContent     = rc.DoctorName || '—';
    document.getElementById('rv-pharmacist').textContent = rc.PharmacistName || '—';
    document.getElementById('rv-meds').textContent       = rc.Medicines || '—';
    document.getElementById('rv-subtotal').textContent   = fmt(rc.Subtotal);
    document.getElementById('rv-total').textContent      = fmt(rc.Total);
    document.getElementById('rv-method').textContent     = rc.PaymentMethod || 'Cash';

    const discount = parseFloat(rc.Discount || 0);
    const discRow  = document.getElementById('rv-discount-row');
    if (discount > 0) {
        discRow.style.display = '';
        document.getElementById('rv-discount').textContent = '−' + fmt(discount);
    } else {
        discRow.style.display = 'none';
    }

    const tendered = parseFloat(rc.AmountTendered || rc.Total || 0);
    const change   = Math.max(0, tendered - parseFloat(rc.Total || 0));
    document.getElementById('rv-tendered').textContent = fmt(tendered);
    document.getElementById('rv-change').textContent   = fmt(change);

    document.getElementById('receiptViewOverlay').classList.add('show');
}

function closeReceiptView() {
    document.getElementById('receiptViewOverlay').classList.remove('show');
}

function printReceiptView() {
    const box    = document.getElementById('receiptViewBox');
    const orig   = document.body.innerHTML;
    document.body.innerHTML = box.outerHTML;
    window.print();
    document.body.innerHTML = orig;
    location.reload();
}

function closeConfirm() {
    const ov = document.getElementById('pcConfirmOverlay');
    if (ov) ov.classList.remove('show');
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

// Auto-dismiss flash banner after 4 seconds
document.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('adminFlash');
    if (flash) setTimeout(() => { flash.style.transition='opacity .4s'; flash.style.opacity='0'; setTimeout(()=>flash.remove(),400); }, 4000);
});
</script>

</body>
</html>