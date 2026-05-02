<?php
require_once "config.php";
requireLogin();

if (!canAccess('barbers_payments')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function isBarberPaymentNumericValue($value)
{
    return is_string($value) && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1;
}

function formatBarberPaymentAmount($value)
{
    return number_format((float) $value, 2, '.', '');
}

function getBarberPaymentMonthLabel($monthStart)
{
    $timestamp = strtotime($monthStart);

    if ($timestamp === false) {
        return date('m/Y');
    }

    return date('m/Y', $timestamp);
}

function getBarberPaymentSummary($conn, $barberId, $monthStart, $nextMonthStart)
{
    $attendanceCountStmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN check_in_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS attendance_count,
            COALESCE(SUM(CASE WHEN check_out_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS departure_count,
            COALESCE(SUM(CASE WHEN day_status = 'غياب' OR attendance_status = 'غياب' OR departure_status = 'غياب' THEN 1 ELSE 0 END), 0) AS absence_count
         FROM barbers_attendance
         WHERE barber_id = ? AND record_date >= ? AND record_date < ?"
    );
    $attendanceCountStmt->execute([$barberId, $monthStart, $nextMonthStart]);
    $attendanceCounts = $attendanceCountStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'attendance_count' => 0,
        'departure_count' => 0,
        'absence_count' => 0
    ];

    $attendanceRowsStmt = $conn->prepare(
        "SELECT record_date, attendance_status, departure_status, day_status, scheduled_attendance_time, scheduled_departure_time, check_in_at, check_out_at
         FROM barbers_attendance
         WHERE barber_id = ? AND record_date >= ? AND record_date < ?
         ORDER BY record_date DESC, id DESC"
    );
    $attendanceRowsStmt->execute([$barberId, $monthStart, $nextMonthStart]);
    $attendanceRows = $attendanceRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $loansStmt = $conn->prepare(
        "SELECT amount, created_at
         FROM barbers_loans
         WHERE barber_id = ? AND created_at >= ? AND created_at < ?
         ORDER BY id DESC"
    );
    $loansStmt->execute([$barberId, $monthStart, $nextMonthStart]);
    $loanRows = $loansStmt->fetchAll(PDO::FETCH_ASSOC);
    $loanTotal = 0.0;

    foreach ($loanRows as $loanRow) {
        $loanTotal += (float) ($loanRow['amount'] ?? 0);
    }

    $deductionsStmt = $conn->prepare(
        "SELECT amount, reason, created_at
         FROM barbers_deductions
         WHERE barber_id = ? AND created_at >= ? AND created_at < ?
         ORDER BY id DESC"
    );
    $deductionsStmt->execute([$barberId, $monthStart, $nextMonthStart]);
    $deductionRows = $deductionsStmt->fetchAll(PDO::FETCH_ASSOC);
    $deductionTotal = 0.0;

    foreach ($deductionRows as $deductionRow) {
        $deductionTotal += (float) ($deductionRow['amount'] ?? 0);
    }

    $invoicesStmt = $conn->prepare(
        "SELECT id, total_amount, barber_share_amount, barber_commission_percent, created_at
         FROM salon_invoices
         WHERE barber_id = ? AND created_at >= ? AND created_at < ?
         ORDER BY id DESC"
    );
    $invoicesStmt->execute([$barberId, $monthStart, $nextMonthStart]);
    $invoiceRows = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
    $commissionTotal = 0.0;
    $salesTotal = 0.0;

    foreach ($invoiceRows as $invoiceRow) {
        $commissionTotal += (float) ($invoiceRow['barber_share_amount'] ?? 0);
        $salesTotal += (float) ($invoiceRow['total_amount'] ?? 0);
    }

    return [
        'attendance_count' => (int) ($attendanceCounts['attendance_count'] ?? 0),
        'departure_count' => (int) ($attendanceCounts['departure_count'] ?? 0),
        'absence_count' => (int) ($attendanceCounts['absence_count'] ?? 0),
        'attendance_rows' => $attendanceRows,
        'loan_rows' => $loanRows,
        'loan_total' => $loanTotal,
        'deduction_rows' => $deductionRows,
        'deduction_total' => $deductionTotal,
        'invoice_rows' => $invoiceRows,
        'commission_total' => $commissionTotal,
        'sales_total' => $salesTotal,
        'net_total' => $commissionTotal - $loanTotal - $deductionTotal
    ];
}

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS barbers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            barber_name VARCHAR(255) NOT NULL,
            barber_number VARCHAR(100) NOT NULL,
            barber_barcode VARCHAR(100) NOT NULL DEFAULT '',
            attendance_time VARCHAR(10) NOT NULL,
            departure_time VARCHAR(10) NOT NULL,
            off_days TEXT NOT NULL,
            commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS barbers_attendance (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            barber_id INT UNSIGNED NOT NULL,
            record_date DATE NOT NULL,
            scheduled_attendance_time VARCHAR(10) NOT NULL DEFAULT '',
            scheduled_departure_time VARCHAR(10) NOT NULL DEFAULT '',
            check_in_at DATETIME NULL,
            check_out_at DATETIME NULL,
            attendance_status VARCHAR(50) NOT NULL DEFAULT '',
            departure_status VARCHAR(50) NOT NULL DEFAULT '',
            day_status VARCHAR(50) NOT NULL DEFAULT '',
            is_off_day TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_barber_attendance_day (barber_id, record_date),
            KEY idx_barber_attendance_date (record_date),
            KEY idx_barber_attendance_status (day_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS barbers_loans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            barber_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_barbers_loans_barber_id (barber_id),
            CONSTRAINT fk_barbers_loans_barber_id FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS barbers_deductions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            barber_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_barbers_deductions_barber_id (barber_id),
            CONSTRAINT fk_barbers_deductions_barber_id FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS salon_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            employee_name VARCHAR(255) NOT NULL,
            barber_id INT UNSIGNED NOT NULL,
            barber_name VARCHAR(255) NOT NULL,
            barber_commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            barber_share_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            salon_share_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_salon_invoices_created_at (created_at),
            INDEX idx_salon_invoices_employee_id (employee_id),
            INDEX idx_salon_invoices_barber_id (barber_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS barbers_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            barber_id INT UNSIGNED NOT NULL,
            salary_month DATE NOT NULL,
            payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_loans DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_commission DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            attendance_count INT UNSIGNED NOT NULL DEFAULT 0,
            departure_count INT UNSIGNED NOT NULL DEFAULT 0,
            absence_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_barber_payment_month (barber_id, salary_month),
            KEY idx_barbers_payments_month (salary_month),
            CONSTRAINT fk_barbers_payments_barber_id FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة قبض الحلاقين");
}

$settings = getSiteSettings($conn);
$currentMonthStart = date('Y-m-01');
$nextMonthStart = (new DateTimeImmutable($currentMonthStart))->modify('first day of next month')->format('Y-m-d');
$currentMonthLabel = getBarberPaymentMonthLabel($currentMonthStart);
$errorMessage = '';
$successMessage = trim((string) ($_GET['success'] ?? ''));
$selectedBarberId = ctype_digit((string) ($_GET['barber_id'] ?? '')) ? (int) $_GET['barber_id'] : 0;
$paymentAmountValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    $selectedBarberId = ctype_digit((string) ($_POST['barber_id'] ?? '')) ? (int) $_POST['barber_id'] : 0;
    $paymentAmountValue = trim((string) ($_POST['payment_amount'] ?? ''));

    if ($selectedBarberId <= 0) {
        $errorMessage = 'اختر الحلاق أولاً';
    } elseif ($paymentAmountValue === '') {
        $errorMessage = 'اكتب مبلغ قبض الحلاق';
    } elseif (getTextLength($paymentAmountValue) > 20 || !isBarberPaymentNumericValue($paymentAmountValue)) {
        $errorMessage = 'مبلغ القبض يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } else {
        $paymentAmount = (float) $paymentAmountValue;

        if ($paymentAmount <= 0) {
            $errorMessage = 'مبلغ القبض يجب أن يكون أكبر من صفر';
        } else {
            try {
                $conn->beginTransaction();

                $barberStmt = $conn->prepare("SELECT id, barber_name FROM barbers WHERE id = ? LIMIT 1");
                $barberStmt->execute([$selectedBarberId]);
                $barber = $barberStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($barber === null) {
                    throw new RuntimeException('الحلاق غير موجود');
                }

                $existingPaymentStmt = $conn->prepare(
                    "SELECT id
                     FROM barbers_payments
                     WHERE barber_id = ? AND salary_month = ?
                     LIMIT 1
                     FOR UPDATE"
                );
                $existingPaymentStmt->execute([$selectedBarberId, $currentMonthStart]);

                if ($existingPaymentStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('تم قبض هذا الحلاق خلال الشهر الحالي بالفعل');
                }

                $summary = getBarberPaymentSummary($conn, $selectedBarberId, $currentMonthStart, $nextMonthStart);
                $insertStmt = $conn->prepare(
                    "INSERT INTO barbers_payments (
                        barber_id,
                        salary_month,
                        payment_amount,
                        total_loans,
                        total_deductions,
                        total_commission,
                        net_amount,
                        attendance_count,
                        departure_count,
                        absence_count
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $selectedBarberId,
                    $currentMonthStart,
                    formatBarberPaymentAmount($paymentAmount),
                    formatBarberPaymentAmount($summary['loan_total']),
                    formatBarberPaymentAmount($summary['deduction_total']),
                    formatBarberPaymentAmount($summary['commission_total']),
                    formatBarberPaymentAmount($summary['net_total']),
                    $summary['attendance_count'],
                    $summary['departure_count'],
                    $summary['absence_count']
                ]);

                $conn->commit();
                header('Location: barbers_payments.php?success=' . urlencode('تم قبض الحلاق ' . $barber['barber_name'] . ' بنجاح'));
                exit;
            } catch (Throwable $throwable) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }

                $errorMessage = $throwable->getMessage();
            }
        }
    }
}

