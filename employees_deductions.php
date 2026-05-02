<?php
require_once "config.php";
requireLogin();

if (!canAccess('employees_deductions')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const MYSQL_ERROR_DUPLICATE_COLUMN = 1060;

$isManager = isset($_SESSION['role']) && $_SESSION['role'] === APP_MANAGER_ROLE;

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

    try {
        $conn->exec("ALTER TABLE employees_deductions ADD COLUMN reason VARCHAR(500) NOT NULL DEFAULT '' AFTER amount");
    } catch (PDOException $exception) {
        if ((int) $exception->errorInfo[1] !== MYSQL_ERROR_DUPLICATE_COLUMN) {
            throw $exception;
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة خصومات الموظفين");
}

$settings = getSiteSettings($conn);
$errorMessage = '';
$editDeduction = null;
$formData = [
    'id' => '',
    'employee_id' => '',
    'amount' => '',
    'reason' => ''
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $deductionId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, employee_id, amount, reason FROM employees_deductions WHERE id = ?");
    $stmt->execute([$deductionId]);
    $editDeduction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editDeduction) {
        $formData = [
            'id' => (string) $editDeduction['id'],
            'employee_id' => (string) $editDeduction['employee_id'],
            'amount' => number_format((float) $editDeduction['amount'], 2, '.', ''),
            'reason' => trim((string) $editDeduction['reason'])
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    if (isset($_POST['delete_id'])) {
        if (!$isManager) {
            http_response_code(403);
            die("غير مصرح");
        }

        $stmt = $conn->prepare("DELETE FROM employees_deductions WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: employees_deductions.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'employee_id' => trim($_POST['employee_id'] ?? ''),
        'amount' => trim($_POST['amount'] ?? ''),
        'reason' => trim($_POST['reason'] ?? '')
    ];

    if ($formData['id'] !== '' && !$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    if ($formData['employee_id'] === '' || $formData['amount'] === '' || $formData['reason'] === '') {
        $errorMessage = 'اختر الموظف وأدخل المبلغ وسبب الخصم';
    } elseif (!ctype_digit($formData['employee_id'])) {
        $errorMessage = 'اختر موظفًا صحيحًا';
    } elseif (getTextLength($formData['amount']) > 20 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $formData['amount'])) {
        $errorMessage = 'المبلغ يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } elseif (getTextLength($formData['reason']) > 500) {
        $errorMessage = 'سبب الخصم يجب ألا يزيد على 500 حرف';
    } else {
        $employeeCheckStmt = $conn->prepare("SELECT employee_name FROM employees WHERE id = ? LIMIT 1");
        $employeeCheckStmt->execute([(int) $formData['employee_id']]);
        $employeeExists = $employeeCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$employeeExists) {
            $errorMessage = 'الموظف غير موجود';
        } else {
            $amountValue = (float) $formData['amount'];

            if ($amountValue <= 0) {
                $errorMessage = 'المبلغ يجب أن يكون أكبر من صفر';
            } else {
                $formattedAmount = number_format($amountValue, 2, '.', '');

                if ($formData['id'] === '') {
                    $stmt = $conn->prepare("INSERT INTO employees_deductions (employee_id, amount, reason) VALUES (?, ?, ?)");
                    $stmt->execute([(int) $formData['employee_id'], $formattedAmount, $formData['reason']]);
                } else {
                    $stmt = $conn->prepare("UPDATE employees_deductions SET employee_id = ?, amount = ?, reason = ? WHERE id = ?");
                    $stmt->execute([(int) $formData['employee_id'], $formattedAmount, $formData['reason'], (int) $formData['id']]);
                }

                header("Location: employees_deductions.php");
                exit;
            }
        }
    }
}

$employeesStmt = $conn->prepare("SELECT id, employee_name FROM employees ORDER BY employee_name ASC");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$deductionsStmt = $conn->prepare(
    "SELECT ed.id, ed.employee_id, ed.amount, ed.reason, ed.created_at, ed.updated_at, e.employee_name
     FROM employees_deductions ed
     INNER JOIN employees e ON e.id = ed.employee_id
     ORDER BY ed.id DESC"
);
$deductionsStmt->execute();
$deductions = $deductionsStmt->fetchAll(PDO::FETCH_ASSOC);

$deductionsCount = count($deductions);
$totalAmount = 0.0;

foreach ($deductions as $deductionSummary) {
    $totalAmount += (float) ($deductionSummary['amount'] ?? 0);
}

$averageAmount = $deductionsCount > 0 ? $totalAmount / $deductionsCount : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خصومات الموظفين</title>
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

            <div class="content-card loan-page-stack">
                <div class="page-header">
                    <div>
                        <h1 class="section-title">خصومات الموظفين</h1>
                        <p class="page-subtitle">إدارة تسجيل خصومات الموظفين وعرض السجل الكامل.</p>
                    </div>
                </div>

                <div class="loans-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي السجلات</span>
                        <strong class="overview-value"><?php echo $deductionsCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الخصومات</span>
                        <strong class="overview-value"><?php echo number_format($totalAmount, 2); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">متوسط الخصم</span>
                        <strong class="overview-value"><?php echo number_format($averageAmount, 2); ?></strong>
                    </div>
                </div>

                <?php if ($errorMessage !== '') { ?>
                    <div class="login-error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <div class="loan-management-layout">
                    <section class="loan-form-card">
                        <div class="staff-card-head">
                            <h2><?php echo $formData['id'] !== '' ? 'تعديل الخصم' : 'إضافة خصم'; ?></h2>
                        </div>

                        <form method="post" class="inline-form deduction-form-grid">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>الموظف</label>
                                <select name="employee_id" required>
                                    <option value="">اختر الموظف</option>
                                    <?php foreach ($employees as $employee) { ?>
                                        <option value="<?php echo $employee['id']; ?>" <?php if ($formData['employee_id'] === (string) $employee['id']) echo 'selected'; ?>><?php echo htmlspecialchars($employee['employee_name']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>المبلغ</label>
                                <input type="number" name="amount" min="0.01" step="0.01" required value="<?php echo htmlspecialchars($formData['amount']); ?>">
                            </div>

                            <div class="field-group horizontal-field deduction-wide-field">
                                <label>سبب الخصم</label>
                                <textarea name="reason" rows="3" maxlength="500" required><?php echo htmlspecialchars($formData['reason']); ?></textarea>
                            </div>

                            <div class="form-actions-row loan-actions-row">
                                <button type="submit" class="btn <?php echo $formData['id'] !== '' ? 'btn-warning' : 'btn-success'; ?>">
                                    <?php echo $formData['id'] !== '' ? 'تحديث الخصم' : 'حفظ الخصم'; ?>
                                </button>
                                <a href="employees_deductions.php" class="btn btn-secondary">سجل جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table deductions-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الموظف</th>
                                <th>المبلغ</th>
                                <th>سبب الخصم</th>
                                <th>تاريخ التسجيل</th>
                                <th>آخر تحديث</th>
                                <?php if ($isManager) { ?><th>الإجراءات</th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($deductions) { ?>
                                <?php foreach ($deductions as $deduction) { ?>
                                    <tr>
                                        <td data-label="#"><?php echo $deduction['id']; ?></td>
                                        <td data-label="الموظف"><?php echo htmlspecialchars($deduction['employee_name']); ?></td>
                                        <td data-label="المبلغ"><span class="amount-badge"><?php echo number_format((float) $deduction['amount'], 2); ?></span></td>
                                        <td data-label="سبب الخصم"><span class="reason-badge"><?php echo nl2br(htmlspecialchars($deduction['reason'])); ?></span></td>
                                        <td data-label="تاريخ التسجيل"><?php echo htmlspecialchars(formatDateTimeValue($deduction['created_at'])); ?></td>
                                        <td data-label="آخر تحديث"><?php echo htmlspecialchars(formatDateTimeValue($deduction['updated_at'])); ?></td>
                                        <?php if ($isManager) { ?>
                                            <td class="action-cell" data-label="الإجراءات">
                                                <a href="employees_deductions.php?edit=<?php echo $deduction['id']; ?>" class="btn btn-warning">تعديل</a>
                                                <form method="post" data-confirm-message="حذف الخصم؟">
                                                    <input type="hidden" name="delete_id" value="<?php echo $deduction['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <button type="submit" class="btn btn-danger">حذف</button>
                                                </form>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="<?php echo $isManager ? '7' : '6'; ?>">لا توجد خصومات مسجلة</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>
