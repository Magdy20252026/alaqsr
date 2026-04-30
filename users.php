<?php
require_once "config.php";
requireLogin();

if (!canAccess('users')) {
    die("غير مصرح");
}

$settings = getSiteSettings($conn);
$editUser = null;

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: users.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';
    $usernameValue = trim($_POST['username'] ?? '');
    $passwordValue = trim($_POST['password'] ?? '');
    $roleValue = trim($_POST['role'] ?? '');

    if ($id === '') {
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, MD5(?), ?)");
        $stmt->execute([$usernameValue, $passwordValue, $roleValue]);
    } else {
        if ($passwordValue !== '') {
            $stmt = $conn->prepare("UPDATE users SET username = ?, password = MD5(?), role = ? WHERE id = ?");
            $stmt->execute([$usernameValue, $passwordValue, $roleValue, $id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
            $stmt->execute([$usernameValue, $roleValue, $id]);
        }
    }

    header("Location: users.php");
    exit;
}

if (isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

$users = $conn->query("SELECT * FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>المستخدمين</title>
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
                <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?php echo $editUser['id'] ?? ''; ?>">

                    <div class="field-group horizontal-field">
                        <label>👤 اسم المستخدم</label>
                        <input type="text" name="username" required value="<?php echo $editUser['username'] ?? ''; ?>">
                    </div>

                    <div class="field-group horizontal-field">
                        <label>🔒 كلمة السر</label>
                        <input type="text" name="password" value="">
                    </div>

                    <div class="field-group horizontal-field">
                        <label>🛡️ الصلاحية</label>
                        <select name="role" required>
                            <option value="">اختر</option>
                            <option value="مدير" <?php if (($editUser['role'] ?? '') === 'مدير') echo 'selected'; ?>>مدير</option>
                            <option value="مشرف" <?php if (($editUser['role'] ?? '') === 'مشرف') echo 'selected'; ?>>مشرف</option>
                        </select>
                    </div>

                    <div class="form-actions-row">
                        <button type="submit" class="btn btn-primary">💾 حفظ</button>
                        <a href="users.php" class="btn btn-secondary">🧹 جديد</a>
                    </div>
                </form>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>👤 اسم المستخدم</th>
                                <th>🛡️ الصلاحية</th>
                                <th>⚙️ الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user) { ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                                    <td class="action-cell">
                                        <a href="users.php?edit=<?php echo $user['id']; ?>" class="btn btn-warning">✏️ تعديل</a>
                                        <a href="users.php?delete=<?php echo $user['id']; ?>" class="btn btn-danger" onclick="return confirm('حذف المستخدم؟')">🗑️ حذف</a>
                                    </td>
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