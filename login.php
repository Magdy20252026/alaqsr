<?php
require_once "config.php";

if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit;
}

$salonName = "صالوني الملكي ✂️";
$salonLogo = "https://via.placeholder.com/150x150.png?text=%E2%9C%82%EF%B8%8F";

try {
    $settings = getSiteSettings($conn);
    if (!empty($settings['salon_name'])) {
        $salonName = $settings['salon_name'];
    }
    if (!empty($settings['salon_logo'])) {
        $salonLogo = $settings['salon_logo'];
    }
} catch (Exception $e) {
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usernameValue = trim($_POST["username"] ?? "");
    $passwordValue = trim($_POST["password"] ?? "");

    if ($usernameValue !== "" && $passwordValue !== "") {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = MD5(?) LIMIT 1");
        $stmt->execute([$usernameValue, $passwordValue]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['permissions'] = getUserPermissions($conn, $user['id']);
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "بيانات الدخول غير صحيحة";
        }
    } else {
        $error = "يرجى إدخال اسم المستخدم وكلمة السر";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - <?php echo htmlspecialchars($salonName); ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="background-shapes">
        <span class="shape shape1"></span>
        <span class="shape shape2"></span>
        <span class="shape shape3"></span>
    </div>

    <div class="top-bar">
        <div class="theme-toggle-box">
            <span class="toggle-text">🌙 الوضع الداكن</span>
            <label class="switch">
                <input type="checkbox" id="themeToggle">
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <main class="login-wrapper">
        <section class="login-card">
            <div class="brand-side">
                <div class="brand-content">
                    <h1 class="salon-name"><?php echo htmlspecialchars($salonName); ?></h1>
                    <div class="logo-frame">
                        <img src="<?php echo htmlspecialchars($salonLogo); ?>" alt="شعار الصالون" class="salon-logo">
                    </div>
                </div>
            </div>

            <div class="form-side">
                <div class="form-header">
                    <h2>👋 أهلاً بعودتك</h2>
                    <p>قم بتسجيل الدخول للوصول إلى لوحة التحكم</p>
                </div>

                <?php if ($error !== "") { ?>
                    <div class="login-error-box"><?php echo htmlspecialchars($error); ?></div>
                <?php } ?>

                <form class="login-form" action="" method="post">
                    <div class="input-group">
                        <label for="username">👤 اسم المستخدم</label>
                        <div class="input-box">
                            <span class="input-icon">🧑</span>
                            <input type="text" id="username" name="username" placeholder="اكتب اسم المستخدم" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="password">🔒 كلمة السر</label>
                        <div class="input-box">
                            <span class="input-icon">🔐</span>
                            <input type="password" id="password" name="password" placeholder="اكتب كلمة السر" required>
                        </div>
                    </div>

                    <button type="submit" class="login-btn">🚀 تسجيل الدخول</button>
                </form>
            </div>
        </section>
    </main>

    <script src="assets/script.js"></script>
</body>
</html>