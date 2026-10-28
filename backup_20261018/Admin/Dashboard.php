<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/payment_methods.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Asia/Manila');
$paymentArchiveReady = false;
$homeownerArchiveReady = false;
$reservationArchiveReady = false;
try {
    ensure_archive_columns($conn, 'payments');
    $paymentArchiveReady = true;
} catch (Throwable $e) {
    error_log('Dashboard payment archive setup failed: ' . $e->getMessage());
}
try {
    ensure_archive_columns($conn, 'homeowners');
    $homeownerArchiveReady = true;
} catch (Throwable $e) {
    error_log('Dashboard homeowner archive setup failed: ' . $e->getMessage());
}
try {
    ensure_archive_columns($conn, 'reservations');
    $reservationArchiveReady = true;
} catch (Throwable $e) {
    error_log('Dashboard reservation archive setup failed: ' . $e->getMessage());
}

// ---------- Helpers ----------
function generate_period_range($start_ym, $end_ym) {
    $periods = [];
    $start = DateTime::createFromFormat('!Y-m', $start_ym);
    $end = DateTime::createFromFormat('!Y-m', $end_ym);
    if (!$start || !$end || $start > $end) return $periods;
    while ($start <= $end) {
        $periods[] = $start->format('Y-m');
        $start->modify('+1 month');
    }
    return $periods;
}

function scalar_query($conn, $sql, $types = '', $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return 0;
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        $stmt->close();
        return $row ? $row[0] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

$current_period = date('Y-m');
$prev_period = date('Y-m', strtotime('first day of last month'));

// ---------- Headline numbers ----------
$homeownerArchiveCondition = $homeownerArchiveReady ? ' WHERE archived_at IS NULL' : '';
$total_homeowners = (int) scalar_query($conn, "SELECT COUNT(*) FROM homeowners{$homeownerArchiveCondition}");
$pending_verification = (int) scalar_query($conn, "SELECT COUNT(*) FROM homeowners WHERE LOWER(status) = 'pending'" . ($homeownerArchiveReady ? ' AND archived_at IS NULL' : ''));
$pending_reservations = (int) scalar_query($conn, "SELECT COUNT(*) FROM reservations r INNER JOIN homeowners h ON h.user_id = r.user_id AND h.archived_at IS NULL WHERE r.status = 'pending'" . ($reservationArchiveReady ? ' AND r.archived_at IS NULL' : ''));
$paymentActiveCondition = $paymentArchiveReady ? ' AND archived_at IS NULL' : '';
$revenue_now = (float) scalar_query($conn, "SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?{$paymentActiveCondition}", 's', [$current_period]);
$revenue_prev = (float) scalar_query($conn, "SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?{$paymentActiveCondition}", 's', [$prev_period]);
$revenue_change = $revenue_prev > 0 ? (($revenue_now - $revenue_prev) / $revenue_prev) * 100 : null;

// ---------- Billable homeowners (verified, not inactive) ----------
$billable = [];
try {
    $res = $conn->query("SELECT * FROM homeowners WHERE block != 'Unassigned' AND LOWER(status) = 'active'" . ($homeownerArchiveReady ? ' AND archived_at IS NULL' : '') . " ORDER BY block, lot");
    if ($res) $billable = $res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) { $billable = []; }

$paidPeriods = [];
$collected_period = 0;
try {
    $res = $conn->query("SELECT homeowner_id, period_covered, amount_paid FROM payments" . ($paymentArchiveReady ? ' WHERE archived_at IS NULL' : ''));
    if ($res) {
        while ($p = $res->fetch_assoc()) {
            $hid = (int) $p['homeowner_id'];
            $period = (string) $p['period_covered'];
            $paidPeriods[$hid][$period] = ($paidPeriods[$hid][$period] ?? 0) + (float) $p['amount_paid'];
            if ($p['period_covered'] === $current_period) {
                $collected_period += (float) $p['amount_paid'];
            }
        }
    }
} catch (Exception $e) {}

$dues_amount = 0;
$dues_accounts = 0;
$expected_total = 0;
$dues_eligible_count = 0;
$followUp = [];

foreach ($billable as $h) {
    $due = (float) $h['monthly_due'];
    $paid = $paidPeriods[$h['id']] ?? [];
    $duesStartYm = homeowner_dues_start_ym($h);
    if ($duesStartYm > $current_period) continue;
    $dues_eligible_count++;
    $expected_total += $due;

    $paidCurrent = (float) ($paid[$current_period] ?? 0);
    if ($paidCurrent < $due) {
        $dues_amount += max(0, $due - $paidCurrent);
        $dues_accounts++;
    }

    // Count consecutive unpaid months, starting from the current month going backwards
    $expectedPeriods = generate_period_range($duesStartYm, $current_period);
    $streak = 0;
    for ($i = count($expectedPeriods) - 1; $i >= 0; $i--) {
        if ((float) ($paid[$expectedPeriods[$i]] ?? 0) < $due) {
            $streak++;
        } else {
            break;
        }
    }

    if ($streak > 0) {
        $followUp[] = [
            'id' => $h['id'],
            'name' => $h['full_name'],
            'block' => $h['block'],
            'lot' => $h['lot'],
            'streak' => $streak,
            'amount' => array_sum(array_map(static fn($period) => max(0, $due - (float) ($paid[$period] ?? 0)), array_slice($expectedPeriods, -$streak))),
        ];
    }
}

usort($followUp, fn($a, $b) => $b['streak'] <=> $a['streak'] ?: $b['amount'] <=> $a['amount']);
$overdue_2plus = count(array_filter($followUp, fn($e) => $e['streak'] >= 2));
$topFollowUp = array_slice($followUp, 0, 3);

$billable_count = $dues_eligible_count;
$paid_accounts = $billable_count - $dues_accounts;
$collection_rate = $billable_count > 0 ? (int) round(($paid_accounts / $billable_count) * 100) : 0;

// ---------- Recent transactions ----------
$recentTx = [];
try {
    $archiveCondition = $paymentArchiveReady ? 'WHERE p.archived_at IS NULL ' : '';
    $res = $conn->query("SELECT p.*, h.full_name AS homeowner_name FROM payments p LEFT JOIN homeowners h ON p.homeowner_id = h.id {$archiveCondition}ORDER BY p.payment_date DESC, p.id DESC LIMIT 5");
    if ($res) $recentTx = $res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) { $recentTx = []; }

// ---------- Pending reservations ----------
$pendingRes = [];
try {
    $res = $conn->query("SELECT r.*, u.full_name AS reserved_by FROM reservations r INNER JOIN homeowners h ON h.user_id = r.user_id AND h.archived_at IS NULL LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'pending'" . ($reservationArchiveReady ? ' AND r.archived_at IS NULL' : '') . " ORDER BY r.res_date ASC, r.start_time ASC LIMIT 4");
    if ($res) $pendingRes = $res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) { $pendingRes = []; }

// ---------- Greeting ----------
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim($_SESSION['full_name'] ?? 'Admin'))[0];

