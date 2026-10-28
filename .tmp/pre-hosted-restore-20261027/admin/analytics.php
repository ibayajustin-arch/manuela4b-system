<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}
// Release the session lock early so the analytics API call isn't queued behind this page.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$streets = [];
try {
    $result = $conn->query("SELECT DISTINCT TRIM(block) AS value FROM homeowners WHERE archived_at IS NULL AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned' ORDER BY value");
    if ($result) while ($row = $result->fetch_assoc()) $streets[] = (string) $row['value'];
} catch (Throwable $e) {
    error_log('Analytics street list could not be loaded: ' . $e->getMessage());
}

require_once '../includes/header.php';
?>
<link rel="stylesheet" href="../Assets/css/analytics.css?v=39">
<!-- Print-only overrides: must stay AFTER every other stylesheet -->
<link rel="stylesheet" href="../Assets/css/analytics-print.css?v=2" media="print">
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content analytics-page" id="analyticsPage" data-api="api/analytics_finance.php">
        <header class="an-hero">
            <div class="an-hero-content">
                <p class="an-eyebrow"><i class="bi bi-bar-chart-steps"></i> VISUAL DATA ANALYTICS</p>
                <h1>Money &amp; community activity</h1>
                <p class="an-sub">Who pays late, which days bring in the most cash, the busiest reservation weeks, and a moving-average forecast of what comes next.</p>
            </div>
            <div class="an-actions">
                <button class="btn btn-outline-primary" id="anCsv" type="button" disabled title="Export the current analytics as a CSV file"><i class="bi bi-filetype-csv" aria-hidden="true"></i><span>Export CSV</span></button>
                <button class="btn btn-primary" id="anPrint" type="button" disabled title="Print this analytics report or save it as a PDF"><i class="bi bi-printer" aria-hidden="true"></i><span>Print / Save PDF</span></button>
            </div>
        </header>
        <p class="an-print-meta" id="anPrintMeta" aria-hidden="true"></p>

        <form class="an-filters" id="anFilters">
            <label><i class="bi bi-calendar3"></i> Date range
                <select class="form-select" id="anRange" name="range">
                    <option value="month">This month</option>
                    <option value="30d">Last 30 days</option>
                    <option value="90d">Last 90 days</option>
                    <option value="year" selected>This year</option>
                    <option value="last_year">Last year</option>
                    <option value="custom">Custom range</option>
                </select>
            </label>
            <label class="an-custom" hidden><i class="bi bi-calendar-event"></i> From <input class="form-control" type="date" id="anFrom"></label>
            <label class="an-custom" hidden><i class="bi bi-calendar-event"></i> To <input class="form-control" type="date" id="anTo"></label>
            <label><i class="bi bi-geo-alt"></i> Street
                <select class="form-select" id="anBlock">
                    <option value="">All streets</option>
                    <?php foreach ($streets as $s): ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Apply</button>
            <span class="an-updated" id="anUpdated" aria-live="polite"><span class="an-live-dot"></span> <span>Loading…</span></span>
        </form>

        <div class="an-error" id="anError" role="alert" hidden>
            <span><i class="bi bi-exclamation-triangle-fill me-2"></i>We couldn’t load analytics right now.</span>
            <button class="btn btn-sm btn-outline-danger" type="button" id="anRetry">Try again</button>
        </div>

        <div id="anApp" class="an-loading" aria-live="polite">
            <div class="an-skeleton-kpis">
                <div class="an-skel-card"></div>
                <div class="an-skel-card"></div>
                <div class="an-skel-card"></div>
                <div class="an-skel-card"></div>
                <div class="an-skel-card"></div>
                <div class="an-skel-card"></div>
            </div>
            <div class="an-skeleton-grid">
                <div class="an-skel-card tall span-8"></div>
                <div class="an-skel-card tall span-4"></div>
            </div>
        </div>
    </main>
</div>
<script src="../Assets/js/admin-analytics.js?v=37"></script>
<?php require_once '../includes/footer.php'; ?>
