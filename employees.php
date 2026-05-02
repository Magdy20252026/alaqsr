<?php
require_once "config.php";
requireLogin();

if (!canAccess('employees')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const MYSQL_ERROR_DUPLICATE_COLUMN = 1060;

function getEmployeeWeekDays()
{
    return [
        'saturday' => 'السبت',
        'sunday' => 'الأحد',
        'monday' => 'الاثنين',
        'tuesday' => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday' => 'الخميس',
        'friday' => 'الجمعة'
    ];
}

function normalizeEmployeeOffDays($offDays, $weekDays)
{
    if (!is_array($offDays)) {
        return [];
    }

    $normalized = [];

    foreach ($offDays as $day) {
        $dayKey = trim((string) $day);

        if (isset($weekDays[$dayKey]) && !in_array($dayKey, $normalized, true)) {
            $normalized[] = $dayKey;
        }
    }

    return $normalized;
}

function buildEmployeeTime($hour, $minute, $period)
{
    $hourValue = trim((string) $hour);
    $minuteValue = trim((string) $minute);
    $periodValue = strtoupper(trim((string) $period));

    if (!preg_match('/^(?:[1-9]|1[0-2])$/', $hourValue)) {
        return null;
    }

    if (!preg_match('/^\d{2}$/', $minuteValue) || (int) $minuteValue > 59) {
        return null;
    }

    if (!in_array($periodValue, ['AM', 'PM'], true)) {
        return null;
    }

    return sprintf('%02d:%02d %s', (int) $hourValue, (int) $minuteValue, $periodValue);
}

function splitEmployeeTime($timeValue)
{
    if (is_string($timeValue) && preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s?(AM|PM)$/i', trim($timeValue), $matches)) {
        return [
            'hour' => (string) ((int) $matches[1]),
            'minute' => $matches[2],
            'period' => strtoupper($matches[3])
        ];
    }

    return [
        'hour' => '',
        'minute' => '00',
        'period' => 'AM'
    ];
}

function getEmployeeTextLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
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

    $barcodeColumnStmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'employees'
           AND COLUMN_NAME = 'employee_barcode'
         LIMIT 1"
    );
    $barcodeColumnStmt->execute();

    if (!$barcodeColumnStmt->fetchColumn()) {
        try {
            $conn->exec("ALTER TABLE employees ADD COLUMN employee_barcode VARCHAR(100) NOT NULL DEFAULT '' AFTER employee_number");
        } catch (PDOException $migrationException) {
            $duplicateColumn = ($migrationException->errorInfo[1] ?? null) === MYSQL_ERROR_DUPLICATE_COLUMN;
            if (!$duplicateColumn) {
                throw $migrationException;
            }
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة الموظفين");
}

$settings = getSiteSettings($conn);
$weekDays = getEmployeeWeekDays();
$hours = range(1, 12);
$minutes = [];

for ($minuteValue = 0; $minuteValue <= 59; $minuteValue++) {
    $minutes[] = sprintf('%02d', $minuteValue);
}

$formData = [
    'id' => '',
    'employee_name' => '',
    'employee_number' => '',
    'employee_barcode' => '',
    'attendance_time' => '',
    'departure_time' => '',
    'off_days' => [],
    'salary_amount' => ''
];
$errorMessage = '';
$editMode = false;

if (isset($_GET['edit'])) {
    $employeeId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $editEmployee = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editEmployee) {
        $decodedDays = json_decode($editEmployee['off_days'] ?? '[]', true);
        $formData = [
            'id' => (string) $editEmployee['id'],
            'employee_name' => $editEmployee['employee_name'],
            'employee_number' => $editEmployee['employee_number'],
            'employee_barcode' => $editEmployee['employee_barcode'] ?? '',
            'attendance_time' => $editEmployee['attendance_time'],
            'departure_time' => $editEmployee['departure_time'],
            'off_days' => normalizeEmployeeOffDays($decodedDays, $weekDays),
            'salary_amount' => number_format((float) $editEmployee['salary_amount'], 2, '.', '')
        ];
        $editMode = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    if (isset($_POST['delete_id'])) {
        $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: employees.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'employee_name' => trim($_POST['employee_name'] ?? ''),
        'employee_number' => trim($_POST['employee_number'] ?? ''),
        'employee_barcode' => trim($_POST['employee_barcode'] ?? ''),
        'attendance_time' => buildEmployeeTime($_POST['attendance_hour'] ?? '', $_POST['attendance_minute'] ?? '', $_POST['attendance_period'] ?? ''),
        'departure_time' => buildEmployeeTime($_POST['departure_hour'] ?? '', $_POST['departure_minute'] ?? '', $_POST['departure_period'] ?? ''),
        'off_days' => normalizeEmployeeOffDays($_POST['off_days'] ?? [], $weekDays),
        'salary_amount' => trim($_POST['salary_amount'] ?? '')
    ];
    $editMode = $formData['id'] !== '';

    if ($formData['employee_name'] === '' || $formData['employee_number'] === '') {
        $errorMessage = '⚠️ الاسم ورقم الموظف مطلوبان';
    } elseif (
        getEmployeeTextLength($formData['employee_name']) > 255
        || getEmployeeTextLength($formData['employee_number']) > 100
        || getEmployeeTextLength($formData['employee_barcode']) > 100
    ) {
        $errorMessage = '⚠️ تحقق من طول البيانات المدخلة';
    } elseif ($formData['attendance_time'] === null || $formData['departure_time'] === null) {
        $errorMessage = '⚠️ اختر مواعيد صحيحة بنظام 12 ساعة';
    } elseif (!preg_match('/^\d+(?:\.\d{1,2})?$/', $formData['salary_amount'])) {
        $errorMessage = '⚠️ الراتب يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } else {
        $salaryValue = (float) $formData['salary_amount'];

        if ($salaryValue < 0) {
            $errorMessage = '⚠️ الراتب يجب أن يكون رقمًا موجبًا أو صفر';
        } else {
            $formattedSalary = number_format($salaryValue, 2, '.', '');
            $offDaysJson = json_encode(array_values($formData['off_days']), JSON_UNESCAPED_UNICODE);
            $barcodeValue = $formData['employee_barcode'] !== '' ? $formData['employee_barcode'] : $formData['employee_number'];

            if ($formData['id'] === '') {
                $stmt = $conn->prepare(
                    "INSERT INTO employees (employee_name, employee_number, employee_barcode, attendance_time, departure_time, off_days, salary_amount)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $formData['employee_name'],
                    $formData['employee_number'],
                    $barcodeValue,
                    $formData['attendance_time'],
                    $formData['departure_time'],
                    $offDaysJson,
                    $formattedSalary
                ]);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE employees
                     SET employee_name = ?, employee_number = ?, employee_barcode = ?, attendance_time = ?, departure_time = ?, off_days = ?, salary_amount = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $formData['employee_name'],
                    $formData['employee_number'],
                    $barcodeValue,
                    $formData['attendance_time'],
                    $formData['departure_time'],
                    $offDaysJson,
                    $formattedSalary,
                    (int) $formData['id']
                ]);
            }

            header("Location: employees.php");
            exit;
        }
    }
}

