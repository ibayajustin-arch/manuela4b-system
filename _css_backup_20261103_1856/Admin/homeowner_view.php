<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: homeowners.php");
    exit;
}

try {
    $stmt = $conn->prepare("SELECT h.*, u.profile_photo AS resident_photo
                             FROM homeowners h
                             LEFT JOIN users u ON h.user_id = u.id
                             WHERE h.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $homeowner = $result->fetch_assoc();
    $stmt->close();

    if (!$homeowner) {
        header("Location: homeowners.php");
        exit;
    }
} catch (Exception $e) {
    header("Location: homeowners.php");
    exit;
}

$id = (int) $homeowner['id'];

// CSRF token for the action forms
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// Flash messages (set by homeowner_action.php)
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// The photo is whatever the resident most recently uploaded on their own profile page
$has_photo = !empty($homeowner['resident_photo']) && file_exists('../' . $homeowner['resident_photo']);
$status = $homeowner['status'] ?? 'inactive';
$member_since = !empty($homeowner['created_at']) ? date('F Y', strtotime($homeowner['created_at'])) : '—';
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Optional fields (exist only after running sql/homeowner_profile_upgrade.sql)
$hasExtras = array_key_exists('ownership_type', $homeowner);
$ownership = $homeowner['ownership_type'] ?? 'Owner';
$ecName = $homeowner['emergency_contact_name'] ?? '';
$ecNum = $homeowner['emergency_contact_number'] ?? '';
$members = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($homeowner['household_members'] ?? '')))));

// ---------- Payment standing ----------
$sum = homeowner_account_summary($conn, $homeowner);
$lastPay = $sum['last_payment'];
$hasLastPayDate = $lastPay && !empty($lastPay['payment_date']) && $lastPay['payment_date'] !== '0000-00-00';
$lastPayLabel = ($hasLastPayDate)
    ? date('M d, Y', strtotime($lastPay['payment_date'])) : 'No payment yet';
$expectedMonths = count($sum['expected']);
$paidMonths = max(0, $expectedMonths - (int) $sum['unpaid_count']);
$unpaidDueText = (int) $sum['unpaid_count'] === 1
    ? 'Unpaid for the month of ' . date('F', strtotime($sum['unpaid'][0] . '-01'))
    : ((int) $sum['unpaid_count'] > 1
        ? (int) $sum['unpaid_count'] . ' monthly dues not yet paid'
        : 'All monthly dues are paid');
$paidMonthsText = $expectedMonths > 0
    ? $paidMonths . ' of ' . $expectedMonths . ' expected monthly dues recorded as paid'
    : 'No monthly dues expected yet';
$scoreColor = $sum['score'] >= 80 ? 'var(--success-color)' : ($sum['score'] >= 50 ? 'var(--warning-color)' : 'var(--danger-color)');
$streakColor = $sum['streak'] === 0 ? 'var(--success-color)' : ($sum['streak'] <= 2 ? 'var(--warning-color)' : 'var(--danger-color)');

// ---------- Unified activity history count (account actions, payments, reservations) ----------
$activityCount = homeowner_activity_count($conn, $id, isset($homeowner['user_id']) ? (int) $homeowner['user_id'] : null);

