<?php
require_once "config.php";
requireLogin();

if (!canAccess('barbers')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function getBarberWeekDays()
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

function normalizeBarberOffDays($offDays, $weekDays)
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

function buildBarberTime($hour, $minute, $period)
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

function splitBarberTime($timeValue)
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

function getBarberTextLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
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

    $barcodeColumnStmt = $conn->query("SHOW COLUMNS FROM barbers LIKE 'barber_barcode'");
    if (!$barcodeColumnStmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            $conn->exec("ALTER TABLE barbers ADD COLUMN barber_barcode VARCHAR(100) NOT NULL DEFAULT '' AFTER barber_number");
        } catch (PDOException $migrationException) {
            $duplicateColumn = ($migrationException->errorInfo[1] ?? null) === 1060;
            if (!$duplicateColumn) {
                throw $migrationException;
            }
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة الحلاقين");
}

$settings = getSiteSettings($conn);
$weekDays = getBarberWeekDays();
$hours = range(1, 12);
$minutes = [];

for ($minuteValue = 0; $minuteValue <= 59; $minuteValue++) {
    $minutes[] = sprintf('%02d', $minuteValue);
}

$formData = [
    'id' => '',
    'barber_name' => '',
    'barber_number' => '',
    'barber_barcode' => '',
    'attendance_time' => '',
    'departure_time' => '',
    'off_days' => [],
    'commission_percent' => ''
];
$errorMessage = '';
$editMode = false;

if (isset($_GET['edit'])) {
    $barberId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM barbers WHERE id = ?");
    $stmt->execute([$barberId]);
    $editBarber = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editBarber) {
        $decodedDays = json_decode($editBarber['off_days'] ?? '[]', true);
        $formData = [
            'id' => (string) $editBarber['id'],
            'barber_name' => $editBarber['barber_name'],
            'barber_number' => $editBarber['barber_number'],
            'barber_barcode' => $editBarber['barber_barcode'] ?? '',
            'attendance_time' => $editBarber['attendance_time'],
            'departure_time' => $editBarber['departure_time'],
            'off_days' => normalizeBarberOffDays($decodedDays, $weekDays),
            'commission_percent' => number_format((float) $editBarber['commission_percent'], 2, '.', '')
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
        $stmt = $conn->prepare("DELETE FROM barbers WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: barbers.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'barber_name' => trim($_POST['barber_name'] ?? ''),
        'barber_number' => trim($_POST['barber_number'] ?? ''),
        'barber_barcode' => trim($_POST['barber_barcode'] ?? ''),
        'attendance_time' => buildBarberTime($_POST['attendance_hour'] ?? '', $_POST['attendance_minute'] ?? '', $_POST['attendance_period'] ?? ''),
        'departure_time' => buildBarberTime($_POST['departure_hour'] ?? '', $_POST['departure_minute'] ?? '', $_POST['departure_period'] ?? ''),
        'off_days' => normalizeBarberOffDays($_POST['off_days'] ?? [], $weekDays),
        'commission_percent' => trim($_POST['commission_percent'] ?? '')
    ];
    if ($formData['barber_barcode'] === '') {
        $formData['barber_barcode'] = $formData['barber_number'];
    }
    $editMode = $formData['id'] !== '';

    if ($formData['barber_name'] === '' || $formData['barber_number'] === '') {
        $errorMessage = '⚠️ الاسم ورقم الحلاق مطلوبان';
    } elseif (
        getBarberTextLength($formData['barber_name']) > 255
        || getBarberTextLength($formData['barber_number']) > 100
        || getBarberTextLength($formData['barber_barcode']) > 100
    ) {
        $errorMessage = '⚠️ تحقق من طول البيانات المدخلة';
    } elseif ($formData['attendance_time'] === null || $formData['departure_time'] === null) {
        $errorMessage = '⚠️ اختر مواعيد صحيحة بنظام 12 ساعة';
    } elseif (!preg_match('/^\d+(?:\.\d{1,2})?$/', $formData['commission_percent'])) {
        $errorMessage = '⚠️ النسبة يجب أن تكون رقمًا صحيحًا أو عشريًا';
    } else {
        $commissionValue = (float) $formData['commission_percent'];

        if ($commissionValue < 0 || $commissionValue > 100) {
            $errorMessage = '⚠️ النسبة يجب أن تكون بين 0 و 100';
        } else {
            $formattedCommission = number_format($commissionValue, 2, '.', '');
            $offDaysJson = json_encode(array_values($formData['off_days']), JSON_UNESCAPED_UNICODE);

            if ($formData['id'] === '') {
                $stmt = $conn->prepare(
                    "INSERT INTO barbers (barber_name, barber_number, barber_barcode, attendance_time, departure_time, off_days, commission_percent)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $formData['barber_name'],
                    $formData['barber_number'],
                    $formData['barber_barcode'],
                    $formData['attendance_time'],
                    $formData['departure_time'],
                    $offDaysJson,
                    $formattedCommission
                ]);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE barbers
                     SET barber_name = ?, barber_number = ?, barber_barcode = ?, attendance_time = ?, departure_time = ?, off_days = ?, commission_percent = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $formData['barber_name'],
                    $formData['barber_number'],
                    $formData['barber_barcode'],
                    $formData['attendance_time'],
                    $formData['departure_time'],
                    $offDaysJson,
                    $formattedCommission,
                    (int) $formData['id']
                ]);
            }

            header("Location: barbers.php");
            exit;
        }
    }
}

