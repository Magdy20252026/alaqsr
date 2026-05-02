<?php
require_once "config.php";
requireLogin();

if (!canAccess('expenses')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$isManager = isset($_SESSION['role']) && $_SESSION['role'] === APP_MANAGER_ROLE;
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            description VARCHAR(500) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            recorded_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_expenses_recorded_by (recorded_by),
            CONSTRAINT fk_expenses_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة المصروفات");
}

$settings = getSiteSettings($conn);
$errorMessage = '';
$formData = [
    'id' => '',
    'description' => '',
    'amount' => ''
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $expenseId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, description, amount FROM expenses WHERE id = ?");
    $stmt->execute([$expenseId]);
    $editExpense = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editExpense) {
        $formData = [
            'id' => (string) $editExpense['id'],
            'description' => trim((string) $editExpense['description']),
            'amount' => number_format((float) $editExpense['amount'], 2, '.', '')
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

        $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: expenses.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'amount' => trim($_POST['amount'] ?? '')
    ];

    if ($formData['id'] !== '' && !$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    if ($formData['description'] === '' || $formData['amount'] === '') {
        $errorMessage = 'أدخل البيان والمبلغ';
    } elseif (getTextLength($formData['description']) > 500) {
        $errorMessage = 'البيان يجب ألا يزيد على 500 حرف';
    } elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $formData['amount'])) {
        $errorMessage = 'المبلغ يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } else {
        $amountValue = (float) $formData['amount'];

        if ($amountValue <= 0) {
            $errorMessage = 'المبلغ يجب أن يكون أكبر من صفر';
        } elseif ($amountValue > 99999999.99) {
            $errorMessage = 'المبلغ أكبر من الحد المسموح';
        } elseif ($currentUserId <= 0 && $formData['id'] === '') {
            $errorMessage = 'تعذر تحديد المستخدم الحالي';
        } else {
            $formattedAmount = number_format($amountValue, 2, '.', '');

            if ($formData['id'] === '') {
                $stmt = $conn->prepare("INSERT INTO expenses (description, amount, recorded_by) VALUES (?, ?, ?)");
                $stmt->execute([$formData['description'], $formattedAmount, $currentUserId]);
            } else {
                $stmt = $conn->prepare("UPDATE expenses SET description = ?, amount = ? WHERE id = ?");
                $stmt->execute([$formData['description'], $formattedAmount, (int) $formData['id']]);
            }

            header("Location: expenses.php");
            exit;
        }
    }
}

$expensesStmt = $conn->prepare(
    "SELECT e.id, e.description, e.amount, e.recorded_by, e.created_at, e.updated_at, u.username AS recorded_by_username
     FROM expenses e
     LEFT JOIN users u ON u.id = e.recorded_by
     ORDER BY e.id DESC"
);
$expensesStmt->execute();
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);

$expensesCount = count($expenses);
$totalAmount = 0.0;
$latestEntryValue = '—';

foreach ($expenses as $expenseSummary) {
    $totalAmount += (float) ($expenseSummary['amount'] ?? 0);
}

if ($expenses) {
    $latestEntryValue = formatDateTimeValue($expenses[0]['created_at'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المصروفات</title>
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
                    <h1 class="section-title">🧾 المصروفات</h1>
                </div>

                <div class="loans-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي السجلات</span>
                        <strong class="overview-value"><?php echo $expensesCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي المصروفات</span>
                        <strong class="overview-value"><?php echo number_format($totalAmount, 2); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">آخر تسجيل</span>
                        <strong class="overview-value"><?php echo htmlspecialchars($latestEntryValue); ?></strong>
                    </div>
                </div>

                <?php if ($errorMessage !== '') { ?>
                    <div class="login-error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <div class="loan-management-layout">
                    <section class="loan-form-card">
                        <div class="staff-card-head">
                            <h2><?php echo $formData['id'] !== '' ? 'تعديل مصروف' : 'إضافة مصروف'; ?></h2>
                        </div>

                        <form method="post" class="inline-form expenses-form-grid">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>البيان</label>
                                <textarea name="description" rows="3" required><?php echo htmlspecialchars($formData['description']); ?></textarea>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>المبلغ</label>
                                <input type="number" name="amount" min="0.01" step="0.01" required value="<?php echo htmlspecialchars($formData['amount']); ?>">
                            </div>

                            <div class="form-actions-row loan-actions-row">
                                <button type="submit" class="btn <?php echo $formData['id'] !== '' ? 'btn-warning' : 'btn-success'; ?>">
                                    <?php echo $formData['id'] !== '' ? 'تحديث المصروف' : 'حفظ المصروف'; ?>
                                </button>
                                <a href="expenses.php" class="btn btn-secondary">سجل جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table expenses-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>البيان</th>
                                <th>المبلغ</th>
                                <th>المستخدم</th>
                                <th>تاريخ التسجيل</th>
                                <?php if ($isManager) { ?><th>الإجراءات</th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expenses) { ?>
                                <?php foreach ($expenses as $expense) { ?>
                                    <?php
                                    $recordedByLabel = trim((string) ($expense['recorded_by_username'] ?? ''));
                                    if ($recordedByLabel === '') {
                                        $recordedByLabel = !empty($expense['recorded_by']) ? 'مستخدم محذوف' : '—';
                                    }
                                    ?>
                                    <tr>
                                        <td data-label="#"><?php echo $expense['id']; ?></td>
                                        <td data-label="البيان"><span class="reason-badge"><?php echo htmlspecialchars($expense['description']); ?></span></td>
                                        <td data-label="المبلغ"><span class="amount-badge"><?php echo number_format((float) $expense['amount'], 2); ?></span></td>
                                        <td data-label="المستخدم"><span class="expense-user-badge"><?php echo htmlspecialchars($recordedByLabel); ?></span></td>
                                        <td data-label="تاريخ التسجيل"><?php echo htmlspecialchars(formatDateTimeValue($expense['created_at'])); ?></td>
                                        <?php if ($isManager) { ?>
                                            <td class="action-cell" data-label="الإجراءات">
                                                <a href="expenses.php?edit=<?php echo $expense['id']; ?>" class="btn btn-warning">تعديل</a>
                                                <form method="post" data-confirm-message="حذف المصروف؟">
                                                    <input type="hidden" name="delete_id" value="<?php echo $expense['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <button type="submit" class="btn btn-danger">حذف</button>
                                                </form>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="<?php echo $isManager ? '6' : '5'; ?>">لا توجد مصروفات مسجلة</td>
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
