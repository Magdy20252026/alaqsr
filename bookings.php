<?php
require_once "config.php";
requireLogin();

if (!canAccess('appointments')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const APPOINTMENT_TIME_STEP_SECONDS = 900;

function roundTimestampToNextInterval($timestamp, $intervalSeconds)
{
    return (int) (ceil(($timestamp + $intervalSeconds) / $intervalSeconds) * $intervalSeconds);
}

function isAppointmentPhoneValue($value)
{
    return is_string($value) && preg_match('/^(?=.*\p{N})[\p{N}\+\-\s\(\)]+$/u', $value) === 1;
}

function normalizeAppointmentTime($value)
{
    $value = trim((string) $value);

    if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
        return '';
    }

    $time = DateTimeImmutable::createFromFormat('H:i', $value, new DateTimeZone(APP_TIMEZONE));

    if (!$time || $time->format('H:i') !== $value) {
        return '';
    }

    return $value;
}

function getDefaultAppointmentTime()
{
    $timezone = new DateTimeZone(APP_TIMEZONE);
    $now = new DateTimeImmutable('now', $timezone);
    $roundedTimestamp = roundTimestampToNextInterval($now->getTimestamp(), APPOINTMENT_TIME_STEP_SECONDS);
    $candidate = (new DateTimeImmutable('@' . $roundedTimestamp))->setTimezone($timezone);

    if ($candidate->format('Y-m-d') !== $now->format('Y-m-d')) {
        return '23:45';
    }

    return $candidate->format('H:i');
}

function formatAppointmentTimeLabel($value)
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, new DateTimeZone(APP_TIMEZONE));

    if (!$date) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', (string) $value, new DateTimeZone(APP_TIMEZONE));
    }

    if (!$date) {
        return '—';
    }

    return $date->format('H:i');
}

function getAppointmentMessage($key)
{
    $messages = [
        'created' => 'تم حفظ الحجز بنجاح',
        'arrived' => 'تم تسجيل وصول العميل',
        'deleted' => 'تم حذف الحجز بنجاح'
    ];

    return $messages[$key] ?? '';
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
        "CREATE TABLE IF NOT EXISTS appointments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NOT NULL,
            barber_id INT UNSIGNED NOT NULL,
            barber_name VARCHAR(255) NOT NULL,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            appointment_at DATETIME NOT NULL,
            is_arrived TINYINT(1) NOT NULL DEFAULT 0,
            arrived_at DATETIME DEFAULT NULL,
            created_by_user_id INT UNSIGNED DEFAULT NULL,
            created_by_username VARCHAR(255) NOT NULL DEFAULT '',
            admin_created_notified_at DATETIME DEFAULT NULL,
            admin_reminder_notified_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_appointments_date (appointment_date),
            INDEX idx_appointments_datetime (appointment_at),
            INDEX idx_appointments_arrived (is_arrived)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة الحجوزات");
}

$settings = getSiteSettings($conn);
$isManager = (($_SESSION['role'] ?? '') === APP_MANAGER_ROLE);
$todayDate = date('Y-m-d');
$now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
$errorMessage = '';
$successMessage = getAppointmentMessage($_GET['message'] ?? '');

$barbersStmt = $conn->prepare("SELECT id, barber_name FROM barbers ORDER BY barber_name ASC");
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);

$barbersById = [];
foreach ($barbers as $barber) {
    $barbersById[(int) $barber['id']] = $barber;
}

