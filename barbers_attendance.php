<?php
require_once "config.php";
requireLogin();

if (!canAccess('barbers_attendance')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const MYSQL_ERROR_DUPLICATE_COLUMN = 1060;

function getAttendanceWeekDays()
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

function normalizeAttendanceOffDays($offDays, $weekDays)
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

function parseAttendanceDateTime($dateValue, $timeValue)
{
    if (!is_string($timeValue)) {
        return null;
    }

    $normalizedTime = strtoupper(trim($timeValue));

    if (!preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s?(AM|PM)$/i', $normalizedTime)) {
        return null;
    }

    $dateTime = DateTimeImmutable::createFromFormat('Y-m-d h:i A', $dateValue . ' ' . $normalizedTime);

    if (!$dateTime) {
        return null;
    }

    return $dateTime;
}

function getAttendanceDayKey($dateValue)
{
    $timestamp = strtotime($dateValue);

    if ($timestamp === false) {
        return '';
    }

    return strtolower(date('l', $timestamp));
}

function getStatusTone($status)
{
    $statusValue = trim((string) $status);

    if ($statusValue === 'حضور في الموعد' || $statusValue === 'انصراف في الموعد') {
        return 'success';
    }

    if ($statusValue === 'تأخير' || $statusValue === 'انصراف مبكر') {
        return 'warning';
    }

    if ($statusValue === 'غياب') {
        return 'danger';
    }

    if ($statusValue === 'إجازة') {
        return 'secondary';
    }

    if ($statusValue === '') {
        return 'muted';
    }

    return 'info';
}

function syncBarbersAttendanceArchive($conn, $barbers, $weekDays, $todayDate)
{
    $selectStmt = $conn->prepare(
        "SELECT id, record_date, check_in_at, check_out_at, attendance_status, departure_status, day_status, is_off_day
         FROM barbers_attendance
         WHERE barber_id = ? AND record_date BETWEEN ? AND ?"
    );
    $insertStmt = $conn->prepare(
        "INSERT INTO barbers_attendance (
            barber_id,
            record_date,
            scheduled_attendance_time,
            scheduled_departure_time,
            check_in_at,
            check_out_at,
            attendance_status,
            departure_status,
            day_status,
            is_off_day
        ) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?)"
    );
    $updateStmt = $conn->prepare(
        "UPDATE barbers_attendance
         SET scheduled_attendance_time = ?,
             scheduled_departure_time = ?,
             attendance_status = ?,
             departure_status = ?,
             day_status = ?,
             is_off_day = ?
         WHERE id = ?"
    );

    foreach ($barbers as $barber) {
        $createdAt = isset($barber['created_at']) ? (string) $barber['created_at'] : '';
        $createdDate = substr($createdAt, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdDate) || $createdDate > $todayDate) {
            $createdDate = $todayDate;
        }

        $offDays = json_decode($barber['off_days'] ?? '[]', true);
        $offDays = normalizeAttendanceOffDays($offDays, $weekDays);

        $selectStmt->execute([(int) $barber['id'], $createdDate, $todayDate]);
        $existingRows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
        $existingMap = [];

        foreach ($existingRows as $row) {
            $existingMap[$row['record_date']] = $row;
        }

        $cursor = new DateTimeImmutable($createdDate);
        $endDate = new DateTimeImmutable($todayDate);

        while ($cursor <= $endDate) {
            $recordDate = $cursor->format('Y-m-d');
            $dayKey = strtolower($cursor->format('l'));
            $isOffDay = in_array($dayKey, $offDays, true);
            $existingRow = $existingMap[$recordDate] ?? null;

            if ($isOffDay) {
                if ($existingRow === null) {
                    $insertStmt->execute([
                        (int) $barber['id'],
                        $recordDate,
                        $barber['attendance_time'],
                        $barber['departure_time'],
                        'إجازة',
                        'إجازة',
                        'إجازة',
                        1
                    ]);
                } elseif (empty($existingRow['check_in_at']) && empty($existingRow['check_out_at']) && (((int) $existingRow['is_off_day']) !== 1 || $existingRow['day_status'] !== 'إجازة')) {
                    $updateStmt->execute([
                        $barber['attendance_time'],
                        $barber['departure_time'],
                        'إجازة',
                        'إجازة',
                        'إجازة',
                        1,
                        (int) $existingRow['id']
                    ]);
                }
            } elseif ($recordDate < $todayDate && $existingRow === null) {
                $insertStmt->execute([
                    (int) $barber['id'],
                    $recordDate,
                    $barber['attendance_time'],
                    $barber['departure_time'],
                    'غياب',
                    '',
                    'غياب',
                    0
                ]);
            } elseif ($recordDate < $todayDate && $existingRow !== null && empty($existingRow['check_in_at']) && empty($existingRow['check_out_at']) && (((int) $existingRow['is_off_day']) === 1 || $existingRow['day_status'] === 'إجازة')) {
                $updateStmt->execute([
                    $barber['attendance_time'],
                    $barber['departure_time'],
                    'غياب',
                    '',
                    'غياب',
                    0,
                    (int) $existingRow['id']
                ]);
            }

            $cursor = $cursor->modify('+1 day');
        }
    }
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

    $barcodeColumnStmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'barbers'
           AND COLUMN_NAME = 'barber_barcode'
         LIMIT 1"
    );
    $barcodeColumnStmt->execute();

    if (!$barcodeColumnStmt->fetchColumn()) {
        try {
            $conn->exec("ALTER TABLE barbers ADD COLUMN barber_barcode VARCHAR(100) NOT NULL DEFAULT '' AFTER barber_number");
        } catch (PDOException $migrationException) {
            $duplicateColumn = ($migrationException->errorInfo[1] ?? null) === MYSQL_ERROR_DUPLICATE_COLUMN;
            if (!$duplicateColumn) {
                throw $migrationException;
            }
        }
    }

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
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة حضور الحلاقين");
}

