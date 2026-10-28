<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/payment_methods.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
ensure_archive_columns($conn, 'homeowners');
ensure_archive_columns($conn, 'payments');
ensure_archive_columns($conn, 'reservations');

$availableBlocks = [];
try {
    $res = $conn->query("SELECT DISTINCT TRIM(block) AS block_name FROM homeowners WHERE archived_at IS NULL AND TRIM(block) != '' AND LOWER(TRIM(block)) != 'unassigned' AND LOWER(TRIM(status)) = 'active' ORDER BY block_name");
    if ($res) while ($row = $res->fetch_assoc()) $availableBlocks[] = $row['block_name'];
} catch (Exception $e) {}

$validMonth = static function ($value) {
    return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
};
$currentMonth = date('Y-m');
$selectedTo = $validMonth($_GET['to'] ?? null) ? $_GET['to'] : $currentMonth;
$defaultFrom = date('Y-m', strtotime('-5 months', strtotime($selectedTo . '-01')));
$selectedFrom = $validMonth($_GET['from'] ?? null) ? $_GET['from'] : $defaultFrom;
if ($selectedFrom > $selectedTo) [$selectedFrom, $selectedTo] = [$selectedTo, $selectedFrom];
$rangeLength = ((int) substr($selectedTo, 0, 4) - (int) substr($selectedFrom, 0, 4)) * 12
    + ((int) substr($selectedTo, 5, 2) - (int) substr($selectedFrom, 5, 2)) + 1;
if ($rangeLength > 36) $selectedFrom = date('Y-m', strtotime('-35 months', strtotime($selectedTo . '-01')));

$selectedBlock = trim((string) ($_GET['block'] ?? ''));
if ($selectedBlock !== '' && !in_array($selectedBlock, $availableBlocks, true)) $selectedBlock = '';
$blockPaymentHomeownerIds = [];
if ($selectedBlock !== '') {
    $escapedBlock = $conn->real_escape_string($selectedBlock);
    $res = $conn->query("SELECT id FROM homeowners WHERE archived_at IS NULL AND LOWER(TRIM(block)) = LOWER('{$escapedBlock}')");
    if ($res) while ($row = $res->fetch_assoc()) $blockPaymentHomeownerIds[] = (int) $row['id'];
}

// Shared computation: filtered collection trend, late payments, reliability,
// at-risk accounts, and block comparisons.
$analyticsFrom = $selectedFrom;
$analyticsTo = $selectedTo;
require_once '../includes/analytics_data.php';

