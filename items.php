<?php
require_once "config.php";
requireLogin();

if (!canAccess('items')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function getItemPricingTypes()
{
    return [
        'fixed_price' => 'سعر فقط',
        'quantity_price' => 'عدد وسعر'
    ];
}

function getItemsPageNumber($value)
{
    return trim((string) $value);
}

function isItemsPageNumericValue($value)
{
    return preg_match('/^\d+(?:\.\d{1,2})?$/', getItemsPageNumber($value)) === 1;
}

function formatItemsPageAmount($value)
{
    return number_format((float) $value, 2, '.', '');
}

function getItemsPricingLabel($pricingType, $pricingTypes)
{
    return $pricingTypes[$pricingType] ?? 'غير محدد';
}

try {
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
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة الأصناف");
}

$settings = getSiteSettings($conn);
$pricingTypes = getItemPricingTypes();
$formData = [
    'id' => '',
    'item_name' => '',
    'pricing_type' => 'fixed_price',
    'quantity_value' => '',
    'item_price' => ''
];
$errorMessage = '';
$editMode = false;

if (isset($_GET['edit'])) {
    $itemId = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$itemId]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editItem) {
        $formData = [
            'id' => (string) $editItem['id'],
            'item_name' => $editItem['item_name'],
            'pricing_type' => $editItem['pricing_type'],
            'quantity_value' => $editItem['quantity_value'] !== null ? formatItemsPageAmount($editItem['quantity_value']) : '',
            'item_price' => formatItemsPageAmount($editItem['item_price'])
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
        $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: items.php");
        exit;
    }

    $formData = [
        'id' => trim($_POST['id'] ?? ''),
        'item_name' => trim($_POST['item_name'] ?? ''),
        'pricing_type' => trim($_POST['pricing_type'] ?? 'fixed_price'),
        'quantity_value' => getItemsPageNumber($_POST['quantity_value'] ?? ''),
        'item_price' => getItemsPageNumber($_POST['item_price'] ?? '')
    ];
    $editMode = $formData['id'] !== '';

    if ($formData['item_name'] === '') {
        $errorMessage = '⚠️ اسم الصنف مطلوب';
    } elseif (!isset($pricingTypes[$formData['pricing_type']])) {
        $errorMessage = '⚠️ اختر نوع تسجيل صحيح';
    } elseif (getTextLength($formData['item_name']) > 255) {
        $errorMessage = '⚠️ اسم الصنف طويل جدًا';
    } elseif (!isItemsPageNumericValue($formData['item_price'])) {
        $errorMessage = '⚠️ السعر يجب أن يكون رقمًا صحيحًا أو عشريًا';
    } elseif ((float) $formData['item_price'] < 0) {
        $errorMessage = '⚠️ السعر يجب أن يكون رقمًا موجبًا أو صفرًا';
    } elseif (
        $formData['pricing_type'] === 'quantity_price'
        && (!isItemsPageNumericValue($formData['quantity_value']) || (float) $formData['quantity_value'] <= 0)
    ) {
        $errorMessage = '⚠️ العدد يجب أن يكون رقمًا أكبر من صفر';
    } else {
        $priceValue = formatItemsPageAmount($formData['item_price']);
        $quantityValue = $formData['pricing_type'] === 'quantity_price'
            ? formatItemsPageAmount($formData['quantity_value'])
            : null;

        if ($formData['id'] === '') {
            $stmt = $conn->prepare(
                "INSERT INTO items (item_name, pricing_type, quantity_value, item_price)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $formData['item_name'],
                $formData['pricing_type'],
                $quantityValue,
                $priceValue
            ]);
        } else {
            $stmt = $conn->prepare(
                "UPDATE items
                 SET item_name = ?, pricing_type = ?, quantity_value = ?, item_price = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $formData['item_name'],
                $formData['pricing_type'],
                $quantityValue,
                $priceValue,
                (int) $formData['id']
            ]);
        }

        header("Location: items.php");
        exit;
    }
}

$itemsStmt = $conn->prepare("SELECT id, item_name, pricing_type, quantity_value, item_price FROM items ORDER BY id DESC");
$itemsStmt->execute();
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalItems = count($items);
$fixedPriceCount = 0;
$quantityPriceCount = 0;

