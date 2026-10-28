<?php
require_once '../config/init.php';
require_once '../config/db.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('Asia/Manila');
$blocks = [];
$blockStmt = $conn->prepare("SELECT DISTINCT TRIM(block) AS block_name FROM homeowners WHERE archived_at IS NULL AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned' ORDER BY block_name");
if ($blockStmt && $blockStmt->execute()) {
    $blockResult = $blockStmt->get_result();
    while ($row = $blockResult->fetch_assoc()) $blocks[] = $row['block_name'];
    $blockStmt->close();
}
$basePath = defined('BASE_PATH') ? BASE_PATH : '/MANUELA';
require_once '../includes/header.php';
?>

<link rel="stylesheet" href="../Assets/css/analytics.css?v=3">
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content analytics-page" id="analyticsPage" data-api="api/analytics.php" data-records-api="api/analytics_records.php">
        <header class="analytics-page-header">
            <div>
                <p class="analytics-eyebrow">VISUAL DATA ANALYTICS</p>
                <h1>Visual Data Analytics</h1>
                <p class="analytics-subtitle">See what changed, compare periods, and open the exact records behind each number.</p>
            </div>
            <div class="analytics-header-actions">
                <a class="btn btn-outline-secondary" href="Dashboard.php">Dashboard overview</a>
                <button class="btn btn-outline-primary" id="guideToggle" type="button" aria-expanded="false">How to read this page</button>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#reportModal">Generate a report</button>
            </div>
            <span id="analyticsUpdated" class="analytics-updated analytics-header-updated" aria-live="polite">Loading latest figures…</span>
        </header>

        <aside class="analytics-guide" id="readingGuide" hidden>
            <button type="button" class="analytics-guide-close" id="guideDismiss" aria-label="Close guide">×</button>
            <h2>How to read this page</h2>
            <p>Choose the dates and street first. The large numbers and charts will update together. Select a number, bar, or chart section to see the matching records in the table below. Use the column headings to sort and the search box to find a record.</p>
        </aside>

        <form class="analytics-filters" id="analyticsFilters">
            <div class="analytics-filter-field">
                <label for="datePreset">Date range</label>
                <select class="form-select" id="datePreset" name="range">
                    <option value="month" selected>This month</option>
                    <option value="today">Today</option>
                    <option value="week">This week</option>
                    <option value="year">This year</option>
                    <option value="custom">Choose dates</option>
                </select>
            </div>
            <div class="analytics-filter-field analytics-custom-date" hidden>
                <label for="dateFrom">From</label>
                <input class="form-control" type="date" id="dateFrom" name="from">
            </div>
            <div class="analytics-filter-field analytics-custom-date" hidden>
                <label for="dateTo">To</label>
                <input class="form-control" type="date" id="dateTo" name="to">
            </div>
            <div class="analytics-filter-field">
                <label for="blockFilter">Street / phase</label>
                <select class="form-select" id="blockFilter" name="block">
                    <option value="">All streets</option>
                    <?php foreach ($blocks as $block): ?>
                        <option value="<?= htmlspecialchars($block, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($block, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="analytics-filter-field analytics-status-filter">
                <label for="facilityStatus">Booking status</label>
                <select class="form-select" id="facilityStatus"><option value="approved" selected>Approved</option><option value="pending">Pending</option><option value="rejected">Rejected</option></select>
            </div>
            <button class="btn btn-primary analytics-apply" type="submit">Apply filters</button>
        </form>
        <div class="analytics-global-error" id="analyticsGlobalError" role="alert" hidden>
            <span>We couldn’t update the figures. Check your connection and try again.</span>
            <button type="button" class="btn btn-outline-primary btn-sm" id="analyticsRetry">Try again</button>
        </div>

        <section class="analytics-summary-grid" aria-label="Key figures">
            <button type="button" class="analytics-summary-card" data-kpi-metric="collections" aria-label="View payment records for money received">
                <span class="analytics-summary-icon icon-green" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                <span class="analytics-kpi-copy"><h2>Money received</h2><strong id="summaryCollections" aria-live="polite">—</strong><p id="summaryCollectionsChange">Compared with the previous period</p></span>
            </button>
            <button type="button" class="analytics-summary-card" data-kpi-metric="payment_methods" aria-label="View payment records">
                <span class="analytics-summary-icon icon-blue" aria-hidden="true"><i class="bi bi-receipt"></i></span>
                <span class="analytics-kpi-copy"><h2>Payments recorded</h2><strong id="summaryPaymentCount" aria-live="polite">—</strong><p id="summaryPaymentCountChange">Compared with the previous period</p></span>
            </button>
            <button type="button" class="analytics-summary-card" data-kpi-metric="facilities" aria-label="View facility bookings">
                <span class="analytics-summary-icon icon-amber" aria-hidden="true"><i class="bi bi-building"></i></span>
                <span class="analytics-kpi-copy"><h2>Facility bookings</h2><strong id="summaryFacilityBookings" aria-live="polite">—</strong><p id="summaryFacilityBookingsChange">Compared with the previous period</p></span>
            </button>
            <button type="button" class="analytics-summary-card" data-kpi-metric="dues" aria-label="View households with paid dues">
                <span class="analytics-summary-icon icon-green" aria-hidden="true"><i class="bi bi-check-circle"></i></span>
                <span class="analytics-kpi-copy"><h2>Households fully paid</h2><strong id="summaryPaid" aria-live="polite">—</strong><p id="summaryPaidChange">Compared with the previous period</p></span>
            </button>
        </section>

        <section class="analytics-chart-grid" aria-label="Analytics charts">
            <article class="analytics-card" data-chart-card="collections">
                <div class="analytics-card-heading"><div><h2>Money received over time</h2><p>Payments recorded on each date, in Philippine pesos.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="collections">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="collections">Print</button></div></div>
                <div class="analytics-chart-wrap"><canvas id="chartCollections" role="img" aria-label="Money received over time"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No payments found for these dates.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="collections">Try again</button></div></div>
            </article>

            <article class="analytics-card" data-chart-card="dues">
                <div class="analytics-card-heading"><div><h2>Monthly dues</h2><p>Households paid, partly paid, or unpaid for the selected month.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="dues">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="dues">Print</button></div></div>
                <div class="analytics-chart-wrap analytics-chart-donut"><canvas id="chartDues" role="img" aria-label="Monthly dues by payment status"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No active households found.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="dues">Try again</button></div></div>
            </article>

            <article class="analytics-card" data-chart-card="facilities">
                <div class="analytics-card-heading"><div><h2>Facility bookings</h2><p>Bookings by facility for the selected dates.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="facilities">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="facilities">Print</button></div></div>
                <div class="analytics-chart-wrap"><canvas id="chartFacilities" role="img" aria-label="Facility bookings by facility"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No bookings found for these dates.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="facilities">Try again</button></div></div>
                <label class="analytics-inline-filter" for="facilityCategory">Facility</label><select class="form-select analytics-inline-select" id="facilityCategory"><option value="">All facilities</option></select>
            </article>

            <article class="analytics-card" data-chart-card="payment_methods">
                <div class="analytics-card-heading"><div><h2>Payments by method</h2><p>See how often each payment option was used. GCash and Maya variations are grouped together.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="payment_methods">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="payment_methods">Print</button></div></div>
                <div class="analytics-chart-wrap analytics-chart-donut"><canvas id="chartMethods" role="img" aria-label="Payment methods used"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No payment methods found for these dates.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="payment_methods">Try again</button></div></div>
                <label class="analytics-inline-filter" for="paymentMethodCategory">Payment method</label><select class="form-select analytics-inline-select" id="paymentMethodCategory"><option value="">All payment methods</option></select>
            </article>

        </section>

        <section class="analytics-records-card" id="analyticsRecords" aria-labelledby="analyticsRecordsTitle">
            <div class="analytics-records-heading"><div><h2 id="analyticsRecordsTitle">Detailed records</h2><p id="analyticsRecordsDescription">Choose a number or chart above to show its matching records.</p></div><button type="button" class="btn btn-outline-secondary" id="recordsExport" disabled>Download this page as CSV</button></div>
            <div class="analytics-records-controls"><label for="recordsSearch">Search these records</label><input class="form-control" id="recordsSearch" type="search" placeholder="Type a name, street, date, or method" disabled><span id="recordsCount" aria-live="polite">No records selected yet</span></div>
            <div class="table-responsive"><table class="table table-hover align-middle analytics-detail-table" aria-describedby="analyticsRecordsDescription"><thead id="analyticsDetailHead"></thead><tbody id="analyticsDetailBody"><tr><td class="analytics-table-placeholder">Select a KPI, bar, or chart section to see details here.</td></tr></tbody></table></div>
            <div class="analytics-pagination"><span id="recordsPageLabel">Page 1</span><div><button class="btn btn-outline-secondary" type="button" id="recordsPrevious" disabled>Previous</button><button class="btn btn-outline-secondary" type="button" id="recordsNext" disabled>Next</button></div></div>
            <div class="analytics-detail-loading" id="recordsLoading" hidden>Loading matching records…</div>
            <div class="analytics-detail-error" id="recordsError" hidden>We couldn’t load these records. <button class="btn btn-link p-0" type="button" id="analyticsDetailRetry">Try again</button></div>
        </section>
    </main>
</div>

<div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="get" action="report.php" target="_blank">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="reportModalTitle">Generate a report</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label fw-semibold" for="reportType">Report type</label>
                    <select name="type" id="reportType" class="form-select">
                        <option value="collection">Payment summary</option>
                        <option value="aging">Late dues by month</option>
                        <option value="followup">Households to follow up</option>
                        <option value="reliability">Payment track record</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label fw-semibold" for="reportFrom">From month</label><input class="form-control" type="month" name="from" id="reportFrom" value="<?= htmlspecialchars(date('Y-m', strtotime('-5 months')), ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="col-6"><label class="form-label fw-semibold" for="reportTo">To month</label><input class="form-control" type="month" name="to" id="reportTo" value="<?= htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8') ?>"></div>
                </div>
                <div><label class="form-label fw-semibold" for="reportFormat">Format</label>
                    <select class="form-select" name="format" id="reportFormat"><option value="pdf">Print / Save as PDF</option><option value="csv">Excel / CSV</option></select>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create report</button></div>
        </form>
    </div>
</div>

<script src="../Assets/js/admin-analytics.js?v=2"></script>
<?php require_once '../includes/footer.php'; ?>