$formData = [
    'customer_name' => '',
    'customer_phone' => '',
    'barber_id' => '',
    'appointment_time' => getDefaultAppointmentTime()
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    if (isset($_POST['mark_arrived_id'])) {
        $appointmentId = (int) $_POST['mark_arrived_id'];
        $arrivedStmt = $conn->prepare(
            "UPDATE appointments
             SET is_arrived = 1, arrived_at = NOW()
             WHERE id = ? AND appointment_date = ? AND is_arrived = 0"
        );
        $arrivedStmt->execute([$appointmentId, $todayDate]);
        header("Location: bookings.php?message=arrived");
        exit;
    }

    if (isset($_POST['delete_booking_id'])) {
        if (!$isManager) {
            http_response_code(403);
            die("غير مصرح");
        }

        $deleteStmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
        $deleteStmt->execute([(int) $_POST['delete_booking_id']]);
        header("Location: bookings.php?message=deleted");
        exit;
    }

    $formData = [
        'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
        'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
        'barber_id' => trim((string) ($_POST['barber_id'] ?? '')),
        'appointment_time' => normalizeAppointmentTime($_POST['appointment_time'] ?? '')
    ];

    if (!$barbers) {
        $errorMessage = 'يجب تسجيل الحلاقين أولاً';
    } elseif ($formData['customer_name'] === '') {
        $errorMessage = 'اكتب اسم العميل';
    } elseif (getTextLength($formData['customer_name']) > 255) {
        $errorMessage = 'اسم العميل طويل جدًا';
    } elseif ($formData['customer_phone'] === '') {
        $errorMessage = 'اكتب رقم الهاتف';
    } elseif (getTextLength($formData['customer_phone']) > 50) {
        $errorMessage = 'رقم الهاتف طويل جدًا';
    } elseif (!isAppointmentPhoneValue($formData['customer_phone'])) {
        $errorMessage = 'رقم الهاتف غير صالح';
    } elseif ($formData['barber_id'] === '' || !isset($barbersById[(int) $formData['barber_id']])) {
        $errorMessage = 'اختر الحلاق';
    } elseif ($formData['appointment_time'] === '') {
        $errorMessage = 'اختر وقت الموعد';
    } else {
        $appointmentAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $todayDate . ' ' . $formData['appointment_time'],
            new DateTimeZone(APP_TIMEZONE)
        );

        if (!$appointmentAt) {
            $errorMessage = 'وقت الموعد غير صالح';
        } elseif ($appointmentAt->getTimestamp() < $now->getTimestamp()) {
            $errorMessage = 'وقت الموعد يجب أن يكون الآن أو لاحقًا';
        } else {
            try {
                $insertStmt = $conn->prepare(
                    "INSERT INTO appointments (
                        customer_name,
                        customer_phone,
                        barber_id,
                        barber_name,
                        appointment_date,
                        appointment_time,
                        appointment_at,
                        created_by_user_id,
                        created_by_username
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $insertStmt->execute([
                    $formData['customer_name'],
                    $formData['customer_phone'],
                    (int) $formData['barber_id'],
                    $barbersById[(int) $formData['barber_id']]['barber_name'],
                    $todayDate,
                    $appointmentAt->format('H:i:s'),
                    $appointmentAt->format('Y-m-d H:i:s'),
                    isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                    (string) ($_SESSION['username'] ?? '')
                ]);
                header("Location: bookings.php?message=created");
                exit;
            } catch (PDOException $e) {
                $errorMessage = 'تعذر حفظ الحجز';
            }
        }
    }
}

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN is_arrived = 0 THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN is_arrived = 1 THEN 1 ELSE 0 END) AS arrived_count,
        SUM(CASE WHEN is_arrived = 0 AND appointment_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS upcoming_count
     FROM appointments
     WHERE appointment_date = ?"
);
$summaryStmt->execute([
    $now->format('Y-m-d H:i:s'),
    $now->modify('+15 minutes')->format('Y-m-d H:i:s'),
    $todayDate
]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_count' => 0,
    'waiting_count' => 0,
    'arrived_count' => 0,
    'upcoming_count' => 0
];

$adminSignals = [
    'new_count' => 0,
    'reminder_count' => 0
];

