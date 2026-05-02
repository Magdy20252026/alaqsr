<?php
require_once "config.php";
requireLogin();

if (!canAccess('barbers_deductions')) {
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

    try {
        $conn->exec("ALTER TABLE barbers_deductions ADD COLUMN reason VARCHAR(500) NOT NULL DEFAULT '' AFTER amount");
    } catch (PDOException $exception) {
        if ((int) $exception->errorInfo[1] !== MYSQL_ERROR_DUPLICATE_COLUMN) {
            throw $exception;
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة خصومات الحلاقين");
}

$settings = getSiteSettings($conn);
$errorMessage = '';
$editDeduction = null;
$formData = [
    'id' => '',
    'barber_id' => '',
    'amount' => '',
    'reason' => ''
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $deductionId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, barber_id, amount, reason FROM barbers_deductions WHERE id = ?");
    $stmt->execute([$deductionId]);
    $editDeduction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editDeduction) {
        $formData = [
            'id' => (string) $editDeduction['id'],
            'barber_id' => (string) $editDeduction['barber_id'],
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

        $stmt = $conn->prepare("DELETE FROM barbers_deductions WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: barbers_deductions.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'barber_id' => trim($_POST['barber_id'] ?? ''),
        'amount' => trim($_POST['amount'] ?? ''),
        'reason' => trim($_POST['reason'] ?? '')
    ];

    if ($formData['id'] !== '' && !$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    if ($formData['barber_id'] === '' || $formData['amount'] === '' || $formData['reason'] === '') {
        $errorMessage = 'اختر الحلاق وأدخل المبلغ وسبب الخصم';
    } elseif (!ctype_digit($formData['barber_id'])) {
        $errorMessage = 'اختر حلاقًا صحيحًا';
    } elseif (getTextLength($formData['amount']) > 20 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $formData['amount'])) {
        $errorMessage = 'المبلغ يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } elseif (getTextLength($formData['reason']) > 500) {
        $errorMessage = 'سبب الخصم يجب ألا يزيد على 500 حرف';
    } else {
        $barberCheckStmt = $conn->prepare("SELECT barber_name FROM barbers WHERE id = ? LIMIT 1");
        $barberCheckStmt->execute([(int) $formData['barber_id']]);
        $barberExists = $barberCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$barberExists) {
            $errorMessage = 'الحلاق غير موجود';
        } else {
            $amountValue = (float) $formData['amount'];

            if ($amountValue <= 0) {
                $errorMessage = 'المبلغ يجب أن يكون أكبر من صفر';
            } else {
                $formattedAmount = number_format($amountValue, 2, '.', '');

                if ($formData['id'] === '') {
                    $stmt = $conn->prepare("INSERT INTO barbers_deductions (barber_id, amount, reason) VALUES (?, ?, ?)");
                    $stmt->execute([(int) $formData['barber_id'], $formattedAmount, $formData['reason']]);
                } else {
                    $stmt = $conn->prepare("UPDATE barbers_deductions SET barber_id = ?, amount = ?, reason = ? WHERE id = ?");
                    $stmt->execute([(int) $formData['barber_id'], $formattedAmount, $formData['reason'], (int) $formData['id']]);
                }

                header("Location: barbers_deductions.php");
                exit;
            }
        }
    }
}

$barbersStmt = $conn->prepare("SELECT id, barber_name FROM barbers ORDER BY barber_name ASC");
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);

$deductionsStmt = $conn->prepare(
    "SELECT bd.id, bd.barber_id, bd.amount, bd.reason, bd.created_at, bd.updated_at, b.barber_name
     FROM barbers_deductions bd
     INNER JOIN barbers b ON b.id = bd.barber_id
     ORDER BY bd.id DESC"
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
    <title>خصومات الحلاقين</title>
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
                        <h1 class="section-title">خصومات الحلاقين</h1>
                        <p class="page-subtitle">إدارة تسجيل خصومات الحلاقين وعرض السجل الكامل.</p>
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
                        <div class="barber-card-head">
                            <h2><?php echo $formData['id'] !== '' ? 'تعديل الخصم' : 'إضافة خصم'; ?></h2>
                        </div>

                        <form method="post" class="inline-form deduction-form-grid">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>الحلاق</label>
                                <select name="barber_id" required>
                                    <option value="">اختر الحلاق</option>
                                    <?php foreach ($barbers as $barber) { ?>
                                        <option value="<?php echo $barber['id']; ?>" <?php if ($formData['barber_id'] === (string) $barber['id']) echo 'selected'; ?>><?php echo htmlspecialchars($barber['barber_name']); ?></option>
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
                                <a href="barbers_deductions.php" class="btn btn-secondary">سجل جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table deductions-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الحلاق</th>
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
                                        <td data-label="الحلاق"><?php echo htmlspecialchars($deduction['barber_name']); ?></td>
                                        <td data-label="المبلغ"><span class="amount-badge"><?php echo number_format((float) $deduction['amount'], 2); ?></span></td>
                                        <td data-label="سبب الخصم"><span class="reason-badge"><?php echo nl2br(htmlspecialchars($deduction['reason'])); ?></span></td>
                                        <td data-label="تاريخ التسجيل"><?php echo htmlspecialchars(formatDateTimeValue($deduction['created_at'])); ?></td>
                                        <td data-label="آخر تحديث"><?php echo htmlspecialchars(formatDateTimeValue($deduction['updated_at'])); ?></td>
                                        <?php if ($isManager) { ?>
                                            <td class="action-cell" data-label="الإجراءات">
                                                <a href="barbers_deductions.php?edit=<?php echo $deduction['id']; ?>" class="btn btn-warning">تعديل</a>
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
