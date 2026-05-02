<?php
require_once "config.php";
requireLogin();

if (!canAccess('employees_salaries')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function parseEmployeeId($value)
{
    return ctype_digit((string) $value) ? (int) $value : 0;
}

function formatAmount($value)
{
    return number_format((float) $value, 2, '.', '');
}

function formatMonthLabel($monthStart)
{
    $timestamp = strtotime($monthStart);

    if ($timestamp === false) {
        return date('m/Y');
    }

    return date('m/Y', $timestamp);
}

function calculateEmployeeMonthlySummary($conn, $employeeId, $monthStart, $nextMonthStart)
{
    $employeeStmt = $conn->prepare(
        "SELECT employee_name, salary_amount
         FROM employees
         WHERE id = ?
         LIMIT 1"
    );
    $employeeStmt->execute([$employeeId]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($employee === null) {
        throw new RuntimeException('الموظف غير موجود');
    }

    $attendanceCountStmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN check_in_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS attendance_count,
            COALESCE(SUM(CASE WHEN check_out_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS departure_count,
            COALESCE(SUM(CASE WHEN day_status = 'غياب' OR attendance_status = 'غياب' OR departure_status = 'غياب' THEN 1 ELSE 0 END), 0) AS absence_count
         FROM employees_attendance
         WHERE employee_id = ? AND record_date >= ? AND record_date < ?"
    );
    $attendanceCountStmt->execute([$employeeId, $monthStart, $nextMonthStart]);
    $attendanceCounts = $attendanceCountStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'attendance_count' => 0,
        'departure_count' => 0,
        'absence_count' => 0
    ];

    $attendanceRowsStmt = $conn->prepare(
        "SELECT record_date, attendance_status, departure_status, day_status, check_in_at, check_out_at
         FROM employees_attendance
         WHERE employee_id = ? AND record_date >= ? AND record_date < ?
         ORDER BY record_date DESC, id DESC"
    );
    $attendanceRowsStmt->execute([$employeeId, $monthStart, $nextMonthStart]);
    $attendanceRows = $attendanceRowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $loansStmt = $conn->prepare(
        "SELECT amount, created_at
         FROM employees_loans
         WHERE employee_id = ? AND created_at >= ? AND created_at < ?
         ORDER BY id DESC"
    );
    $loansStmt->execute([$employeeId, $monthStart, $nextMonthStart]);
    $loanRows = $loansStmt->fetchAll(PDO::FETCH_ASSOC);
    $loanTotal = 0.0;

    foreach ($loanRows as $loanRow) {
        $loanTotal += (float) ($loanRow['amount'] ?? 0);
    }

    $deductionsStmt = $conn->prepare(
        "SELECT amount, reason, created_at
         FROM employees_deductions
         WHERE employee_id = ? AND created_at >= ? AND created_at < ?
         ORDER BY id DESC"
    );
    $deductionsStmt->execute([$employeeId, $monthStart, $nextMonthStart]);
    $deductionRows = $deductionsStmt->fetchAll(PDO::FETCH_ASSOC);
    $deductionTotal = 0.0;

    foreach ($deductionRows as $deductionRow) {
        $deductionTotal += (float) ($deductionRow['amount'] ?? 0);
    }

    $salaryAmount = (float) ($employee['salary_amount'] ?? 0);
    $netAmount = $salaryAmount - $loanTotal - $deductionTotal;

    return [
        'employee_name' => (string) ($employee['employee_name'] ?? ''),
        'salary_amount' => $salaryAmount,
        'attendance_count' => (int) ($attendanceCounts['attendance_count'] ?? 0),
        'departure_count' => (int) ($attendanceCounts['departure_count'] ?? 0),
        'absence_count' => (int) ($attendanceCounts['absence_count'] ?? 0),
        'attendance_rows' => $attendanceRows,
        'loan_rows' => $loanRows,
        'loan_total' => $loanTotal,
        'deduction_rows' => $deductionRows,
        'deduction_total' => $deductionTotal,
        'net_amount' => $netAmount,
        'payment_amount' => max($netAmount, 0)
    ];
}

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS employees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_name VARCHAR(255) NOT NULL,
            employee_number VARCHAR(100) NOT NULL,
            employee_barcode VARCHAR(100) NOT NULL DEFAULT '',
            attendance_time VARCHAR(10) NOT NULL,
            departure_time VARCHAR(10) NOT NULL,
            off_days TEXT NOT NULL,
            salary_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS employees_attendance (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
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
            UNIQUE KEY uniq_employee_attendance_day (employee_id, record_date),
            KEY idx_employee_attendance_date (record_date),
            KEY idx_employee_attendance_status (day_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS employees_loans (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employees_loans_employee_id (employee_id),
            CONSTRAINT fk_employees_loans_employee_id FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS employees_deductions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employees_deductions_employee_id (employee_id),
            CONSTRAINT fk_employees_deductions_employee_id FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS employees_salary_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            salary_month DATE NOT NULL,
            payment_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            base_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_loans DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(10,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            attendance_count INT UNSIGNED NOT NULL DEFAULT 0,
            departure_count INT UNSIGNED NOT NULL DEFAULT 0,
            absence_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_employee_salary_payment_month (employee_id, salary_month),
            KEY idx_employees_salary_payments_month (salary_month),
            CONSTRAINT fk_employees_salary_payments_employee_id FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة قبض رواتب الموظفين");
}

$settings = getSiteSettings($conn);
$currentMonthStart = date('Y-m-01');
$currentMonthDate = DateTimeImmutable::createFromFormat('Y-m-d', $currentMonthStart);

if (!$currentMonthDate || $currentMonthDate->format('Y-m-d') !== $currentMonthStart) {
    http_response_code(500);
    die("تعذر تحليل تاريخ الشهر الحالي");
}

$nextMonthStart = $currentMonthDate->modify('+1 month')->format('Y-m-d');
$currentMonthLabel = formatMonthLabel($currentMonthStart);
$errorMessage = '';
$successMessage = trim((string) ($_GET['success'] ?? ''));
$selectedEmployeeId = parseEmployeeId($_GET['employee_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    $selectedEmployeeId = parseEmployeeId($_POST['employee_id'] ?? '');

    if ($selectedEmployeeId <= 0) {
        $errorMessage = 'اختر الموظف أولاً';
    } else {
        try {
            $conn->beginTransaction();

            $employeeStmt = $conn->prepare("SELECT id, employee_name FROM employees WHERE id = ? LIMIT 1");
            $employeeStmt->execute([$selectedEmployeeId]);
            $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($employee === null) {
                throw new RuntimeException('الموظف غير موجود');
            }

            $existingPaymentStmt = $conn->prepare(
                "SELECT id
                 FROM employees_salary_payments
                 WHERE employee_id = ? AND salary_month = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $existingPaymentStmt->execute([$selectedEmployeeId, $currentMonthStart]);

            if ($existingPaymentStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('تم صرف راتب هذا الموظف خلال الشهر الحالي بالفعل');
            }

            $summary = calculateEmployeeMonthlySummary($conn, $selectedEmployeeId, $currentMonthStart, $nextMonthStart);
            $insertStmt = $conn->prepare(
                "INSERT INTO employees_salary_payments (
                    employee_id,
                    salary_month,
                    payment_amount,
                    base_salary,
                    total_loans,
                    total_deductions,
                    net_amount,
                    attendance_count,
                    departure_count,
                    absence_count
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->execute([
                $selectedEmployeeId,
                $currentMonthStart,
                formatAmount($summary['payment_amount']),
                formatAmount($summary['salary_amount']),
                formatAmount($summary['loan_total']),
                formatAmount($summary['deduction_total']),
                formatAmount($summary['net_amount']),
                $summary['attendance_count'],
                $summary['departure_count'],
                $summary['absence_count']
            ]);

            $conn->commit();
            header('Location: employees_salaries.php?success=' . urlencode('تم صرف راتب الموظف ' . $employee['employee_name'] . ' بنجاح'));
            exit;
        } catch (Throwable $throwable) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $errorMessage = $throwable->getMessage();
        }
    }
}

$employeesStmt = $conn->prepare("SELECT id, employee_name, salary_amount FROM employees ORDER BY employee_name ASC, id ASC");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);
$employeesById = [];

foreach ($employees as $employeeRow) {
    $employeesById[(int) $employeeRow['id']] = $employeeRow;
}

if (!isset($employeesById[$selectedEmployeeId])) {
    $selectedEmployeeId = 0;
}

$eligibleEmployeesStmt = $conn->prepare(
    "SELECT e.id, e.employee_name
     FROM employees e
     LEFT JOIN employees_salary_payments esp
       ON esp.employee_id = e.id
      AND esp.salary_month = ?
     WHERE esp.id IS NULL
     ORDER BY e.employee_name ASC, e.id ASC"
);
$eligibleEmployeesStmt->execute([$currentMonthStart]);
$eligibleEmployees = $eligibleEmployeesStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentsStmt = $conn->prepare(
    "SELECT esp.id, esp.employee_id, e.employee_name, esp.payment_amount, esp.base_salary, esp.total_loans, esp.total_deductions, esp.net_amount, esp.attendance_count, esp.departure_count, esp.absence_count, esp.created_at
     FROM employees_salary_payments esp
     INNER JOIN employees e ON e.id = esp.employee_id
     WHERE esp.salary_month = ?
     ORDER BY esp.id DESC"
);
$paymentsStmt->execute([$currentMonthStart]);
$payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
$paymentsByEmployeeId = [];

foreach ($payments as $paymentRow) {
    $paymentsByEmployeeId[(int) $paymentRow['employee_id']] = $paymentRow;
}

$salaryOverviewStmt = $conn->prepare(
    "SELECT COALESCE(SUM(salary_amount), 0) AS total_salary
     FROM employees"
);
$salaryOverviewStmt->execute();
$totalSalaryMonth = (float) ($salaryOverviewStmt->fetchColumn() ?: 0);

$loansOverviewStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_loans
     FROM employees_loans
     WHERE created_at >= ? AND created_at < ?"
);
$loansOverviewStmt->execute([$currentMonthStart, $nextMonthStart]);
$totalLoansMonth = (float) ($loansOverviewStmt->fetchColumn() ?: 0);

$deductionsOverviewStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) AS total_deductions
     FROM employees_deductions
     WHERE created_at >= ? AND created_at < ?"
);
$deductionsOverviewStmt->execute([$currentMonthStart, $nextMonthStart]);
$totalDeductionsMonth = (float) ($deductionsOverviewStmt->fetchColumn() ?: 0);

$totalPaidMonth = 0.0;
foreach ($payments as $paymentRow) {
    $totalPaidMonth += (float) ($paymentRow['payment_amount'] ?? 0);
}

$selectedSummary = null;
$selectedEmployee = null;
$selectedPayment = null;

if ($selectedEmployeeId > 0 && isset($employeesById[$selectedEmployeeId])) {
    $selectedEmployee = $employeesById[$selectedEmployeeId];
    $selectedSummary = calculateEmployeeMonthlySummary($conn, $selectedEmployeeId, $currentMonthStart, $nextMonthStart);
    $selectedPayment = $paymentsByEmployeeId[$selectedEmployeeId] ?? null;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قبض رواتب الموظفين</title>
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
                        <h1 class="section-title">قبض رواتب الموظفين</h1>
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
                        <span class="overview-label">المتاحون للصرف</span>
                        <strong class="overview-value"><?php echo count($eligibleEmployees); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">المصروف هذا الشهر</span>
                        <strong class="overview-value"><?php echo count($payments); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الرواتب</span>
                        <strong class="overview-value"><?php echo number_format($totalSalaryMonth, 2); ?> ج</strong>
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
                                <h2 class="section-title barber-payments-mini-title">اختيار الموظف</h2>
                            </div>
                        </div>

                        <?php if ($eligibleEmployees) { ?>
                            <form method="get" class="barber-payment-select-form">
                                <div class="field-group horizontal-field">
                                    <label for="employeeSalarySelect">الموظف</label>
                                    <select name="employee_id" id="employeeSalarySelect" required>
                                        <option value="">اختر الموظف</option>
                                        <?php foreach ($eligibleEmployees as $eligibleEmployee) { ?>
                                            <option value="<?php echo (int) $eligibleEmployee['id']; ?>" <?php echo $selectedEmployeeId === (int) $eligibleEmployee['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($eligibleEmployee['employee_name']); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="form-actions-row barber-payment-actions-row">
                                    <button type="submit" class="btn btn-primary">عرض</button>
                                    <a href="employees_salaries.php" class="btn btn-secondary">مسح</a>
                                </div>
                            </form>
                        <?php } else { ?>
                            <div class="barber-payment-empty-state">تم صرف رواتب جميع الموظفين هذا الشهر</div>
                        <?php } ?>
                    </section>

                    <section class="barber-payments-panel">
                        <div class="page-header barber-payments-panel-header">
                            <div>
                                <h2 class="section-title barber-payments-mini-title">صرف الراتب</h2>
                            </div>
                        </div>

                        <?php if ($selectedEmployee && $selectedSummary) { ?>
                            <div class="barber-payment-highlight-grid">
                                <div class="barber-payment-highlight-box">
                                    <span class="overview-label">الموظف</span>
                                    <strong class="overview-value barber-payment-highlight-value"><?php echo htmlspecialchars($selectedSummary['employee_name']); ?></strong>
                                </div>
                                <div class="barber-payment-highlight-box">
                                    <span class="overview-label">مبلغ الراتب المصروف</span>
                                    <strong class="overview-value barber-payment-highlight-value"><?php echo number_format($selectedSummary['payment_amount'], 2); ?> ج</strong>
                                </div>
                            </div>

                            <?php if ($selectedPayment) { ?>
                                <div class="status-box status-box-success">تم صرف راتب هذا الموظف في <?php echo htmlspecialchars(formatDateTimeValue($selectedPayment['created_at'])); ?></div>
                            <?php } else { ?>
                                <form method="post" class="barber-payment-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="employee_id" value="<?php echo (int) $selectedEmployeeId; ?>">
                                    <div class="form-actions-row barber-payment-actions-row">
                                        <button type="submit" class="btn btn-success">صرف الراتب</button>
                                    </div>
                                </form>
                            <?php } ?>
                        <?php } else { ?>
                            <div class="barber-payment-empty-state"><?php echo htmlspecialchars($currentMonthLabel); ?></div>
                        <?php } ?>
                    </section>
                </div>

                <?php if ($selectedEmployee && $selectedSummary) { ?>
                    <section class="barber-payments-panel">
                        <div class="page-header barber-payments-panel-header">
                            <div>
                                <h2 class="section-title barber-payments-mini-title"><?php echo htmlspecialchars($selectedSummary['employee_name']); ?></h2>
                            </div>
                        </div>

                        <div class="barber-payments-summary-grid">
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">الراتب</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['salary_amount'], 2); ?> ج</strong>
                            </div>
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
                                <span class="overview-label">الصافي</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['net_amount'], 2); ?> ج</strong>
                            </div>
                            <div class="barber-payment-stat-box">
                                <span class="overview-label">المصروف</span>
                                <strong class="overview-value"><?php echo number_format($selectedSummary['payment_amount'], 2); ?> ج</strong>
                            </div>
                        </div>
                    </section>

                    <div class="barber-payments-log-grid">
                        <section class="barber-payments-panel">
                            <div class="page-header barber-payments-panel-header">
                                <div>
                                    <h2 class="section-title barber-payments-mini-title">الحضور والانصراف والغياب</h2>
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
                                    <th>الموظف</th>
                                    <th>المبلغ المصروف</th>
                                    <th>الراتب</th>
                                    <th>إجمالي السلف</th>
                                    <th>إجمالي الخصومات</th>
                                    <th>الصافي</th>
                                    <th>الحضور</th>
                                    <th>الانصراف</th>
                                    <th>الغياب</th>
                                    <th>تاريخ الصرف</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($payments) { ?>
                                    <?php foreach ($payments as $paymentRow) { ?>
                                        <tr>
                                            <td data-label="#"><?php echo (int) $paymentRow['id']; ?></td>
                                            <td data-label="الموظف"><?php echo htmlspecialchars((string) $paymentRow['employee_name']); ?></td>
                                            <td data-label="المبلغ المصروف"><?php echo number_format((float) $paymentRow['payment_amount'], 2); ?> ج</td>
                                            <td data-label="الراتب"><?php echo number_format((float) $paymentRow['base_salary'], 2); ?> ج</td>
                                            <td data-label="إجمالي السلف"><?php echo number_format((float) $paymentRow['total_loans'], 2); ?> ج</td>
                                            <td data-label="إجمالي الخصومات"><?php echo number_format((float) $paymentRow['total_deductions'], 2); ?> ج</td>
                                            <td data-label="الصافي"><?php echo number_format((float) $paymentRow['net_amount'], 2); ?> ج</td>
                                            <td data-label="الحضور"><?php echo (int) $paymentRow['attendance_count']; ?></td>
                                            <td data-label="الانصراف"><?php echo (int) $paymentRow['departure_count']; ?></td>
                                            <td data-label="الغياب"><?php echo (int) $paymentRow['absence_count']; ?></td>
                                            <td data-label="تاريخ الصرف"><?php echo htmlspecialchars(formatDateTimeValue($paymentRow['created_at'])); ?></td>
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