$settings = getSiteSettings($conn);
$weekDays = getAttendanceWeekDays();
$todayDate = date('Y-m-d');
$now = new DateTimeImmutable();
$errorMessage = '';
$successMessage = trim((string) ($_GET['success'] ?? ''));
$scanValue = '';

$barbersStmt = $conn->prepare(
    "SELECT id, barber_name, barber_number, barber_barcode, attendance_time, departure_time, off_days, created_at
     FROM barbers
     ORDER BY barber_name ASC, id ASC"
);
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);

syncBarbersAttendanceArchive($conn, $barbers, $weekDays, $todayDate);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    $scanValue = trim((string) ($_POST['barcode_value'] ?? ''));

    if ($scanValue === '') {
        $errorMessage = 'أدخل الباركود أولاً';
    } else {
        try {
            $conn->beginTransaction();

            $barberLookupStmt = $conn->prepare(
                "SELECT id, barber_name, barber_number, barber_barcode, attendance_time, departure_time, off_days
                 FROM barbers
                 WHERE barber_barcode = ? OR barber_number = ?
                 ORDER BY id ASC
                 LIMIT 2"
            );
            $barberLookupStmt->execute([$scanValue, $scanValue]);
            $matchedBarbers = $barberLookupStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($matchedBarbers) === 0) {
                throw new RuntimeException('الباركود غير مسجل');
            }

            if (count($matchedBarbers) > 1) {
                throw new RuntimeException('الباركود مرتبط بأكثر من حلاق');
            }

            $barber = $matchedBarbers[0];
            $offDays = json_decode($barber['off_days'] ?? '[]', true);
            $offDays = normalizeAttendanceOffDays($offDays, $weekDays);
            $todayDayKey = getAttendanceDayKey($todayDate);

            if (in_array($todayDayKey, $offDays, true)) {
                throw new RuntimeException('لا يمكن تسجيل حضور أو انصراف في يوم الإجازة');
            }

            $scheduledAttendance = parseAttendanceDateTime($todayDate, $barber['attendance_time']);
            $scheduledDeparture = parseAttendanceDateTime($todayDate, $barber['departure_time']);

            if ($scheduledAttendance === null || $scheduledDeparture === null) {
                throw new RuntimeException('مواعيد الحلاق غير صالحة');
            }

            $recordStmt = $conn->prepare(
                "SELECT id, check_in_at, check_out_at, attendance_status, departure_status, day_status, is_off_day
                 FROM barbers_attendance
                 WHERE barber_id = ? AND record_date = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $recordStmt->execute([(int) $barber['id'], $todayDate]);
            $record = $recordStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($record !== null && ((int) $record['is_off_day']) === 1) {
                throw new RuntimeException('لا يمكن تسجيل حضور أو انصراف في يوم الإجازة');
            }

            $currentDateTime = $now->format('Y-m-d H:i:s');

            if ($record === null) {
                $insertTodayStmt = $conn->prepare(
                    "INSERT INTO barbers_attendance (
                        barber_id,
                        record_date,
                        scheduled_attendance_time,
                        scheduled_departure_time,
                        check_in_at,
                        check_out_at,
                        attendance_status,
                        departure_status,
                        day_status,
                        is_off_day
                    ) VALUES (?, ?, ?, ?, NULL, NULL, '', '', '', 0)"
                );
                $insertTodayStmt->execute([
                    (int) $barber['id'],
                    $todayDate,
                    $barber['attendance_time'],
                    $barber['departure_time']
                ]);
                $recordId = (int) $conn->lastInsertId();
                $record = [
                    'id' => $recordId,
                    'check_in_at' => null,
                    'check_out_at' => null
                ];
            }

            if (empty($record['check_in_at'])) {
                $graceAttendance = $scheduledAttendance->modify('+15 minutes');
                $attendanceStatus = $now <= $graceAttendance ? 'حضور في الموعد' : 'تأخير';
                $checkInStmt = $conn->prepare(
                    "UPDATE barbers_attendance
                     SET check_in_at = ?,
                         attendance_status = ?,
                         day_status = 'حضور مفتوح',
                         is_off_day = 0,
                         scheduled_attendance_time = ?,
                         scheduled_departure_time = ?
                     WHERE id = ?"
                );
                $checkInStmt->execute([
                    $currentDateTime,
                    $attendanceStatus,
                    $barber['attendance_time'],
                    $barber['departure_time'],
                    (int) $record['id']
                ]);
                $successMessage = 'تم تسجيل الحضور لـ ' . $barber['barber_name'];
            } elseif (empty($record['check_out_at'])) {
                $departureStatus = $now < $scheduledDeparture ? 'انصراف مبكر' : 'انصراف في الموعد';
                $checkOutStmt = $conn->prepare(
                    "UPDATE barbers_attendance
                     SET check_out_at = ?,
                         departure_status = ?,
                         day_status = 'مكتمل',
                         is_off_day = 0,
                         scheduled_attendance_time = ?,
                         scheduled_departure_time = ?
                     WHERE id = ?"
                );
                $checkOutStmt->execute([
                    $currentDateTime,
                    $departureStatus,
                    $barber['attendance_time'],
                    $barber['departure_time'],
                    (int) $record['id']
                ]);
                $successMessage = 'تم تسجيل الانصراف لـ ' . $barber['barber_name'];
            } else {
                throw new RuntimeException('تم تسجيل حضور وانصراف الحلاق اليوم بالفعل');
            }

            $conn->commit();
            header('Location: barbers_attendance.php?success=' . urlencode($successMessage));
            exit;
        } catch (Throwable $throwable) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $errorMessage = $throwable->getMessage();
            $successMessage = '';
        }
    }
}

