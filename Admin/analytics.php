<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

// view key => [page title, description, icon, short tab label]
$analyticsViews = [
    'overview'     => ['Overview', 'Key collection, dues, reservation, and feedback indicators at a glance.', 'bi-speedometer2', 'Overview'],
    'collections'  => ['Collections and Payments', 'Money received over time, payment methods, and the latest payments and bookings.', 'bi-cash-coin', 'Collections'],
    'late'         => ['Who Pays Late', 'Late payment patterns, unpaid accounts, and late rates by street.', 'bi-hourglass-split', 'Late Payers'],
    'reservations' => ['Reservations', 'Approved reservation trends, facilities, and the busiest weeks of the year.', 'bi-calendar-week', 'Reservations'],
    'feedback'     => ['Resident Feedback', 'Suggestions, concerns, and incident reports from residents, and how they are being handled.', 'bi-chat-square-text', 'Feedback'],
    'forecast'     => ['Forecast', 'How much money we expect to collect in the coming months, and how close past estimates were.', 'bi-graph-up-arrow', 'Forecast'],
];
$analyticsView = (string) ($_GET['view'] ?? 'overview');
if (!isset($analyticsViews[$analyticsView])) $analyticsView = 'overview';

// Release the session lock early so the analytics API call isn't queued behind this page.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$streets = [];
try {
    $result = $conn->query("SELECT DISTINCT TRIM(block) AS value FROM homeowners WHERE archived_at IS NULL AND TRIM(block) <> '' AND LOWER(TRIM(block)) <> 'unassigned' ORDER BY value");
    if ($result) while ($row = $result->fetch_assoc()) $streets[] = (string) $row['value'];
} catch (Throwable $e) {
    error_log('Analytics street list could not be loaded: ' . $e->getMessage());
}

// ---------- Filters carried in the link (kept when switching sections) ----------
$rangeOptions = [
    'month' => 'This month',
    '30d' => 'Last 30 days',
    '90d' => 'Last 90 days',
    'year' => 'This year',
    'last_year' => 'Last year',
    'custom' => 'Custom range',
];
$selRange = (string) ($_GET['range'] ?? 'year');
if (!isset($rangeOptions[$selRange])) $selRange = 'year';
$validDate = static function ($value): string {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : '';
};
$selFrom = $validDate($_GET['from'] ?? '');
$selTo = $validDate($_GET['to'] ?? '');
if ($selRange === 'custom' && ($selFrom === '' || $selTo === '' || $selFrom > $selTo)) {
    $selRange = 'year';
    $selFrom = $selTo = '';
}
$selBlock = (string) ($_GET['block'] ?? '');
if (!in_array($selBlock, $streets, true)) $selBlock = '';

$tabHref = static function (string $view) use ($selRange, $selFrom, $selTo, $selBlock): string {
    $q = ['view' => $view];
    if ($selRange !== 'year') $q['range'] = $selRange;
    if ($selRange === 'custom') { $q['from'] = $selFrom; $q['to'] = $selTo; }
    if ($selBlock !== '') $q['block'] = $selBlock;
    return 'analytics.php?' . http_build_query($q);
};

$viewsForJs = [];
foreach ($analyticsViews as $key => $v) $viewsForJs[$key] = ['title' => $v[0], 'sub' => $v[1]];

