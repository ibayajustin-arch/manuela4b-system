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

<link rel="stylesheet" href="../Assets/css/analytics.css?v=1">
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content analytics-page" id="analyticsPage" data-api="api/analytics.php" data-records-api="api/analytics_records.php">
        <header class="analytics-page-header">
            <div>
                <p class="analytics-eyebrow">ADMIN DASHBOARD</p>
                <h1>Community Overview</h1>
                <p class="analytics-subtitle">Simple summaries of collections, dues, and community activity.</p>
            </div>
            <div class="analytics-header-actions">
                <button class="btn btn-outline-primary" id="guideToggle" type="button" aria-expanded="false">How to read this page</button>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#reportModal">Generate a report</button>
            </div>
        </header>

        <aside class="analytics-guide" id="readingGuide" hidden>
            <button type="button" class="analytics-guide-close" id="guideDismiss" aria-label="Close guide">×</button>
            <h2>How to read this page</h2>
            <p>Choose a date range and street first. The cards and charts update together. Select a bar or chart section to see the matching records. Green means paid or completed; orange and red show items that may need attention.</p>
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
                <label for="blockFilter">Street</label>
                <select class="form-select" id="blockFilter" name="block">
                    <option value="">All streets</option>
                    <?php foreach ($blocks as $block): ?>
                        <option value="<?= htmlspecialchars($block, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($block, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary analytics-apply" type="submit">Update charts</button>
            <span id="analyticsUpdated" class="analytics-updated" aria-live="polite">Loading latest figures…</span>
        </form>
        <div class="analytics-global-error" id="analyticsGlobalError" role="alert" hidden>
            <span>We couldn’t update the figures. Check your connection and try again.</span>
            <button type="button" class="btn btn-outline-primary btn-sm" id="analyticsRetry">Try again</button>
        </div>

        <section class="analytics-summary-grid" aria-label="Key figures">
            <article class="analytics-summary-card">
                <span class="analytics-summary-icon icon-green" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                <div><h2>Money received</h2><strong id="summaryCollections" aria-live="polite">—</strong><p id="summaryCollectionsChange">For the selected dates</p></div>
            </article>
            <article class="analytics-summary-card">
                <span class="analytics-summary-icon icon-blue" aria-hidden="true"><i class="bi bi-house-heart"></i></span>
                <div><h2>Active households</h2><strong id="summaryResidents" aria-live="polite">—</strong><p>Current registered households</p></div>
            </article>
            <article class="analytics-summary-card">
                <span class="analytics-summary-icon icon-amber" aria-hidden="true"><i class="bi bi-check-circle"></i></span>
                <div><h2>Households fully paid</h2><strong id="summaryPaid" aria-live="polite">—</strong><p id="summaryPaidNote">For the selected billing month</p></div>
            </article>
            <article class="analytics-summary-card">
                <span class="analytics-summary-icon icon-red" aria-hidden="true"><i class="bi bi-exclamation-circle"></i></span>
                <div><h2>Households with unpaid dues</h2><strong id="summaryUnpaid" aria-live="polite">—</strong><p>Includes partly paid dues</p></div>
            </article>
        </section>

        <section class="analytics-chart-grid" aria-label="Analytics charts">
            <article class="analytics-card analytics-card-wide" data-chart-card="collections">
                <div class="analytics-card-heading"><div><h2>Money received over time</h2><p>Payments recorded on each date, in Philippine pesos.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="collections">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="collections">Print</button></div></div>
                <div class="analytics-chart-wrap"><canvas id="chartCollections" role="img" aria-label="Money received over time"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No payments found for these dates.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="collections">Try again</button></div></div>
            </article>

            <article class="analytics-card" data-chart-card="dues">
                <div class="analytics-card-heading"><div><h2>Monthly dues</h2><p>Households paid, partly paid, or unpaid for the selected month.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="dues">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="dues">Print</button></div></div>
                <div class="analytics-chart-wrap analytics-chart-donut"><canvas id="chartDues" role="img" aria-label="Monthly dues by payment status"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No active households found.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="dues">Try again</button></div></div>
                <label class="analytics-inline-filter" for="duesStatus">Show payment status</label><select class="form-select analytics-inline-select" id="duesStatus"><option value="">All statuses</option><option value="paid">Paid</option><option value="partial">Partly paid</option><option value="unpaid">Unpaid</option></select>
            </article>

            <article class="analytics-card" data-chart-card="facilities">
                <div class="analytics-card-heading"><div><h2>Facility bookings</h2><p>Bookings by facility for the selected dates.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="facilities">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="facilities">Print</button></div></div>
                <div class="analytics-chart-wrap"><canvas id="chartFacilities" role="img" aria-label="Facility bookings by facility"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No bookings found for these dates.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="facilities">Try again</button></div></div>
                <label class="analytics-inline-filter" for="facilityStatus">Booking status</label><select class="form-select analytics-inline-select" id="facilityStatus"><option value="approved" selected>Approved</option><option value="pending">Pending</option><option value="rejected">Rejected</option></select>
                <label class="analytics-inline-filter" for="facilityCategory">Facility</label><select class="form-select analytics-inline-select" id="facilityCategory"><option value="">All facilities</option></select>
            </article>

            <article class="analytics-card" data-chart-card="payment_methods">
                <div class="analytics-card-heading"><div><h2>Payment methods</h2><p>Number of payments by method.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="payment_methods">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="payment_methods">Print</button></div></div>
                <div class="analytics-chart-wrap analytics-chart-donut"><canvas id="chartMethods" role="img" aria-label="Payment methods used"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No payment methods found for these dates.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="payment_methods">Try again</button></div></div>
                <label class="analytics-inline-filter" for="paymentMethodCategory">Payment method</label><select class="form-select analytics-inline-select" id="paymentMethodCategory"><option value="">All payment methods</option></select>
            </article>

            <article class="analytics-card" data-chart-card="account_status">
                <div class="analytics-card-heading"><div><h2>Household account status</h2><p>Current account statuses for active records.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="account_status">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="account_status">Print</button></div></div>
                <div class="analytics-chart-wrap analytics-chart-donut"><canvas id="chartAccounts" role="img" aria-label="Current household account status"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No household accounts found.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="account_status">Try again</button></div></div>
            </article>

            <article class="analytics-card analytics-card-wide" data-chart-card="homeowners_by_block">
                <div class="analytics-card-heading"><div><h2>Households by street</h2><p>Number of registered households on each street. Select a bar to see records.</p></div><div class="analytics-card-actions"><button class="btn btn-sm btn-outline-secondary" data-export="homeowners_by_block">Download CSV</button><button class="btn btn-sm btn-outline-secondary" data-print="homeowners_by_block">Print</button></div></div>
                <div class="analytics-chart-wrap"><canvas id="chartBlocks" role="img" aria-label="Households by street"></canvas><div class="analytics-loading"><span aria-hidden="true"></span><small>Loading figures…</small></div><div class="analytics-empty" hidden>No households found.</div><div class="analytics-error" hidden>We couldn’t load this chart. <button type="button" class="btn btn-link p-0" data-retry="homeowners_by_block">Try again</button></div></div>
            </article>
        </section>
    </main>
</div>

<div class="modal fade" id="analyticsDetailModal" tabindex="-1" aria-labelledby="analyticsDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h2 class="modal-title fs-5" id="analyticsDetailTitle">Detailed records</h2><p class="mb-0 text-muted" id="analyticsDetailDescription">Matching records for this chart.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="analytics-detail-loading">Loading matching records…</div><div class="analytics-detail-error" hidden>We couldn’t load the records. <button class="btn btn-link p-0" type="button" id="analyticsDetailRetry">Try again</button></div><div class="analytics-detail-empty" hidden>No matching records found.</div><div class="table-responsive"><table class="table table-hover align-middle analytics-detail-table"><thead id="analyticsDetailHead"></thead><tbody id="analyticsDetailBody"></tbody></table></div><p class="small text-muted" id="analyticsDetailLimit" hidden>Showing the first 100 records.</p></div>
    </div></div>
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

<script src="../Assets/js/admin-analytics.js?v=1"></script>
<?php require_once '../includes/footer.php'; ?>
