<?php
require_once "config.php";
requireLogin();

if (!canAccess('services')) {
    http_response_code(403);
    die("غير مصرح");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
} catch (PDOException $e) {
    http_response_code(500);
    die("تعذر تجهيز صفحة الخدمات");
}

$settings = getSiteSettings($conn);
$editService = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        die("الطلب غير صالح");
    }

    if (isset($_POST['delete_id'])) {
        $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([(int) $_POST['delete_id']]);
        header("Location: services.php");
        exit;
    }

    $id = trim($_POST['id'] ?? '');
    $serviceName = trim($_POST['service_name'] ?? '');
    $priceInput = trim($_POST['price'] ?? '0');

    if ($serviceName !== '') {
        $priceNumber = is_numeric($priceInput) ? max(0, (float) $priceInput) : 0;
        $priceValue = number_format($priceNumber, 2, '.', '');

        if ($id === '') {
            $stmt = $conn->prepare("INSERT INTO services (service_name, price) VALUES (?, ?)");
            $stmt->execute([$serviceName, $priceValue]);
        } else {
            $stmt = $conn->prepare("UPDATE services SET service_name = ?, price = ? WHERE id = ?");
            $stmt->execute([$serviceName, $priceValue, (int) $id]);
        }
    }

    header("Location: services.php");
    exit;
}

if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$id]);
    $editService = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$servicesStmt = $conn->prepare("SELECT id, service_name, price FROM services ORDER BY id DESC");
$servicesStmt->execute();
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الخدمات</title>
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
                    <h1 class="section-title">✂️ الخدمات</h1>
                </div>

                <form method="post" class="inline-form services-form-grid">
                    <input type="hidden" name="id" value="<?php echo isset($editService['id']) ? (int) $editService['id'] : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="field-group horizontal-field">
                        <label>🧾 اسم الخدمة</label>
                        <input type="text" name="service_name" required value="<?php echo htmlspecialchars($editService['service_name'] ?? ''); ?>">
                    </div>

                    <div class="field-group horizontal-field">
                        <label>💵 السعر</label>
                        <input type="number" name="price" min="0" step="0.01" required value="<?php echo isset($editService['price']) ? number_format((float) $editService['price'], 2, '.', '') : ''; ?>">
                    </div>

                    <div class="form-actions-row services-actions-row">
                        <button type="submit" class="btn <?php echo $editService ? 'btn-warning' : 'btn-success'; ?>">
                            <?php echo $editService ? '✏️ تعديل' : '➕ إضافة'; ?>
                        </button>
                        <a href="services.php" class="btn btn-secondary">🧹 جديد</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table class="data-table services-table responsive-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>🧾 اسم الخدمة</th>
                                <th>💵 السعر</th>
                                <th>⚙️ الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($services) { ?>
                                <?php foreach ($services as $service) { ?>
                                    <tr>
                                        <td data-label="#"><?php echo $service['id']; ?></td>
                                        <td data-label="🧾 اسم الخدمة"><?php echo htmlspecialchars($service['service_name']); ?></td>
                                        <td data-label="💵 السعر"><?php echo number_format((float) $service['price'], 2); ?></td>
                                        <td class="action-cell" data-label="⚙️ الإجراءات">
                                            <a href="services.php?edit=<?php echo $service['id']; ?>" class="btn btn-warning">✏️ تعديل</a>
                                            <form method="post" onsubmit="return confirm('حذف الخدمة؟')">
                                                <input type="hidden" name="delete_id" value="<?php echo $service['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <button type="submit" class="btn btn-danger">🗑️ حذف</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="4">📭 لا توجد خدمات</td>
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
