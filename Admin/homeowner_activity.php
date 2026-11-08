<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$homeownerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($homeownerId <= 0) {
    header('Location: homeowners.php');
    exit;
}

$stmt = $conn->prepare('SELECT id, user_id, full_name, block, lot, status FROM homeowners WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $homeownerId);
$stmt->execute();
$homeowner = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$homeowner) {
    header('Location: homeowners.php');
    exit;
}

$pageSize = 25;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalItems = 0;
$activities = [];
$historyAvailable = table_exists($conn, 'activity_log') || table_exists($conn, 'payments') || table_exists($conn, 'reservations');
$historyError = '';

if ($historyAvailable) {
    try {
        $totalItems = homeowner_activity_count($conn, $homeownerId, !empty($homeowner['user_id']) ? (int) $homeowner['user_id'] : null);

        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $pageSize;

        $eventSources = [];
        $homeownerIdSql = (int) $homeownerId;
        if (table_exists($conn, 'activity_log')) {
            $eventSources[] = "SELECT 'activity' AS event_type, a.id AS source_id,
                COALESCE(NULLIF(a.action, ''), 'Account activity') AS title,
                COALESCE(a.details, '') AS details,
                COALESCE(NULLIF(a.admin_name, ''), 'Admin') AS actor,
                a.created_at AS happened_at
                FROM activity_log a WHERE a.homeowner_id = {$homeownerIdSql}
                AND NOT (LOWER(COALESCE(a.action, '')) = 'recorded payment'
                  AND EXISTS (SELECT 1 FROM payments p WHERE p.homeowner_id = a.homeowner_id
                    AND a.details LIKE CONCAT('%', p.receipt_no, '%')))";
        }
        if (table_exists($conn, 'payments')) {
            $eventSources[] = "SELECT 'payment' AS event_type, p.id AS source_id,
                'Payment received' AS title,
                CONCAT_WS(' · ', CONCAT('Amount: ₱', FORMAT(p.amount_paid, 2)),
                    CONCAT('Period: ', p.period_covered),
                    IF(p.payment_date IS NOT NULL AND p.payment_date <> '0000-00-00', CONCAT('Paid: ', DATE_FORMAT(p.payment_date, '%b %e, %Y')), NULL),
                    IF(p.receipt_no IS NOT NULL AND p.receipt_no <> '', CONCAT('Receipt: ', p.receipt_no), NULL),
                    IF(p.payment_method IS NOT NULL AND p.payment_method <> '', CONCAT('Method: ', p.payment_method), NULL)) AS details,
                COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'Admin') AS actor,
                p.created_at AS happened_at
                FROM payments p LEFT JOIN users u ON u.id = p.collector_id
                WHERE p.homeowner_id = {$homeownerIdSql}";
        }
        if (!empty($homeowner['user_id']) && table_exists($conn, 'reservations')) {
            $userIdSql = (int) $homeowner['user_id'];
            $eventSources[] = "SELECT 'reservation' AS event_type, r.id AS source_id,
                'Facility reservation submitted' AS title,
                CONCAT_WS(' · ', CONCAT('Facility: ', r.facility),
                    CONCAT('Date: ', DATE_FORMAT(r.res_date, '%b %e, %Y')),
                    CONCAT('Time: ', TIME_FORMAT(r.start_time, '%h:%i %p'), '–', TIME_FORMAT(r.end_time, '%h:%i %p')),
                    CONCAT('Current status: ', UPPER(LEFT(COALESCE(r.status, 'pending'), 1)), SUBSTRING(COALESCE(r.status, 'pending'), 2))) AS details,
                COALESCE(NULLIF(u.full_name, ''), NULLIF(u.username, ''), 'Resident') AS actor,
                r.created_at AS happened_at
                FROM reservations r LEFT JOIN users u ON u.id = r.user_id
                WHERE r.user_id = {$userIdSql}";
        }
        if ($eventSources) {
            $eventsSql = 'SELECT * FROM (' . implode(' UNION ALL ', $eventSources) . ') account_events
                ORDER BY happened_at DESC, source_id DESC LIMIT ' . (int) $pageSize . ' OFFSET ' . (int) $offset;
            $activityResult = $conn->query($eventsSql);
            if (!$activityResult) throw new RuntimeException('Could not load account activity events.');
            $activities = $activityResult->fetch_all(MYSQLI_ASSOC);
            $activityResult->free();
        }
    } catch (Throwable $e) {
        error_log('Homeowner activity history failed: ' . $e->getMessage());
        $historyError = 'Activity history could not be loaded. Please try again later.';
        $totalItems = 0;
        $activities = [];
        $totalPages = 1;
        $currentPage = 1;
    }
} else {
    $totalPages = 1;
}

