<?php
const APP_TIMEZONE = 'Africa/Cairo';

date_default_timezone_set(APP_TIMEZONE);
session_start();

$host = "sql208.infinityfree.com";
$dbname = "if0_41797439_a_01";
$username = "if0_41797439";
$password = "6Mq8FJU02jepJ";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $egyptOffset = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
    $conn->exec("SET time_zone = '{$egyptOffset}'");
} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات");
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

function requireLogin()
{
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

function getSiteSettings($conn)
{
    $stmt = $conn->query("SELECT * FROM site_settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        return [
            'salon_name' => 'صالوني الملكي ✂️',
            'salon_logo' => 'https://via.placeholder.com/150x150.png?text=%E2%9C%82%EF%B8%8F'
        ];
    }

    return $settings;
}

function getUserPermissions($conn, $userId)
{
    $stmt = $conn->prepare("SELECT page_key FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function canAccess($pageKey)
{
    if (!isset($_SESSION['role'])) {
        return false;
    }

    if ($_SESSION['role'] === 'مدير') {
        return true;
    }

    if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
        return false;
    }

    return in_array($pageKey, $_SESSION['permissions']);
}

$allPages = [
    'users' => '👥 المستخدمين',
    'user_permissions' => '🛡️ صلاحيات المستخدمين',
    'services' => '✂️ الخدمات',
    'barbers' => '💈 الحلاقين',
    'barbers_attendance' => '📅 حضور الحلاقين',
    'barbers_loans' => '💵 سلف الحلاقين',
    'barbers_deductions' => '📉 خصومات الحلاقين',
    'barbers_payments' => '💰 قبض حلاقين',
    'employees' => '🧑‍💼 الموظفين',
    'employees_attendance' => '🗓️ حضور الموظفين',
    'employees_loans' => '💸 سلف الموظفين',
    'employees_deductions' => '➖ خصومات الموظفين',
    'employees_salaries' => '💳 قبض رواتب الموظفين',
    'salon_cashier' => '🏦 كاشير الصالون',
    'items' => '📦 الأصناف',
    'sales_cashier' => '🛒 كاشير المبيعات',
    'expenses' => '🧾 مصروفات',
    'statistics' => '📊 احصائيات',
    'daily_closing' => '📘 تقفيل يومي',
    'monthly_closing' => '📗 تقفيل شهري',
    'site_settings' => '⚙️ إعدادت الموقع'
];
?>