$canRemind = $status === 'active' && !empty($homeowner['email']) && $sum['unpaid_count'] > 0;

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">

        <div class="page-header flex-wrap gap-2">
            <div class="d-flex align-items-center">
                <a href="homeowners.php" class="btn btn-icon btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i></a>
                <h1 class="mb-0">Homeowner Profile</h1>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="record_payment.php?homeowner_id=<?= $id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-plus-circle me-1"></i> Record Payment
                </a>
                <?php if ($canRemind): ?>
                    <button type="button" class="btn btn-outline-secondary" id="sendReminderButton"
                            onclick="sendReminder(this)">
                        <i class="bi bi-envelope-paper me-1"></i> Send Reminder
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary" disabled
                            title="<?= $status !== 'active' ? 'Only active homeowners can receive dues reminders' : (empty($homeowner['email']) ? 'No email on file' : 'No unpaid months') ?>">
                        <i class="bi bi-envelope-paper me-1"></i> Send Reminder
                    </button>
                <?php endif; ?>
                <a href="homeowner_edit.php?id=<?= $id ?>" class="btn btn-primary">
                    <i class="bi bi-pencil-square me-2"></i> Edit Homeowner
                </a>
            </div>
        </div>

        <?php if ($flash_success): ?>
            <div class="alert alert-success border-0 rounded-3 d-flex align-items-center mb-4">
                <i class="bi bi-check-circle-fill me-2"></i><div><?= $e($flash_success) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="alert alert-danger border-0 rounded-3 d-flex align-items-center mb-4">
                <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= $e($flash_error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($homeowner['verification_id_path']) || !empty($homeowner['verification_id_back_path'])): ?>
        <section class="card mb-4" aria-labelledby="verificationIdHeading">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h2 id="verificationIdHeading" class="h5 fw-bold mb-1">Resident ID verification</h2>
                        <p class="small text-muted mb-0">Private document submitted with the homeowner application. Only administrators can access it.</p>
                    </div>
                    <span class="badge <?= $status === 'active' ? 'badge-success' : 'badge-warning' ?>"><?= $status === 'active' ? 'Verified' : 'Pending review' ?></span>
                </div>
                <div class="row g-3">
                    <?php foreach (['front' => ['Front of ID', $homeowner['verification_id_path'] ?? ''], 'back' => ['Back of ID', $homeowner['verification_id_back_path'] ?? '']] as $side => [$sideLabel, $documentFile]): ?>
                        <?php if (!empty($documentFile) && preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $documentFile)): ?>
                            <div class="col-12 col-md-6">
                                <h3 class="h6"><?= $sideLabel ?></h3>
                                <a href="homeowner_id_document.php?id=<?= $id ?>&amp;side=<?= $side ?>" target="_blank" rel="noopener" aria-label="Open <?= strtolower($sideLabel) ?> image in a new tab">
                                    <img src="homeowner_id_document.php?id=<?= $id ?>&amp;side=<?= $side ?>" alt="<?= $sideLabel ?> submitted by <?= $e($homeowner['full_name'] ?? 'homeowner') ?>" class="img-fluid rounded border" style="max-height: 360px;">
                                </a>
                                <div class="small mt-2"><a href="homeowner_id_document.php?id=<?= $id ?>&amp;side=<?= $side ?>" target="_blank" rel="noopener">Open full-size image</a></div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php elseif ($status === 'pending'): ?>
        <div class="alert alert-warning border-0 rounded-3 mb-4">This pending application has no resident ID image attached.</div>
        <?php endif; ?>

        <!-- ================= PROFILE CARD ================= -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row gap-4 gap-md-5">

                    <!-- Left: Avatar, name, status -->
                    <div class="text-center flex-shrink-0" style="min-width: 220px;">
                        <?php if ($has_photo): ?>
                            <div class="avatar-circle avatar-circle-photo">
                                <img src="../<?= $e($homeowner['resident_photo']) ?>" alt="<?= $e($homeowner['full_name']) ?>">
                            </div>
                        <?php else: ?>
                            <div class="avatar-circle">
                                <?= strtoupper(substr($homeowner['full_name'] ?? 'H', 0, 1)) ?>
                            </div>
                        <?php endif; ?>

                        <h4 class="fw-bold mb-1"><?= $e($homeowner['full_name'] ?? '') ?></h4>
                        <p class="mb-2">
                            <?php if ($status === 'active'): ?>
                                <span class="status-pill status-active"><i class="bi bi-check-circle"></i> Active</span>
                            <?php else: ?>
                                <span class="status-pill" style="background-color: var(--warning-soft); color: var(--warning-color);">
                                    <i class="bi bi-clock-history"></i> <?= ucfirst($e($status)) ?>
                                </span>
                            <?php endif; ?>
                        </p>
                        <p class="mb-0 text-muted small">
                            <i class="bi bi-house me-1"></i><?= $e(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? '')) ?>
                        </p>
                    </div>

                    <div class="d-none d-md-block" style="width: 1px; background-color: var(--border-color-soft); align-self: stretch;"></div>

                    <!-- Right: Info grid -->
                    <div class="flex-grow-1 w-100">
                        <div class="row row-cols-1 row-cols-md-2 g-3 g-md-4">
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-envelope"></i> Email</span>
                                    <span class="detail-value">
                                        <?php if (!empty($homeowner['email'])): ?>
                                            <a href="mailto:<?= $e($homeowner['email']) ?>" class="text-decoration-none" style="color: inherit;"><?= $e($homeowner['email']) ?></a>
                                            <button type="button" class="btn btn-sm p-0 ms-1 text-muted" title="Copy" onclick="copyText('<?= $e(addslashes($homeowner['email'])) ?>', this)"><i class="bi bi-clipboard"></i></button>
                                        <?php else: ?>—<?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-telephone"></i> Contact No.</span>
                                    <span class="detail-value">
                                        <?php if (!empty($homeowner['contact'])): ?>
                                            <a href="tel:<?= $e($homeowner['contact']) ?>" class="text-decoration-none" style="color: inherit;"><?= $e($homeowner['contact']) ?></a>
                                            <button type="button" class="btn btn-sm p-0 ms-1 text-muted" title="Copy" onclick="copyText('<?= $e(addslashes($homeowner['contact'])) ?>', this)"><i class="bi bi-clipboard"></i></button>
                                        <?php else: ?>—<?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-geo-alt"></i> Address</span>
                                    <span class="detail-value"><?= $e($homeowner['address'] ?: '—') ?></span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-cash-stack"></i> Monthly Due</span>
                                    <span class="detail-value">₱ <?= number_format($homeowner['monthly_due'] ?? 0, 2) ?></span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-person-badge"></i> Ownership Type</span>
                                    <span class="detail-value"><?= $hasExtras ? $e($ownership) : '—' ?></span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-calendar"></i> Member Since</span>
                                    <span class="detail-value"><?= $e($member_since) ?></span>
                                </div>
                            </div>
                            <div class="col">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-heart-pulse"></i> Emergency Contact</span>
                                    <span class="detail-value">
                                        <?php if ($ecName || $ecNum): ?>
                                            <?= $e($ecName ?: '—') ?>
                                            <?php if ($ecNum): ?> &middot; <a href="tel:<?= $e($ecNum) ?>" class="text-decoration-none" style="color: inherit;"><?= $e($ecNum) ?></a><?php endif; ?>
                                        <?php else: ?>—<?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="detail-field">
                                    <span class="detail-label"><i class="bi bi-people"></i> Household Members</span>
                                    <span class="detail-value">
                                        <?php if ($members): ?>
                                            <?php foreach ($members as $m): ?><span class="badge badge-success me-1 mb-1"><?= $e($m) ?></span><?php endforeach; ?>
                                        <?php else: ?>—<?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Account actions -->
                        <div class="d-flex flex-wrap gap-2 mt-4 pt-3" style="border-top: 1px solid var(--border-color-soft);">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#detailsModal">
                                <i class="bi bi-pencil me-1"></i> Edit Additional Details
                            </button>

                            <?php if (!empty($homeowner['user_id'])): ?>
                            <form method="POST" action="homeowner_action.php" class="d-inline"
                                  onsubmit="return confirm('Reset this resident\'s password? A temporary password will be generated and shown once.');">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="homeowner_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-key me-1"></i> Reset Password</button>
                            </form>
                            <?php endif; ?>

                            <?php if ($status === 'active'): ?>
                            <form method="POST" action="homeowner_action.php" class="d-inline"
                                  onsubmit="return confirm('Deactivate this homeowner? They will no longer be billed or counted in collection reports.');">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="homeowner_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="new_status" value="inactive">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-person-x me-1"></i> Deactivate</button>
                            </form>
                            <?php elseif ($status === 'inactive'): ?>
                            <form method="POST" action="homeowner_action.php" class="d-inline"
                                  onsubmit="return confirm('Reactivate this homeowner?');">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="homeowner_id" value="<?= $id ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="new_status" value="active">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-check me-1"></i> Reactivate</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ================= PAYMENT STANDING ================= -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card homeowner-summary-card p-4 h-100">
                    <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                    <div>
                        <div class="stat-value" style="color: <?= $sum['outstanding'] > 0 ? 'var(--danger-color)' : 'var(--success-color)' ?>;">₱ <?= number_format($sum['outstanding'], 2) ?></div>
                        <div class="stat-label">Amount still unpaid</div>
                        <div class="stat-help-text"><?= $e($unpaidDueText) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card homeowner-summary-card p-4 h-100">
                    <div class="stat-icon"><i class="bi bi-calendar2-week"></i></div>
                    <div>
                        <div class="stat-value" style="color: <?= $streakColor ?>;"><?= (int) $sum['streak'] ?> month<?= (int) $sum['streak'] === 1 ? '' : 's' ?></div>
                        <div class="stat-label">Unpaid months in a row</div>
                        <div class="stat-help-text">Counts missed monthly dues consecutively</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card homeowner-summary-card p-4 h-100">
                    <div class="stat-icon"><i class="bi bi-calendar2-check"></i></div>
                    <div>
                        <div class="stat-value"><?= $e($lastPayLabel) ?></div>
                        <div class="stat-label">Most recent payment</div>
                        <div class="stat-help-text"><?= $hasLastPayDate ? 'Date recorded in the system' : 'No payment has been recorded for this account' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card stat-card homeowner-summary-card p-4 h-100">
                    <div class="stat-icon"><i class="bi bi-speedometer2"></i></div>
                    <div>
                        <div class="stat-value" style="color: <?= $scoreColor ?>;"><?= $sum['score'] ?>%</div>
                        <div class="stat-label">Monthly dues paid</div>
                        <div class="stat-help-text"><?= $e($paidMonthsText) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= ACTIVITY HISTORY LINK ================= -->
        <div class="mb-4">
            <a href="homeowner_activity.php?id=<?= $id ?>" class="activity-history-link">
                <span class="activity-history-icon"><i class="bi bi-clock-history"></i></span>
                <span class="activity-history-copy">
                    <strong>Activity history</strong>
                    <small><?= $activityCount > 0 ? number_format($activityCount) . ' account event' . ($activityCount === 1 ? '' : 's') . ' · payments, reservations, and changes' : 'No account activity has been recorded yet' ?></small>
                </span>
                <span class="activity-history-cta">View history <i class="bi bi-arrow-right"></i></span>
            </a>
        </div>

    </div>