foreach ($items as $item) {
    if ($item['pricing_type'] === 'quantity_price') {
        $quantityPriceCount++;
    } else {
        $fixedPriceCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الأصناف</title>
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

            <div class="content-card items-content-card">
                <div class="page-header">
                    <div>
                        <h1 class="section-title">📦 الأصناف</h1>
                        <p class="page-subtitle">إدارة الأصناف مع تسجيل الاسم وطريقة التسعير والعدد عند الحاجة.</p>
                    </div>
                </div>

                <div class="items-overview">
                    <div class="overview-card">
                        <span class="overview-label">إجمالي الأصناف</span>
                        <strong class="overview-value"><?php echo $totalItems; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">أصناف سعر فقط</span>
                        <strong class="overview-value"><?php echo $fixedPriceCount; ?></strong>
                    </div>
                    <div class="overview-card">
                        <span class="overview-label">أصناف عدد وسعر</span>
                        <strong class="overview-value"><?php echo $quantityPriceCount; ?></strong>
                    </div>
                </div>

                <?php if ($errorMessage !== '') { ?>
                    <div class="login-error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php } ?>

                <form method="post" class="inline-form items-form-grid">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($formData['id']); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="field-group horizontal-field items-wide-field">
                        <label>اسم الصنف</label>
                        <input type="text" name="item_name" required value="<?php echo htmlspecialchars($formData['item_name']); ?>">
                    </div>

                    <div class="field-group horizontal-field">
                        <label>نوع التسجيل</label>
                        <select name="pricing_type" id="itemPricingType" data-item-pricing-type>
                            <?php foreach ($pricingTypes as $pricingTypeKey => $pricingTypeLabel) { ?>
                                <option value="<?php echo htmlspecialchars($pricingTypeKey); ?>" <?php echo $formData['pricing_type'] === $pricingTypeKey ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pricingTypeLabel); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="field-group horizontal-field <?php echo $formData['pricing_type'] === 'quantity_price' ? '' : 'items-quantity-hidden'; ?>" data-item-quantity-field>
                        <label>العدد</label>
                        <input type="number" name="quantity_value" min="0.01" step="0.01" <?php echo $formData['pricing_type'] === 'quantity_price' ? 'required' : ''; ?> value="<?php echo htmlspecialchars($formData['quantity_value']); ?>">
                    </div>

                    <div class="field-group horizontal-field">
                        <label>السعر</label>
                        <input type="number" name="item_price" min="0" step="0.01" required value="<?php echo htmlspecialchars($formData['item_price']); ?>">
                    </div>

                    <div class="form-actions-row items-actions-row">
                        <button type="submit" class="btn <?php echo $editMode ? 'btn-warning' : 'btn-success'; ?>">
                            <?php echo $editMode ? 'تعديل الصنف' : 'إضافة الصنف'; ?>
                        </button>
                        <a href="items.php" class="btn btn-secondary">نموذج جديد</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table class="data-table responsive-table items-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>اسم الصنف</th>
                                <th>نوع التسجيل</th>
                                <th>العدد</th>
                                <th>السعر</th>
                                <th>الإجمالي</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($items) { ?>
                                <?php foreach ($items as $item) { ?>
                                    <?php
                                    $quantityValue = $item['pricing_type'] === 'quantity_price' && $item['quantity_value'] !== null
                                        ? (float) $item['quantity_value']
                                        : null;
                                    $priceValue = (float) $item['item_price'];
                                    $totalValue = $quantityValue !== null ? $quantityValue * $priceValue : $priceValue;
                                    ?>
                                    <tr>
                                        <td data-label="#"><?php echo $item['id']; ?></td>
                                        <td data-label="اسم الصنف"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td data-label="نوع التسجيل">
                                            <span class="item-type-badge item-type-badge-<?php echo $item['pricing_type'] === 'quantity_price' ? 'quantity' : 'fixed'; ?>">
                                                <?php echo htmlspecialchars(getItemsPricingLabel($item['pricing_type'], $pricingTypes)); ?>
                                            </span>
                                        </td>
                                        <td data-label="العدد"><?php echo $quantityValue !== null ? number_format($quantityValue, 2) : '—'; ?></td>
                                        <td data-label="السعر"><?php echo number_format($priceValue, 2); ?></td>
                                        <td data-label="الإجمالي"><?php echo number_format($totalValue, 2); ?></td>
                                        <td class="action-cell" data-label="الإجراءات">
                                            <a href="items.php?edit=<?php echo $item['id']; ?>" class="btn btn-warning">تعديل</a>
                                            <form method="post" data-confirm-message="حذف الصنف &quot;<?php echo htmlspecialchars($item['item_name']); ?>&quot;؟">
                                                <input type="hidden" name="delete_id" value="<?php echo $item['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <button type="submit" class="btn btn-danger">حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="7">لا توجد أصناف مسجلة حتى الآن</td>
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
