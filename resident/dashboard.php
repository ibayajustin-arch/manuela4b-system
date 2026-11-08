<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/payment_methods.php';
require_once '../includes/feedback.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Asia/Manila');
$user_id = (int) $_SESSION['user_id'];

// Homeowner record and dues standing
$homeowner = null;
$summary = null;
$dues_error = false;
try {
    ensure_archive_columns($conn, 'payments');
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $homeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($homeowner) {
        $summary = homeowner_account_summary($conn, $homeowner);
    }
} catch (Throwable $e) {
    error_log('Resident dashboard dues failed: ' . $e->getMessage());
    $dues_error = true;
}

$resident_status = $homeowner ? strtolower((string) ($homeowner['status'] ?? 'pending')) : 'unavailable';
$is_active = $resident_status === 'active';
$outstanding = (float) ($summary['outstanding'] ?? 0);
$unpaid_months = $summary['unpaid'] ?? [];
$unpaid_count = count($unpaid_months);
$recent_payments = array_slice($summary['payments'] ?? [], 0, 3);

// Upcoming reservations
$upcoming_reservations = [];
$reservation_error = false;
try {
    ensure_archive_columns($conn, 'reservations');
    $today = date('Y-m-d');
    $now_time = date('H:i:s');
    $stmt = $conn->prepare(
        "SELECT id, facility, res_date, start_time, end_time, status, payment_status, payment_amount
         FROM reservations
         WHERE user_id = ? AND archived_at IS NULL AND status <> 'rejected'
           AND (res_date > ? OR (res_date = ? AND end_time > ?))
         ORDER BY res_date ASC, start_time ASC"
    );
    $stmt->bind_param("isss", $user_id, $today, $today, $now_time);
    $stmt->execute();
    $upcoming_reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    error_log('Resident dashboard reservations failed: ' . $e->getMessage());
    $reservation_error = true;
}
$next_reservation = $upcoming_reservations[0] ?? null;

// Suggestions, concerns, and incident reports
$feedback_items = [];
$open_feedback_count = 0;
$feedback_error = false;
try {
    ensure_resident_feedback_table($conn);
    ensure_archive_columns($conn, 'resident_feedback');
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM resident_feedback
         WHERE user_id = ? AND archived_at IS NULL AND status IN ('new', 'in_review')"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $open_feedback_count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT id, category, subject, status, admin_reply, admin_replied_at, created_at
         FROM resident_feedback
         WHERE user_id = ? AND archived_at IS NULL
         ORDER BY COALESCE(admin_replied_at, created_at) DESC, id DESC
         LIMIT 3"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $feedback_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    error_log('Resident dashboard feedback failed: ' . $e->getMessage());
    $feedback_error = true;
}

// Latest community announcements
$announcements = [];
$announcement_error = false;
try {
    ensure_archive_columns($conn, 'announcements');
    $stmt = $conn->prepare(
        "SELECT id, title, message, created_at FROM announcements
         WHERE archived_at IS NULL ORDER BY created_at DESC LIMIT 3"
    );
    $stmt->execute();
    $announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    error_log('Resident dashboard announcements failed: ' . $e->getMessage());
    $announcement_error = true;
}

// Greeting
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$display_name = trim((string) ($homeowner['full_name'] ?? ($_SESSION['full_name'] ?? '')));
$first_name = $display_name !== '' ? explode(' ', $display_name)[0] : 'Resident';
$address_label = $homeowner ? homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? '') : '';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$reservation_badge = static function (array $r): array {
    if (($r['status'] ?? '') === 'approved') return ['badge-success', 'Confirmed'];
    if (($r['payment_status'] ?? '') === 'paid') return ['badge-warning', 'Being reviewed'];
    return ['badge-warning', 'Awaiting payment'];
};
$feedback_badge = static function (string $status): array {
    if ($status === 'resolved') return ['badge-success', 'Resolved'];
    if ($status === 'in_review') return ['badge-info', 'In review'];
    return ['badge-neutral', 'New'];
};