$barbersStmt = $conn->prepare("SELECT id, barber_name, commission_percent FROM barbers ORDER BY barber_name ASC, id ASC");
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);
$barbersById = [];

foreach ($barbers as $barberRow) {
    $barbersById[(int) $barberRow['id']] = $barberRow;
}

if (!isset($barbersById[$selectedBarberId])) {
    $selectedBarberId = 0;
}

$eligibleBarbersStmt = $conn->prepare(
    "SELECT b.id, b.barber_name
     FROM barbers b
     LEFT JOIN barbers_payments bp
       ON bp.barber_id = b.id
      AND bp.salary_month = ?
     WHERE bp.id IS NULL
     ORDER BY b.barber_name ASC, b.id ASC"
);
$eligibleBarbersStmt->execute([$currentMonthStart]);
$eligibleBarbers = $eligibleBarbersStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentsStmt = $conn->prepare(
    "SELECT bp.id, bp.barber_id, b.barber_name, bp.payment_amount, bp.total_loans, bp.total_deductions, bp.total_commission, bp.net_amount, bp.attendance_count, bp.departure_count, bp.absence_count, bp.created_at
     FROM barbers_payments bp
     INNER JOIN barbers b ON b.id = bp.barber_id
     WHERE bp.salary_month = ?
     ORDER BY bp.id DESC"
);
$paymentsStmt->execute([$currentMonthStart]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
$paymentsByBarberId = [];

foreach ($payments as $paymentRow) {
    $paymentsByBarberId[(int) $paymentRow['barber_id']] = $paymentRow;
}

$invoiceOverviewStmt = $conn->prepare(
    "SELECT COALESCE(SUM(barber_share_amount), 0) AS total_commission
     FROM salon_invoices
     WHERE created_at >= ? AND created_at < ?"
);
$invoiceOverviewStmt->execute([$currentMonthStart, $nextMonthStart]);
$totalCommissionMonth = (float) ($invoiceOverviewStmt->fetchColumn() ?: 0);

$loansOverviewStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_loans
     FROM barbers_loans
     WHERE created_at >= ? AND created_at < ?"
);
$loansOverviewStmt->execute([$currentMonthStart, $nextMonthStart]);
$totalLoansMonth = (float) ($loansOverviewStmt->fetchColumn() ?: 0);

$deductionsOverviewStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_deductions
     FROM barbers_deductions
     WHERE created_at >= ? AND created_at < ?"
);
$deductionsOverviewStmt->execute([$currentMonthStart, $nextMonthStart]);
$totalDeductionsMonth = (float) ($deductionsOverviewStmt->fetchColumn() ?: 0);

$totalPaidMonth = 0.0;
foreach ($payments as $paymentRow) {
    $totalPaidMonth += (float) ($paymentRow['payment_amount'] ?? 0);
}

$selectedSummary = null;
$selectedBarber = null;
$selectedPayment = null;

if ($selectedBarberId > 0 && isset($barbersById[$selectedBarberId])) {
    $selectedBarber = $barbersById[$selectedBarberId];
    $selectedSummary = getBarberPaymentSummary($conn, $selectedBarberId, $currentMonthStart, $nextMonthStart);
    $selectedPayment = $paymentsByBarberId[$selectedBarberId] ?? null;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قبض الحلاقين</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-layout">
        <?php include "sidebar.php"; ?>

        <main class="main-content">
            <div class="top-actions">
                <button class="toggle-sidebar-btn" id="toggleSidebar">☰</button>
                <div class="theme-floating-inline">
                    <span>🌙</span>
                    <label class="switch">
                        <input type="checkbox" id="themeToggle">
                        <span class="slider"></span>
                    </label>
                    <span>☀️</span>
                </div>
            </div>

            <div class="content-card barber-payments-content-card">
                <div class="page-header">
                    <div>
                        <h1 class="section-title">💰 قبض الحلاقين</h1>
                        <p class="page-subtitle">عرض بيانات الشهر الحالي <?php echo htmlspecialchars($currentMonthLabel); ?></p>
                    </div>
                </div>

                <?php if ($successMessage !== '') { ?>
                    <div class="status-box status-box-success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php } ?>

                <?php if ($errorMessage !== '') { ?>
                    <div class="status-box status-box-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <div class="barbers-overview barber-payments-overview">
                    <div class="overview-card">
                        <span class="overview-label">الحلاقون المتاحون للقبض</span>
                        <strong class="overview-value"><?php echo count($eligibleBarbers); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">سجلات القبض هذا الشهر</span>
                        <strong class="overview-value"><?php echo count($payments); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي نسبة الحلاقين</span>
                        <strong class="overview-value"><?php echo number_format($totalCommissionMonth, 2); ?> ج</strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي السلف</span>
                        <strong class="overview-value"><?php echo number_format($totalLoansMonth, 2); ?> ج</strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الخصومات</span>
                        <strong class="overview-value"><?php echo number_format($totalDeductionsMonth, 2); ?> ج</strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي المصروف</span>
                        <strong class="overview-value"><?php echo number_format($totalPaidMonth, 2); ?> ج</strong>
                    </div>
                </div>

                <div class="barber-payments-top-grid">
                    <section class="barber-payments-panel">
                        <div class="page-header barber-payments-panel-header">
                            <div>
                                <h2 class="section-title barber-payments-mini-title">اختيار الحلاق</h2>
                                <p class="page-subtitle">تظهر القائمة بالحلاقين غير المقبوض لهم في الشهر الحالي</p>
                            </div>
                        </div>

                        <?php if ($eligibleBarbers) { ?>
                            <form method="get" class="barber-payment-select-form">
                                <div class="field-group horizontal-field">
                                    <label>الحلاق</label>
                                    <select name="barber_id" required>
                                        <option value="">اختر الحلاق</option>
                                        <?php foreach ($eligibleBarbers as $eligibleBarber) { ?>
                                            <option value="<?php echo (int) $eligibleBarber['id']; ?>" <?php echo $selectedBarberId === (int) $eligibleBarber['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($eligibleBarber['barber_name']); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-actions-row barber-payment-actions-row">
                                    <button type="submit" class="btn btn-primary">عرض البيانات</button>
                                    <a href="barbers_payments.php" class="btn btn-secondary">مسح الاختيار</a>
                                </div>
                            </form>
                        <?php } else { ?>
                            <div class="barber-payment-empty-state">تم قبض جميع الحلاقين خلال الشهر الحالي</div>
                        <?php } ?>
                    </section>

                    <section class="barber-payments-panel">
                        <div class="page-header barber-payments-panel-header">
                            <div>
                                <h2 class="section-title barber-payments-mini-title">تنفيذ القبض</h2>
                                <p class="page-subtitle">اكتب مبلغ القبض بعد مراجعة بيانات الحلاق</p>
                            </div>
                        </div>

                        <?php if ($selectedBarber && $selectedSummary) { ?>
                            <div class="barber-payment-highlight-grid">
                                <div class="barber-payment-highlight-box">
                                    <span class="overview-label">الحلاق</span>
                                    <strong class="overview-value barber-payment-highlight-value"><?php echo htmlspecialchars($selectedBarber['barber_name']); ?></strong>
                                </div>
                                <div class="barber-payment-highlight-box">
                                    <span class="overview-label">صافي المستحق</span>
                                    <strong class="overview-value barber-payment-highlight-value"><?php echo number_format($selectedSummary['net_total'], 2); ?> ج</strong>
                                </div>
                            </div>

                            <?php if ($selectedPayment) { ?>
                                <div class="status-box status-box-success">تم قبض هذا الحلاق بمبلغ <?php echo number_format((float) $selectedPayment['payment_amount'], 2); ?> ج في <?php echo htmlspecialchars(formatDateTimeValue($selectedPayment['created_at'])); ?></div>
                            <?php } else { ?>
                                <form method="post" class="barber-payment-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="barber_id" value="<?php echo (int) $selectedBarberId; ?>">

                                    <div class="field-group horizontal-field">
                                        <label>مبلغ قبض الحلاق</label>
                                        <input type="number" name="payment_amount" min="0.01" step="0.01" required value="<?php echo htmlspecialchars($paymentAmountValue !== '' ? $paymentAmountValue : formatBarberPaymentAmount($selectedSummary['net_total'])); ?>">
                                    </div>

                                    <div class="form-actions-row barber-payment-actions-row">
                                        <button type="submit" class="btn btn-success">قبض</button>
                                    </div>
                                </form>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="barber-payment-empty-state">اختر حلاقًا لعرض السجل وإتمام القبض</div>
                        <?php } ?>
                    </section>
                </div>

                <?php if ($selectedBarber && $selectedSummary) { ?>
                    <section class="barber-payments-panel">
                        <div class="page-header barber-payments-panel-header">
                            <div>
                                <h2 class="section-title barber-payments-mini-title">بيانات <?php echo htmlspecialchars($selectedBarber['barber_name']); ?></h2>
                                <p class="page-subtitle">ملخص الشهر الحالي</p>
                            </div>
                        </div>

                        <div class="barber-payments-summary-grid">
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">الحضور</span>
                                <strong class="overview-value"><?php echo $selectedSummary['attendance_count']; ?></strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">الانصراف</span>
                                <strong class="overview-value"><?php echo $selectedSummary['departure_count']; ?></strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">الغياب</span>
                                <strong class="overview-value"><?php echo $selectedSummary['absence_count']; ?></strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">إجمالي السلف</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['loan_total'], 2); ?> ج</strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">إجمالي الخصومات</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['deduction_total'], 2); ?> ج</strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">إجمالي نسبة الحلاق</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['commission_total'], 2); ?> ج</strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">إجمالي الفواتير</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['sales_total'], 2); ?> ج</strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">صافي المستحق</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['net_total'], 2); ?> ج</strong>
                            </div>
                        </div>
                    </section>

                    <div class="barber-payments-log-grid">
                        <section class="barber-payments-panel">
                            <div class="page-header barber-payments-panel-header">
                                <div>
                                    <h2 class="section-title barber-payments-mini-title">سجل الحضور والانصراف والغياب</h2>
                                </div>
                            </div>
                            <div class="table-wrap">
                                <table class="data-table responsive-table barber-payments-table">
                                    <thead>
                                        <tr>
                                            <th>التاريخ</th>
                                            <th>الحضور</th>
                                            <th>الانصراف</th>
                                            <th>حالة اليوم</th>
                                            <th>وقت الحضور</th>
                                            <th>وقت الانصراف</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($selectedSummary['attendance_rows']) { ?>
                                            <?php foreach ($selectedSummary['attendance_rows'] as $attendanceRow) { ?>
                                                <tr>
                                                    <td data-label="التاريخ"><?php echo htmlspecialchars((string) $attendanceRow['record_date']); ?></td>
                                                    <td data-label="الحضور"><?php echo htmlspecialchars((string) ($attendanceRow['attendance_status'] !== '' ? $attendanceRow['attendance_status'] : '—')); ?></td>
                                                    <td data-label="الانصراف"><?php echo htmlspecialchars((string) ($attendanceRow['departure_status'] !== '' ? $attendanceRow['departure_status'] : '—')); ?></td>
                                                    <td data-label="حالة اليوم"><?php echo htmlspecialchars((string) ($attendanceRow['day_status'] !== '' ? $attendanceRow['day_status'] : '—')); ?></td>
                                                    <td data-label="وقت الحضور"><?php echo htmlspecialchars((string) ($attendanceRow['check_in_at'] ? formatDateTimeValue($attendanceRow['check_in_at']) : '—')); ?></td>
                                                    <td data-label="وقت الانصراف"><?php echo htmlspecialchars((string) ($attendanceRow['check_out_at'] ? formatDateTimeValue($attendanceRow['check_out_at']) : '—')); ?></td>
                                                </tr>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <tr>
                                                <td colspan="6">لا يوجد سجل خلال الشهر الحالي</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="barber-payments-panel">
                            <div class="page-header barber-payments-panel-header">
                                <div>
                                    <h2 class="section-title barber-payments-mini-title">سجل السلف</h2>
                                </div>
                            </div>
                            <div class="table-wrap">
                                <table class="data-table responsive-table barber-payments-table">
                                    <thead>
                                        <tr>
                                            <th>المبلغ</th>
                                            <th>التاريخ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($selectedSummary['loan_rows']) { ?>
                                            <?php foreach ($selectedSummary['loan_rows'] as $loanRow) { ?>
                                                <tr>
                                                    <td data-label="المبلغ"><?php echo number_format((float) $loanRow['amount'], 2); ?> ج</td>
                                                    <td data-label="التاريخ"><?php echo htmlspecialchars(formatDateTimeValue($loanRow['created_at'])); ?></td>
                                                </tr>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <tr>
                                                <td colspan="2">لا توجد سلف خلال الشهر الحالي</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="barber-payments-panel">
                            <div class="page-header barber-payments-panel-header">
                                <div>
                                    <h2 class="section-title barber-payments-mini-title">سجل الخصومات</h2>
                                </div>
                            </div>
                            <div class="table-wrap">
                                <table class="data-table responsive-table barber-payments-table">
                                    <thead>
                                        <tr>
                                            <th>المبلغ</th>
                                            <th>السبب</th>
                                            <th>التاريخ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($selectedSummary['deduction_rows']) { ?>
                                            <?php foreach ($selectedSummary['deduction_rows'] as $deductionRow) { ?>
                                                <tr>
                                                    <td data-label="المبلغ"><?php echo number_format((float) $deductionRow['amount'], 2); ?> ج</td>
                                                    <td data-label="السبب"><?php echo htmlspecialchars((string) $deductionRow['reason']); ?></td>
                                                    <td data-label="التاريخ"><?php echo htmlspecialchars(formatDateTimeValue($deductionRow['created_at'])); ?></td>
                                                </tr>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <tr>
                                                <td colspan="3">لا توجد خصومات خلال الشهر الحالي</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="barber-payments-panel">
                            <div class="page-header barber-payments-panel-header">
                                <div>
                                    <h2 class="section-title barber-payments-mini-title">سجل الفواتير</h2>
                                </div>
                            </div>
                            <div class="table-wrap">
                                <table class="data-table responsive-table barber-payments-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>إجمالي الفاتورة</th>
                                            <th>نسبة الحلاق</th>
                                            <th>النسبة المئوية</th>
                                            <th>التاريخ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($selectedSummary['invoice_rows']) { ?>
                                            <?php foreach ($selectedSummary['invoice_rows'] as $invoiceRow) { ?>
                                                <tr>
                                                    <td data-label="#"><?php echo (int) $invoiceRow['id']; ?></td>
                                                    <td data-label="إجمالي الفاتورة"><?php echo number_format((float) $invoiceRow['total_amount'], 2); ?> ج</td>
                                                    <td data-label="نسبة الحلاق"><?php echo number_format((float) $invoiceRow['barber_share_amount'], 2); ?> ج</td>
                                                    <td data-label="النسبة المئوية"><?php echo number_format((float) $invoiceRow['barber_commission_percent'], 2); ?>%</td>
                                                    <td data-label="التاريخ"><?php echo htmlspecialchars(formatDateTimeValue($invoiceRow['created_at'])); ?></td>
                                                </tr>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <tr>
                                                <td colspan="5">لا توجد فواتير خلال الشهر الحالي</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                <?php } ?>

                <section class="barber-payments-panel">
                    <div class="page-header barber-payments-panel-header">
                        <div>
                            <h2 class="section-title barber-payments-mini-title">المرتبات المصروفة هذا الشهر</h2>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table responsive-table barber-payments-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الحلاق</th>
                                    <th>مبلغ القبض</th>
                                    <th>إجمالي السلف</th>
                                    <th>إجمالي الخصومات</th>
                                    <th>إجمالي نسبة الحلاق</th>
                                    <th>صافي المستحق</th>
                                    <th>الحضور</th>
                                    <th>الانصراف</th>
                                    <th>الغياب</th>
                                    <th>تاريخ القبض</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($payments) { ?>
                                    <?php foreach ($payments as $paymentRow) { ?>
                                        <tr>
                                            <td data-label="#"><?php echo (int) $paymentRow['id']; ?></td>
                                            <td data-label="الحلاق"><?php echo htmlspecialchars((string) $paymentRow['barber_name']); ?></td>
                                            <td data-label="مبلغ القبض"><?php echo number_format((float) $paymentRow['payment_amount'], 2); ?> ج</td>
                                            <td data-label="إجمالي السلف"><?php echo number_format((float) $paymentRow['total_loans'], 2); ?> ج</td>
                                            <td data-label="إجمالي الخصومات"><?php echo number_format((float) $paymentRow['total_deductions'], 2); ?> ج</td>
                                            <td data-label="إجمالي نسبة الحلاق"><?php echo number_format((float) $paymentRow['total_commission'], 2); ?> ج</td>
                                            <td data-label="صافي المستحق"><?php echo number_format((float) $paymentRow['net_amount'], 2); ?> ج</td>
                                            <td data-label="الحضور"><?php echo (int) $paymentRow['attendance_count']; ?></td>
                                            <td data-label="الانصراف"><?php echo (int) $paymentRow['departure_count']; ?></td>
                                            <td data-label="الغياب"><?php echo (int) $paymentRow['absence_count']; ?></td>
                                            <td data-label="تاريخ القبض"><?php echo htmlspecialchars(formatDateTimeValue($paymentRow['created_at'])); ?></td>
                                        </tr>
                                    <?php } ?>
                                <?php } else { ?>
                                    <tr>
                                        <td colspan="11">لا توجد مرتبات مصروفة خلال الشهر الحالي</td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>