$attendanceParts = splitEmployeeTime($formData['attendance_time']);
$departureParts = splitEmployeeTime($formData['departure_time']);

$employeesStmt = $conn->prepare(
    "SELECT id, employee_name, employee_number, employee_barcode, attendance_time, departure_time, off_days, salary_amount
     FROM employees
     ORDER BY id DESC"
);
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$employeesCount = count($employees);
$salaryTotal = 0.0;
$registeredOffDaysCount = 0;

foreach ($employees as $employeeSummary) {
    $salaryTotal += (float) ($employeeSummary['salary_amount'] ?? 0);
    $registeredOffDays = json_decode($employeeSummary['off_days'] ?? '[]', true);
    $registeredOffDaysCount += count(normalizeEmployeeOffDays($registeredOffDays, $weekDays));
}

$averageSalary = $employeesCount > 0 ? $salaryTotal / $employeesCount : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الموظفين</title>
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

            <div class="content-card">
                <div class="page-header">
                    <div>
                        <h1 class="section-title">🧑‍💼 الموظفين</h1>
                        <p class="barbers-page-subtitle">إدارة بيانات الموظفين، مواعيدهم، وأيام الإجازة مع حفظ الرواتب من واجهة واضحة وسهلة الاستخدام.</p>
                    </div>
                </div>

                <div class="barbers-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الموظفين</span>
                        <strong class="overview-value"><?php echo $employeesCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">متوسط الراتب</span>
                        <strong class="overview-value"><?php echo number_format($averageSalary, 2); ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">أيام إجازة مسجلة</span>
                        <strong class="overview-value"><?php echo $registeredOffDaysCount; ?></strong>
                    </div>
                </div>

                <?php if ($errorMessage !== '') { ?>
                    <div class="login-error-box"><?php echo $errorMessage; ?></div>
                <?php } ?>

                <div class="barbers-management-layout">
                    <section class="barber-form-card">
                        <div class="barber-card-head">
                            <h2><?php echo $editMode ? 'تعديل بيانات الموظف' : 'إضافة موظف جديد'; ?></h2>
                            <p>أدخل البيانات الأساسية وحدد أكثر من يوم إجازة بسهولة، مع حفظ الراتب وباركود الموظف.</p>
                        </div>

                        <form method="post" class="inline-form barbers-form-grid">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>👤 اسم الموظف</label>
                                <input type="text" name="employee_name" required value="<?php echo htmlspecialchars($formData['employee_name']); ?>">
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🔢 رقم الموظف</label>
                                <input type="text" name="employee_number" required value="<?php echo htmlspecialchars($formData['employee_number']); ?>">
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🏷️ باركود الموظف</label>
                                <input type="text" name="employee_barcode" value="<?php echo htmlspecialchars($formData['employee_barcode']); ?>" placeholder="يُستخدم رقم الموظف تلقائيًا إذا تُرك فارغًا">
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🕘 ميعاد الحضور</label>
                                <div class="time-selects">
                                    <select name="attendance_hour" required>
                                        <option value="">الساعة</option>
                                        <?php foreach ($hours as $hour) { ?>
                                            <option value="<?php echo $hour; ?>" <?php if ($attendanceParts['hour'] === (string) $hour) echo 'selected'; ?>><?php echo $hour; ?></option>
                                        <?php } ?>
                                    </select>
                                    <select name="attendance_minute" required>
                                        <?php foreach ($minutes as $minute) { ?>
                                            <option value="<?php echo $minute; ?>" <?php if ($attendanceParts['minute'] === $minute) echo 'selected'; ?>><?php echo $minute; ?></option>
                                        <?php } ?>
                                    </select>
                                    <select name="attendance_period" required>
                                        <option value="AM" <?php if ($attendanceParts['period'] === 'AM') echo 'selected'; ?>>صباحًا</option>
                                        <option value="PM" <?php if ($attendanceParts['period'] === 'PM') echo 'selected'; ?>>مساءً</option>
                                    </select>
                                </div>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🌙 ميعاد الانصراف</label>
                                <div class="time-selects">
                                    <select name="departure_hour" required>
                                        <option value="">الساعة</option>
                                        <?php foreach ($hours as $hour) { ?>
                                            <option value="<?php echo $hour; ?>" <?php if ($departureParts['hour'] === (string) $hour) echo 'selected'; ?>><?php echo $hour; ?></option>
                                        <?php } ?>
                                    </select>
                                    <select name="departure_minute" required>
                                        <?php foreach ($minutes as $minute) { ?>
                                            <option value="<?php echo $minute; ?>" <?php if ($departureParts['minute'] === $minute) echo 'selected'; ?>><?php echo $minute; ?></option>
                                        <?php } ?>
                                    </select>
                                    <select name="departure_period" required>
                                        <option value="AM" <?php if ($departureParts['period'] === 'AM') echo 'selected'; ?>>صباحًا</option>
                                        <option value="PM" <?php if ($departureParts['period'] === 'PM') echo 'selected'; ?>>مساءً</option>
                                    </select>
                                </div>
                            </div>

                            <div class="field-group horizontal-field barber-wide-field">
                                <label>🗓️ أيام الإجازة</label>
                                <div class="off-days-panel">
                                    <div class="off-days-panel-head">
                                        <strong>اختر يومًا أو أكثر</strong>
                                        <span>يمكن تحديد أكثر من يوم إجازة للموظف من خلال البطاقات التالية.</span>
                                    </div>
                                    <div class="off-days-grid">
                                        <?php foreach ($weekDays as $dayKey => $dayLabel) { ?>
                                            <label class="off-day-option">
                                                <input type="checkbox" name="off_days[]" value="<?php echo $dayKey; ?>" <?php if (in_array($dayKey, $formData['off_days'], true)) echo 'checked'; ?>>
                                                <span class="off-day-pill">
                                                    <span class="off-day-check">✓</span>
                                                    <span class="off-day-text"><?php echo htmlspecialchars($dayLabel); ?></span>
                                                </span>
                                            </label>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>💵 الراتب</label>
                                <input type="number" name="salary_amount" min="0" step="0.01" required value="<?php echo htmlspecialchars($formData['salary_amount']); ?>">
                            </div>

                            <div class="form-actions-row barbers-actions-row">
                                <button type="submit" class="btn <?php echo $editMode ? 'btn-warning' : 'btn-success'; ?>">
                                    <?php echo $editMode ? '✏️ تعديل' : '➕ إضافة'; ?>
                                </button>
                                <a href="employees.php" class="btn btn-secondary">🧹 جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table barbers-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>👤 اسم الموظف</th>
                                <th>🔢 رقم الموظف</th>
                                <th>🏷️ الباركود</th>
                                <th>🕘 الحضور</th>
                                <th>🌙 الانصراف</th>
                                <th>🗓️ الإجازة</th>
                                <th>💵 الراتب</th>
                                <th>⚙️ الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees) { ?>
                                <?php foreach ($employees as $employee) { ?>
                                    <?php
                                    $offDays = json_decode($employee['off_days'] ?? '[]', true);
                                    $offDays = normalizeEmployeeOffDays($offDays, $weekDays);
                                    ?>
                                    <tr>
                                        <td data-label="#"><?php echo $employee['id']; ?></td>
                                        <td data-label="👤 اسم الموظف"><?php echo htmlspecialchars($employee['employee_name']); ?></td>
                                        <td data-label="🔢 رقم الموظف"><?php echo htmlspecialchars($employee['employee_number']); ?></td>
                                        <td data-label="🏷️ الباركود"><?php echo htmlspecialchars($employee['employee_barcode'] ?? ''); ?></td>
                                        <td data-label="🕘 الحضور"><?php echo htmlspecialchars($employee['attendance_time']); ?></td>
                                        <td data-label="🌙 الانصراف"><?php echo htmlspecialchars($employee['departure_time']); ?></td>
                                        <td data-label="🗓️ الإجازة">
                                            <?php if ($offDays) { ?>
                                                <div class="day-badges">
                                                    <?php foreach ($offDays as $dayKey) { ?>
                                                        <span class="day-badge"><?php echo htmlspecialchars($weekDays[$dayKey]); ?></span>
                                                    <?php } ?>
                                                </div>
                                            <?php } else { ?>
                                                <span class="day-badge empty-badge">لا يوجد</span>
                                            <?php } ?>
                                        </td>
                                        <td data-label="💵 الراتب"><?php echo number_format((float) $employee['salary_amount'], 2); ?></td>
                                        <td class="action-cell" data-label="⚙️ الإجراءات">
                                            <a href="employees.php?edit=<?php echo $employee['id']; ?>" class="btn btn-warning">✏️ تعديل</a>
                                            <form method="post" data-confirm-message="حذف الموظف &quot;<?php echo htmlspecialchars($employee['employee_name']); ?>&quot;؟">
                                                <input type="hidden" name="delete_id" value="<?php echo $employee['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="9">📭 لا يوجد موظفون مسجلون</td>
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