$quickActions = [
    ['Add Homeowner',     'homeowner_create.php', 'bi-person-plus', 'var(--success-soft)', 'var(--primary-color)'],
    ['Manage Dues',       '../settings.php?section=dues', 'bi-cash-stack',  'var(--warning-soft)', 'var(--warning-color)'],
    ['Post Announcement', 'announcements.php',    'bi-megaphone',   '#ECE8F7',             '#6B4FA3'],
];

require_once '../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>

    <div class="main-content">

        <!-- Header -->
        <div class="page-header">
            <div>
                <h1><?= $greeting ?>, <?= htmlspecialchars($firstName) ?>!</h1>
                <p class="mb-0">Here's what needs your attention today &middot; <?= date('l, F j, Y') ?></p>
            </div>
            <a href="analytics.php" class="btn btn-primary">
                <i class="bi bi-graph-up-arrow me-2"></i> View Analytics
            </a>
        </div>

        <!-- Stat cards (action-oriented) -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-sm-6">
                <a href="homeowners.php" class="text-decoration-none d-block h-100">
                    <div class="card stat-card-pastel pastel-green hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--primary-color);"><i class="bi bi-people"></i></div>
                            <div>
                                <p class="stat-label mb-1">Total Homeowners</p>
                                <p class="stat-value mb-1"><?= number_format($total_homeowners) ?></p>
                                <p class="mb-0" style="font-size: 0.78rem; color: var(--text-muted);">
                                    <?php if ($pending_verification > 0): ?>
                                        <i class="bi bi-person-exclamation"></i> <?= $pending_verification ?> awaiting verification
                                    <?php else: ?>
                                        <i class="bi bi-check2-circle"></i> All accounts verified
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-sm-6">
                <a href="reservations.php" class="text-decoration-none d-block h-100">
                    <div class="card stat-card-pastel pastel-blue hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--info-color);"><i class="bi bi-calendar-check"></i></div>
                            <div>
                                <p class="stat-label mb-1">Pending Reservations</p>
                                <p class="stat-value mb-1"><?= number_format($pending_reservations) ?></p>
                                <p class="mb-0" style="font-size: 0.78rem; color: var(--text-muted);">
                                    <?= $pending_reservations > 0 ? 'Waiting for your approval' : 'Nothing waiting' ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-sm-6">
                <a href="payments.php" class="text-decoration-none d-block h-100">
                    <div class="card stat-card-pastel pastel-amber hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--warning-color);"><i class="bi bi-cash-coin"></i></div>
                            <div>
                                <p class="stat-label mb-1">Revenue This Month</p>
                                <p class="stat-value mb-1">₱<?= number_format($revenue_now, 2) ?></p>
                                <p class="mb-0" style="font-size: 0.78rem; color: var(--text-muted);">
                                    <?php if ($revenue_change === null): ?>
                                        No data for last month
                                    <?php else: ?>
                                        <span style="color: <?= $revenue_change >= 0 ? 'var(--success-color)' : 'var(--danger-color)' ?>; font-weight: 600;">
                                            <?= $revenue_change >= 0 ? '↑' : '↓' ?> <?= abs(round($revenue_change)) ?>%
                                        </span> vs last month
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-xl-3 col-sm-6">
                <a href="analytics.php" class="text-decoration-none d-block h-100">
                    <div class="card stat-card-pastel pastel-red hover-lift h-100">
                        <div class="card-body stat-card">
                            <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--danger-color);"><i class="bi bi-exclamation-circle"></i></div>
                            <div>
                                <p class="stat-label mb-1">Pending Dues</p>
                                <p class="stat-value mb-1">₱<?= number_format($dues_amount, 2) ?></p>
                                <p class="mb-0" style="font-size: 0.78rem; color: var(--text-muted);">
                                    <?= $dues_accounts ?> unpaid account<?= $dues_accounts === 1 ? '' : 's' ?> this month
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Row: Follow-up + Quick actions -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card card-body h-100">
                    <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
                        <div>
                            <h2 class="mb-0" style="font-size: 1rem;">Needs Follow-up</h2>
                            <p class="mb-0" style="font-size: 0.8rem; color: var(--text-muted);">Unpaid accounts, most overdue first</p>
                        </div>
                        <?php if ($overdue_2plus > 0): ?>
                            <span class="badge badge-danger"><?= $overdue_2plus ?> account<?= $overdue_2plus === 1 ? '' : 's' ?> unpaid 2+ months</span>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($topFollowUp)): ?>
                        <div class="status-box status-box-success">
                            <i class="bi bi-check-circle-fill status-box-icon"></i>
                            <div>
                                <p class="status-box-title">All accounts are current</p>
                                <p class="status-box-subtitle">No homeowners need follow-up right now.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($topFollowUp as $e): ?>
                        <div class="tx-row">
                            <div class="d-flex align-items-center gap-3">
                                <div class="tx-avatar" style="background-color: var(--danger-soft); color: var(--danger-color);"><?= strtoupper(substr($e['name'], 0, 1)) ?></div>
                                <div>
                                    <p class="mb-0 fw-semibold" style="color: var(--text-heading); font-size: 0.9rem;"><?= htmlspecialchars($e['name']) ?></p>
                                    <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);">
                                        <?= $e['streak'] === 1 ? 'Unpaid for the month of ' . htmlspecialchars(date('F', strtotime($current_period . '-01'))) : $e['streak'] . ' months unpaid' ?> &middot; <?= htmlspecialchars(homeowner_address_label($e['block'] ?? '', $e['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <span class="fw-semibold" style="color: var(--text-heading);">₱<?= number_format($e['amount'], 2) ?></span>
                                <a href="homeowner_view.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="mt-3">
                        <a href="analytics.php" class="fw-semibold" style="font-size: 0.85rem;">
                            See the full delinquency breakdown in Analytics <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <h2 class="mb-3" style="font-size: 1rem;"><i class="bi bi-lightning-charge-fill me-2" style="color: var(--primary-color);"></i>Quick Actions</h2>
                    <?php foreach ($quickActions as [$label, $href, $icon, $bg, $fg]): ?>
                    <a href="<?= $href ?>" class="d-flex align-items-center justify-content-between p-3 rounded-3 text-decoration-none mb-2" style="background: <?= $bg ?>; color: var(--text-heading);">
                        <span class="d-flex align-items-center gap-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3" style="width: 34px; height: 34px; background: rgba(255,255,255,0.8); color: <?= $fg ?>;"><i class="bi <?= $icon ?>"></i></span>
                            <span class="fw-semibold" style="font-size: 0.9rem;"><?= $label ?></span>
                        </span>
                        <i class="bi bi-chevron-right" style="color: var(--text-muted);"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Row: Collection progress + Pending reservations + Recent transactions -->
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <h2 class="mb-0" style="font-size: 1rem;">Collection Progress</h2>
                    <p class="mb-3" style="font-size: 0.8rem; color: var(--text-muted);"><?= date('F Y') ?></p>

                    <div style="position: relative; height: 190px;">
                        <canvas id="collectionRing" role="img" aria-label="Collection progress for active homeowner accounts"></canvas>
                        <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none;">
                            <span style="font-size: 1.8rem; font-weight: 700; color: var(--text-heading); line-height: 1;"><?= $billable_count > 0 ? $collection_rate . '%' : '—' ?></span>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">
                                <?= $billable_count > 0 ? 'of active accounts paid' : 'No active accounts' ?>
                            </span>
                        </div>
                    </div>

                    <div class="mt-3" style="font-size: 0.85rem;">
                        <div class="d-flex justify-content-between mb-1">
                            <span><span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: #15803D;"></span>Paid</span>
                            <strong style="color: var(--text-heading);"><?= $paid_accounts ?> account<?= $paid_accounts === 1 ? '' : 's' ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span><span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: #D1C7E3;"></span>Unpaid</span>
                            <strong style="color: var(--text-heading);"><?= $dues_accounts ?> account<?= $dues_accounts === 1 ? '' : 's' ?></strong>
                        </div>
                        <div class="pt-2" style="border-top: 1px solid var(--border-color-soft); color: var(--text-muted);">
                            ₱<?= number_format($collected_period, 2) ?> collected of ₱<?= number_format($expected_total, 2) ?> expected
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h2 class="mb-0" style="font-size: 1rem;">Pending Reservations</h2>
                            <p class="mb-0" style="font-size: 0.8rem; color: var(--text-muted);">Needs your decision</p>
                        </div>
                        <a href="reservations.php" class="btn btn-sm btn-outline-secondary">Review</a>
                    </div>

                    <?php if (empty($pendingRes)): ?>
                        <div class="table-empty-state">
                            <i class="bi bi-calendar-check fs-2 d-block mb-2"></i>
                            No pending requests.
                        </div>
                    <?php else: ?>
                        <?php foreach ($pendingRes as $r): ?>
                        <div class="tx-row" style="padding-left: 0; padding-right: 0;">
                            <div>
                                <p class="mb-0 fw-semibold" style="color: var(--text-heading); font-size: 0.9rem;"><?= htmlspecialchars($r['facility'] ?? '') ?></p>
                                <p class="mb-0" style="font-size: 0.75rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($r['reserved_by'] ?? 'Unknown') ?> &middot;
                                    <?= !empty($r['res_date']) ? date('M j', strtotime($r['res_date'])) : '' ?>,
                                    <?= !empty($r['start_time']) ? date('g:i A', strtotime($r['start_time'])) : '' ?>
                                </p>
                            </div>
                            <span class="badge badge-warning">Pending</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-body h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h2 class="mb-0" style="font-size: 1rem;">Recent Transactions</h2>
                            <p class="mb-0" style="font-size: 0.8rem; color: var(--text-muted);">Latest payments received</p>
                        </div>
                        <a href="payments.php" class="btn btn-sm btn-outline-secondary">View All</a>
                    </div>

                    <?php if (empty($recentTx)): ?>
                        <div class="table-empty-state">No recent transactions.</div>
                    <?php else: ?>
                        <?php foreach ($recentTx as $tx):
                            $name = $tx['homeowner_name'] ?? 'Unknown';
                        ?>
                        <div class="tx-row" style="padding-left: 0; padding-right: 0;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="tx-avatar"><?= strtoupper(substr($name, 0, 1)) ?></div>
                                <div>
                                    <p class="mb-0 fw-semibold" style="color: var(--text-heading); font-size: 0.88rem;"><?= htmlspecialchars($name) ?></p>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span style="font-size: 0.72rem; color: var(--text-muted);">
                                            <?= !empty($tx['payment_date']) ? date('M j', strtotime($tx['payment_date'])) : '' ?>
                                        </span>
                                        <?= payment_method_indicator_html($tx['payment_method'] ?? null, $tx['notes'] ?? null) ?>
                                    </div>
                                </div>
                            </div>
                            <span class="fw-semibold" style="color: var(--text-heading); font-size: 0.88rem;">₱<?= number_format($tx['amount_paid'], 2) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
new Chart(document.getElementById('collectionRing').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Paid', 'Unpaid'],
        datasets: [{
            data: [<?= $billable_count > 0 ? (int) $paid_accounts : 0 ?>, <?= (int) $dues_accounts ?>],
            backgroundColor: ['#15803D', '#D1C7E3'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '75%',
        plugins: { legend: { display: false } }
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