// ================================================================
// MODULE 4: BLOCK COMPARISON
// ================================================================
$blockStats = [];
$blockStatsByPeriod = [];
$blockMembersByPeriod = [];
foreach ($homeowners as $h) {
    $block = trim((string) $h['block']);
    if (!isset($blockStats[$block])) $blockStats[$block] = ['total' => 0, 'paid' => 0];
    $duesStartYm = homeowner_dues_start_ym($h);
    if ($duesStartYm > $current_period) continue;
    $blockStats[$block]['total']++;
    $paidThisPeriod = (float) ($paymentsByHomeowner[$h['id']][$current_period] ?? 0);
    $isPaid = $paidThisPeriod >= (float) $h['monthly_due'];
    if ($isPaid) {
        $blockStats[$block]['paid']++;
    }
}
foreach ($trendPeriods as $period) {
    foreach ($homeowners as $h) {
        $block = trim((string) $h['block']);
        if (homeowner_dues_start_ym($h) > $period) continue;
        if (!isset($blockStatsByPeriod[$period][$block])) {
            $blockStatsByPeriod[$period][$block] = ['total' => 0, 'paid' => 0];
        }
        $blockStatsByPeriod[$period][$block]['total']++;
        $periodPaid = (float) ($paymentsByHomeowner[$h['id']][$period] ?? 0);
        $isPaid = $periodPaid >= (float) $h['monthly_due'];
        if ($isPaid) $blockStatsByPeriod[$period][$block]['paid']++;
        $blockMembersByPeriod[$period][$block][] = [
            'id' => (int) $h['id'],
            'name' => $h['full_name'],
            'lot' => $h['lot'],
            'paid' => $isPaid,
            'paid_amount' => $periodPaid,
            'due' => (float) $h['monthly_due'],
        ];
    }
}
$blockLabels = [];
$blockRates = [];
foreach ($blockStats as $block => $s) {
    $blockLabels[] = "{$block} Street";
    $blockRates[] = $s['total'] > 0 ? round(($s['paid'] / $s['total']) * 100) : 0;
}   
if (empty($blockLabels)) { $blockLabels = ['No Data']; $blockRates = [0]; }
$periodHomeowners = array_filter($homeowners, fn($h) => homeowner_dues_start_ym($h) <= $current_period);
$expectedThisMonth = array_sum(array_map(fn($h) => (float) $h['monthly_due'], $periodHomeowners));
$paidThisMonth = 0;
foreach ($periodHomeowners as $h) {
    $paidThisMonth += min((float) $h['monthly_due'], (float) ($paymentsByHomeowner[$h['id']][$current_period] ?? 0));
}
$collectionRate = $expectedThisMonth > 0 ? round(($paidThisMonth / $expectedThisMonth) * 100) : null;
$selectedPeriodLabel = date('F Y', strtotime($current_period . '-01'));
$overdueRows = array_merge($agingBuckets['0-30'], $agingBuckets['31-60'], $agingBuckets['61-90'], $agingBuckets['90+']);
$totalOverdue = array_sum(array_column($reliabilityList, 'amount_at_risk'));

// ================================================================
// MODULE 5: FACILITY UTILIZATION (approved bookings only)
// ================================================================
$facilityLabels = [];
$facilityData = [];
try {
    $dateFrom = $selectedFrom . '-01';
    $dateTo = date('Y-m-t', strtotime($selectedTo . '-01'));
    $facilityScope = " WHERE r.archived_at IS NULL AND r.status = 'approved' AND r.res_date BETWEEN '{$dateFrom}' AND '{$dateTo}'";
    $facilitySource = 'FROM reservations r INNER JOIN homeowners active_homeowner ON active_homeowner.user_id = r.user_id AND active_homeowner.archived_at IS NULL';
    if ($selectedBlock !== '') {
        $escapedBlock = $conn->real_escape_string($selectedBlock);
        $facilitySource .= " INNER JOIN (SELECT DISTINCT user_id FROM homeowners WHERE archived_at IS NULL AND user_id IS NOT NULL AND LOWER(TRIM(block)) = LOWER('{$escapedBlock}')) block_users ON block_users.user_id = r.user_id";
    }
    $res = $conn->query("SELECT r.facility, COUNT(*) AS total {$facilitySource}{$facilityScope} GROUP BY r.facility ORDER BY total DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $facilityLabels[] = $row['facility'];
            $facilityData[] = (int) $row['total'];
        }
    }
} catch (Exception $e) {}
if (empty($facilityLabels)) { $facilityLabels = ['No Data']; $facilityData = [0]; }

// ================================================================
// MODULE 7: PAYMENT CHANNELS
// ================================================================
$channelLabels = [];
$channelData = [];
try {
    $paymentScope = "archived_at IS NULL AND period_covered BETWEEN '{$selectedFrom}' AND '{$selectedTo}'";
    if ($selectedBlock !== '') {
        $paymentIds = empty($blockPaymentHomeownerIds) ? '0' : implode(',', array_map('intval', $blockPaymentHomeownerIds));
        $paymentScope .= " AND homeowner_id IN ({$paymentIds})";
    }
    $methodExpression = payment_method_sql_expression('payments');
    $res = $conn->query("SELECT {$methodExpression} AS method, COUNT(*) AS total FROM payments WHERE {$paymentScope} GROUP BY method ORDER BY total DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $channelLabels[] = $row['method'];
            $channelData[] = (int) $row['total'];
        }
    }
} catch (Exception $e) {}
if (empty($channelLabels)) { $channelLabels = ['No Data']; $channelData = [0]; }

