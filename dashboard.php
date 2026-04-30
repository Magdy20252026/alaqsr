<?php
require_once "config.php";
requireLogin();
$settings = getSiteSettings($conn);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم</title>
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

            <div class="welcome-panel">
                <h1>👋 أهلاً <?php echo htmlspecialchars($_SESSION['username']); ?></h1>
                <p>لوحة التحكم</p>
            </div>
        </main>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>