require_once '../includes/header.php';
?>
<style>
    .resident-dashboard-page,
    .resident-dashboard-page .card,
    .resident-dashboard-page .card-body {
        min-width: 0;
    }

    .resident-dashboard-page .rd-stack {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .resident-dashboard-page .rd-stack > .card {
        flex: 0 0 auto;
        height: auto !important;
    }

    .resident-dashboard-page .rd-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }

    /* Keep the header button clear of the floating notification bell. */
    @media (min-width: 768px) {
        .resident-dashboard-page .rd-header {
            padding-right: 3.75rem;
        }
    }

    .resident-dashboard-page .rd-item-side .btn-link {
        color: var(--primary-color);
        font-weight: 600;
    }

    .resident-dashboard-page .rd-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 48px;
    }

    .resident-dashboard-page .rd-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .resident-dashboard-page .rd-section-head h2 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
    }

    .resident-dashboard-page .rd-section-head a {
        flex-shrink: 0;
        font-size: 0.875rem;
        font-weight: 600;
    }

    .resident-dashboard-page .rd-item {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color, #E5E7EB);
    }

    .resident-dashboard-page .rd-item:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .resident-dashboard-page .rd-item-main {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .resident-dashboard-page .rd-item-side {
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        align-items: flex-end;
        gap: 0.35rem;
    }

    .resident-dashboard-page .rd-announcement {
        display: block;
        width: 100%;
        padding: 0.75rem 0;
        border: 0;
        border-bottom: 1px solid var(--border-color, #E5E7EB);
        background: transparent;
        text-align: left;
        overflow-wrap: anywhere;
    }

    .resident-dashboard-page .rd-announcement:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .resident-dashboard-page .rd-quick-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }

    .resident-dashboard-page .rd-quick-grid .btn {
        display: flex;
        min-height: 48px;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        font-weight: 600;
    }

    .resident-dashboard-page .rd-empty {
        padding: 1rem 0;
        color: var(--text-muted);
        text-align: center;
    }

    .resident-dashboard-page .rd-reply {
        margin-top: 0.35rem;
        padding: 0.5rem 0.65rem;
        border-left: 3px solid var(--primary-color);
        border-radius: 0.25rem;
        background: var(--primary-soft);
        font-size: 0.85rem;
    }
</style>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0 resident-dashboard-page">

            <div class="rd-header mb-4">
                <div>
                    <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($greeting . ', ' . $first_name) ?>!</h1>
                    <p class="text-muted mb-0">
                        <?= htmlspecialchars(date('l, F j, Y')) ?>
                        <?php if ($address_label !== ''): ?>
                            · <?= htmlspecialchars($address_label) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="profile.php" class="btn btn-outline-primary rd-action">
                    <i class="bi bi-person-circle me-2" aria-hidden="true"></i>My Profile
                </a>
            </div>

            <?php if ($flash_success): ?>
                <div class="alert alert-success border-0 rounded-3 d-flex align-items-center mb-4 shadow-sm">
                    <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i><div><?= htmlspecialchars($flash_success) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="alert alert-danger border-0 rounded-3 d-flex align-items-center mb-4 shadow-sm">
                    <i class="bi bi-exclamation-circle-fill me-2" aria-hidden="true"></i><div><?= htmlspecialchars($flash_error) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!$is_active && !$dues_error): ?>
                <div class="alert alert-warning border-0 rounded-3 d-flex align-items-center mb-4 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
                    <div>Your resident account is not active. Please contact the subdivision administrator.</div>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-lg-4 col-sm-6">
                    <a href="payments.php" class="text-decoration-none d-block h-100">
                        <div class="card stat-card-pastel <?= $outstanding > 0 ? 'pastel-red' : 'pastel-green' ?> hover-lift h-100">
                            <div class="card-body stat-card">
                                <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--primary-color);"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                                <div>
                                    <p class="stat-label mb-1">Dues Balance</p>
                                    <?php if ($dues_error): ?>
                                        <p class="stat-value mb-1">-</p>
                                        <p class="mb-0 small text-muted">Dues are unavailable right now.</p>
                                    <?php elseif ($outstanding > 0): ?>
                                        <p class="stat-value mb-1">₱<?= number_format($outstanding, 2) ?></p>
                                        <p class="mb-0 small text-muted">
                                            <?= $unpaid_count ?> unpaid <?= $unpaid_count === 1 ? 'month' : 'months' ?>, since <?= htmlspecialchars(date('F Y', strtotime($unpaid_months[0] . '-01'))) ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="stat-value mb-1">All Paid Up</p>
                                        <p class="mb-0 small text-muted">No unpaid dues as of <?= htmlspecialchars(date('F Y')) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-lg-4 col-sm-6">
                    <a href="reservations.php" class="text-decoration-none d-block h-100">
                        <div class="card stat-card-pastel pastel-green hover-lift h-100">
                            <div class="card-body stat-card">
                                <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--primary-color);"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div>
                                <div>
                                    <p class="stat-label mb-1">Upcoming Reservations</p>
                                    <?php if ($reservation_error): ?>
                                        <p class="stat-value mb-1">-</p>
                                        <p class="mb-0 small text-muted">Reservations are unavailable right now.</p>
                                    <?php else: ?>
                                        <p class="stat-value mb-1"><?= count($upcoming_reservations) ?></p>
                                        <p class="mb-0 small text-muted">
                                            <?php if ($next_reservation): ?>
                                                Next: <?= htmlspecialchars($next_reservation['facility']) ?>, <?= htmlspecialchars(date('M j', strtotime($next_reservation['res_date']))) ?>
                                            <?php else: ?>
                                                No upcoming bookings
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-lg-4 col-sm-12">
                    <a href="feedback.php" class="text-decoration-none d-block h-100">
                        <div class="card stat-card-pastel pastel-amber hover-lift h-100">
                            <div class="card-body stat-card">
                                <div class="stat-icon" style="background: rgba(255,255,255,0.75); color: var(--primary-color);"><i class="bi bi-chat-dots" aria-hidden="true"></i></div>
                                <div>
                                    <p class="stat-label mb-1">Open Concerns</p>
                                    <?php if ($feedback_error): ?>
                                        <p class="stat-value mb-1">-</p>
                                        <p class="mb-0 small text-muted">Concerns are unavailable right now.</p>
                                    <?php else: ?>
                                        <p class="stat-value mb-1"><?= $open_feedback_count ?></p>
                                        <p class="mb-0 small text-muted">New or in review by the admin</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7 rd-stack">
                    <section class="card" aria-labelledby="rdAnnouncementsTitle">
                        <div class="card-body">
                            <div class="rd-section-head">
                                <h2 id="rdAnnouncementsTitle">Community Announcements</h2>
                            </div>
                            <?php if ($announcement_error): ?>
                                <p class="rd-empty mb-0">Announcements are unavailable right now.</p>
                            <?php elseif (!$announcements): ?>
                                <p class="rd-empty mb-0">No announcements yet.</p>
                            <?php else: ?>
                                <?php foreach ($announcements as $a): ?>
                                    <button type="button" class="rd-announcement"
                                            data-bs-toggle="modal" data-bs-target="#viewAnnouncementModal"
                                            data-title="<?= htmlspecialchars($a['title'], ENT_QUOTES) ?>"
                                            data-date="<?= htmlspecialchars(date('M d, Y h:i A', strtotime($a['created_at'])), ENT_QUOTES) ?>"
                                            data-message="<?= htmlspecialchars($a['message'], ENT_QUOTES) ?>">
                                        <span class="d-block fw-semibold text-dark"><?= htmlspecialchars($a['title']) ?></span>
                                        <span class="d-block small text-muted mb-1"><?= htmlspecialchars(date('M d, Y', strtotime($a['created_at']))) ?></span>
                                        <span class="d-block small text-muted"><?= htmlspecialchars(mb_strimwidth(strip_tags($a['message']), 0, 120, '...')) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card" aria-labelledby="rdReservationsTitle">
                        <div class="card-body">
                            <div class="rd-section-head">
                                <h2 id="rdReservationsTitle">My Upcoming Reservations</h2>
                                <a href="reservations.php">View all</a>
                            </div>
                            <?php if ($reservation_error): ?>
                                <p class="rd-empty mb-0">Reservations are unavailable right now.</p>
                            <?php elseif (!$upcoming_reservations): ?>
                                <p class="rd-empty mb-0">No upcoming reservations.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($upcoming_reservations, 0, 3) as $r): ?>
                                    <?php [$badgeClass, $badgeLabel] = $reservation_badge($r); ?>
                                    <div class="rd-item">
                                        <div class="rd-item-main">
                                            <div class="fw-semibold"><?= htmlspecialchars($r['facility']) ?></div>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars(date('M d, Y', strtotime($r['res_date']))) ?>,
                                                <?= htmlspecialchars(date('g:i A', strtotime($r['start_time']))) ?> to <?= htmlspecialchars(date('g:i A', strtotime($r['end_time']))) ?>
                                            </div>
                                        </div>
                                        <div class="rd-item-side">
                                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <div class="col-lg-5 rd-stack">
                    <section class="card" aria-labelledby="rdQuickTitle">
                        <div class="card-body">
                            <div class="rd-section-head">
                                <h2 id="rdQuickTitle">Quick Actions</h2>
                            </div>
                            <div class="rd-quick-grid">
                                <a href="payments.php" class="btn btn-primary"><i class="bi bi-wallet2" aria-hidden="true"></i>Pay Dues</a>
                                <a href="reservations.php" class="btn btn-outline-primary"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Reserve</a>
                                <a href="feedback.php" class="btn btn-outline-primary"><i class="bi bi-chat-dots" aria-hidden="true"></i>Send Concern</a>
                                <a href="../settings.php" class="btn btn-outline-primary"><i class="bi bi-gear" aria-hidden="true"></i>Settings</a>
                            </div>
                        </div>
                    </section>

                    <section class="card" aria-labelledby="rdPaymentsTitle">
                        <div class="card-body">
                            <div class="rd-section-head">
                                <h2 id="rdPaymentsTitle">Recent Payments</h2>
                                <a href="payments.php">View all</a>
                            </div>
                            <?php if ($dues_error): ?>
                                <p class="rd-empty mb-0">Payments are unavailable right now.</p>
                            <?php elseif (!$recent_payments): ?>
                                <p class="rd-empty mb-0">No payments recorded yet.</p>
                            <?php else: ?>
                                <?php foreach ($recent_payments as $p): ?>
                                    <?php
                                    $period_time = strtotime(((string) $p['period_covered']) . '-01');
                                    $period_label = $period_time ? date('F Y', $period_time) : (string) $p['period_covered'];
                                    ?>
                                    <div class="rd-item">
                                        <div class="rd-item-main">
                                            <div class="fw-semibold"><?= htmlspecialchars($period_label) ?></div>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars(date('M d, Y', strtotime($p['payment_date']))) ?>
                                                · <?= htmlspecialchars(payment_method_label($p['payment_method'] ?? null, $p['notes'] ?? null)) ?>
                                            </div>
                                        </div>
                                        <div class="rd-item-side">
                                            <span class="fw-semibold">₱<?= number_format((float) $p['amount_paid'], 2) ?></span>
                                            <button type="button" class="btn btn-link btn-sm p-0"
                                                    aria-label="View receipt for <?= htmlspecialchars($period_label, ENT_QUOTES) ?>"
                                                    onclick="openReceipt('payment', <?= (int) $p['id'] ?>)">Receipt</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card" aria-labelledby="rdFeedbackTitle">
                        <div class="card-body">
                            <div class="rd-section-head">
                                <h2 id="rdFeedbackTitle">My Concerns</h2>
                                <a href="feedback.php">View all</a>
                            </div>
                            <?php if ($feedback_error): ?>
                                <p class="rd-empty mb-0">Concerns are unavailable right now.</p>
                            <?php elseif (!$feedback_items): ?>
                                <p class="rd-empty mb-0">You have not sent any suggestions or concerns yet.</p>
                            <?php else: ?>
                                <?php foreach ($feedback_items as $f): ?>
                                    <?php [$badgeClass, $badgeLabel] = $feedback_badge((string) $f['status']); ?>
                                    <div class="rd-item">
                                        <div class="rd-item-main">
                                            <div class="fw-semibold"><?= htmlspecialchars($f['subject']) ?></div>
                                            <div class="small text-muted"><?= htmlspecialchars(ucfirst((string) $f['category'])) ?> · <?= htmlspecialchars(date('M d, Y', strtotime($f['created_at']))) ?></div>
                                            <?php if (trim((string) ($f['admin_reply'] ?? '')) !== ''): ?>
                                                <div class="rd-reply"><strong>Admin reply:</strong> <?= htmlspecialchars(mb_strimwidth((string) $f['admin_reply'], 0, 120, '...')) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rd-item-side">
                                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Announcement Modal -->
<div class="modal fade" id="viewAnnouncementModal" tabindex="-1" aria-labelledby="modalAnnounceTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-bottom-0 pb-0 px-4 pt-4">
        <h2 class="modal-title fw-bold fs-4" id="modalAnnounceTitle" style="color: var(--primary-color) !important;"></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2 pb-4 px-4">
        <p class="text-muted mb-4 pb-2 border-bottom small">
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
            <span id="modalAnnounceDate"></span>
        </p>
        <div id="modalAnnounceMessage" class="text-dark" style="white-space: pre-wrap; font-size: 1.05rem; line-height: 1.6;"></div>
      </div>
      <div class="modal-footer border-top-0 pt-0 mb-2 px-4">
        <button type="button" class="btn btn-outline-secondary px-4 py-2 fw-semibold" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('viewAnnouncementModal');
        if (!modal) return;
        modal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            modal.querySelector('#modalAnnounceTitle').textContent = button.getAttribute('data-title');
            modal.querySelector('#modalAnnounceDate').textContent = button.getAttribute('data-date');
            modal.querySelector('#modalAnnounceMessage').textContent = button.getAttribute('data-message');
        });
    });
</script>

<?php
require_once '../includes/receipt_modal.php';
require_once '../includes/footer.php';
?>