// ================================================================
// MODULE 8: ACCOUNT STATUS
// ================================================================
$statusLabels = [];
$statusData = [];
$statusColors = [];
$statusPalette = ['active' => '#15803D', 'pending' => '#92400E', 'inactive' => '#64748B'];
try {
    $statusScope = ' WHERE archived_at IS NULL';
    if ($selectedBlock !== '') {
        $escapedBlock = $conn->real_escape_string($selectedBlock);
        $statusScope .= " AND LOWER(TRIM(block)) = LOWER('{$escapedBlock}')";
    }
    $res = $conn->query("SELECT LOWER(status) AS s, COUNT(*) AS total FROM homeowners{$statusScope} GROUP BY s");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $statusLabels[] = ucfirst($row['s']);
            $statusData[] = (int) $row['total'];
            $statusColors[] = $statusPalette[$row['s']] ?? '#3B82F6';
        }
    }
} catch (Exception $e) {}
if (empty($statusLabels)) { $statusLabels = ['No Data']; $statusData = [0]; $statusColors = ['#A8A1B5']; }

require_once '../includes/header.php';
?>

<style>
.analytics-trend-card h2, .main-content h2 { color: var(--text-heading); letter-spacing: -.02em; }
.analytics-intro { margin: 0; color: var(--text-heading); font-size: clamp(1.5rem, 2.2vw, 2.4rem); font-weight: 800; line-height: 1.15; letter-spacing: -.04em; }
.analytics-chart { position: relative; width: 100%; min-width: 0; }
.analytics-chart-trend { height: 310px; }
.analytics-chart-medium { height: 300px; }
.analytics-chart-small { height: 280px; }
.analytics-kpi { flex: 1 1 110px; min-width: 110px; padding: 12px 14px; border: 1.5px solid var(--border-color); border-radius: 12px; background: #FFFFFF; box-shadow: var(--shadow-sm); }
.analytics-kpi p { color: var(--text-muted); font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .045em; }
.analytics-kpi p:nth-child(2) { font-size: 1.2rem !important; font-weight: 800; line-height: 1.35; color: var(--text-heading); }
.analytics-kpi:nth-child(3) { background: #F1F5F9; border-color: #CBD5E1; }
.analytics-kpi-highlight { background: #F1F5F9; border-color: #CBD5E1; }
@media (max-width: 767.98px) { .analytics-chart-trend { height: 270px; } .analytics-chart-medium { height: 260px; } .analytics-chart-small { height: 250px; } }
</style>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">

        <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <p class="analytics-intro">Explore collections, late dues, and facility use for <?= htmlspecialchars($selectedFrom) ?> through <?= htmlspecialchars($selectedTo) ?><?= $selectedBlock !== '' ? ' · Street ' . htmlspecialchars($selectedBlock) : '' ?>.</p>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportModal">
                Generate Report
            </button>
        </div>

        <form method="get" action="analytics.php" class="card card-body mb-4" data-fullscreen-loading="Updating analytics...">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-3">
                    <label for="analyticsFrom" class="form-label mb-1">From month</label>
                    <input type="month" id="analyticsFrom" name="from" class="form-control" value="<?= htmlspecialchars($selectedFrom) ?>" required>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="analyticsTo" class="form-label mb-1">To month</label>
                    <input type="month" id="analyticsTo" name="to" class="form-control" value="<?= htmlspecialchars($selectedTo) ?>" required>
                </div>
                <div class="col-sm-8 col-lg-3">
                    <label for="analyticsBlock" class="form-label mb-1">Street</label>
                    <select id="analyticsBlock" name="block" class="form-select">
                        <option value="">All streets</option>
                        <?php foreach ($availableBlocks as $blockOption): ?>
                            <option value="<?= htmlspecialchars($blockOption) ?>" <?= $selectedBlock === $blockOption ? 'selected' : '' ?>><?= htmlspecialchars($blockOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-lg-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">Apply filters</button>
                    <a href="analytics.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
            <p class="small text-muted mt-2 mb-0">Up to 36 months per view. The range filters collection trends, payment methods, and bookings. Late dues and payment reliability are measured through the To month; account status shows current accounts.</p>
        </form>

        <div class="row g-3 mb-4" aria-label="Collection summary">
            <div class="col-sm-6 col-xl-3"><div class="card card-body h-100"><span class="small text-muted">Collection rate through <?= htmlspecialchars($selectedPeriodLabel) ?></span><strong class="fs-4"><?= $collectionRate === null ? '—' : $collectionRate . '%' ?></strong><span class="small text-muted">Paid ₱<?= number_format($paidThisMonth, 2) ?> of ₱<?= number_format($expectedThisMonth, 2) ?> expected</span></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card card-body h-100"><span class="small text-muted">Homeowners fully paid</span><strong class="fs-4"><?= array_sum(array_column($blockStats, 'paid')) ?> / <?= count($periodHomeowners) ?></strong><span class="small text-muted">For <?= htmlspecialchars($selectedPeriodLabel) ?></span></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card card-body h-100"><span class="small text-muted">Behind for <?= htmlspecialchars($selectedPeriodLabel) ?></span><strong class="fs-4"><?= count($overdueRows) ?></strong><span class="small text-muted">No full payment recorded for selected month</span></div></div>
            <div class="col-sm-6 col-xl-3"><div class="card card-body h-100"><span class="small text-muted">Outstanding dues</span><strong class="fs-4">₱<?= number_format($totalOverdue, 2) ?></strong><span class="small text-muted">Net of recorded payments by billing period</span></div></div>
        </div>

        <!-- MODULE 1: Monthly Collections -->
        <div class="card card-body mb-4 analytics-trend-card">
            <div class="row align-items-center mb-4 g-3">
                <div class="col-md-7">
                    <h2 class="mb-0" style="font-size: 1rem;">Monthly Collections</h2>
                    <p class="mb-0" style="font-size: 0.8rem; color: var(--text-muted);">Select a month on the chart to compare streets and inspect household payments.</p>
                </div>
                <div class="col-md-5">
                    <div class="analytics-kpis d-flex flex-wrap gap-2 justify-content-md-end align-items-center">
                        <div class="analytics-kpi text-center flex-grow-1">
                            <p class="mb-0" style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Selected Month</p>
                            <p class="mb-0 fw-bold" style="color: var(--text-heading);">₱<?= number_format($thisMonthTotal, 0) ?></p>
                        </div>
                        <div class="analytics-kpi text-center flex-grow-1">
                            <p class="mb-0" style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">vs Previous Month</p>
                            <p class="mb-0 fw-bold" style="color: <?= $momChange === null ? 'var(--text-muted)' : ($momChange >= 0 ? 'var(--success-color)' : 'var(--danger-color)') ?>;">
                                <?= $momChange === null ? 'No baseline' : (($momChange >= 0 ? '↑ ' : '↓ ') . abs(round($momChange)) . '%') ?>
                            </p>
                        </div>
                        <div class="analytics-kpi text-center flex-grow-1">
                            <p class="mb-0" style="font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase;">Next Month (Est.)</p>
                            <p class="mb-0 fw-bold" style="color: var(--info-color);">₱<?= number_format($forecastNext, 0) ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="analytics-chart analytics-chart-trend" role="img" aria-label="Monthly collection trend chart">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- MODULE 2: Late Payments Overview -->
            <div class="col-lg-7">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Late Payments Overview</h2>
                    <p class="mb-4" style="font-size: 0.8rem; color: var(--text-muted);">Unpaid monthly dues as of <?= htmlspecialchars($selectedPeriodLabel) ?></p>

                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="p-3 rounded-3 text-center" style="background: var(--warning-soft);">
                                <p class="mb-0 fw-bold fs-4" style="color: var(--warning-color);"><?= count($agingBuckets['0-30']) ?></p>
                                <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);">1 Month</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 rounded-3 text-center" style="background: rgba(201,138,30,0.2);">
                                <p class="mb-0 fw-bold fs-4" style="color: var(--warning-color);"><?= count($agingBuckets['31-60']) ?></p>
                                <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);">2 Months</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 rounded-3 text-center" style="background: var(--danger-soft);">
                                <p class="mb-0 fw-bold fs-4" style="color: var(--danger-color);"><?= count($agingBuckets['61-90']) ?></p>
                                <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);">3 Months</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 rounded-3 text-center" style="background: rgba(192,57,43,0.25);">
                                <p class="mb-0 fw-bold fs-4" style="color: var(--danger-color);"><?= count($agingBuckets['90+']) ?></p>
                                <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);">4+ Months</p>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive border-0 shadow-none p-0" style="max-height: 260px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Homeowner</th><th>Property Address</th><th>Unpaid Periods</th><th class="text-end">Amount Owed</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $overdueFlat = array_merge($agingBuckets['0-30'], $agingBuckets['31-60'], $agingBuckets['61-90'], $agingBuckets['90+']);
                                usort($overdueFlat, fn($a, $b) => $b['streak'] <=> $a['streak']);
                                foreach ($overdueFlat as $e):
                                    $label = $e['streak'] === 1 ? 'Unpaid for the month of ' . date('F', strtotime($current_period . '-01')) : ($e['streak'] >= 4 ? '4+ Months' : $e['streak'] . ' Months');
                                    $badgeClass = $e['streak'] <= 1 ? 'badge-warning' : 'badge-danger';
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($e['name']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars(homeowner_address_label($e['block'] ?? '', $e['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="badge <?= $badgeClass ?>"><?= $label ?></span></td>
                                    <td class="text-end fw-semibold">₱<?= number_format($e['amount_at_risk'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($overdueFlat)): ?>
                                <tr><td colspan="4" class="table-empty-state">No overdue accounts. Everyone's current!</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- MODULE 6: Needs Follow-Up -->
            <div class="col-lg-5">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Needs Follow-Up</h2>
                    <p class="mb-4" style="font-size: 0.8rem; color: var(--text-muted);">Homeowners to contact first about unpaid dues</p>

                    <?php if (empty($atRiskList)): ?>
                        <div class="table-empty-state">No at-risk accounts right now.</div>
                    <?php else: ?>
                        <?php foreach ($atRiskList as $e): ?>
                        <div class="d-flex align-items-center justify-content-between py-3" style="border-bottom: 1px solid var(--border-color-soft);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="tx-avatar" style="background-color: var(--danger-soft); color: var(--danger-color);"><?= strtoupper(substr($e['name'], 0, 1)) ?></div>
                                <div>
                                    <p class="mb-0 fw-semibold" style="font-size: 0.9rem; color: var(--text-heading);"><?= htmlspecialchars($e['name']) ?></p>
                                    <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);"><?= $e['streak'] ?> month<?= $e['streak'] > 1 ? 's' : '' ?> unpaid &middot; <?= htmlspecialchars($e['block']) ?> Street</p>
                                </div>
                            </div>
                            <a href="homeowner_view.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- MODULE 3: Payment Track Record -->
            <div class="col-lg-6">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Payment Track Record</h2>
                    <p class="mb-4" style="font-size: 0.8rem; color: var(--text-muted);">Share of billed months paid in full through <?= htmlspecialchars($selectedPeriodLabel) ?> &mdash; lowest first</p>

                    <div class="table-responsive border-0 shadow-none p-0" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Homeowner</th><th class="text-end">Score</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($reliabilityList, 0, 10) as $e):
                                    $barColor = $e['score'] >= 80 ? 'var(--success-color)' : ($e['score'] >= 50 ? 'var(--warning-color)' : 'var(--danger-color)');
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($e['name']) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 justify-content-end">
                                            <div style="width: 90px; height: 6px; background: var(--border-color-soft); border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?= $e['score'] ?>%; height: 100%; background: <?= $barColor ?>;"></div>
                                            </div>
                                            <span class="fw-bold" style="min-width: 36px; color: <?= $barColor ?>;"><?= $e['score'] ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($reliabilityList)): ?>
                                <tr><td colspan="2" class="table-empty-state">No data yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- MODULE 4: Payments by Street -->
            <div class="col-lg-6">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Payments by Street</h2>
                    <p class="mb-2" style="font-size: 0.8rem; color: var(--text-muted);">Select a month in Monthly Collections to compare streets, then select a bar to inspect its households.</p>
                    <p id="blockSelectedPeriod" class="small fw-semibold mb-3" aria-live="polite">Showing <?= htmlspecialchars(date('F Y', strtotime($current_period . '-01'))) ?>.</p>
                    <div class="analytics-chart analytics-chart-medium">
                        <canvas id="blockChart"></canvas>
                    </div>
                    <div id="blockDrilldown" class="mt-3" hidden aria-live="polite">
                        <div class="d-flex justify-content-between align-items-center mb-2"><strong id="blockDrilldownTitle"></strong><button type="button" class="btn btn-sm btn-outline-secondary" id="closeBlockDrilldown">Close</button></div>
                        <div class="table-responsive" style="max-height: 250px; overflow-y: auto"><table class="table table-sm align-middle mb-0"><thead><tr><th>Homeowner</th><th>Lot</th><th>Status</th><th class="text-end">Paid / Due</th></tr></thead><tbody id="blockDrilldownRows"></tbody></table></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODULES 5, 7, 8 -->
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Most Used Facilities</h2>
                    <p class="mb-4" style="font-size: 0.8rem; color: var(--text-muted);">Approved bookings from <?= htmlspecialchars($selectedFrom) ?> through <?= htmlspecialchars($selectedTo) ?><?= $selectedBlock !== '' ? ' for ' . htmlspecialchars($selectedBlock) . ' Street' : '' ?></p>
                    <div class="analytics-chart analytics-chart-small">
                        <canvas id="facilityChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Payment Methods</h2>
                    <p class="mb-4" style="font-size: 0.8rem; color: var(--text-muted);">Payment methods used from <?= htmlspecialchars($selectedFrom) ?> through <?= htmlspecialchars($selectedTo) ?></p>
                    <div class="analytics-chart analytics-chart-small">
                        <canvas id="channelChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <h2 class="mb-1" style="font-size: 1rem;">Account Status</h2>
                    <p class="mb-4" style="font-size: 0.8rem; color: var(--text-muted);">Current account status<?= $selectedBlock !== '' ? ' on ' . htmlspecialchars($selectedBlock) . ' Street' : ' across all streets' ?></p>
                    <div class="analytics-chart analytics-chart-small">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- GENERATE REPORT MODAL -->
<div class="modal fade" id="reportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="get" action="report.php" target="_blank">
            <div class="modal-header">
                <h5 class="modal-title">Generate Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Report type</label>
                    <select name="type" id="reportType" class="form-select">
                        <option value="collection">Payment Summary</option>
                        <option value="aging">Late Payments</option>
                        <option value="followup">Follow-Up List</option>
                        <option value="reliability">Payment Track Record</option>
                    </select>
                </div>
                <div class="row g-2 mb-3" id="rangeFields">
                    <div class="col">
                        <label class="form-label">From</label>
                        <input type="month" name="from" class="form-control" value="<?= htmlspecialchars($selectedFrom) ?>">
                    </div>
                    <div class="col">
                        <label class="form-label">To</label>
                        <input type="month" name="to" class="form-control" value="<?= htmlspecialchars($selectedTo) ?>">
                    </div>
                </div>
                <div>
                    <label class="form-label">Format</label>
                    <select name="format" class="form-select">
                        <option value="pdf">PDF (print / save as PDF)</option>
                        <option value="csv">Excel / CSV</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" data-loading-text="Opening report...">Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
// Show the date range only for the Collection Summary report
document.getElementById('reportType').addEventListener('change', function () {
    document.getElementById('rangeFields').style.display = this.value === 'collection' ? '' : 'none';
});

Chart.defaults.font.family = "Poppins, -apple-system, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.font.weight = '600';
Chart.defaults.color = '#2E273D';
const trendLabels = <?= json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const trendValues = <?= json_encode($trendData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const trendPeriods = <?= json_encode($trendPeriods, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const trendPeriodNames = <?= json_encode(array_combine($trendPeriods, array_map(fn($period) => date('F Y', strtotime($period . '-01')), $trendPeriods)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const forecastValues = trendValues.map(() => null);
forecastValues[forecastValues.length - 1] = trendValues[trendValues.length - 1] || 0;
forecastValues.push(<?= json_encode(round($forecastNext, 2)) ?>);
trendLabels.push('Next month');
trendValues.push(null);
const trendChart = new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [{
            label: 'Collected (₱)',
            data: trendValues,
            borderColor: '#0F172A',
            backgroundColor: 'rgba(15, 23, 42, 0.08)',
            fill: true,
            tension: 0.35,
            pointBackgroundColor: '#0F172A',
            pointRadius: 4,
            pointHoverRadius: 7,
            borderWidth: 3.5
        }, {
            label: 'Estimate (₱)',
            data: forecastValues,
            borderColor: '#64748B',
            borderDash: [6, 5],
            pointRadius: 5,
            pointStyle: 'rectRot',
            fill: false,
            tension: 0.25
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        onClick: function(event, elements) {
            const monthPoint = elements.find(element => element.datasetIndex === 0 && trendPeriods[element.index]);
            if (monthPoint) updateBlockPeriod(trendPeriods[monthPoint.index]);
        },
        onHover: function(event, elements) {
            if (event.native) event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        plugins: { 
            legend: { 
                display: true, 
                position: 'top', 
                align: 'end', 
                labels: { 
                    usePointStyle: true, 
                    boxWidth: 8,
                    font: { size: 12, weight: '700' },
                    color: '#1E293B'
                } 
            } 
        },
        scales: {
            x: { 
                grid: { display: false }, 
                ticks: { 
                    maxRotation: 0, 
                    autoSkip: true, 
                    maxTicksLimit: 8,
                    font: { size: 11.5, weight: '600' },
                    color: '#475569'
                } 
            },
            y: { 
                beginAtZero: true, 
                grid: { color: 'rgba(15, 23, 42, 0.08)' }, 
                ticks: { 
                    font: { size: 11.5, weight: '600' },
                    color: '#475569',
                    callback: value => '₱' + Number(value).toLocaleString('en-PH', { notation: 'compact', maximumFractionDigits: 1 }) 
                } 
            }
        }
    }
});

// Color-coded block bars: Green (>=80%), Amber (>=50%), Red (<50%)
const blockRatesData = <?= json_encode($blockRates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const blockNames = <?= json_encode(array_keys($blockStats), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const blockStatsByPeriod = <?= json_encode($blockStatsByPeriod, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const blockMembersByPeriod = <?= json_encode($blockMembersByPeriod, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const blockColors = blockRatesData.map(function(rate) {
    if (rate >= 80) return '#15803D';
    if (rate >= 50) return '#92400E';
    return '#B91C1C';
});

const blockChart = new Chart(document.getElementById('blockChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($blockLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        datasets: [{
            label: 'Paid (%)',
            data: blockRatesData,
            backgroundColor: blockColors,
            borderRadius: 6,
            barPercentage: 0.65
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        onClick: function(event, elements) {
            if (!elements.length || !blockNames[elements[0].index]) return;
            const block = blockNames[elements[0].index];
            const panel = document.getElementById('blockDrilldown');
            const rows = document.getElementById('blockDrilldownRows');
            document.getElementById('blockDrilldownTitle').textContent = block + ' Street · ' + trendPeriodNames[selectedPeriod];
            rows.replaceChildren();
            (((blockMembersByPeriod[selectedPeriod] || {})[block]) || []).forEach(function(member) {
                const row = document.createElement('tr');
                const nameCell = document.createElement('td');
                const nameLink = document.createElement('a');
                nameLink.href = 'homeowner_view.php?id=' + encodeURIComponent(member.id);
                nameLink.textContent = member.name;
                nameCell.appendChild(nameLink); row.appendChild(nameCell);
                [member.lot, member.paid ? 'Paid' : 'Unpaid / partial'].forEach(function(value) {
                    const cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell);
                });
                const amount = document.createElement('td'); amount.className = 'text-end';
                amount.textContent = '₱' + Number(member.paid_amount).toLocaleString('en-PH', {minimumFractionDigits: 2}) + ' / ₱' + Number(member.due).toLocaleString('en-PH', {minimumFractionDigits: 2});
                row.appendChild(amount); rows.appendChild(row);
            });
            panel.hidden = false;
        },
        onHover: function(event, elements) {
            if (event.native) event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        plugins: { legend: { display: false } },
        scales: {
            x: { 
                grid: { display: false },
                ticks: { font: { size: 11.5, weight: '600' }, color: '#4A4259' }
            },
            y: { 
                beginAtZero: true, 
                max: 100, 
                grid: { color: 'rgba(109, 40, 217, 0.1)' }, 
                ticks: { 
                    font: { size: 11.5, weight: '600' },
                    color: '#4A4259',
                    callback: value => value + '%' 
                } 
            }
        }
    }
});

let selectedPeriod = <?= json_encode($current_period) ?>;
function updateBlockPeriod(period) {
    if (!blockStatsByPeriod[period]) return;
    selectedPeriod = period;
    const periodStats = blockStatsByPeriod[period];
    const values = blockNames.map(function(block) {
        const stats = periodStats[block];
        return stats && stats.total ? Math.round((stats.paid / stats.total) * 100) : 0;
    });
    blockChart.data.datasets[0].data = values;
    blockChart.data.datasets[0].backgroundColor = values.map(function(rate) {
        if (rate >= 80) return '#15803D';
        if (rate >= 50) return '#92400E';
        return '#B91C1C';
    });
    blockChart.update();
    document.getElementById('blockSelectedPeriod').textContent = 'Showing ' + trendPeriodNames[period] + '. Click a bar to inspect household payments.';
    document.getElementById('blockDrilldown').hidden = true;
}

document.getElementById('closeBlockDrilldown').addEventListener('click', function() {
    document.getElementById('blockDrilldown').hidden = true;
});

new Chart(document.getElementById('facilityChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($facilityLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        datasets: [{
            label: 'Bookings',
            data: <?= json_encode($facilityData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            backgroundColor: '#0F172A',
            borderRadius: 6,
            barPercentage: 0.55
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { 
                beginAtZero: true, 
                ticks: { precision: 0, font: { size: 11.5, weight: '600' }, color: '#475569' }, 
                grid: { color: 'rgba(15, 23, 42, 0.08)' } 
            },
            y: { 
                grid: { display: false },
                ticks: { font: { size: 12, weight: '600' }, color: '#09090B' }
            }
        }
    }
});

new Chart(document.getElementById('channelChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($channelLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        datasets: [{
            data: <?= json_encode($channelData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            backgroundColor: ['#0F172A', '#0284C7', '#D97706', '#15803D', '#64748B', '#475569'],
            borderWidth: 3,
            borderColor: '#FFFFFF',
            hoverOffset: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: { 
            legend: { 
                position: 'bottom', 
                labels: { 
                    usePointStyle: true, 
                    pointStyle: 'circle', 
                    boxWidth: 9, 
                    padding: 14,
                    font: { size: 12, weight: '600' },
                    color: '#2E273D'
                } 
            } 
        }
    }
});

new Chart(document.getElementById('statusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($statusLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        datasets: [{
            data: <?= json_encode($statusData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            backgroundColor: <?= json_encode($statusColors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
            borderWidth: 3,
            borderColor: '#FFFFFF',
            hoverOffset: 7
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: { 
            legend: { 
                position: 'bottom', 
                labels: { 
                    usePointStyle: true, 
                    pointStyle: 'circle', 
                    boxWidth: 9, 
                    padding: 14,
                    font: { size: 12, weight: '600' },
                    color: '#2E273D'
                } 
            } 
        }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
