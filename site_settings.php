<?php
require_once "config.php";
requireLogin();

if (!canAccess('site_settings')) {
    die("غير مصرح");
}

$settings = getSiteSettings($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $salonName = trim($_POST['salon_name'] ?? $settings['salon_name']);
    $logoPath = $settings['salon_logo'];

    if (isset($_FILES['salon_logo']) && $_FILES['salon_logo']['error'] === 0) {
        if (!is_dir('uploads')) {
            mkdir('uploads', 0777, true);
        }

        $ext = pathinfo($_FILES['salon_logo']['name'], PATHINFO_EXTENSION);
        $newName = 'uploads/logo_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['salon_logo']['tmp_name'], $newName);
        $logoPath = $newName;
    }

    $stmt = $conn->prepare("UPDATE site_settings SET salon_name = ?, salon_logo = ? WHERE id = 1");
    $stmt->execute([$salonName, $logoPath]);

    header("Location: site_settings.php");
    exit;
}

$settings = getSiteSettings($conn);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادت الموقع</title>
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
                <form method="post" enctype="multipart/form-data" class="settings-form">
                    <div class="field-group">
                        <label>🏷️ اسم الصالون</label>
                        <input type="text" name="salon_name" required value="<?php echo htmlspecialchars($settings['salon_name']); ?>">
                    </div>

                    <div class="field-group">
                        <label>🖼️ شعار الصالون</label>
                        <input type="file" name="salon_logo" accept="image/*">
                    </div>

                    <div class="logo-preview-box">
                        <img src="<?php echo htmlspecialchars($settings['salon_logo']); ?>" alt="logo" class="preview-logo">
                    </div>

                    <button type="submit" class="btn btn-primary">💾 حفظ الإعدادات</button>
                </form>
            </div>
        </main>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>