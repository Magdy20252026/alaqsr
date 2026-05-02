<?php
require_once "config.php";
requireLogin();

if (!canAccess('sales_cashier')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const SALES_MIN_PRICE_RATIO = 0.5;

function getSalesInvoiceTypes()
{
    return [
        'sale' => 'بيع',
        'return' => 'مرتجع'
    ];
}

function getSalesCashierNumber($value)
{
    return trim((string) $value);
}

function isSalesCashierNumericValue($value)
{
    return preg_match('/^\d+(?:\.\d{1,2})?$/', getSalesCashierNumber($value)) === 1;
}

function formatSalesCashierAmount($value)
{
    return number_format((float) $value, 2, '.', '');
}

function normalizeSalesCashierDate($value)
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

function getSalesCashierMessage($key)
{
    $messages = [
        'created' => 'تم حفظ الفاتورة بنجاح',
        'updated' => 'تم تعديل الفاتورة بنجاح',
        'deleted' => 'تم حذف الفاتورة بنجاح'
    ];

    return $messages[$key] ?? '';
}

function fetchSalesInvoiceItems($conn, $invoiceId)
{
    $stmt = $conn->prepare(
        "SELECT item_id, item_name, pricing_type, quantity, registered_price, unit_price, line_total
         FROM sales_invoice_items
         WHERE invoice_id = ?
         ORDER BY id ASC"
    );
    $stmt->execute([(int) $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function applySalesInventoryAdjustments($conn, $invoiceType, $invoiceItems, $reverse = false)
{
    foreach ($invoiceItems as $invoiceItem) {
        if (($invoiceItem['pricing_type'] ?? '') !== 'quantity_price') {
            continue;
        }

        $itemId = (int) ($invoiceItem['item_id'] ?? 0);
        $quantity = (float) ($invoiceItem['quantity'] ?? 0);
        if ($itemId <= 0 || $quantity <= 0) {
            continue;
        }

        $itemStmt = $conn->prepare(
            "SELECT id, quantity_value
             FROM items
             WHERE id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $itemStmt->execute([$itemId]);
        $itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC);

        if (!$itemRow) {
            throw new RuntimeException('missing_item');
        }

        if ($itemRow['quantity_value'] === null) {
            throw new RuntimeException('invalid_stock');
        }

        $delta = $invoiceType === 'sale' ? -$quantity : $quantity;
        if ($reverse) {
            $delta *= -1;
        }

        $currentQuantity = (float) $itemRow['quantity_value'];
        $nextQuantity = round($currentQuantity + $delta, 2);

        if ($nextQuantity < 0) {
            throw new RuntimeException('insufficient_stock');
        }

        $updateStmt = $conn->prepare("UPDATE items SET quantity_value = ? WHERE id = ?");
        $updateStmt->execute([
            formatSalesCashierAmount($nextQuantity),
            $itemId
        ]);
    }
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

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(255) NOT NULL,
            pricing_type ENUM('fixed_price', 'quantity_price') NOT NULL DEFAULT 'fixed_price',
            quantity_value DECIMAL(10,2) DEFAULT NULL,
            item_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS sales_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_type ENUM('sale', 'return') NOT NULL DEFAULT 'sale',
            employee_id INT UNSIGNED DEFAULT NULL,
            employee_name VARCHAR(255) NOT NULL DEFAULT '',
            items_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sales_invoices_created_at (created_at),
            INDEX idx_sales_invoices_type (invoice_type),
            INDEX idx_sales_invoices_employee_id (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS sales_invoice_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            pricing_type ENUM('fixed_price', 'quantity_price') NOT NULL DEFAULT 'fixed_price',
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
            registered_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sales_invoice_items_invoice_id (invoice_id),
            INDEX idx_sales_invoice_items_item_id (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة كاشير المبيعات");
}

$settings = getSiteSettings($conn);
$isManager = (($_SESSION['role'] ?? '') === APP_MANAGER_ROLE);
$invoiceTypes = getSalesInvoiceTypes();
$filterDate = normalizeSalesCashierDate($_GET['filter_date'] ?? $_POST['filter_date'] ?? date('Y-m-d'));
$errorMessage = '';
$successMessage = getSalesCashierMessage($_GET['message'] ?? '');

$employeesStmt = $conn->prepare("SELECT id, employee_name FROM employees ORDER BY employee_name ASC");
$employeesStmt->execute();
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$itemsStmt = $conn->prepare("SELECT id, item_name, pricing_type, quantity_value, item_price FROM items ORDER BY item_name ASC");
$itemsStmt->execute();
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$employeesById = [];
foreach ($employees as $employee) {
    $employeesById[(int) $employee['id']] = $employee;
}

$itemsById = [];
$itemsJs = [];
foreach ($items as $item) {
    $itemId = (int) $item['id'];
    $registeredPrice = (float) $item['item_price'];
    $availableQuantity = $item['quantity_value'] !== null ? (float) $item['quantity_value'] : null;
    $itemsById[$itemId] = $item;
    $itemsJs[(string) $itemId] = [
        'item_name' => $item['item_name'],
        'pricing_type' => $item['pricing_type'],
        'registered_price' => $registeredPrice,
        'min_price' => $registeredPrice * SALES_MIN_PRICE_RATIO,
        'available_quantity' => $availableQuantity
    ];
}

$formData = [
    'id' => '',
    'invoice_type' => 'sale',
    'employee_id' => '',
    'items' => [
        [
            'item_id' => '',
            'quantity' => '1.00',
            'unit_price' => ''
        ]
    ]
];

if (isset($_GET['edit'])) {
    if (!$isManager) {
        http_response_code(403);
        die("غير مصرح");
    }

    $invoiceId = (int) $_GET['edit'];
    $invoiceStmt = $conn->prepare("SELECT * FROM sales_invoices WHERE id = ? LIMIT 1");
    $invoiceStmt->execute([$invoiceId]);
    $editInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

    if ($editInvoice) {
        $filterDate = date('Y-m-d', strtotime($editInvoice['created_at']));
        $editItems = fetchSalesInvoiceItems($conn, $invoiceId);
        $preparedItems = [];

        foreach ($editItems as $item) {
            $preparedItems[] = [
                'item_id' => (string) $item['item_id'],
                'quantity' => formatSalesCashierAmount($item['quantity']),
                'unit_price' => formatSalesCashierAmount($item['unit_price'])
            ];
        }

        if (!$preparedItems) {
            $preparedItems[] = [
                'item_id' => '',
                'quantity' => '1.00',
                'unit_price' => ''
            ];
        }

        $formData = [
            'id' => (string) $editInvoice['id'],
            'invoice_type' => (string) $editInvoice['invoice_type'],
            'employee_id' => $editInvoice['employee_id'] !== null ? (string) $editInvoice['employee_id'] : '',
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

            $invoiceStmt = $conn->prepare("SELECT id, invoice_type FROM sales_invoices WHERE id = ? LIMIT 1 FOR UPDATE");
            $invoiceStmt->execute([$invoiceId]);
            $existingInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingInvoice) {
                throw new RuntimeException('missing_invoice');
            }

            $existingItems = fetchSalesInvoiceItems($conn, $invoiceId);
            applySalesInventoryAdjustments($conn, $existingInvoice['invoice_type'], $existingItems, true);

            $deleteItemsStmt = $conn->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?");
            $deleteItemsStmt->execute([$invoiceId]);
            $deleteInvoiceStmt = $conn->prepare("DELETE FROM sales_invoices WHERE id = ?");
            $deleteInvoiceStmt->execute([$invoiceId]);

            $conn->commit();
            header("Location: sales_cashier.php?filter_date=" . urlencode($filterDate) . "&message=deleted");
            exit;
        } catch (RuntimeException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errorMessage = $e->getMessage() === 'missing_invoice'
                ? 'الفاتورة غير موجودة'
                : ($e->getMessage() === 'invalid_stock' ? 'رصيد أحد الأصناف غير صالح للتحديث' : 'تعذر حذف الفاتورة');
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errorMessage = 'تعذر حذف الفاتورة';
        }
    } else {
        $formData = [
            'id' => trim((string) ($_POST['id'] ?? '')),
            'invoice_type' => trim((string) ($_POST['invoice_type'] ?? 'sale')),
            'employee_id' => trim((string) ($_POST['employee_id'] ?? '')),
            'items' => is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : []
        ];

        if ($formData['id'] !== '' && !$isManager) {
            http_response_code(403);
            die("غير مصرح");
        }

        if (!isset($invoiceTypes[$formData['invoice_type']])) {
            $errorMessage = 'اختر نوع فاتورة صحيح';
        } elseif (!$items) {
            $errorMessage = 'يجب تسجيل الأصناف أولاً';
        } elseif ($formData['invoice_type'] === 'sale' && !$employees) {
            $errorMessage = 'يجب تسجيل الموظفين أولاً لإنشاء فاتورة بيع';
        } elseif ($formData['invoice_type'] === 'sale' && ($formData['employee_id'] === '' || !isset($employeesById[(int) $formData['employee_id']]))) {
            $errorMessage = 'اختر الموظف';
        } else {
            $validatedItems = [];
            $invoiceTotal = 0.0;

            foreach ($formData['items'] as $item) {
                $itemIdInput = trim((string) ($item['item_id'] ?? ''));
                $quantityInput = getSalesCashierNumber($item['quantity'] ?? '');
                $unitPriceInput = getSalesCashierNumber($item['unit_price'] ?? '');

                if ($itemIdInput === '' && $quantityInput === '' && $unitPriceInput === '') {
                    continue;
                }

                $itemId = (int) $itemIdInput;
                if (!$itemId || !isset($itemsById[$itemId])) {
                    $errorMessage = 'اختر صنفًا صحيحًا لكل بند';
                    break;
                }

                if (!isSalesCashierNumericValue($quantityInput) || (float) $quantityInput <= 0) {
                    $errorMessage = 'الكمية يجب أن تكون رقمًا أكبر من صفر';
                    break;
                }

                if (!isSalesCashierNumericValue($unitPriceInput) || (float) $unitPriceInput < 0) {
                    $errorMessage = 'السعر يجب أن يكون رقمًا موجبًا أو صفرًا';
                    break;
                }

                $registeredPrice = (float) $itemsById[$itemId]['item_price'];
                $minimumAllowed = $registeredPrice * SALES_MIN_PRICE_RATIO;
                $unitPrice = (float) $unitPriceInput;
                $quantity = (float) $quantityInput;

                if ($unitPrice < $minimumAllowed) {
                    $errorMessage = 'لا يمكن أن يقل سعر الصنف عن 50% من السعر المسجل';
                    break;
                }

                $lineTotal = $quantity * $unitPrice;
                $validatedItems[] = [
                    'item_id' => $itemId,
                    'item_name' => $itemsById[$itemId]['item_name'],
                    'pricing_type' => $itemsById[$itemId]['pricing_type'],
                    'quantity' => formatSalesCashierAmount($quantity),
                    'registered_price' => formatSalesCashierAmount($registeredPrice),
                    'unit_price' => formatSalesCashierAmount($unitPrice),
                    'line_total' => formatSalesCashierAmount($lineTotal)
                ];
                $invoiceTotal += $lineTotal;
            }

            if ($errorMessage === '' && !$validatedItems) {
                $errorMessage = 'أضف صنفًا واحدًا على الأقل';
            }

            if ($errorMessage === '') {
                $employeeId = $formData['invoice_type'] === 'sale' ? (int) $formData['employee_id'] : null;
                $employeeName = $employeeId !== null ? $employeesById[$employeeId]['employee_name'] : '';

                try {
                    $conn->beginTransaction();

                    if ($formData['id'] === '') {
                        applySalesInventoryAdjustments($conn, $formData['invoice_type'], $validatedItems);

                        $invoiceStmt = $conn->prepare(
                            "INSERT INTO sales_invoices (
                                invoice_type,
                                employee_id,
                                employee_name,
                                items_count,
                                total_amount
                            ) VALUES (?, ?, ?, ?, ?)"
                        );
                        $invoiceStmt->execute([
                            $formData['invoice_type'],
                            $employeeId,
                            $employeeName,
                            count($validatedItems),
                            formatSalesCashierAmount($invoiceTotal)
                        ]);
                        $invoiceId = (int) $conn->lastInsertId();
                        $messageKey = 'created';
                    } else {
                        $invoiceId = (int) $formData['id'];
                        $invoiceStmt = $conn->prepare(
                            "SELECT id, invoice_type
                             FROM sales_invoices
                             WHERE id = ?
                             LIMIT 1
                             FOR UPDATE"
                        );
                        $invoiceStmt->execute([$invoiceId]);
                        $existingInvoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$existingInvoice) {
                            throw new RuntimeException('missing_invoice');
                        }

                        $existingItems = fetchSalesInvoiceItems($conn, $invoiceId);
                        applySalesInventoryAdjustments($conn, $existingInvoice['invoice_type'], $existingItems, true);
                        applySalesInventoryAdjustments($conn, $formData['invoice_type'], $validatedItems);

                        $updateStmt = $conn->prepare(
                            "UPDATE sales_invoices
                             SET invoice_type = ?, employee_id = ?, employee_name = ?, items_count = ?, total_amount = ?
                             WHERE id = ?"
                        );
                        $updateStmt->execute([
                            $formData['invoice_type'],
                            $employeeId,
                            $employeeName,
                            count($validatedItems),
                            formatSalesCashierAmount($invoiceTotal),
                            $invoiceId
                        ]);

                        $deleteItemsStmt = $conn->prepare("DELETE FROM sales_invoice_items WHERE invoice_id = ?");
                        $deleteItemsStmt->execute([$invoiceId]);
                        $messageKey = 'updated';
                    }

                    $itemStmt = $conn->prepare(
                        "INSERT INTO sales_invoice_items (
                            invoice_id,
                            item_id,
                            item_name,
                            pricing_type,
                            quantity,
                            registered_price,
                            unit_price,
                            line_total
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    foreach ($validatedItems as $validatedItem) {
                        $itemStmt->execute([
                            $invoiceId,
                            $validatedItem['item_id'],
                            $validatedItem['item_name'],
                            $validatedItem['pricing_type'],
                            $validatedItem['quantity'],
                            $validatedItem['registered_price'],
                            $validatedItem['unit_price'],
                            $validatedItem['line_total']
                        ]);
                    }

                    $conn->commit();
                    header("Location: sales_cashier.php?filter_date=" . urlencode($filterDate) . "&message=" . $messageKey);
                    exit;
                } catch (RuntimeException $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }

                    if ($e->getMessage() === 'missing_invoice') {
                        $errorMessage = 'الفاتورة غير موجودة';
                    } elseif ($e->getMessage() === 'missing_item') {
                        $errorMessage = 'أحد الأصناف لم يعد موجودًا';
                    } elseif ($e->getMessage() === 'invalid_stock') {
                        $errorMessage = 'رصيد أحد الأصناف غير صالح للتحديث';
                    } elseif ($e->getMessage() === 'insufficient_stock') {
                        $errorMessage = 'الكمية المطلوبة أكبر من الرصيد المتاح في الأصناف';
                    } else {
                        $errorMessage = 'تعذر حفظ الفاتورة';
                    }
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
                    'item_id' => '',
                    'quantity' => '1.00',
                    'unit_price' => ''
                ]
            ];
        }
    }
}

$summaryStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS invoices_count,
        COALESCE(SUM(CASE WHEN invoice_type = 'sale' THEN 1 ELSE 0 END), 0) AS sales_count,
        COALESCE(SUM(CASE WHEN invoice_type = 'return' THEN 1 ELSE 0 END), 0) AS returns_count,
        COALESCE(SUM(CASE WHEN invoice_type = 'sale' THEN total_amount ELSE 0 END), 0) AS sales_total,
        COALESCE(SUM(CASE WHEN invoice_type = 'return' THEN total_amount ELSE 0 END), 0) AS returns_total,
        COALESCE(SUM(items_count), 0) AS items_count
     FROM sales_invoices
     WHERE DATE(created_at) = ?"
);
$summaryStmt->execute([$filterDate]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'invoices_count' => 0,
    'sales_count' => 0,
    'returns_count' => 0,
    'sales_total' => 0,
    'returns_total' => 0,
    'items_count' => 0
];
$netTotal = (float) $summary['sales_total'] - (float) $summary['returns_total'];

$invoiceRowsStmt = $conn->prepare(
    "SELECT
        id,
        invoice_type,
        employee_name,
        items_count,
        total_amount,
        created_at
     FROM sales_invoices
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
        "SELECT invoice_id, item_name, pricing_type, quantity, registered_price, unit_price, line_total
         FROM sales_invoice_items
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
    <title>كاشير المبيعات</title>
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
                        <h1 class="section-title">🛒 كاشير المبيعات</h1>
                        <p class="page-subtitle">إدارة فواتير البيع والمرتجع مع متابعة الأسعار والمخزون اليومي</p>
                    </div>
                </div>

                <?php if ($successMessage !== '') { ?>
                    <div class="status-box status-box-success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php } ?>

                <?php if ($errorMessage !== '') { ?>
                    <div class="status-box status-box-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <?php if (!$items) { ?>
                    <div class="status-box status-box-danger">يجب تسجيل الأصناف أولاً قبل إنشاء الفواتير</div>
                <?php } elseif (!$employees) { ?>
                    <div class="status-box status-box-danger">يمكن إنشاء مرتجع الآن، ولإنشاء فاتورة بيع يجب تسجيل موظف واحد على الأقل</div>
                <?php } ?>

                <div class="cashier-overview sales-overview">
                    <div class="overview-card">
                        <span class="overview-label">فواتير البيع</span>
                        <strong class="overview-value"><?php echo (int) $summary['sales_count']; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">فواتير المرتجع</span>
                        <strong class="overview-value"><?php echo (int) $summary['returns_count']; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي البيع</span>
                        <strong class="overview-value"><?php echo number_format((float) $summary['sales_total'], 2); ?> ج</strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">إجمالي المرتجع</span>
                        <strong class="overview-value"><?php echo number_format((float) $summary['returns_total'], 2); ?> ج</strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">صافي اليوم</span>
                        <strong class="overview-value <?php echo $netTotal < 0 ? 'sales-negative-total' : 'sales-positive-total'; ?>"><?php echo number_format($netTotal, 2); ?> ج</strong>
                    </div>
                </div>

                <div class="cashier-panels">
                    <section class="cashier-panel cashier-form-panel">
                        <div class="page-header cashier-panel-header">
                            <div>
                                <h2 class="section-title cashier-mini-title"><?php echo $formData['id'] !== '' ? 'تعديل الفاتورة' : 'فاتورة جديدة'; ?></h2>
                                <p class="page-subtitle">اختر نوع الفاتورة ثم أضف الأصناف والكمية والسعر لكل بند</p>
                            </div>
                        </div>

                        <form method="post" class="cashier-form-grid" id="salesCashierForm">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="filter_date" value="<?php echo htmlspecialchars($filterDate); ?>">

                            <div class="field-group horizontal-field">
                                <label for="salesInvoiceType">نوع الفاتورة</label>
                                <select name="invoice_type" id="salesInvoiceType" <?php echo !$items ? 'disabled' : ''; ?>>
                                    <?php foreach ($invoiceTypes as $invoiceTypeKey => $invoiceTypeLabel) { ?>
                                        <option value="<?php echo htmlspecialchars($invoiceTypeKey); ?>" <?php echo $formData['invoice_type'] === $invoiceTypeKey ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($invoiceTypeLabel); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="field-group horizontal-field sales-employee-field <?php echo $formData['invoice_type'] === 'sale' ? '' : 'sales-employee-field-hidden'; ?>" data-sales-employee-field>
                                <label for="salesEmployeeSelect">الموظف</label>
                                <select
                                    name="employee_id"
                                    id="salesEmployeeSelect"
                                    data-no-employees="<?php echo $employees ? '0' : '1'; ?>"
                                    <?php echo $formData['invoice_type'] === 'sale' ? '' : 'disabled'; ?>
                                    <?php echo $formData['invoice_type'] === 'sale' ? 'required' : ''; ?>
                                >
                                    <option value="">اختر الموظف</option>
                                    <?php foreach ($employees as $employee) { ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $formData['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($employee['employee_name']); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="cashier-items-card">
                                <div class="cashier-inline-head">
                                    <h3 class="cashier-card-title">الأصناف</h3>
                                    <button type="button" class="btn btn-success" id="addSalesCashierItem" <?php echo !$items ? 'disabled' : ''; ?>>إضافة صنف</button>
                                </div>

                                <div class="cashier-items-list" id="salesCashierItems" data-next-index="<?php echo count($formData['items']); ?>">
                                    <?php foreach ($formData['items'] as $index => $item) { ?>
                                        <?php
                                        $selectedItemId = (int) ($item['item_id'] ?? 0);
                                        $registeredPrice = isset($itemsById[$selectedItemId]) ? (float) $itemsById[$selectedItemId]['item_price'] : 0.0;
                                        $minimumAllowed = $registeredPrice * SALES_MIN_PRICE_RATIO;
                                        $availableQuantity = isset($itemsById[$selectedItemId]) && $itemsById[$selectedItemId]['quantity_value'] !== null
                                            ? (float) $itemsById[$selectedItemId]['quantity_value']
                                            : null;
                                        $lineTotal = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
                                        ?>
                                        <div class="cashier-item-row sales-item-row" data-sales-item>
                                            <div class="field-group">
                                                <label>الصنف</label>
                                                <select name="items[<?php echo $index; ?>][item_id]" data-sales-item-select required <?php echo !$items ? 'disabled' : ''; ?>>
                                                    <option value="">اختر الصنف</option>
                                                    <?php foreach ($items as $itemOption) { ?>
                                                        <option value="<?php echo (int) $itemOption['id']; ?>" <?php echo (string) ($item['item_id'] ?? '') === (string) $itemOption['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($itemOption['item_name']); ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>

                                            <div class="field-group">
                                                <label>الرصيد</label>
                                                <input type="text" value="<?php echo $availableQuantity !== null ? number_format($availableQuantity, 2) : 'غير متابع'; ?>" data-sales-stock-display readonly>
                                            </div>

                                            <div class="field-group">
                                                <label>السعر المسجل</label>
                                                <input type="text" value="<?php echo number_format($registeredPrice, 2); ?> ج" data-sales-registered-price readonly>
                                            </div>

                                            <div class="field-group">
                                                <label>أقل سعر</label>
                                                <input type="text" value="<?php echo number_format($minimumAllowed, 2); ?> ج" data-sales-min-price readonly>
                                            </div>

                                            <div class="field-group">
                                                <label>الكمية</label>
                                                <input
                                                    type="number"
                                                    name="items[<?php echo $index; ?>][quantity]"
                                                    min="0.01"
                                                    step="0.01"
                                                    required
                                                    value="<?php echo htmlspecialchars((string) ($item['quantity'] ?? '1.00')); ?>"
                                                    data-sales-quantity-input
                                                    <?php echo !$items ? 'disabled' : ''; ?>
                                                >
                                            </div>

                                            <div class="field-group">
                                                <label>سعر البيع</label>
                                                <input
                                                    type="number"
                                                    name="items[<?php echo $index; ?>][unit_price]"
                                                    min="<?php echo formatSalesCashierAmount($minimumAllowed); ?>"
                                                    step="0.01"
                                                    required
                                                    value="<?php echo htmlspecialchars((string) ($item['unit_price'] ?? '')); ?>"
                                                    data-sales-price-input
                                                    <?php echo !$items ? 'disabled' : ''; ?>
                                                >
                                            </div>

                                            <div class="field-group">
                                                <label>الإجمالي</label>
                                                <input type="text" value="<?php echo number_format($lineTotal, 2); ?> ج" data-sales-line-total readonly>
                                            </div>

                                            <button type="button" class="btn btn-danger cashier-remove-btn" data-remove-sales-item <?php echo !$items ? 'disabled' : ''; ?>>حذف الصنف</button>
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="cashier-summary-strip sales-summary-strip">
                                <div class="cashier-summary-box">
                                    <span class="overview-label">نوع الحركة</span>
                                    <strong class="cashier-summary-value" id="salesInvoiceModeLabel"><?php echo htmlspecialchars($invoiceTypes[$formData['invoice_type']]); ?></strong>
                                </div>
                                <div class="cashier-summary-box">
                                    <span class="overview-label">عدد البنود</span>
                                    <strong class="cashier-summary-value" id="salesItemsCount"><?php echo count($formData['items']); ?></strong>
                                </div>
                                <div class="cashier-summary-box">
                                    <span class="overview-label">إجمالي الفاتورة</span>
                                    <strong class="cashier-summary-value" id="salesInvoiceTotal">0.00 ج</strong>
                                </div>
                            </div>

                            <div class="form-actions-row cashier-actions-row">
                                <button type="submit" class="btn <?php echo $formData['id'] !== '' ? 'btn-warning' : 'btn-success'; ?>" <?php echo !$items ? 'disabled' : ''; ?>>
                                    <?php echo $formData['id'] !== '' ? 'حفظ التعديل' : 'حفظ الفاتورة'; ?>
                                </button>
                                <a href="sales_cashier.php?filter_date=<?php echo urlencode($filterDate); ?>" class="btn btn-secondary">فاتورة جديدة</a>
                            </div>
                        </form>
                    </section>

                    <section class="cashier-panel cashier-log-panel">
                        <div class="page-header cashier-panel-header">
                            <div>
                                <h2 class="section-title cashier-mini-title">جدول المبيعات اليومي</h2>
                                <p class="page-subtitle">عرض فواتير البيع والمرتجع حسب التاريخ</p>
                            </div>
                        </div>

                        <form method="get" class="cashier-filter-form">
                            <div class="field-group horizontal-field">
                                <label>التاريخ</label>
                                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                            </div>
                            <div class="form-actions-row cashier-actions-row cashier-filter-actions">
                                <button type="submit" class="btn btn-primary">عرض</button>
                            </div>
                        </form>

                        <div class="table-wrap">
                            <table class="data-table responsive-table cashier-table sales-cashier-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الوقت</th>
                                        <th>النوع</th>
                                        <th>الموظف</th>
                                        <th>الأصناف</th>
                                        <th>عدد البنود</th>
                                        <th>الإجمالي</th>
                                        <?php if ($isManager) { ?>
                                            <th>الإجراءات</th>
                                        <?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($invoiceRows) { ?>
                                        <?php foreach ($invoiceRows as $invoiceRow) { ?>
                                            <?php $invoiceId = (int) $invoiceRow['id']; ?>
                                            <tr>
                                                <td data-label="#"><?php echo $invoiceId; ?></td>
                                                <td data-label="الوقت"><?php echo formatDateTimeValue($invoiceRow['created_at']); ?></td>
                                                <td data-label="النوع">
                                                    <span class="sales-type-badge sales-type-badge-<?php echo $invoiceRow['invoice_type'] === 'return' ? 'return' : 'sale'; ?>">
                                                        <?php echo htmlspecialchars($invoiceTypes[$invoiceRow['invoice_type']] ?? '—'); ?>
                                                    </span>
                                                </td>
                                                <td data-label="الموظف"><?php echo htmlspecialchars($invoiceRow['employee_name'] !== '' ? $invoiceRow['employee_name'] : '—'); ?></td>
                                                <td data-label="الأصناف">
                                                    <div class="cashier-service-stack">
                                                        <?php foreach ($invoiceItemsByInvoiceId[$invoiceId] ?? [] as $invoiceItem) { ?>
                                                            <div class="cashier-service-pill sales-item-pill">
                                                                <div class="sales-item-pill-main">
                                                                    <span><?php echo htmlspecialchars($invoiceItem['item_name']); ?></span>
                                                                    <strong><?php echo number_format((float) $invoiceItem['quantity'], 2); ?> × <?php echo number_format((float) $invoiceItem['unit_price'], 2); ?> ج</strong>
                                                                </div>
                                                                <span class="sales-item-pill-meta <?php echo $invoiceItem['pricing_type'] === 'quantity_price' ? 'sales-stock-pill' : ''; ?>">
                                                                    <?php echo $invoiceItem['pricing_type'] === 'quantity_price' ? 'مخزون' : 'سعر فقط'; ?>
                                                                </span>
                                                            </div>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                                <td data-label="عدد البنود"><?php echo (int) $invoiceRow['items_count']; ?></td>
                                                <td data-label="الإجمالي"><?php echo number_format((float) $invoiceRow['total_amount'], 2); ?> ج</td>
                                                <?php if ($isManager) { ?>
                                                    <td class="action-cell" data-label="الإجراءات">
                                                        <a href="sales_cashier.php?edit=<?php echo $invoiceId; ?>&filter_date=<?php echo urlencode($filterDate); ?>" class="btn btn-warning">تعديل</a>
                                                        <form method="post" data-confirm-message="حذف الفاتورة رقم <?php echo $invoiceId; ?>؟">
                                                            <input type="hidden" name="delete_invoice_id" value="<?php echo $invoiceId; ?>">
                                                            <input type="hidden" name="filter_date" value="<?php echo htmlspecialchars($filterDate); ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                            <button type="submit" class="btn btn-danger">حذف</button>
                                                        </form>
                                                    </td>
                                                <?php } ?>
                                            </tr>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <tr>
                                            <td colspan="<?php echo $isManager ? '8' : '7'; ?>">لا توجد فواتير في هذا اليوم</td>
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

    <template id="salesCashierItemTemplate">
        <div class="cashier-item-row sales-item-row" data-sales-item>
            <div class="field-group">
                <label>الصنف</label>
                <select name="items[__INDEX__][item_id]" data-sales-item-select required>
                    <option value="">اختر الصنف</option>
                    <?php foreach ($items as $item) { ?>
                        <option value="<?php echo (int) $item['id']; ?>"><?php echo htmlspecialchars($item['item_name']); ?></option>
                    <?php } ?>
                </select>
            </div>

            <div class="field-group">
                <label>الرصيد</label>
                <input type="text" value="غير متابع" data-sales-stock-display readonly>
            </div>

            <div class="field-group">
                <label>السعر المسجل</label>
                <input type="text" value="0.00 ج" data-sales-registered-price readonly>
            </div>

            <div class="field-group">
                <label>أقل سعر</label>
                <input type="text" value="0.00 ج" data-sales-min-price readonly>
            </div>

            <div class="field-group">
                <label>الكمية</label>
                <input type="number" name="items[__INDEX__][quantity]" min="0.01" step="0.01" required value="1.00" data-sales-quantity-input>
            </div>

            <div class="field-group">
                <label>سعر البيع</label>
                <input type="number" name="items[__INDEX__][unit_price]" min="0.00" step="0.01" required value="" data-sales-price-input>
            </div>

            <div class="field-group">
                <label>الإجمالي</label>
                <input type="text" value="0.00 ج" data-sales-line-total readonly>
            </div>

            <button type="button" class="btn btn-danger cashier-remove-btn" data-remove-sales-item>حذف الصنف</button>
        </div>
    </template>

    <script type="application/json" id="salesItemsData"><?php echo json_encode($itemsJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <script src="assets/script.js"></script>
</body>
</html>
