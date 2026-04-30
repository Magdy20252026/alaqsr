<?php
require_once "config.php";
requireLogin();

if (!canAccess('user_permissions')) {
    die("غير مصرح");
}

$settings = getSiteSettings($conn);
$users = $conn->query("SELECT * FROM users WHERE role = 'مشرف' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
$selectedUserId = $_POST['user_id'] ?? ($_GET['user_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    $selectedUserId = (int) $_POST['user_id'];
    $permissions = $_POST['permissions'] ?? [];

    $stmt = $conn->prepare("DELETE FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$selectedUserId]);

    foreach ($permissions as $pageKey) {
        $stmt = $conn->prepare("INSERT INTO user_permissions (user_id, page_key) VALUES (?, ?)");
        $stmt->execute([$selectedUserId, $pageKey]);
    }

    header("Location: user_permissions.php?user_id=" . $selectedUserId);
    exit;
}

$currentPermissions = [];
if ($selectedUserId) {
    $stmt = $conn->prepare("SELECT page_key FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$selectedUserId]);
    $currentPermissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>صلاحيات المستخدمين</title>
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
                <form method="post" class="permission-form">
                    <div class="field-group">
                        <label>👤 المستخدم المشرف</label>
                        <select name="user_id" onchange="this.form.submit()" required>
                            <option value="">اختر المستخدم</option>
                            <?php foreach ($users as $user) { ?>
                                <option value="<?php echo $user['id']; ?>" <?php if ($selectedUserId == $user['id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <?php if ($selectedUserId) { ?>
                        <div class="permissions-grid">
                            <?php foreach ($allPages as $key => $label) { ?>
                                <label class="permission-item">
                                    <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>" <?php if (in_array($key, $currentPermissions)) echo 'checked'; ?>>
                                    <span><?php echo $label; ?></span>
                                </label>
                            <?php } ?>
                        </div>

                        <button type="submit" name="save_permissions" class="btn btn-primary">💾 حفظ الصلاحيات</button>
                    <?php } ?>
                </form>
            </div>
        </main>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>