</div>

<!-- ================= EDIT ADDITIONAL DETAILS MODAL ================= -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="homeowner_action.php">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="homeowner_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="save_details">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title" id="detailsModalLabel">Additional Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if (!$hasExtras): ?>
                    <div class="alert alert-warning border-0 small">Run <code>sql/homeowner_profile_upgrade.sql</code> first, then reload this page.</div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Ownership type</label>
                    <select name="ownership_type" class="form-select">
                        <?php foreach (['Owner', 'Tenant', 'Caretaker'] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $ownership === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label">Emergency contact name</label>
                        <input type="text" name="emergency_contact_name" class="form-control" maxlength="100" value="<?= $e($ecName) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Number</label>
                        <input type="text" name="emergency_contact_number" class="form-control" maxlength="30" value="<?= $e($ecNum) ?>">
                    </div>
                </div>
                <div>
                    <label class="form-label">Household members</label>
                    <textarea name="household_members" class="form-control" rows="4"
                              placeholder="One per line, e.g.&#10;Maria Ibaya – Spouse&#10;Juan Ibaya – Son"><?= $e($homeowner['household_members'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" <?= $hasExtras ? '' : 'disabled' ?>>Save</button>
            </div>
        </form>
    </div>
</div>

<div id="reminderToast" class="position-fixed top-0 end-0 p-3" style="z-index: 2000; display: none;">
    <div id="reminderToastBox" class="alert border-0 shadow mb-0"></div>
</div>

<script>
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(function () {
        const icon = btn.querySelector('i');
        const old = icon.className;
        icon.className = 'bi bi-check2';
        setTimeout(function () { icon.className = old; }, 1200);
    });
}

function sendReminder(btn) {
    if (!confirm('Send a payment reminder email to this homeowner?')) return;

    const icon = btn.querySelector('i');
    const oldClass = icon.className;
    btn.disabled = true;
    icon.className = 'spinner-border spinner-border-sm';

    const formData = new FormData();
    formData.append('homeowner_id', <?= json_encode($id) ?>);
    formData.append('csrf', <?= json_encode($csrf) ?>);

    fetch('send_reminder.php', { method: 'POST', body: formData })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            const wrap = document.getElementById('reminderToast');
            const box = document.getElementById('reminderToastBox');
            box.className = 'alert border-0 shadow mb-0 ' + (data.status === 'success' ? 'alert-success' : 'alert-danger');
            box.textContent = data.message || 'The reminder request could not be completed.';
            wrap.style.display = 'block';
            setTimeout(function () { wrap.style.display = 'none'; }, 5000);
        })
        .catch(function () {
            const wrap = document.getElementById('reminderToast');
            const box = document.getElementById('reminderToastBox');
            box.className = 'alert border-0 shadow mb-0 alert-danger';
            box.textContent = 'Something went wrong. Please try again.';
            wrap.style.display = 'block';
            setTimeout(function () { wrap.style.display = 'none'; }, 5000);
        })
        .finally(function () {
            btn.disabled = false;
            icon.className = oldClass;
        });
}
</script>

<?php require_once '../includes/receipt_modal.php'; ?>
<?php require_once '../includes/footer.php'; ?>