$recordsStmt = $conn->prepare(
    "SELECT
        barbers_attendance.id,
        barbers_attendance.record_date,
        barbers_attendance.scheduled_attendance_time,
        barbers_attendance.scheduled_departure_time,
        barbers_attendance.check_in_at,
        barbers_attendance.check_out_at,
        barbers_attendance.attendance_status,
        barbers_attendance.departure_status,
        barbers_attendance.day_status,
        barbers_attendance.is_off_day,
        barbers.barber_name,
        barbers.barber_number,
        barbers.barber_barcode
     FROM barbers_attendance
     INNER JOIN barbers ON barbers.id = barbers_attendance.barber_id
     ORDER BY barbers_attendance.record_date DESC, barbers_attendance.id DESC"
);
$recordsStmt->execute();
$attendanceRecords = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalBarbers = count($barbers);
$todayOnTimeCount = 0;
$todayLateCount = 0;
$todayOffDayCount = 0;
$totalAbsenceCount = 0;

foreach ($attendanceRecords as $recordSummary) {
    if ($recordSummary['record_date'] === $todayDate) {
        if ($recordSummary['attendance_status'] === 'حضور في الموعد') {
            $todayOnTimeCount++;
        }

        if ($recordSummary['attendance_status'] === 'تأخير') {
            $todayLateCount++;
        }

        if (((int) $recordSummary['is_off_day']) === 1 || $recordSummary['day_status'] === 'إجازة') {
            $todayOffDayCount++;
        }
    }

    if ($recordSummary['day_status'] === 'غياب') {
        $totalAbsenceCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>حضور الحلاقين</title>
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

            <div class="content-card attendance-content-card">
                <div class="page-header attendance-page-header">
                    <div>
                        <h1 class="section-title">📅 حضور الحلاقين</h1>
                        <p class="barbers-page-subtitle">تسجيل الحضور والانصراف بالباركود مع حفظ السجل اليومي للحضور والتأخير والانصراف المبكر والغياب والإجازات.</p>
                    </div>
                </div>

                <div class="barbers-overview attendance-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الحلاقين</span>
                        <strong class="overview-value"><?php echo $totalBarbers; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">حضور اليوم في الموعد</span>
                        <strong class="overview-value"><?php echo $todayOnTimeCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">تأخير اليوم</span>
                        <strong class="overview-value"><?php echo $todayLateCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجازات اليوم</span>
                        <strong class="overview-value"><?php echo $todayOffDayCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">غياب محفوظ</span>
                        <strong class="overview-value"><?php echo $totalAbsenceCount; ?></strong>
                    </div>
                </div>

                <?php if ($successMessage !== '') { ?>
                    <div class="status-box status-box-success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php } ?>

                <?php if ($errorMessage !== '') { ?>
                    <div class="status-box status-box-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <div class="attendance-layout">
                    <section class="barber-form-card attendance-scan-card">
                        <div class="barber-card-head">
                            <h2>تسجيل الحضور والانصراف</h2>
                            <p>اكتب الباركود أو استخدم قارئ الباركود، وسيتم احتساب أول قراءة حضورًا وثاني قراءة انصرافًا لنفس اليوم.</p>
                        </div>

                        <form method="post" class="attendance-scan-form" id="attendanceScanForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="field-group horizontal-field attendance-barcode-field">
                                <label>🏷️ الباركود</label>
                                <input type="text" name="barcode_value" id="barcodeInput" value="<?php echo htmlspecialchars($scanValue); ?>" required autofocus autocomplete="off" inputmode="text">
                            </div>
                            <div class="form-actions-row attendance-actions-row">
                                <button type="submit" class="btn btn-success">تسجيل</button>
                                <button type="button" class="btn btn-secondary mobile-camera-button" id="openCameraScanner">قراءة الباركود بالكاميرا</button>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="table-wrap attendance-table-wrap">
                    <table class="data-table attendance-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>📆 التاريخ</th>
                                <th>👤 الحلاق</th>
                                <th>🏷️ الباركود</th>
                                <th>🕘 الحضور المحدد</th>
                                <th>🟢 الحضور الفعلي</th>
                                <th>📌 حالة الحضور</th>
                                <th>🌙 الانصراف المحدد</th>
                                <th>🔵 الانصراف الفعلي</th>
                                <th>📌 حالة الانصراف</th>
                                <th>🧾 الحالة اليومية</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($attendanceRecords) { ?>
                                <?php foreach ($attendanceRecords as $record) { ?>
                                    <tr>
                                        <td data-label="#"><?php echo (int) $record['id']; ?></td>
                                        <td data-label="📆 التاريخ"><?php echo htmlspecialchars($record['record_date']); ?></td>
                                        <td data-label="👤 الحلاق"><?php echo htmlspecialchars($record['barber_name']); ?></td>
                                        <td data-label="🏷️ الباركود"><?php echo htmlspecialchars($record['barber_barcode'] !== '' ? $record['barber_barcode'] : $record['barber_number']); ?></td>
                                        <td data-label="🕘 الحضور المحدد"><?php echo htmlspecialchars($record['scheduled_attendance_time']); ?></td>
                                        <td data-label="🟢 الحضور الفعلي"><?php echo $record['check_in_at'] ? htmlspecialchars(date('Y-m-d h:i A', strtotime($record['check_in_at']))) : '—'; ?></td>
                                        <td data-label="📌 حالة الحضور">
                                            <?php if ($record['attendance_status'] !== '') { ?>
                                                <span class="status-pill status-<?php echo getStatusTone($record['attendance_status']); ?>"><?php echo htmlspecialchars($record['attendance_status']); ?></span>
                                            <?php } else { ?>
                                                <span class="status-pill status-muted">—</span>
                                            <?php } ?>
                                        </td>
                                        <td data-label="🌙 الانصراف المحدد"><?php echo htmlspecialchars($record['scheduled_departure_time']); ?></td>
                                        <td data-label="🔵 الانصراف الفعلي"><?php echo $record['check_out_at'] ? htmlspecialchars(date('Y-m-d h:i A', strtotime($record['check_out_at']))) : '—'; ?></td>
                                        <td data-label="📌 حالة الانصراف">
                                            <?php if ($record['departure_status'] !== '') { ?>
                                                <span class="status-pill status-<?php echo getStatusTone($record['departure_status']); ?>"><?php echo htmlspecialchars($record['departure_status']); ?></span>
                                            <?php } else { ?>
                                                <span class="status-pill status-muted">—</span>
                                            <?php } ?>
                                        </td>
                                        <td data-label="🧾 الحالة اليومية">
                                            <span class="status-pill status-<?php echo getStatusTone($record['day_status']); ?>"><?php echo htmlspecialchars($record['day_status']); ?></span>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="11">لا توجد سجلات حضور حتى الآن</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div class="camera-sheet" id="cameraScannerSheet" hidden>
        <div class="camera-sheet-card">
            <div class="camera-sheet-head">
                <strong>قراءة الباركود</strong>
                <button type="button" class="btn btn-danger camera-close-button" id="closeCameraScanner">إغلاق</button>
            </div>
            <video id="cameraScannerVideo" playsinline></video>
            <div class="camera-sheet-status" id="cameraScannerStatus">وجّه الكاميرا نحو الباركود</div>
        </div>
    </div>

    <script src="assets/script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const scanForm = document.getElementById('attendanceScanForm');
            const barcodeInput = document.getElementById('barcodeInput');
            const openCameraButton = document.getElementById('openCameraScanner');
            const cameraSheet = document.getElementById('cameraScannerSheet');
            const closeCameraButton = document.getElementById('closeCameraScanner');
            const cameraVideo = document.getElementById('cameraScannerVideo');
            const cameraStatus = document.getElementById('cameraScannerStatus');
            let cameraStream = null;
            let scanTimer = null;
            let detector = null;

            function focusBarcodeInput() {
                if (barcodeInput) {
                    barcodeInput.focus();
                    barcodeInput.select();
                }
            }

            function isMobileDevice() {
                return window.matchMedia('(max-width: 768px), (pointer: coarse)').matches;
            }

            function refreshCameraButton() {
                if (!openCameraButton) {
                    return;
                }

                openCameraButton.style.display = isMobileDevice() ? 'inline-flex' : 'none';
            }

            async function stopCameraScanner() {
                if (scanTimer) {
                    clearTimeout(scanTimer);
                    scanTimer = null;
                }

                if (cameraStream) {
                    cameraStream.getTracks().forEach(function (track) {
                        track.stop();
                    });
                    cameraStream = null;
                }

                if (cameraVideo) {
                    cameraVideo.pause();
                    cameraVideo.srcObject = null;
                }

                if (cameraSheet) {
                    cameraSheet.hidden = true;
                }

                focusBarcodeInput();
            }

            async function scanFrame() {
                if (!detector || !cameraVideo || cameraSheet.hidden) {
                    return;
                }

                try {
                    const codes = await detector.detect(cameraVideo);

                    if (codes.length > 0) {
                        const rawValue = (codes[0].rawValue || '').trim();

                        if (rawValue !== '') {
                            barcodeInput.value = rawValue;
                            cameraStatus.textContent = 'تمت القراءة';
                            await stopCameraScanner();
                            scanForm.requestSubmit();
                            return;
                        }
                    }
                } catch (error) {
                    cameraStatus.textContent = 'تعذر قراءة الباركود';
                }

                scanTimer = setTimeout(scanFrame, 350);
            }

            async function startCameraScanner() {
                if (!isMobileDevice()) {
                    return;
                }

                if (!('BarcodeDetector' in window)) {
                    cameraStatus.textContent = 'المتصفح لا يدعم قراءة الباركود بالكاميرا';
                    cameraSheet.hidden = false;
                    return;
                }

                try {
                    detector = new BarcodeDetector({
                        formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code']
                    });
                    cameraStatus.textContent = 'وجّه الكاميرا نحو الباركود';
                    cameraSheet.hidden = false;
                    cameraStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: {
                                ideal: 'environment'
                            }
                        },
                        audio: false
                    });
                    cameraVideo.srcObject = cameraStream;
                    await cameraVideo.play();
                    scanFrame();
                } catch (error) {
                    cameraStatus.textContent = 'تعذر تشغيل الكاميرا';
                }
            }

            refreshCameraButton();
            window.addEventListener('resize', refreshCameraButton);

            if (openCameraButton) {
                openCameraButton.addEventListener('click', function () {
                    startCameraScanner();
                });
            }

            if (closeCameraButton) {
                closeCameraButton.addEventListener('click', function () {
                    stopCameraScanner();
                });
            }

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    stopCameraScanner();
                }
            });

            focusBarcodeInput();
        });
    </script>
</body>
</html>
