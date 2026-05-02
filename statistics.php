<?php
require_once "config.php";
requireLogin();

if (!canAccess('statistics')) {
    http_response_code(403);
    die("غير مصرح");
}

$settings = getSiteSettings($conn);

function statisticsTableExists(PDO $conn, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $stmt = $conn->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tableName]);
    $cache[$tableName] = (bool) $stmt->fetchColumn();

    return $cache[$tableName];
}

function statisticsFetchAll(PDO $conn, string $tableName, string $sql, array $params = []): array
{
    if (!statisticsTableExists($conn, $tableName)) {
        return [];
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function statisticsFormatMoney(float $value): string
{
    return number_format($value, 2) . ' ج';
}

function statisticsParseExactDate(string $value, string $format): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat($format, $value);

    if (!$date || $date->format($format) !== $value) {
        return null;
    }

    return $date;
}

function statisticsBuildPeriodMeta(string $periodType, DateTimeImmutable $start): array
{
    $start = $start->setTime(0, 0, 0);

    switch ($periodType) {
        case 'weekly':
            $end = $start->modify('+7 days');
            $displayLabel = 'الأسبوع من ' . $start->format('Y-m-d') . ' إلى ' . $end->modify('-1 day')->format('Y-m-d');
            break;
        case 'monthly':
            $end = $start->modify('+1 month');
            $displayLabel = 'الشهر ' . $start->format('Y-m');
            break;
        case 'yearly':
            $end = $start->modify('+1 year');
            $displayLabel = 'السنة ' . $start->format('Y');
            break;
        case 'daily':
        default:
            $periodType = 'daily';
            $end = $start->modify('+1 day');
            $displayLabel = 'اليوم ' . $start->format('Y-m-d');
            break;
    }

    return [
        'type' => $periodType,
        'start' => $start,
        'end' => $end,
        'display_label' => $displayLabel,
        'day_value' => $start->format('Y-m-d'),
        'week_value' => $start->format('o-\WW'),
        'month_value' => $start->format('Y-m'),
        'year_value' => $start->format('Y'),
        'start_sql' => $start->format('Y-m-d H:i:s'),
        'end_sql' => $end->format('Y-m-d H:i:s')
    ];
}

function statisticsShiftPeriodStart(string $periodType, DateTimeImmutable $start, int $direction): DateTimeImmutable
{
    if ($direction === 0) {
        return $start;
    }

    switch ($periodType) {
        case 'weekly':
            return $start->modify(($direction > 0 ? '+' : '-') . '7 days');
        case 'monthly':
            return $start->modify(($direction > 0 ? '+' : '-') . '1 month');
        case 'yearly':
            return $start->modify(($direction > 0 ? '+' : '-') . '1 year');
        case 'daily':
        default:
            return $start->modify(($direction > 0 ? '+' : '-') . '1 day');
    }
}

function statisticsResolvePeriod(array $input): array
{
    $periodType = (string) ($input['period'] ?? 'daily');
    $allowedTypes = ['daily', 'weekly', 'monthly', 'yearly'];

    if (!in_array($periodType, $allowedTypes, true)) {
        $periodType = 'daily';
    }

    $today = new DateTimeImmutable('today');

    switch ($periodType) {
        case 'weekly':
            $weekValue = trim((string) ($input['week'] ?? ''));
            if (preg_match('/^(\d{4})-W(\d{2})$/', $weekValue, $matches)) {
                $start = $today->setISODate((int) $matches[1], (int) $matches[2]);
            } else {
                $start = $today->modify('monday this week');
            }
            break;
        case 'monthly':
            $monthValue = trim((string) ($input['month'] ?? ''));
            $parsedMonth = statisticsParseExactDate($monthValue . '-01', 'Y-m-d');
            $start = $parsedMonth ?: $today->modify('first day of this month');
            break;
        case 'yearly':
            $yearValue = trim((string) ($input['year'] ?? ''));
            $parsedYear = statisticsParseExactDate($yearValue . '-01-01', 'Y-m-d');
            $start = $parsedYear ?: $today->setDate((int) $today->format('Y'), 1, 1);
            break;
        case 'daily':
        default:
            $periodType = 'daily';
            $dayValue = trim((string) ($input['day'] ?? ''));
            $parsedDay = statisticsParseExactDate($dayValue, 'Y-m-d');
            $start = $parsedDay ?: $today;
            break;
    }

    $move = (string) ($input['move'] ?? '');
    if ($move === 'prev') {
        $start = statisticsShiftPeriodStart($periodType, $start, -1);
    } elseif ($move === 'next') {
        $start = statisticsShiftPeriodStart($periodType, $start, 1);
    }

    return statisticsBuildPeriodMeta($periodType, $start);
}

$periodMeta = statisticsResolvePeriod($_GET);
$startSql = $periodMeta['start_sql'];
$endSql = $periodMeta['end_sql'];

$salonInvoices = statisticsFetchAll(
    $conn,
    'salon_invoices',
    "SELECT id, employee_name, barber_name, customer_name, customer_phone, total_amount, barber_share_amount, salon_share_amount, created_at
     FROM salon_invoices
     WHERE created_at >= ? AND created_at < ?
     ORDER BY created_at DESC, id DESC",
    [$startSql, $endSql]
);

$salesInvoices = statisticsFetchAll(
    $conn,
    'sales_invoices',
    "SELECT id, invoice_type, employee_name, items_count, total_amount, created_at
     FROM sales_invoices
     WHERE created_at >= ? AND created_at < ?
     ORDER BY created_at DESC, id DESC",
    [$startSql, $endSql]
);

$barberLoans = statisticsFetchAll(
    $conn,
    'barbers_loans',
    "SELECT bl.id, b.barber_name, bl.amount, bl.created_at
     FROM barbers_loans bl
     LEFT JOIN barbers b ON b.id = bl.barber_id
     WHERE bl.created_at >= ? AND bl.created_at < ?
     ORDER BY bl.created_at DESC, bl.id DESC",
    [$startSql, $endSql]
);

$employeeLoans = statisticsFetchAll(
    $conn,
    'employees_loans',
    "SELECT el.id, e.employee_name, el.amount, el.created_at
     FROM employees_loans el
     LEFT JOIN employees e ON e.id = el.employee_id
     WHERE el.created_at >= ? AND el.created_at < ?
     ORDER BY el.created_at DESC, el.id DESC",
    [$startSql, $endSql]
);

$barberPayments = statisticsFetchAll(
    $conn,
    'barbers_payments',
    "SELECT bp.id, b.barber_name, bp.salary_month, bp.payment_amount, bp.total_loans, bp.total_deductions, bp.total_commission, bp.net_amount, bp.created_at
     FROM barbers_payments bp
     LEFT JOIN barbers b ON b.id = bp.barber_id
     WHERE bp.created_at >= ? AND bp.created_at < ?
     ORDER BY bp.created_at DESC, bp.id DESC",
    [$startSql, $endSql]
);

$employeePayments = statisticsFetchAll(
    $conn,
    'employees_salary_payments',
    "SELECT esp.id, e.employee_name, esp.salary_month, esp.payment_amount, esp.base_salary, esp.total_loans, esp.total_deductions, esp.net_amount, esp.created_at
     FROM employees_salary_payments esp
     LEFT JOIN employees e ON e.id = esp.employee_id
     WHERE esp.created_at >= ? AND esp.created_at < ?
     ORDER BY esp.created_at DESC, esp.id DESC",
    [$startSql, $endSql]
);

$expenses = statisticsFetchAll(
    $conn,
    'expenses',
    "SELECT id, description, amount, created_at
     FROM expenses
     WHERE created_at >= ? AND created_at < ?
     ORDER BY created_at DESC, id DESC",
    [$startSql, $endSql]
);

$salonShareTotal = 0.0;
$barberShareTotal = 0.0;
foreach ($salonInvoices as $invoiceRow) {
    $salonShareTotal += (float) ($invoiceRow['salon_share_amount'] ?? 0);
    $barberShareTotal += (float) ($invoiceRow['barber_share_amount'] ?? 0);
}

$salesTotal = 0.0;
$salesPositiveTotal = 0.0;
$salesReturnTotal = 0.0;
foreach ($salesInvoices as $salesRow) {
    $rowAmount = (float) ($salesRow['total_amount'] ?? 0);
    if (($salesRow['invoice_type'] ?? '') === 'return') {
        $salesReturnTotal += $rowAmount;
        $salesTotal -= $rowAmount;
    } else {
        $salesPositiveTotal += $rowAmount;
        $salesTotal += $rowAmount;
    }
}

$barberLoansTotal = 0.0;
foreach ($barberLoans as $loanRow) {
    $barberLoansTotal += (float) ($loanRow['amount'] ?? 0);
}

$employeeLoansTotal = 0.0;
foreach ($employeeLoans as $loanRow) {
    $employeeLoansTotal += (float) ($loanRow['amount'] ?? 0);
}

$barberPaymentsTotal = 0.0;
foreach ($barberPayments as $paymentRow) {
    $barberPaymentsTotal += (float) ($paymentRow['payment_amount'] ?? 0);
}

$employeePaymentsTotal = 0.0;
foreach ($employeePayments as $paymentRow) {
    $employeePaymentsTotal += (float) ($paymentRow['payment_amount'] ?? 0);
}

$expensesTotal = 0.0;
foreach ($expenses as $expenseRow) {
    $expensesTotal += (float) ($expenseRow['amount'] ?? 0);
}

$grandTotal = ($salonShareTotal + $salesTotal) - (
    $barberShareTotal
    + $barberLoansTotal
    + $employeeLoansTotal
    + $barberPaymentsTotal
    + $employeePaymentsTotal
    + $expensesTotal
);

$summaryCards = [
    [
        'modal_id' => 'statisticsSalonShareModal',
        'title' => 'إجمالي نسبة الصالون',
        'value' => $salonShareTotal,
        'meta' => 'عدد سجلات التفاصيل: ' . count($salonInvoices)
    ],
    [
        'modal_id' => 'statisticsBarberShareModal',
        'title' => 'إجمالي نسبة الحلاقين',
        'value' => $barberShareTotal,
        'meta' => 'عدد سجلات التفاصيل: ' . count($salonInvoices)
    ],
    [
        'modal_id' => 'statisticsSalesModal',
        'title' => 'إجمالي المبيعات',
        'value' => $salesTotal,
        'meta' => 'بيع: ' . statisticsFormatMoney($salesPositiveTotal) . ' | مرتجع: ' . statisticsFormatMoney($salesReturnTotal)
    ],
    [
        'modal_id' => 'statisticsBarberLoansModal',
        'title' => 'إجمالي سلف الحلاقين',
        'value' => $barberLoansTotal,
        'meta' => 'عدد السجلات: ' . count($barberLoans)
    ],
    [
        'modal_id' => 'statisticsEmployeeLoansModal',
        'title' => 'إجمالي سلف الموظفين',
        'value' => $employeeLoansTotal,
        'meta' => 'عدد السجلات: ' . count($employeeLoans)
    ],
    [
        'modal_id' => 'statisticsBarberPaymentsModal',
        'title' => 'إجمالي مرتبات الحلاقين المصروفة',
        'value' => $barberPaymentsTotal,
        'meta' => 'عدد السجلات: ' . count($barberPayments)
    ],
    [
        'modal_id' => 'statisticsEmployeePaymentsModal',
        'title' => 'إجمالي مرتبات الموظفين المصروفة',
        'value' => $employeePaymentsTotal,
        'meta' => 'عدد السجلات: ' . count($employeePayments)
    ],
    [
        'modal_id' => 'statisticsExpensesModal',
        'title' => 'إجمالي المصروفات',
        'value' => $expensesTotal,
        'meta' => 'عدد السجلات: ' . count($expenses)
    ],
    [
        'modal_id' => 'statisticsGrandTotalModal',
        'title' => 'المجموع النهائي',
        'value' => $grandTotal,
        'meta' => '(إجمالي نسبة الصالون + إجمالي المبيعات) - باقي البنود'
    ]
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإحصائيات</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="dashboard-body">
    <div class="dashboard-layout" data-statistics-page>
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

            <div class="content-card statistics-content-card">
                <div class="page-header">
                    <div>
                        <h1 class="section-title">الإحصائيات</h1>
                        <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                    </div>
                </div>

                <form method="get" class="statistics-filter-form">
                    <div class="statistics-filter-grid">
                        <div class="field-group">
                            <label for="statisticsPeriod">نوع الإحصائية</label>
                            <select name="period" id="statisticsPeriod" data-statistics-period-select>
                                <option value="daily" <?php echo $periodMeta['type'] === 'daily' ? 'selected' : ''; ?>>يومي</option>
                                <option value="weekly" <?php echo $periodMeta['type'] === 'weekly' ? 'selected' : ''; ?>>أسبوعي</option>
                                <option value="monthly" <?php echo $periodMeta['type'] === 'monthly' ? 'selected' : ''; ?>>شهري</option>
                                <option value="yearly" <?php echo $periodMeta['type'] === 'yearly' ? 'selected' : ''; ?>>سنوي</option>
                            </select>
                        </div>

                        <div class="field-group <?php echo $periodMeta['type'] === 'daily' ? '' : 'statistics-period-input-hidden'; ?>" data-period-input="daily">
                            <label for="statisticsDayInput">اليوم</label>
                            <input type="date" id="statisticsDayInput" name="day" value="<?php echo htmlspecialchars($periodMeta['day_value']); ?>" <?php echo $periodMeta['type'] === 'daily' ? '' : 'disabled'; ?>>
                        </div>

                        <div class="field-group <?php echo $periodMeta['type'] === 'weekly' ? '' : 'statistics-period-input-hidden'; ?>" data-period-input="weekly">
                            <label for="statisticsWeekInput">الأسبوع</label>
                            <input type="week" id="statisticsWeekInput" name="week" value="<?php echo htmlspecialchars($periodMeta['week_value']); ?>" <?php echo $periodMeta['type'] === 'weekly' ? '' : 'disabled'; ?>>
                        </div>

                        <div class="field-group <?php echo $periodMeta['type'] === 'monthly' ? '' : 'statistics-period-input-hidden'; ?>" data-period-input="monthly">
                            <label for="statisticsMonthInput">الشهر</label>
                            <input type="month" id="statisticsMonthInput" name="month" value="<?php echo htmlspecialchars($periodMeta['month_value']); ?>" <?php echo $periodMeta['type'] === 'monthly' ? '' : 'disabled'; ?>>
                        </div>

                        <div class="field-group <?php echo $periodMeta['type'] === 'yearly' ? '' : 'statistics-period-input-hidden'; ?>" data-period-input="yearly">
                            <label for="statisticsYearInput">السنة</label>
                            <input type="number" id="statisticsYearInput" name="year" min="2000" max="2100" step="1" value="<?php echo htmlspecialchars($periodMeta['year_value']); ?>" <?php echo $periodMeta['type'] === 'yearly' ? '' : 'disabled'; ?>>
                        </div>
                    </div>

                    <div class="form-actions-row statistics-actions-row">
                        <button type="submit" class="btn btn-primary">عرض</button>
                        <button type="submit" name="move" value="prev" class="btn btn-secondary">الفترة السابقة</button>
                        <button type="submit" name="move" value="next" class="btn btn-secondary">الفترة التالية</button>
                        <a href="statistics.php?period=<?php echo urlencode($periodMeta['type']); ?>" class="btn btn-success">الفترة الحالية</a>
                    </div>
                </form>

                <div class="statistics-summary-grid">
                    <?php foreach ($summaryCards as $card) { ?>
                        <section class="statistics-summary-card <?php echo $card['modal_id'] === 'statisticsGrandTotalModal' ? 'statistics-summary-card-highlight' : ''; ?>">
                            <div class="statistics-summary-head">
                                <h2 class="statistics-summary-title"><?php echo htmlspecialchars($card['title']); ?></h2>
                            </div>
                            <strong class="statistics-summary-value"><?php echo statisticsFormatMoney((float) $card['value']); ?></strong>
                            <p class="statistics-summary-meta"><?php echo htmlspecialchars($card['meta']); ?></p>
                            <button type="button" class="btn btn-primary statistics-detail-btn" data-modal-target="<?php echo htmlspecialchars($card['modal_id']); ?>">تفاصيل</button>
                        </section>
                    <?php } ?>
                </div>
            </div>
        </main>
    </div>

    <div class="statistics-modal" id="statisticsSalonShareModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي نسبة الصالون</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($salonShareTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد الفواتير</span>
                    <strong class="overview-value"><?php echo count($salonInvoices); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوقت</th>
                            <th>الموظف</th>
                            <th>الحلاق</th>
                            <th>العميل</th>
                            <th>إجمالي الفاتورة</th>
                            <th>نسبة الصالون</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($salonInvoices) { ?>
                            <?php foreach ($salonInvoices as $invoiceRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $invoiceRow['id']; ?></td>
                                    <td data-label="الوقت"><?php echo formatDateTimeValue($invoiceRow['created_at'] ?? ''); ?></td>
                                    <td data-label="الموظف"><?php echo htmlspecialchars($invoiceRow['employee_name'] !== '' ? $invoiceRow['employee_name'] : '—'); ?></td>
                                    <td data-label="الحلاق"><?php echo htmlspecialchars($invoiceRow['barber_name'] !== '' ? $invoiceRow['barber_name'] : '—'); ?></td>
                                    <td data-label="العميل"><?php echo htmlspecialchars($invoiceRow['customer_name'] !== '' ? $invoiceRow['customer_name'] : '—'); ?></td>
                                    <td data-label="إجمالي الفاتورة"><?php echo statisticsFormatMoney((float) $invoiceRow['total_amount']); ?></td>
                                    <td data-label="نسبة الصالون"><?php echo statisticsFormatMoney((float) $invoiceRow['salon_share_amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="7">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsBarberShareModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي نسبة الحلاقين</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($barberShareTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد الفواتير</span>
                    <strong class="overview-value"><?php echo count($salonInvoices); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوقت</th>
                            <th>الحلاق</th>
                            <th>العميل</th>
                            <th>إجمالي الفاتورة</th>
                            <th>نسبة الحلاق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($salonInvoices) { ?>
                            <?php foreach ($salonInvoices as $invoiceRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $invoiceRow['id']; ?></td>
                                    <td data-label="الوقت"><?php echo formatDateTimeValue($invoiceRow['created_at'] ?? ''); ?></td>
                                    <td data-label="الحلاق"><?php echo htmlspecialchars($invoiceRow['barber_name'] !== '' ? $invoiceRow['barber_name'] : '—'); ?></td>
                                    <td data-label="العميل"><?php echo htmlspecialchars($invoiceRow['customer_name'] !== '' ? $invoiceRow['customer_name'] : '—'); ?></td>
                                    <td data-label="إجمالي الفاتورة"><?php echo statisticsFormatMoney((float) $invoiceRow['total_amount']); ?></td>
                                    <td data-label="نسبة الحلاق"><?php echo statisticsFormatMoney((float) $invoiceRow['barber_share_amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="6">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsSalesModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي المبيعات</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid statistics-detail-grid-wide">
                <div class="overview-card">
                    <span class="overview-label">صافي المبيعات</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($salesTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">إجمالي البيع</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($salesPositiveTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">إجمالي المرتجع</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($salesReturnTotal); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوقت</th>
                            <th>النوع</th>
                            <th>الموظف</th>
                            <th>عدد البنود</th>
                            <th>الإجمالي</th>
                            <th>الأثر على الصافي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($salesInvoices) { ?>
                            <?php foreach ($salesInvoices as $salesRow) { ?>
                                <?php $signedAmount = ($salesRow['invoice_type'] ?? '') === 'return' ? -1 * (float) $salesRow['total_amount'] : (float) $salesRow['total_amount']; ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $salesRow['id']; ?></td>
                                    <td data-label="الوقت"><?php echo formatDateTimeValue($salesRow['created_at'] ?? ''); ?></td>
                                    <td data-label="النوع"><?php echo htmlspecialchars(($salesRow['invoice_type'] ?? '') === 'return' ? 'مرتجع' : 'بيع'); ?></td>
                                    <td data-label="الموظف"><?php echo htmlspecialchars($salesRow['employee_name'] !== '' ? $salesRow['employee_name'] : '—'); ?></td>
                                    <td data-label="عدد البنود"><?php echo (int) ($salesRow['items_count'] ?? 0); ?></td>
                                    <td data-label="الإجمالي"><?php echo statisticsFormatMoney((float) $salesRow['total_amount']); ?></td>
                                    <td data-label="الأثر على الصافي"><?php echo statisticsFormatMoney($signedAmount); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="7">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsBarberLoansModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي سلف الحلاقين</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($barberLoansTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد السجلات</span>
                    <strong class="overview-value"><?php echo count($barberLoans); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوقت</th>
                            <th>الحلاق</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($barberLoans) { ?>
                            <?php foreach ($barberLoans as $loanRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $loanRow['id']; ?></td>
                                    <td data-label="الوقت"><?php echo formatDateTimeValue($loanRow['created_at'] ?? ''); ?></td>
                                    <td data-label="الحلاق"><?php echo htmlspecialchars($loanRow['barber_name'] !== '' ? $loanRow['barber_name'] : '—'); ?></td>
                                    <td data-label="المبلغ"><?php echo statisticsFormatMoney((float) $loanRow['amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsEmployeeLoansModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي سلف الموظفين</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($employeeLoansTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد السجلات</span>
                    <strong class="overview-value"><?php echo count($employeeLoans); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوقت</th>
                            <th>الموظف</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($employeeLoans) { ?>
                            <?php foreach ($employeeLoans as $loanRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $loanRow['id']; ?></td>
                                    <td data-label="الوقت"><?php echo formatDateTimeValue($loanRow['created_at'] ?? ''); ?></td>
                                    <td data-label="الموظف"><?php echo htmlspecialchars($loanRow['employee_name'] !== '' ? $loanRow['employee_name'] : '—'); ?></td>
                                    <td data-label="المبلغ"><?php echo statisticsFormatMoney((float) $loanRow['amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsBarberPaymentsModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي مرتبات الحلاقين المصروفة</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($barberPaymentsTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد السجلات</span>
                    <strong class="overview-value"><?php echo count($barberPayments); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>وقت الصرف</th>
                            <th>الحلاق</th>
                            <th>شهر الاستحقاق</th>
                            <th>المبلغ المصروف</th>
                            <th>صافي الاستحقاق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($barberPayments) { ?>
                            <?php foreach ($barberPayments as $paymentRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $paymentRow['id']; ?></td>
                                    <td data-label="وقت الصرف"><?php echo formatDateTimeValue($paymentRow['created_at'] ?? ''); ?></td>
                                    <td data-label="الحلاق"><?php echo htmlspecialchars($paymentRow['barber_name'] !== '' ? $paymentRow['barber_name'] : '—'); ?></td>
                                    <td data-label="شهر الاستحقاق"><?php echo htmlspecialchars((string) ($paymentRow['salary_month'] ?? '—')); ?></td>
                                    <td data-label="المبلغ المصروف"><?php echo statisticsFormatMoney((float) $paymentRow['payment_amount']); ?></td>
                                    <td data-label="صافي الاستحقاق"><?php echo statisticsFormatMoney((float) $paymentRow['net_amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="6">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsEmployeePaymentsModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي مرتبات الموظفين المصروفة</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($employeePaymentsTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد السجلات</span>
                    <strong class="overview-value"><?php echo count($employeePayments); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>وقت الصرف</th>
                            <th>الموظف</th>
                            <th>شهر الاستحقاق</th>
                            <th>المبلغ المصروف</th>
                            <th>صافي الاستحقاق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($employeePayments) { ?>
                            <?php foreach ($employeePayments as $paymentRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $paymentRow['id']; ?></td>
                                    <td data-label="وقت الصرف"><?php echo formatDateTimeValue($paymentRow['created_at'] ?? ''); ?></td>
                                    <td data-label="الموظف"><?php echo htmlspecialchars($paymentRow['employee_name'] !== '' ? $paymentRow['employee_name'] : '—'); ?></td>
                                    <td data-label="شهر الاستحقاق"><?php echo htmlspecialchars((string) ($paymentRow['salary_month'] ?? '—')); ?></td>
                                    <td data-label="المبلغ المصروف"><?php echo statisticsFormatMoney((float) $paymentRow['payment_amount']); ?></td>
                                    <td data-label="صافي الاستحقاق"><?php echo statisticsFormatMoney((float) $paymentRow['net_amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="6">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsExpensesModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل إجمالي المصروفات</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid">
                <div class="overview-card">
                    <span class="overview-label">الإجمالي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($expensesTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">عدد السجلات</span>
                    <strong class="overview-value"><?php echo count($expenses); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الوقت</th>
                            <th>البيان</th>
                            <th>المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($expenses) { ?>
                            <?php foreach ($expenses as $expenseRow) { ?>
                                <tr>
                                    <td data-label="#"><?php echo (int) $expenseRow['id']; ?></td>
                                    <td data-label="الوقت"><?php echo formatDateTimeValue($expenseRow['created_at'] ?? ''); ?></td>
                                    <td data-label="البيان"><?php echo htmlspecialchars($expenseRow['description'] !== '' ? $expenseRow['description'] : '—'); ?></td>
                                    <td data-label="المبلغ"><?php echo statisticsFormatMoney((float) $expenseRow['amount']); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4">لا توجد بيانات</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="statistics-modal" id="statisticsGrandTotalModal" aria-hidden="true">
        <div class="statistics-modal-dialog">
            <div class="page-header statistics-modal-header">
                <div>
                    <h2 class="section-title statistics-modal-title">تفاصيل المجموع النهائي</h2>
                    <p class="page-subtitle"><?php echo htmlspecialchars($periodMeta['display_label']); ?></p>
                </div>
                <button type="button" class="btn btn-danger" data-modal-close>إغلاق</button>
            </div>

            <div class="statistics-detail-grid statistics-detail-grid-wide">
                <div class="overview-card">
                    <span class="overview-label">إجمالي نسبة الصالون + المبيعات</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($salonShareTotal + $salesTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">إجمالي الاستقطاعات</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($barberShareTotal + $barberLoansTotal + $employeeLoansTotal + $barberPaymentsTotal + $employeePaymentsTotal + $expensesTotal); ?></strong>
                </div>
                <div class="overview-card">
                    <span class="overview-label">المجموع النهائي</span>
                    <strong class="overview-value"><?php echo statisticsFormatMoney($grandTotal); ?></strong>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table responsive-table statistics-table">
                    <thead>
                        <tr>
                            <th>البند</th>
                            <th>القيمة</th>
                            <th>التأثير</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td data-label="البند">إجمالي نسبة الصالون</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($salonShareTotal); ?></td>
                            <td data-label="التأثير">إضافة</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي المبيعات</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($salesTotal); ?></td>
                            <td data-label="التأثير">إضافة</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي نسبة الحلاقين</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($barberShareTotal); ?></td>
                            <td data-label="التأثير">خصم</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي سلف الحلاقين</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($barberLoansTotal); ?></td>
                            <td data-label="التأثير">خصم</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي سلف الموظفين</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($employeeLoansTotal); ?></td>
                            <td data-label="التأثير">خصم</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي مرتبات الحلاقين المصروفة</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($barberPaymentsTotal); ?></td>
                            <td data-label="التأثير">خصم</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي مرتبات الموظفين المصروفة</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($employeePaymentsTotal); ?></td>
                            <td data-label="التأثير">خصم</td>
                        </tr>
                        <tr>
                            <td data-label="البند">إجمالي المصروفات</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($expensesTotal); ?></td>
                            <td data-label="التأثير">خصم</td>
                        </tr>
                        <tr>
                            <td data-label="البند">المجموع النهائي</td>
                            <td data-label="القيمة"><?php echo statisticsFormatMoney($grandTotal); ?></td>
                            <td data-label="التأثير">الناتج</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="assets/script.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const statisticsPage = document.querySelector("[data-statistics-page]");

            if (!statisticsPage) {
                return;
            }

            const periodSelect = statisticsPage.querySelector("[data-statistics-period-select]");
            const periodGroups = statisticsPage.querySelectorAll("[data-period-input]");

            function syncPeriodGroups() {
                if (!periodSelect) {
                    return;
                }

                periodGroups.forEach(function (group) {
                    const isActive = group.getAttribute("data-period-input") === periodSelect.value;
                    group.classList.toggle("statistics-period-input-hidden", !isActive);
                    group.querySelectorAll("input").forEach(function (input) {
                        input.disabled = !isActive;
                    });
                });
            }

            if (periodSelect) {
                periodSelect.addEventListener("change", syncPeriodGroups);
                syncPeriodGroups();
            }

            function closeModal(modal) {
                modal.classList.remove("is-open");
                modal.setAttribute("aria-hidden", "true");
                document.body.classList.remove("statistics-modal-open");
            }

            function openModal(modal) {
                modal.classList.add("is-open");
                modal.setAttribute("aria-hidden", "false");
                document.body.classList.add("statistics-modal-open");
            }

            statisticsPage.querySelectorAll("[data-modal-target]").forEach(function (button) {
                button.addEventListener("click", function () {
                    const modal = document.getElementById(button.getAttribute("data-modal-target"));

                    if (modal) {
                        openModal(modal);
                    }
                });
            });

            document.querySelectorAll("[data-modal-close]").forEach(function (button) {
                button.addEventListener("click", function () {
                    const modal = button.closest(".statistics-modal");

                    if (modal) {
                        closeModal(modal);
                    }
                });
            });

            document.querySelectorAll(".statistics-modal").forEach(function (modal) {
                modal.addEventListener("click", function (event) {
                    if (event.target === modal) {
                        closeModal(modal);
                    }
                });
            });

            document.addEventListener("keydown", function (event) {
                if (event.key !== "Escape") {
                    return;
                }

                document.querySelectorAll(".statistics-modal.is-open").forEach(function (modal) {
                    closeModal(modal);
                });
            });
        });
    </script>
</body>
</html>
