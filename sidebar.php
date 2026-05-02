<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-top">
        <h2 class="salon-title"><?php echo htmlspecialchars($settings['salon_name']); ?></h2>
        <img src="<?php echo htmlspecialchars($settings['salon_logo']); ?>" alt="logo" class="sidebar-logo">
        <div class="user-box">
            <div>👤 <?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div>🛡️ <?php echo htmlspecialchars($_SESSION['role']); ?></div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <a href="dashboard.php" class="menu-btn home-btn <?php echo $currentPage === 'dashboard.php' ? 'active-btn' : ''; ?>">🏠 الصفحة الرئيسية</a>

        <?php if (canAccess('users')) { ?><a href="users.php" class="menu-btn users-btn <?php echo $currentPage === 'users.php' ? 'active-btn' : ''; ?>">👥 المستخدمين</a><?php } ?>
        <?php if (canAccess('user_permissions')) { ?><a href="user_permissions.php" class="menu-btn permissions-btn <?php echo $currentPage === 'user_permissions.php' ? 'active-btn' : ''; ?>">🛡️ صلاحيات المستخدمين</a><?php } ?>
        <?php if (canAccess('services')) { ?><a href="services.php" class="menu-btn services-btn <?php echo $currentPage === 'services.php' ? 'active-btn' : ''; ?>">✂️ الخدمات</a><?php } ?>
        <?php if (canAccess('barbers')) { ?><a href="barbers.php" class="menu-btn barbers-btn <?php echo $currentPage === 'barbers.php' ? 'active-btn' : ''; ?>">💈 الحلاقين</a><?php } ?>
        <?php if (canAccess('barbers_attendance')) { ?><a href="barbers_attendance.php" class="menu-btn attendance-btn <?php echo $currentPage === 'barbers_attendance.php' ? 'active-btn' : ''; ?>">📅 حضور الحلاقين</a><?php } ?>
        <?php if (canAccess('barbers_loans')) { ?><a href="#" class="menu-btn loans-btn">💵 سلف الحلاقين</a><?php } ?>
        <?php if (canAccess('barbers_deductions')) { ?><a href="#" class="menu-btn deductions-btn">📉 خصومات الحلاقين</a><?php } ?>
        <?php if (canAccess('barbers_payments')) { ?><a href="#" class="menu-btn payments-btn">💰 قبض حلاقين</a><?php } ?>
        <?php if (canAccess('employees')) { ?><a href="employees.php" class="menu-btn employees-btn <?php echo $currentPage === 'employees.php' ? 'active-btn' : ''; ?>">🧑‍💼 الموظفين</a><?php } ?>
        <?php if (canAccess('employees_attendance')) { ?><a href="employees_attendance.php" class="menu-btn attendance2-btn <?php echo $currentPage === 'employees_attendance.php' ? 'active-btn' : ''; ?>">🗓️ حضور الموظفين</a><?php } ?>
        <?php if (canAccess('employees_loans')) { ?><a href="#" class="menu-btn loans2-btn">💸 سلف الموظفين</a><?php } ?>
        <?php if (canAccess('employees_deductions')) { ?><a href="#" class="menu-btn deductions2-btn">➖ خصومات الموظفين</a><?php } ?>
        <?php if (canAccess('employees_salaries')) { ?><a href="#" class="menu-btn salaries-btn">💳 قبض رواتب الموظفين</a><?php } ?>
        <?php if (canAccess('salon_cashier')) { ?><a href="#" class="menu-btn cashier-btn">🏦 كاشير الصالون</a><?php } ?>
        <?php if (canAccess('items')) { ?><a href="#" class="menu-btn items-btn">📦 الأصناف</a><?php } ?>
        <?php if (canAccess('sales_cashier')) { ?><a href="#" class="menu-btn sales-btn">🛒 كاشير المبيعات</a><?php } ?>
        <?php if (canAccess('expenses')) { ?><a href="#" class="menu-btn expenses-btn">🧾 مصروفات</a><?php } ?>
        <?php if (canAccess('statistics')) { ?><a href="#" class="menu-btn stats-btn">📊 احصائيات</a><?php } ?>
        <?php if (canAccess('daily_closing')) { ?><a href="#" class="menu-btn daily-btn">📘 تقفيل يومي</a><?php } ?>
        <?php if (canAccess('monthly_closing')) { ?><a href="#" class="menu-btn monthly-btn">📗 تقفيل شهري</a><?php } ?>
        <?php if (canAccess('site_settings')) { ?><a href="site_settings.php" class="menu-btn settings-btn <?php echo $currentPage === 'site_settings.php' ? 'active-btn' : ''; ?>">⚙️ إعدادت الموقع</a><?php } ?>

        <a href="logout.php" class="menu-btn logout-btn">🚪 تسجيل خروج</a>
    </nav>
</aside>
