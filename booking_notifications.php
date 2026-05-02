<?php
require_once "config.php";

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['notifications' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SESSION['role'] ?? '') !== APP_MANAGER_ROLE || !canAccess('appointments')) {
    echo json_encode(['notifications' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
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
    echo json_encode(['notifications' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$todayDate = date('Y-m-d');
$now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
$reminderDeadline = $now->modify('+15 minutes');
$notifications = [];

try {
    $conn->beginTransaction();

    $newStmt = $conn->prepare(
        "SELECT id, customer_name, barber_name, appointment_at
         FROM appointments
         WHERE appointment_date = ? AND is_arrived = 0 AND admin_created_notified_at IS NULL
         ORDER BY created_at ASC, id ASC
         LIMIT 10"
    );
    $newStmt->execute([$todayDate]);
    $newAppointments = $newStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($newAppointments) {
        $updateNewStmt = $conn->prepare("UPDATE appointments SET admin_created_notified_at = NOW() WHERE id = ? AND admin_created_notified_at IS NULL");
        foreach ($newAppointments as $appointment) {
            $updateNewStmt->execute([(int) $appointment['id']]);
            $notifications[] = [
                'id' => (int) $appointment['id'],
                'type' => 'new',
                'title' => 'حجز جديد',
                'body' => 'تم تسجيل ' . $appointment['customer_name'] . ' مع ' . $appointment['barber_name']
            ];
        }
    }

    $reminderStmt = $conn->prepare(
        "SELECT id, customer_name, barber_name, appointment_at
         FROM appointments
         WHERE appointment_date = ?
           AND is_arrived = 0
           AND admin_reminder_notified_at IS NULL
           AND appointment_at BETWEEN ? AND ?
         ORDER BY appointment_at ASC, id ASC
         LIMIT 10"
    );
    $reminderStmt->execute([
        $todayDate,
        $now->format('Y-m-d H:i:s'),
        $reminderDeadline->format('Y-m-d H:i:s')
    ]);
    $reminderAppointments = $reminderStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($reminderAppointments) {
        $updateReminderStmt = $conn->prepare("UPDATE appointments SET admin_reminder_notified_at = NOW() WHERE id = ? AND admin_reminder_notified_at IS NULL");
        foreach ($reminderAppointments as $appointment) {
            $updateReminderStmt->execute([(int) $appointment['id']]);
            $notifications[] = [
                'id' => (int) $appointment['id'],
                'type' => 'reminder',
                'title' => 'موعد خلال 15 دقيقة',
                'body' => $appointment['customer_name'] . ' مع ' . $appointment['barber_name'] . ' الساعة ' . date('h:i A', strtotime($appointment['appointment_at']))
            ];
        }
    }

    $conn->commit();
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['notifications' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['notifications' => $notifications], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
