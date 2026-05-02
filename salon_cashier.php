<?php
require_once "config.php";
requireLogin();

if (!canAccess('salon_cashier')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function isCashierNumericValue($value)
{
    return is_string($value) && preg_match('/^\d+(?:\.\d{1,2})?$/', $value) === 1;
}

function formatCashierAmount($value)
{
    return number_format((float) $value, 2, '.', '');
}

function normalizeCashierDate($value)
{
    $value = trim((string) $value);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return date('Y-m-d');
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return date('Y-m-d');
    }

    return $value;
}

function getCashierMessage($key)
{
    $messages = [
        'created' => 'تم حفظ الفاتورة بنجاح',
        'updated' => 'تم تعديل الفاتورة بنجاح',
        'deleted' => 'تم حذف الفاتورة بنجاح'
    ];

    return $messages[$key] ?? '';
}

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS services (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

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
        "CREATE TABLE IF NOT EXISTS salon_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            employee_name VARCHAR(255) NOT NULL,
            barber_id INT UNSIGNED NOT NULL,
            barber_name VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) NOT NULL DEFAULT '',
            customer_phone VARCHAR(50) NOT NULL DEFAULT '',
            barber_commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            barber_share_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            salon_share_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_salon_invoices_created_at (created_at),
            INDEX idx_salon_invoices_employee_id (employee_id),
            INDEX idx_salon_invoices_barber_id (barber_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $customerNameColumnStmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'salon_invoices'
           AND COLUMN_NAME = 'customer_name'
         LIMIT 1"
    );
    $customerNameColumnStmt->execute();

    if (!$customerNameColumnStmt->fetchColumn()) {
        try {
            $conn->exec("ALTER TABLE salon_invoices ADD COLUMN customer_name VARCHAR(255) NOT NULL DEFAULT '' AFTER barber_name");
        } catch (PDOException $migrationException) {
            $duplicateColumn = isset($migrationException->errorInfo[1])
                && (int) $migrationException->errorInfo[1] === MYSQL_ERROR_DUPLICATE_COLUMN;
            if (!$duplicateColumn) {
                throw $migrationException;
            }
        }
    }

    $customerPhoneColumnStmt = $conn->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'salon_invoices'
           AND COLUMN_NAME = 'customer_phone'
         LIMIT 1"
    );
    $customerPhoneColumnStmt->execute();

    if (!$customerPhoneColumnStmt->fetchColumn()) {
        try {
            $conn->exec("ALTER TABLE salon_invoices ADD COLUMN customer_phone VARCHAR(50) NOT NULL DEFAULT '' AFTER customer_name");
        } catch (PDOException $migrationException) {
            $duplicateColumn = isset($migrationException->errorInfo[1])
                && (int) $migrationException->errorInfo[1] === MYSQL_ERROR_DUPLICATE_COLUMN;
            if (!$duplicateColumn) {
                throw $migrationException;
            }
        }
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS salon_invoice_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            service_id INT UNSIGNED NOT NULL,
            service_name VARCHAR(255) NOT NULL,
            service_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            billed_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_salon_invoice_items_invoice_id (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة كاشير الصالون");
}

$settings = getSiteSettings($conn);
$isManager = (($_SESSION['role'] ?? '') === APP_MANAGER_ROLE);
$filterDate = normalizeCashierDate($_GET['filter_date'] ?? date('Y-m-d'));
$errorMessage = '';
$successMessage = getCashierMessage($_GET['message'] ?? '');

$employeesStmt = $conn->prepare("SELECT id, employee_name FROM employees ORDER BY employee_name ASC");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$barbersStmt = $conn->prepare("SELECT id, barber_name, commission_percent FROM barbers ORDER BY barber_name ASC");
$barbersStmt->execute();
$barbers = $barbersStmt->fetchAll(PDO::FETCH_ASSOC);

$servicesStmt = $conn->prepare("SELECT id, service_name, price FROM services ORDER BY service_name ASC");
$servicesStmt->execute();
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

$employeesById = [];
foreach ($employees as $employee) {
    $employeesById[(int) $employee['id']] = $employee;
}

$barbersById = [];
$barbersJs = [];
foreach ($barbers as $barber) {
    $barberId = (int) $barber['id'];
    $barbersById[$barberId] = $barber;
    $barbersJs[(string) $barberId] = [
        'commission_percent' => (float) $barber['commission_percent']
    ];
}

$servicesById = [];
$servicesJs = [];
foreach ($services as $service) {
    $serviceId = (int) $service['id'];
    $servicePrice = (float) $service['price'];
    $servicesById[$serviceId] = $service;
    $servicesJs[(string) $serviceId] = [
        'service_name' => $service['service_name'],
        'price' => $servicePrice,
        'min_price' => $servicePrice * 0.5
    ];
}

$formData = [
    'id' => '',
    'employee_id' => '',
    'barber_id' => '',
    'customer_name' => '',
    'customer_phone' => '',
    'items' => [
        [
            'service_id' => '',
            'amount' => ''
        ]
    ]
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $invoiceId = (int) $_GET['edit'];
    $invoiceStmt = $conn->prepare("SELECT * FROM salon_invoices WHERE id = ? LIMIT 1");
    $invoiceStmt->execute([$invoiceId]);
    $editInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

    if ($editInvoice) {
        $filterDate = date('Y-m-d', strtotime($editInvoice['created_at']));
        $itemsStmt = $conn->prepare(
            "SELECT service_id, billed_amount
             FROM salon_invoice_items
             WHERE invoice_id = ?
             ORDER BY id ASC"
        );
        $itemsStmt->execute([$invoiceId]);
        $editItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $preparedItems = [];

        foreach ($editItems as $item) {
            $preparedItems[] = [
                'service_id' => (string) $item['service_id'],
                'amount' => formatCashierAmount($item['billed_amount'])
            ];
        }

        if (!$preparedItems) {
            $preparedItems[] = [
                'service_id' => '',
                'amount' => ''
            ];
        }

        $formData = [
            'id' => (string) $editInvoice['id'],
            'employee_id' => (string) $editInvoice['employee_id'],
            'barber_id' => (string) $editInvoice['barber_id'],
            'customer_name' => (string) ($editInvoice['customer_name'] ?? ''),
            'customer_phone' => (string) ($editInvoice['customer_phone'] ?? ''),
            'items' => $preparedItems
        ];
    } else {
        $errorMessage = 'الفاتورة غير موجودة';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    if (isset($_POST['delete_invoice_id'])) {
        if (!$isManager) {
            http_response_code(403);
            die("غير مصرح");
        }

        $invoiceId = (int) $_POST['delete_invoice_id'];

        try {
            $conn->beginTransaction();
            $deleteItemsStmt = $conn->prepare("DELETE FROM salon_invoice_items WHERE invoice_id = ?");
            $deleteItemsStmt->execute([$invoiceId]);
            $deleteInvoiceStmt = $conn->prepare("DELETE FROM salon_invoices WHERE id = ?");
            $deleteInvoiceStmt->execute([$invoiceId]);
            $conn->commit();
            header("Location: salon_cashier.php?filter_date=" . urlencode($filterDate) . "&message=deleted");
            exit;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errorMessage = 'تعذر حذف الفاتورة';
        }
    } else {
        $formData = [
            'id' => trim($_POST['id'] ?? ''),
            'employee_id' => trim($_POST['employee_id'] ?? ''),
            'barber_id' => trim($_POST['barber_id'] ?? ''),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
            'items' => is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : []
        ];

        if ($formData['id'] !== '' && !$isManager) {
            http_response_code(403);
            die("غير مصرح");
        }

        if (!$employees || !$barbers || !$services) {
            $errorMessage = 'يجب تسجيل الموظفين والحلاقين والخدمات أولاً';
        } elseif ($formData['employee_id'] === '' || !isset($employeesById[(int) $formData['employee_id']])) {
            $errorMessage = 'اختر الموظف';
        } elseif ($formData['barber_id'] === '' || !isset($barbersById[(int) $formData['barber_id']])) {
            $errorMessage = 'اختر الحلاق';
        } elseif ($formData['customer_name'] === '') {
            $errorMessage = 'اكتب اسم العميل';
        } elseif (getTextLength($formData['customer_name']) > 255) {
            $errorMessage = 'اسم العميل طويل جدًا';
        } elseif ($formData['customer_phone'] === '') {
            $errorMessage = 'اكتب رقم هاتف العميل';
        } elseif (getTextLength($formData['customer_phone']) > 50) {
            $errorMessage = 'رقم الهاتف طويل جدًا';
        } elseif (!$formData['items']) {
            $errorMessage = 'أضف خدمة واحدة على الأقل';
        } else {
            $validatedItems = [];
            $invoiceTotal = 0.0;

            foreach ($formData['items'] as $item) {
                $serviceId = (int) ($item['service_id'] ?? 0);
                $amountInput = trim((string) ($item['amount'] ?? ''));

                if (!$serviceId || !isset($servicesById[$serviceId])) {
                    $errorMessage = 'اختر خدمة صحيحة لكل بند';
                    break;
                }

                if (!isCashierNumericValue($amountInput)) {
                    $errorMessage = 'المبلغ يجب أن يكون رقمًا صحيحًا أو عشريًا';
                    break;
                }

                $servicePrice = (float) $servicesById[$serviceId]['price'];
                $minimumAllowed = $servicePrice * 0.5;
                $billedAmount = (float) $amountInput;

                if ($billedAmount < $minimumAllowed) {
                    $errorMessage = 'لا يمكن أن يقل مبلغ الخدمة عن 50% من سعرها';
                    break;
                }

                $validatedItems[] = [
                    'service_id' => $serviceId,
                    'service_name' => $servicesById[$serviceId]['service_name'],
                    'service_price' => formatCashierAmount($servicePrice),
                    'billed_amount' => formatCashierAmount($billedAmount)
                ];
                $invoiceTotal += $billedAmount;
            }

            if ($errorMessage === '') {
                $employeeId = (int) $formData['employee_id'];
                $barberId = (int) $formData['barber_id'];
                $barberCommission = (float) $barbersById[$barberId]['commission_percent'];
                $barberShare = $invoiceTotal * ($barberCommission / 100);
                $salonShare = $invoiceTotal - $barberShare;

                try {
                    $conn->beginTransaction();

                    if ($formData['id'] === '') {
                        $invoiceStmt = $conn->prepare(
                            "INSERT INTO salon_invoices (
                                employee_id,
                                employee_name,
                                barber_id,
                                barber_name,
                                customer_name,
                                customer_phone,
                                barber_commission_percent,
                                total_amount,
                                barber_share_amount,
                                salon_share_amount
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $invoiceStmt->execute([
                            $employeeId,
                            $employeesById[$employeeId]['employee_name'],
                            $barberId,
                            $barbersById[$barberId]['barber_name'],
                            $formData['customer_name'],
                            $formData['customer_phone'],
                            formatCashierAmount($barberCommission),
                            formatCashierAmount($invoiceTotal),
                            formatCashierAmount($barberShare),
                            formatCashierAmount($salonShare)
                        ]);
                        $invoiceId = (int) $conn->lastInsertId();
                        $messageKey = 'created';
                    } else {
                        $invoiceId = (int) $formData['id'];
                        $existingInvoiceStmt = $conn->prepare("SELECT id FROM salon_invoices WHERE id = ? LIMIT 1");
                        $existingInvoiceStmt->execute([$invoiceId]);

                        if (!$existingInvoiceStmt->fetch(PDO::FETCH_ASSOC)) {
                            throw new RuntimeException('missing_invoice');
                        }

                        $invoiceStmt = $conn->prepare(
                            "UPDATE salon_invoices
                             SET employee_id = ?, employee_name = ?, barber_id = ?, barber_name = ?, customer_name = ?, customer_phone = ?, barber_commission_percent = ?, total_amount = ?, barber_share_amount = ?, salon_share_amount = ?
                             WHERE id = ?"
                        );
                        $invoiceStmt->execute([
                            $employeeId,
                            $employeesById[$employeeId]['employee_name'],
                            $barberId,
                            $barbersById[$barberId]['barber_name'],
                            $formData['customer_name'],
                            $formData['customer_phone'],
                            formatCashierAmount($barberCommission),
                            formatCashierAmount($invoiceTotal),
                            formatCashierAmount($barberShare),
                            formatCashierAmount($salonShare),
                            $invoiceId
                        ]);

                        $deleteItemsStmt = $conn->prepare("DELETE FROM salon_invoice_items WHERE invoice_id = ?");
                        $deleteItemsStmt->execute([$invoiceId]);
                        $messageKey = 'updated';
                    }

                    $itemStmt = $conn->prepare(
                        "INSERT INTO salon_invoice_items (invoice_id, service_id, service_name, service_price, billed_amount)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    foreach ($validatedItems as $validatedItem) {
                        $itemStmt->execute([
                            $invoiceId,
                            $validatedItem['service_id'],
                            $validatedItem['service_name'],
                            $validatedItem['service_price'],
                            $validatedItem['billed_amount']
                        ]);
                    }

                    $conn->commit();
                    header("Location: salon_cashier.php?filter_date=" . urlencode($filterDate) . "&message=" . $messageKey);
                    exit;
                } catch (RuntimeException $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $errorMessage = 'الفاتورة غير موجودة';
                } catch (PDOException $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $errorMessage = 'تعذر حفظ الفاتورة';
                }
            }
        }

        if (!$formData['items']) {
            $formData['items'] = [
                [
                    'service_id' => '',
                    'amount' => ''
                ]
            ];
        }
    }
}

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS invoices_count,
        COALESCE(SUM(total_amount), 0) AS total_amount,
        COALESCE(SUM(barber_share_amount), 0) AS barber_share_amount,
        COALESCE(SUM(salon_share_amount), 0) AS salon_share_amount
     FROM salon_invoices
     WHERE DATE(created_at) = ?"
);
$summaryStmt->execute([$filterDate]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'invoices_count' => 0,
    'total_amount' => 0,
    'barber_share_amount' => 0,
    'salon_share_amount' => 0
];

$invoiceRowsStmt = $conn->prepare(
    "SELECT
        id,
        employee_name,
        barber_name,
        customer_name,
        customer_phone,
        barber_commission_percent,
        total_amount,
        barber_share_amount,
        salon_share_amount,
        created_at
     FROM salon_invoices
     WHERE DATE(created_at) = ?
     ORDER BY id DESC"
);
$invoiceRowsStmt->execute([$filterDate]);
$invoiceRows = $invoiceRowsStmt->fetchAll(PDO::FETCH_ASSOC);

$invoiceItemsByInvoiceId = [];
$invoiceIds = array_map(
    static function ($invoiceRow) {
        return (int) $invoiceRow['id'];
    },
    $invoiceRows
);

if ($invoiceIds) {
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $invoiceItemsStmt = $conn->prepare(
        "SELECT invoice_id, service_name, service_price, billed_amount
         FROM salon_invoice_items
         WHERE invoice_id IN ($placeholders)
         ORDER BY id ASC"
    );
    $invoiceItemsStmt->execute($invoiceIds);
    $invoiceItems = $invoiceItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoiceItems as $invoiceItem) {
        $invoiceItemsByInvoiceId[(int) $invoiceItem['invoice_id']][] = $invoiceItem;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كاشير الصالون</title>
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

            <div class="content-card cashier-content-card">
                <div class="page-header">
                    <div>
                        <h1 class="section-title">🏦 كاشير الصالون</h1>
                        <p class="page-subtitle">إدارة فواتير الخدمات اليومية</p>
                    </div>
                </div>

                <?php if ($successMessage !== '') { ?>
                    <div class="status-box status-box-success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php } ?>

                <?php if ($errorMessage !== '') { ?>
                    <div class="status-box status-box-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <?php if (!$employees || !$barbers || !$services) { ?>
                    <div class="status-box status-box-danger">يجب تسجيل الموظفين والحلاقين والخدمات قبل إنشاء الفواتير</div>
                <?php } ?>

                <div class="cashier-overview barbers-overview">
                    <div class="overview-card">
                        <span class="overview-label">عدد فواتير اليوم</span>
                        <strong class="overview-value"><?php echo (int) $summary['invoices_count']; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي اليوم</span>
                        <strong class="overview-value"><?php echo number_format((float) $summary['total_amount'], 2); ?> ج</strong>
                    </div>
                    <?php if ($isManager) { ?>
                        <div class="overview-card">
                            <span class="overview-label">إجمالي نسبة الحلاقين</span>
                            <strong class="overview-value"><?php echo number_format((float) $summary['barber_share_amount'], 2); ?> ج</strong>
                        </div>
                        <div class="overview-card">
                            <span class="overview-label">إجمالي نسبة الصالون</span>
                            <strong class="overview-value"><?php echo number_format((float) $summary['salon_share_amount'], 2); ?> ج</strong>
                        </div>
                    <?php } ?>
                </div>

                <div class="cashier-panels">
                    <section class="cashier-panel cashier-form-panel">
                        <div class="page-header cashier-panel-header">
                            <div>
                                <h2 class="section-title cashier-mini-title"><?php echo $formData['id'] !== '' ? '✏️ تعديل الفاتورة' : '➕ فاتورة جديدة'; ?></h2>
                                <p class="page-subtitle">اختر الموظف والحلاق وسجل بيانات العميل والخدمات ثم احفظ الفاتورة</p>
                            </div>
                        </div>

                        <form method="post" class="cashier-form-grid" id="salonCashierForm">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="field-group horizontal-field">
                                <label>🧑‍💼 الموظف</label>
                                <select name="employee_id" required <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>>
                                    <option value="">اختر الموظف</option>
                                    <?php foreach ($employees as $employee) { ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $formData['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($employee['employee_name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>💈 الحلاق</label>
                                <select name="barber_id" id="cashierBarberSelect" required <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>>
                                    <option value="">اختر الحلاق</option>
                                    <?php foreach ($barbers as $barber) { ?>
                                        <option value="<?php echo (int) $barber['id']; ?>" <?php echo $formData['barber_id'] === (string) $barber['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($barber['barber_name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="field-group horizontal-field">
                                <label>👤 اسم العميل</label>
                                <input
                                    type="text"
                                    name="customer_name"
                                    maxlength="255"
                                    required
                                    value="<?php echo htmlspecialchars($formData['customer_name']); ?>"
                                    <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>
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
                                    <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>
                                >
                            </div>

                            <div class="cashier-items-card">
                                <div class="cashier-inline-head">
                                    <h3 class="cashier-card-title">الخدمات</h3>
                                    <button type="button" class="btn btn-success" id="addCashierItem" <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>>➕ إضافة خدمة</button>
                                </div>

                                <div class="cashier-items-list" id="cashierItems" data-next-index="<?php echo count($formData['items']); ?>">
                                    <?php foreach ($formData['items'] as $index => $item) { ?>
                                        <?php
                                        $selectedServiceId = (int) ($item['service_id'] ?? 0);
                                        $servicePrice = isset($servicesById[$selectedServiceId]) ? (float) $servicesById[$selectedServiceId]['price'] : 0.0;
                                        $minimumAllowed = $servicePrice * 0.5;
                                        ?>
                                        <div class="cashier-item-row" data-cashier-item>
                                            <div class="field-group">
                                                <label>✂️ الخدمة</label>
                                                <select name="items[<?php echo $index; ?>][service_id]" data-service-select required <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>>
                                                    <option value="">اختر الخدمة</option>
                                                    <?php foreach ($services as $service) { ?>
                                                        <option value="<?php echo (int) $service['id']; ?>" <?php echo (string) ($item['service_id'] ?? '') === (string) $service['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($service['service_name']); ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>

                                            <div class="field-group">
                                                <label>💵 السعر المسجل</label>
                                                <input type="text" value="<?php echo number_format($servicePrice, 2); ?> ج" data-base-price-display readonly>
                                            </div>

                                            <div class="field-group">
                                                <label>📉 أقل مبلغ</label>
                                                <input type="text" value="<?php echo number_format($minimumAllowed, 2); ?> ج" data-min-price-display readonly>
                                            </div>

                                            <div class="field-group">
                                                <label>💰 المبلغ</label>
                                                <input
                                                    type="number"
                                                    name="items[<?php echo $index; ?>][amount]"
                                                    min="<?php echo formatCashierAmount($minimumAllowed); ?>"
                                                    step="0.01"
                                                    required
                                                    value="<?php echo htmlspecialchars((string) ($item['amount'] ?? '')); ?>"
                                                    data-amount-input
                                                    <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>
                                                >
                                            </div>

                                            <button type="button" class="btn btn-danger cashier-remove-btn" data-remove-item <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>>حذف الخدمة</button>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="cashier-summary-strip">
                                <div class="cashier-summary-box">
                                    <span class="overview-label">إجمالي الفاتورة</span>
                                    <strong class="cashier-summary-value" id="cashierInvoiceTotal">0.00 ج</strong>
                                </div>
                                <?php if ($isManager) { ?>
                                    <div class="cashier-summary-box">
                                        <span class="overview-label">نسبة الحلاق</span>
                                        <strong class="cashier-summary-value" id="cashierBarberShare">0.00 ج</strong>
                                    </div>
                                    <div class="cashier-summary-box">
                                        <span class="overview-label">نسبة الصالون</span>
                                        <strong class="cashier-summary-value" id="cashierSalonShare">0.00 ج</strong>
                                    </div>
                                <?php } ?>
                            </div>

                            <div class="form-actions-row cashier-actions-row">
                                <button type="submit" class="btn <?php echo $formData['id'] !== '' ? 'btn-warning' : 'btn-success'; ?>" <?php echo (!$employees || !$barbers || !$services) ? 'disabled' : ''; ?>>
                                    <?php echo $formData['id'] !== '' ? '💾 حفظ التعديل' : '💾 حفظ الفاتورة'; ?>
                                </button>
                                <a href="salon_cashier.php?filter_date=<?php echo urlencode($filterDate); ?>" class="btn btn-secondary">🧹 فاتورة جديدة</a>
                            </div>
                        </form>
                    </section>

                    <section class="cashier-panel cashier-log-panel">
                        <div class="page-header cashier-panel-header">
                            <div>
                                <h2 class="section-title cashier-mini-title">📋 سجل الفواتير اليومي</h2>
                                <p class="page-subtitle">عرض الفواتير حسب التاريخ</p>
                            </div>
                        </div>

                        <form method="get" class="cashier-filter-form">
                            <div class="field-group horizontal-field">
                                <label>📅 التاريخ</label>
                                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                            </div>
                            <div class="form-actions-row cashier-actions-row cashier-filter-actions">
                                <button type="submit" class="btn btn-primary">🔎 عرض</button>
                            </div>
                        </form>

                        <div class="table-wrap">
                            <table class="data-table responsive-table cashier-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>🕒 الوقت</th>
                                        <th>��‍💼 الموظف</th>
                                        <th>💈 الحلاق</th>
                                        <th>👤 العميل</th>
                                        <th>📞 الهاتف</th>
                                        <th>✂️ الخدمات</th>
                                        <th>💵 الإجمالي</th>
                                        <?php if ($isManager) { ?>
                                            <th>💯 النسبة</th>
                                            <th>💰 نسبة الحلاق</th>
                                            <th>🏦 نسبة الصالون</th>
                                            <th>⚙️ الإجراءات</th>
                                        <?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($invoiceRows) { ?>
                                        <?php foreach ($invoiceRows as $invoiceRow) { ?>
                                            <?php $invoiceId = (int) $invoiceRow['id']; ?>
                                            <tr>
                                                <td data-label="#"><?php echo $invoiceId; ?></td>
                                                <td data-label="🕒 الوقت"><?php echo formatDateTimeValue($invoiceRow['created_at']); ?></td>
                                                <td data-label="🧑‍💼 الموظف"><?php echo htmlspecialchars($invoiceRow['employee_name']); ?></td>
                                                <td data-label="💈 الحلاق"><?php echo htmlspecialchars($invoiceRow['barber_name']); ?></td>
                                                <td data-label="👤 العميل"><?php echo htmlspecialchars($invoiceRow['customer_name'] !== '' ? $invoiceRow['customer_name'] : '—'); ?></td>
                                                <td data-label="📞 الهاتف"><?php echo htmlspecialchars($invoiceRow['customer_phone'] !== '' ? $invoiceRow['customer_phone'] : '—'); ?></td>
                                                <td data-label="✂️ الخدمات">
                                                    <div class="cashier-service-stack">
                                                        <?php foreach ($invoiceItemsByInvoiceId[$invoiceId] ?? [] as $invoiceItem) { ?>
                                                            <div class="cashier-service-pill">
                                                                <span><?php echo htmlspecialchars($invoiceItem['service_name']); ?></span>
                                                                <strong><?php echo number_format((float) $invoiceItem['billed_amount'], 2); ?> ج</strong>
                                                            </div>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                                <td data-label="💵 الإجمالي"><?php echo number_format((float) $invoiceRow['total_amount'], 2); ?> ج</td>
                                                <?php if ($isManager) { ?>
                                                    <td data-label="💯 النسبة"><?php echo number_format((float) $invoiceRow['barber_commission_percent'], 2); ?>%</td>
                                                    <td data-label="💰 نسبة الحلاق"><?php echo number_format((float) $invoiceRow['barber_share_amount'], 2); ?> ج</td>
                                                    <td data-label="🏦 نسبة الصالون"><?php echo number_format((float) $invoiceRow['salon_share_amount'], 2); ?> ج</td>
                                                    <td class="action-cell" data-label="⚙️ الإجراءات">
                                                        <a href="salon_cashier.php?edit=<?php echo $invoiceId; ?>&filter_date=<?php echo urlencode($filterDate); ?>" class="btn btn-warning">✏️ تعديل</a>
                                                        <form method="post" data-confirm-message="حذف الفاتورة رقم <?php echo $invoiceId; ?>؟">
                                                            <input type="hidden" name="delete_invoice_id" value="<?php echo $invoiceId; ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                                                        </form>
                                                    </td>
                                                <?php } ?>
                                            </tr>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <tr>
                                            <td colspan="<?php echo $isManager ? '12' : '8'; ?>">لا توجد فواتير في هذا اليوم</td>
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

    <template id="cashierItemTemplate">
        <div class="cashier-item-row" data-cashier-item>
            <div class="field-group">
                <label>✂️ الخدمة</label>
                <select name="items[__INDEX__][service_id]" data-service-select required>
                    <option value="">اختر الخدمة</option>
                    <?php foreach ($services as $service) { ?>
                        <option value="<?php echo (int) $service['id']; ?>"><?php echo htmlspecialchars($service['service_name']); ?></option>
                    <?php } ?>
                </select>
            </div>

            <div class="field-group">
                <label>💵 السعر المسجل</label>
                <input type="text" value="0.00 ج" data-base-price-display readonly>
            </div>

            <div class="field-group">
                <label>📉 أقل مبلغ</label>
                <input type="text" value="0.00 ج" data-min-price-display readonly>
            </div>

            <div class="field-group">
                <label>💰 المبلغ</label>
                <input type="number" name="items[__INDEX__][amount]" min="0.00" step="0.01" required value="" data-amount-input>
            </div>

            <button type="button" class="btn btn-danger cashier-remove-btn" data-remove-item>حذف الخدمة</button>
        </div>
    </template>

    <script type="application/json" id="cashierServicesData"><?php echo json_encode($servicesJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <script type="application/json" id="cashierBarbersData"><?php echo json_encode($barbersJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <script src="assets/script.js"></script>
</body>
</html>
