<?php
require_once "config.php";
requireLogin();

if (!canAccess('employees_loans')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function getTextLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function formatLoanDateTime($value)
{
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '—';
    }

    return date('Y-m-d h:i A', $timestamp);
}

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
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة سلف الموظفين");
}

$settings = getSiteSettings($conn);
$errorMessage = '';
$editLoan = null;
$formData = [
    'id' => '',
    'employee_id' => '',
    'amount' => ''
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $loanId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, employee_id, amount FROM employees_loans WHERE id = ?");
    $stmt->execute([$loanId]);
    $editLoan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editLoan) {
        $formData = [
            'id' => (string) $editLoan['id'],
            'employee_id' => (string) $editLoan['employee_id'],
            'amount' => number_format((float) $editLoan['amount'], 2, '.', '')
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

        $stmt = $conn->prepare("DELETE FROM employees_loans WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: employees_loans.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'employee_id' => trim($_POST['employee_id'] ?? ''),
        'amount' => trim($_POST['amount'] ?? '')
    ];

    if ($formData['id'] !== '' && !$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    if ($formData['employee_id'] === '' || $formData['amount'] === '') {
        $errorMessage = '⚠️ اختر الموظف وأدخل المبلغ';
    } elseif (!ctype_digit($formData['employee_id'])) {
        $errorMessage = '⚠️ اختر موظفًا صحيحًا';
    } elseif (getTextLength($formData['amount']) > 20 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $formData['amount'])) {
        $errorMessage = '⚠️ المبلغ يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } else {
        $employeeCheckStmt = $conn->prepare("SELECT employee_name FROM employees WHERE id = ? LIMIT 1");
        $employeeCheckStmt->execute([(int) $formData['employee_id']]);
        $employeeExists = $employeeCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$employeeExists) {
            $errorMessage = '⚠️ الموظف غير موجود';
        } else {
            $amountValue = (float) $formData['amount'];

            if ($amountValue <= 0) {
                $errorMessage = '⚠️ المبلغ يجب أن يكون أكبر من صفر';
            } else {
                $formattedAmount = number_format($amountValue, 2, '.', '');

                if ($formData['id'] === '') {
                    $stmt = $conn->prepare("INSERT INTO employees_loans (employee_id, amount) VALUES (?, ?)");
                    $stmt->execute([(int) $formData['employee_id'], $formattedAmount]);
                } else {
                    $stmt = $conn->prepare("UPDATE employees_loans SET employee_id = ?, amount = ? WHERE id = ?");
                    $stmt->execute([(int) $formData['employee_id'], $formattedAmount, (int) $formData['id']]);
                }

                header("Location: employees_loans.php");
                exit;
            }
        }
    }
}

$employeesStmt = $conn->prepare("SELECT id, employee_name FROM employees ORDER BY employee_name ASC");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$loansStmt = $conn->prepare(
    "SELECT el.id, el.employee_id, el.amount, el.created_at, el.updated_at, e.employee_name
     FROM employees_loans el
     INNER JOIN employees e ON e.id = el.employee_id
     ORDER BY el.id DESC"
);
$loansStmt->execute();
$loans = $loansStmt->fetchAll(PDO::FETCH_ASSOC);

$loansCount = count($loans);
$totalAmount = 0.0;

foreach ($loans as $loanSummary) {
    $totalAmount += (float) ($loanSummary['amount'] ?? 0);
}

$averageAmount = $loansCount > 0 ? $totalAmount / $loansCount : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سلف الموظفين</title>
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
                    <h1 class="section-title">💸 سلف الموظفين</h1>
                </div>

                <div class="loans-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي السجلات</span>
                        <strong class="overview-value"><?php echo $loansCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي السلف</span>
                        <strong class="overview-value"><?php echo number_format($totalAmount, 2); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">متوسط السلفة</span>
                        <strong class="overview-value"><?php echo number_format($averageAmount, 2); ?></strong>
                    </div>
                </div>

                <?php if ($errorMessage !== '') { ?>
                    <div class="login-error-box"><?php echo $errorMessage; ?></div>
                <?php } ?>

                <div class="loan-management-layout">
                    <section class="loan-form-card">
                        <form method="post" class="inline-form loan-form-grid">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>🧑‍💼 الموظف</label>
                                <select name="employee_id" required>
                                    <option value="">اختر الموظف</option>
                                    <?php foreach ($employees as $employee) { ?>
                                        <option value="<?php echo $employee['id']; ?>" <?php if ($formData['employee_id'] === (string) $employee['id']) echo 'selected'; ?>><?php echo htmlspecialchars($employee['employee_name']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>💵 المبلغ</label>
                                <input type="number" name="amount" min="0.01" step="0.01" required value="<?php echo htmlspecialchars($formData['amount']); ?>">
                            </div>

                            <div class="form-actions-row loan-actions-row">
                                <button type="submit" class="btn <?php echo $formData['id'] !== '' ? 'btn-warning' : 'btn-success'; ?>">
                                    <?php echo $formData['id'] !== '' ? '✏️ تعديل' : '➕ إضافة'; ?>
                                </button>
                                <a href="employees_loans.php" class="btn btn-secondary">🧹 جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table loans-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>🧑‍💼 الموظف</th>
                                <th>💵 المبلغ</th>
                                <th>🕒 تاريخ التسجيل</th>
                                <?php if ($isManager) { ?><th>⚙️ الإجراءات</th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($loans) { ?>
                                <?php foreach ($loans as $loan) { ?>
                                    <tr>
                                        <td data-label="#"><?php echo $loan['id']; ?></td>
                                        <td data-label="🧑‍💼 الموظف"><?php echo htmlspecialchars($loan['employee_name']); ?></td>
                                        <td data-label="💵 المبلغ"><span class="amount-badge"><?php echo number_format((float) $loan['amount'], 2); ?></span></td>
                                        <td data-label="🕒 تاريخ التسجيل"><?php echo htmlspecialchars(formatLoanDateTime($loan['created_at'])); ?></td>
                                        <?php if ($isManager) { ?>
                                            <td class="action-cell" data-label="⚙️ الإجراءات">
                                                <a href="employees_loans.php?edit=<?php echo $loan['id']; ?>" class="btn btn-warning">✏️ تعديل</a>
                                                <form method="post" data-confirm-message="حذف السلفة؟">
                                                    <input type="hidden" name="delete_id" value="<?php echo $loan['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                                                </form>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="<?php echo $isManager ? '5' : '4'; ?>">📭 لا توجد سلف مسجلة</td>
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