$page_css = ['../Assets/css/analytics.css?v=51'];
require_once '../includes/header.php';
?>
<!-- Print-only overrides: must stay AFTER every other stylesheet -->
<link rel="stylesheet" href="../Assets/css/analytics-print.css?v=4" media="print">
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content analytics-page" id="analyticsPage"
          data-api="api/analytics_finance.php"
          data-view="<?= htmlspecialchars($analyticsView, ENT_QUOTES, 'UTF-8') ?>"
          data-views="<?= htmlspecialchars(json_encode($viewsForJs, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">

        <!-- Print-only letterhead (hidden on screen, shown by analytics-print.css) -->
        <div class="an-print-brand" style="display:none" aria-hidden="true">
            <strong>MANUELA 4B · HOA MANAGEMENT</strong>
            <span>Official analytics report</span>
        </div>

        <header class="an-hero">
            <div class="an-hero-content">
                <p class="an-eyebrow"><i class="bi bi-bar-chart-steps" aria-hidden="true"></i> VISUAL DATA ANALYTICS</p>
                <h1 id="anTitle"><?= htmlspecialchars($analyticsViews[$analyticsView][0], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="an-sub" id="anSub"><?= htmlspecialchars($analyticsViews[$analyticsView][1], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="an-actions">
                <button class="btn btn-outline-primary" id="anCsv" type="button" disabled title="Export the current analytics as a CSV file"><i class="bi bi-filetype-csv" aria-hidden="true"></i><span>Export CSV</span></button>
                <button class="btn btn-primary" id="anPrint" type="button" disabled title="Print this analytics report or save it as a PDF"><i class="bi bi-printer" aria-hidden="true"></i><span>Print / Save PDF</span></button>
            </div>
        </header>
        <p class="an-print-meta" id="anPrintMeta" aria-hidden="true"></p>

        <!-- Section tabs: switch instantly, filters are kept -->
        <nav class="an-tabs" id="anTabs" aria-label="Analytics sections">
            <?php foreach ($analyticsViews as $key => $v): ?>
                <a class="an-tab<?= $key === $analyticsView ? ' active' : '' ?>"
                   href="<?= htmlspecialchars($tabHref($key), ENT_QUOTES, 'UTF-8') ?>"
                   data-an-view="<?= $key ?>" data-no-prefetch
                   <?= $key === $analyticsView ? 'aria-current="page"' : '' ?>>
                    <i class="bi <?= $v[2] ?>" aria-hidden="true"></i><span><?= htmlspecialchars($v[3], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Filters update the report automatically as soon as they change -->
        <form class="an-filters" id="anFilters" novalidate>
            <label><span><i class="bi bi-calendar3" aria-hidden="true"></i> Date range</span>
                <select class="form-select" id="anRange" name="range">
                    <?php foreach ($rangeOptions as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $selRange === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="an-custom" <?= $selRange === 'custom' ? '' : 'hidden' ?>><span><i class="bi bi-calendar-event" aria-hidden="true"></i> From</span>
                <input class="form-control" type="date" id="anFrom" value="<?= htmlspecialchars($selFrom, ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="an-custom" <?= $selRange === 'custom' ? '' : 'hidden' ?>><span><i class="bi bi-calendar-event" aria-hidden="true"></i> To</span>
                <input class="form-control" type="date" id="anTo" value="<?= htmlspecialchars($selTo, ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label><span><i class="bi bi-geo-alt" aria-hidden="true"></i> Street</span>
                <select class="form-select" id="anBlock">
                    <option value="">All streets</option>
                    <?php foreach ($streets as $s): ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?>" <?= $selBlock === $s ? 'selected' : '' ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="an-filter-actions">
                <button class="btn btn-outline-secondary an-reset" type="button" id="anReset"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset</button>
            </div>
            <span class="an-updated" id="anUpdated" aria-live="polite"><span class="an-live-dot" aria-hidden="true"></span><span id="anUpdatedText">Loading…</span></span>
        </form>

        <div class="an-error" id="anError" role="alert" hidden>
            <span><i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i><span id="anErrorText">We couldn’t load analytics right now.</span></span>
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

        <!-- Print-only signature lines (hidden on screen, shown by analytics-print.css) -->
        <div class="an-print-sign" style="display:none" aria-hidden="true">
            <div>Prepared by (name &amp; signature)</div>
            <div>Noted by (HOA officer)</div>
        </div>
    </main>
</div>
<script src="../Assets/js/admin-analytics.js?v=52"></script>
<?php require_once '../includes/footer.php'; ?>