<?php
require_once "config.php";
requireLogin();

if (!canAccess('barbers_loans')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة سلف الحلاقين");
}

$settings = getSiteSettings($conn);
$errorMessage = '';
$editLoan = null;
$formData = [
    'id' => '',
    'barber_id' => '',
    'amount' => ''
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $loanId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, barber_id, amount FROM barbers_loans WHERE id = ?");
    $stmt->execute([$loanId]);
    $editLoan = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editLoan) {
        $formData = [
            'id' => (string) $editLoan['id'],
            'barber_id' => (string) $editLoan['barber_id'],
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

        $stmt = $conn->prepare("DELETE FROM barbers_loans WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: barbers_loans.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'barber_id' => trim($_POST['barber_id'] ?? ''),
        'amount' => trim($_POST['amount'] ?? '')
    ];

    if ($formData['id'] !== '' && !$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    if ($formData['barber_id'] === '' || $formData['amount'] === '') {
        $errorMessage = '⚠️ اختر الحلاق وأدخل المبلغ';
    } elseif (!ctype_digit($formData['barber_id'])) {
        $errorMessage = '⚠️ اختر حلاقًا صحيحًا';
    } elseif (getTextLength($formData['amount']) > 20 || !preg_match('/^\d+(?:\.\d{1,2})?$/', $formData['amount'])) {
        $errorMessage = '⚠️ المبلغ يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } else {
        $barberCheckStmt = $conn->prepare("SELECT barber_name FROM barbers WHERE id = ? LIMIT 1");
        $barberCheckStmt->execute([(int) $formData['barber_id']]);
        $barberExists = $barberCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$barberExists) {
            $errorMessage = '⚠️ الحلاق غير موجود';
        } else {
            $amountValue = (float) $formData['amount'];

            if ($amountValue <= 0) {
                $errorMessage = '⚠️ المبلغ يجب أن يكون أكبر من صفر';
            } else {
                $formattedAmount = number_format($amountValue, 2, '.', '');

                if ($formData['id'] === '') {
                    $stmt = $conn->prepare("INSERT INTO barbers_loans (barber_id, amount) VALUES (?, ?)");
                    $stmt->execute([(int) $formData['barber_id'], $formattedAmount]);
                } else {
                    $stmt = $conn->prepare("UPDATE barbers_loans SET barber_id = ?, amount = ? WHERE id = ?");
                    $stmt->execute([(int) $formData['barber_id'], $formattedAmount, (int) $formData['id']]);
                }

                header("Location: barbers_loans.php");
                exit;
            }
        }
    }
}

$barbersStmt = $conn->prepare("SELECT id, barber_name FROM barbers ORDER BY barber_name ASC");
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);

$loansStmt = $conn->prepare(
    "SELECT bl.id, bl.barber_id, bl.amount, bl.created_at, bl.updated_at, b.barber_name
     FROM barbers_loans bl
     INNER JOIN barbers b ON b.id = bl.barber_id
     ORDER BY bl.id DESC"
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
    <title>سلف الحلاقين</title>
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
                    <h1 class="section-title">💵 سلف الحلاقين</h1>
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
                                <label>💈 الحلاق</label>
                                <select name="barber_id" required>
                                    <option value="">اختر الحلاق</option>
                                    <?php foreach ($barbers as $barber) { ?>
                                        <option value="<?php echo $barber['id']; ?>" <?php if ($formData['barber_id'] === (string) $barber['id']) echo 'selected'; ?>><?php echo htmlspecialchars($barber['barber_name']); ?></option>
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
                                <a href="barbers_loans.php" class="btn btn-secondary">🧹 جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table loans-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>💈 الحلاق</th>
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
                                        <td data-label="💈 الحلاق"><?php echo htmlspecialchars($loan['barber_name']); ?></td>
                                        <td data-label="💵 المبلغ"><span class="amount-badge"><?php echo number_format((float) $loan['amount'], 2); ?></span></td>
                                        <td data-label="🕒 تاريخ التسجيل"><?php echo htmlspecialchars(formatDateTimeValue($loan['created_at'])); ?></td>
                                        <?php if ($isManager) { ?>
                                            <td class="action-cell" data-label="⚙️ الإجراءات">
                                                <a href="barbers_loans.php?edit=<?php echo $loan['id']; ?>" class="btn btn-warning">✏️ تعديل</a>
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