$startItem = $totalItems > 0 ? (($currentPage - 1) * $pageSize) + 1 : 0;
$endItem = min($currentPage * $pageSize, $totalItems);
$e = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="container-fluid p-0">
            <div class="page-header">
                <div>
                    <h1>Activity History</h1>
                    <p><?= $e($homeowner['full_name']) ?> · <?= $e(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? '')) ?></p>
                </div>
                <a href="homeowner_view.php?id=<?= $homeownerId ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to homeowner profile</a>
            </div>

            <?php if (!$historyAvailable): ?>
                <div class="alert alert-warning">Activity history is unavailable because no account activity sources are set up.</div>
            <?php elseif ($historyError !== ''): ?>
                <div class="alert alert-danger"><?= $e($historyError) ?></div>
            <?php elseif ($totalItems === 0): ?>
                <section class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5">
                        <span class="activity-empty-icon"><i class="bi bi-clock-history"></i></span>
                        <h2 class="h5 mt-3">No account activity recorded yet</h2>
                        <p class="text-muted mb-0">Payments, facility reservations, and account changes will appear here.</p>
                    </div>
                </section>
            <?php else: ?>
                <section class="card border-0 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h2 class="h5 mb-1">Account activity</h2>
                            <p class="small text-muted mb-0">Payments, reservations, and account changes · newest first</p>
                        </div>
                        <span class="badge bg-light text-dark"><?= number_format($totalItems) ?> total</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="activity-history-list">
                            <?php foreach ($activities as $activity): ?>
                                <article class="activity-history-entry">
                                    <?php
                                        $eventType = (string) ($activity['event_type'] ?? 'activity');
                                        $eventIcon = $eventType === 'payment' ? 'bi-cash-coin' : ($eventType === 'reservation' ? 'bi-calendar-check' : 'bi-check2');
                                        $eventLabel = $eventType === 'payment' ? 'Payment' : ($eventType === 'reservation' ? 'Reservation' : 'Account change');
                                        $eventTimestamp = strtotime((string) $activity['happened_at']) ?: time();
                                    ?>
                                    <span class="activity-entry-marker" aria-hidden="true"><i class="bi <?= $eventIcon ?>"></i></span>
                                    <div class="activity-entry-content">
                                        <span class="badge bg-light text-dark mb-1"><?= $e($eventLabel) ?></span>
                                        <h3><?= $e($activity['title'] ?: 'Activity recorded') ?></h3>
                                        <?php if (trim((string) ($activity['details'] ?? '')) !== ''): ?>
                                            <p><?= nl2br($e($activity['details'])) ?></p>
                                        <?php endif; ?>
                                        <div class="activity-entry-meta">
                                            <span><i class="bi bi-person me-1"></i><?= $e($activity['actor'] ?: 'Admin') ?></span>
                                            <time datetime="<?= $e(date('c', $eventTimestamp)) ?>"><i class="bi bi-clock me-1"></i><?= $e(date('M d, Y · g:i A', $eventTimestamp)) ?></time>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <span class="small text-muted">Showing <?= number_format($startItem) ?>–<?= number_format($endItem) ?> of <?= number_format($totalItems) ?> events</span>
                            <nav aria-label="Activity history pages">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?id=<?= $homeownerId ?>&amp;page=<?= max(1, $currentPage - 1) ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i> Previous</a>
                                    </li>
                                    <?php for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++): ?>
                                        <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>">
                                            <a class="page-link" href="?id=<?= $homeownerId ?>&amp;page=<?= $page ?>" <?= $page === $currentPage ? 'aria-current="page"' : '' ?>><?= $page ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="?id=<?= $homeownerId ?>&amp;page=<?= min($totalPages, $currentPage + 1) ?>" aria-label="Next page">Next <i class="bi bi-chevron-right"></i></a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </section>
                <?php if ($totalPages <= 1): ?>
                    <p class="small text-muted text-end mt-3">Showing <?= number_format($totalItems) ?> account event<?= $totalItems === 1 ? '' : 's' ?>.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php require_once '../includes/footer.php'; ?>