if ($isManager) {
    $adminSignalsStmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN is_arrived = 0 AND admin_created_notified_at IS NULL THEN 1 ELSE 0 END) AS new_count,
            SUM(CASE WHEN is_arrived = 0 AND admin_reminder_notified_at IS NULL AND appointment_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS reminder_count
         FROM appointments
         WHERE appointment_date = ?"
    );
    $adminSignalsStmt->execute([
        $now->format('Y-m-d H:i:s'),
        $now->modify('+15 minutes')->format('Y-m-d H:i:s'),
        $todayDate
    ]);
    $adminSignals = $adminSignalsStmt->fetch(PDO::FETCH_ASSOC) ?: $adminSignals;
}

$appointmentsStmt = $conn->prepare(
    "SELECT id, customer_name, customer_phone, barber_name, appointment_at, created_by_username, created_at
     FROM appointments
     WHERE appointment_date = ? AND is_arrived = 0
     ORDER BY appointment_at ASC, id ASC"
);
$appointmentsStmt->execute([$todayDate]);
$appointments = $appointmentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الحجوزات</title>
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

            <div class="content-card booking-content-card">
                <div class="page-header booking-page-header">
                    <div>
                        <h1 class="section-title">📅 الحجوزات</h1>
                    </div>
                    <div class="booking-date-pill"><?php echo htmlspecialchars($todayDate); ?></div>
                </div>

                <?php if ($successMessage !== '') { ?>
                    <div class="status-box status-box-success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php } ?>

                <?php if ($errorMessage !== '') { ?>
                    <div class="status-box status-box-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <?php if (!$barbers) { ?>
                    <div class="status-box status-box-danger">يجب تسجيل الحلاقين قبل إضافة الحجز</div>
                <?php } ?>

                <div class="booking-overview-grid">
                    <div class="overview-card booking-overview-card">
                        <span class="overview-label">حجوزات اليوم</span>
                        <strong class="overview-value"><?php echo (int) ($summary['total_count'] ?? 0); ?></strong>
                    </div>
                    <div class="overview-card booking-overview-card">
                        <span class="overview-label">بانتظار الوصول</span>
                        <strong class="overview-value"><?php echo (int) ($summary['waiting_count'] ?? 0); ?></strong>
                    </div>
                    <div class="overview-card booking-overview-card">
                        <span class="overview-label">خلال 15 دقيقة</span>
                        <strong class="overview-value"><?php echo (int) ($summary['upcoming_count'] ?? 0); ?></strong>
                    </div>
                    <div class="overview-card booking-overview-card">
                        <span class="overview-label">تم الوصول</span>
                        <strong class="overview-value"><?php echo (int) ($summary['arrived_count'] ?? 0); ?></strong>
                    </div>
                </div>

                <?php if ($isManager) { ?>
                    <div class="booking-admin-strip">
                        <div class="status-pill status-info">حجوزات جديدة <?php echo (int) ($adminSignals['new_count'] ?? 0); ?></div>
                        <div class="status-pill status-warning">تنبيهات قريبة <?php echo (int) ($adminSignals['reminder_count'] ?? 0); ?></div>
                    </div>
                <?php } ?>

                <div class="booking-layout-grid">
                    <section class="booking-panel">
                        <div class="page-header booking-panel-header">
                            <div>
                                <h2 class="cashier-mini-title">➕ حجز جديد</h2>
                            </div>
                        </div>

                        <form method="post" class="booking-form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>👤 اسم العميل</label>
                                <input
                                    type="text"
                                    name="customer_name"
                                    maxlength="255"
                                    required
                                    value="<?php echo htmlspecialchars($formData['customer_name']); ?>"
                                    <?php echo !$barbers ? 'disabled' : ''; ?>
                                >
                            </div>

                            <div class="field-group horizontal-field">
                                <label>📞 رقم الهاتف</label>
                                <input
                                    type="tel"
                                    name="customer_phone"
                                    inputmode="tel"
                                    maxlength="50"
                                    required
                                    value="<?php echo htmlspecialchars($formData['customer_phone']); ?>"
                                    <?php echo !$barbers ? 'disabled' : ''; ?>
                                >
                            </div>

                            <div class="field-group horizontal-field">
                                <label>🕒 الوقت</label>
                                <input
                                    type="time"
                                    name="appointment_time"
                                    required
                                    value="<?php echo htmlspecialchars($formData['appointment_time']); ?>"
                                    data-booking-time-input
                                    <?php echo !$barbers ? 'disabled' : ''; ?>
                                >
                            </div>

                            <div class="field-group horizontal-field">
                                <label>💈 الحلاق</label>
                                <select name="barber_id" required <?php echo !$barbers ? 'disabled' : ''; ?>>
                                    <option value="">اختر الحلاق</option>
                                    <?php foreach ($barbers as $barber) { ?>
                                        <option value="<?php echo (int) $barber['id']; ?>" <?php echo $formData['barber_id'] === (string) $barber['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($barber['barber_name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="booking-form-footer">
                                <div class="booking-day-chip">اليوم <?php echo htmlspecialchars($todayDate); ?></div>
                                <button type="submit" class="btn btn-success" <?php echo !$barbers ? 'disabled' : ''; ?>>💾 حفظ الحجز</button>
                            </div>
                        </form>
                    </section>

                    <section class="booking-panel booking-table-panel">
                        <div class="page-header booking-panel-header">
                            <div>
                                <h2 class="cashier-mini-title">📋 عملاء اليوم</h2>
                            </div>
                        </div>

                        <div class="table-wrap">
                            <table class="data-table responsive-table booking-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>🕒 الموعد</th>
                                        <th>👤 العميل</th>
                                        <th>📞 الهاتف</th>
                                        <th>💈 الحلاق</th>
                                        <th>🧾 أنشأ الحجز</th>
                                        <th>📅 وقت الإضافة</th>
                                        <th>✅ الوصول</th>
                                        <?php if ($isManager) { ?>
                                            <th>🗑️ حذف</th>
                                        <?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($appointments) { ?>
                                        <?php foreach ($appointments as $appointment) { ?>
                                            <tr>
                                                <td data-label="#"><?php echo (int) $appointment['id']; ?></td>
                                                <td data-label="🕒 الموعد"><?php echo htmlspecialchars(formatAppointmentTimeLabel($appointment['appointment_at'])); ?></td>
                                                <td data-label="👤 العميل"><?php echo htmlspecialchars($appointment['customer_name']); ?></td>
                                                <td data-label="📞 الهاتف"><?php echo htmlspecialchars($appointment['customer_phone']); ?></td>
                                                <td data-label="💈 الحلاق"><?php echo htmlspecialchars($appointment['barber_name']); ?></td>
                                                <td data-label="🧾 أنشأ الحجز"><?php echo htmlspecialchars($appointment['created_by_username'] !== '' ? $appointment['created_by_username'] : '—'); ?></td>
                                                <td data-label="📅 وقت الإضافة"><?php echo formatDateTimeValue($appointment['created_at']); ?></td>
                                                <td class="action-cell" data-label="✅ الوصول">
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <input type="hidden" name="mark_arrived_id" value="<?php echo (int) $appointment['id']; ?>">
                                                        <button type="submit" class="btn btn-primary">تم الوصول</button>
                                                    </form>
                                                </td>
                                                <?php if ($isManager) { ?>
                                                    <td class="action-cell" data-label="🗑️ حذف">
                                                        <form method="post" data-confirm-message="حذف الحجز رقم <?php echo (int) $appointment['id']; ?>؟">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <input type="hidden" name="delete_booking_id" value="<?php echo (int) $appointment['id']; ?>">
                                                            <button type="submit" class="btn btn-danger">حذف</button>
                                                        </form>
                                                    </td>
                                                <?php } ?>
                                            </tr>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <tr>
                                            <td colspan="<?php echo $isManager ? '9' : '8'; ?>">لا توجد حجوزات نشطة اليوم</td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>