$attendanceParts = splitBarberTime($formData['attendance_time']);
$departureParts = splitBarberTime($formData['departure_time']);

$barbersStmt = $conn->prepare(
    "SELECT id, barber_name, barber_number, barber_barcode, attendance_time, departure_time, off_days, commission_percent
     FROM barbers
     ORDER BY id DESC"
);
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);

$barbersCount = count($barbers);
$commissionPercentTotal = 0.0;
$registeredOffDaysCount = 0;

foreach ($barbers as $barberSummary) {
    $commissionPercentTotal += (float) ($barberSummary['commission_percent'] ?? 0);
    $registeredOffDays = json_decode($barberSummary['off_days'] ?? '[]', true);
    if (!is_array($registeredOffDays)) {
        $registeredOffDays = [];
    }
    $registeredOffDaysCount += count(normalizeBarberOffDays($registeredOffDays, $weekDays));
}

$averageCommission = $barbersCount > 0 ? $commissionPercentTotal / $barbersCount : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الحلاقين</title>
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
                        <h1 class="section-title">💈 الحلاقين</h1>
                        <p class="barbers-page-subtitle">إدارة بيانات الحلاقين، مواعيدهم، وأيام الإجازة من واجهة أوضح وأكثر احترافية.</p>
                    </div>
                </div>

                <div class="barbers-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الحلاقين</span>
                        <strong class="overview-value"><?php echo $barbersCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">متوسط النسبة</span>
                        <strong class="overview-value"><?php echo number_format($averageCommission, 2); ?>%</strong>
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
                            <h2><?php echo $editMode ? 'تعديل بيانات الحلاق' : 'إضافة حلاق جديد'; ?></h2>
                            <p>أدخل البيانات الأساسية وحدد أكثر من يوم إجازة بسهولة من الاختيارات التالية.</p>
                        </div>

                        <form method="post" class="inline-form barbers-form-grid">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>👤 اسم الحلاق</label>
                                <input type="text" name="barber_name" required value="<?php echo htmlspecialchars($formData['barber_name']); ?>">
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🔢 رقم الحلاق</label>
                                <input type="text" name="barber_number" required value="<?php echo htmlspecialchars($formData['barber_number']); ?>">
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🏷️ باركود الحلاق</label>
                                <input type="text" name="barber_barcode" value="<?php echo htmlspecialchars($formData['barber_barcode']); ?>" placeholder="يُستخدم رقم الحلاق تلقائيًا إذا تُرك فارغًا">
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
                                        <span>يمكن تحديد أكثر من يوم إجازة للحلاق من خلال البطاقات التالية.</span>
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
                                <label>💯 النسبة المئوية</label>
                                <input type="number" name="commission_percent" min="0" max="100" step="0.01" required value="<?php echo htmlspecialchars($formData['commission_percent']); ?>">
                            </div>

                            <div class="form-actions-row barbers-actions-row">
                                <button type="submit" class="btn <?php echo $editMode ? 'btn-warning' : 'btn-success'; ?>">
                                    <?php echo $editMode ? '✏️ تعديل' : '➕ إضافة'; ?>
                                </button>
                                <a href="barbers.php" class="btn btn-secondary">🧹 جديد</a>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap">
                    <table class="data-table barbers-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>👤 اسم الحلاق</th>
                                <th>🔢 رقم الحلاق</th>
                                <th>🏷️ الباركود</th>
                                <th>🕘 الحضور</th>
                                <th>🌙 الانصراف</th>
                                <th>🗓️ الإجازة</th>
                                <th>💯 النسبة</th>
                                <th>⚙️ الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($barbers) { ?>
                                <?php foreach ($barbers as $barber) { ?>
                                    <?php
                                    $offDays = json_decode($barber['off_days'] ?? '[]', true);
                                    $offDays = normalizeBarberOffDays($offDays, $weekDays);
                                    ?>
                                    <tr>
                                        <td data-label="#"><?php echo $barber['id']; ?></td>
                                        <td data-label="👤 اسم الحلاق"><?php echo htmlspecialchars($barber['barber_name']); ?></td>
                                        <td data-label="🔢 رقم الحلاق"><?php echo htmlspecialchars($barber['barber_number']); ?></td>
                                        <td data-label="🏷️ الباركود"><?php echo htmlspecialchars($barber['barber_barcode'] ?? ''); ?></td>
                                        <td data-label="🕘 الحضور"><?php echo htmlspecialchars($barber['attendance_time']); ?></td>
                                        <td data-label="🌙 الانصراف"><?php echo htmlspecialchars($barber['departure_time']); ?></td>
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
                                        <td data-label="💯 النسبة"><?php echo number_format((float) $barber['commission_percent'], 2); ?>%</td>
                                        <td class="action-cell" data-label="⚙️ الإجراءات">
                                            <a href="barbers.php?edit=<?php echo $barber['id']; ?>" class="btn btn-warning">✏️ تعديل</a>
                                            <form method="post" data-confirm-message="حذف الحلاق &quot;<?php echo htmlspecialchars($barber['barber_name']); ?>&quot;؟">
                                                <input type="hidden" name="delete_id" value="<?php echo $barber['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="9">📭 لا يوجد حلاقون مسجلون</td>
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
