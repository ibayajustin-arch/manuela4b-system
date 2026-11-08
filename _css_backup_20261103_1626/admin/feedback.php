<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/feedback.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$feedbackReady = false;
$formError = null;
$archiveView = ($_GET['view'] ?? '') === 'archive';
$streets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
$selectedStreet = trim((string) ($_GET['street'] ?? ''));
if (!in_array(strtolower($selectedStreet), array_map('strtolower', $streets), true)) $selectedStreet = '';
try {
    ensure_resident_feedback_table($conn);
    ensure_archive_columns($conn, 'resident_feedback');
    $feedbackReady = true;
} catch (Throwable $e) {
    error_log('Admin feedback setup failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_feedback', 'restore_feedback'], true)) {
    $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please refresh the page and try again.';
    } elseif ($feedbackId > 0 && $feedbackReady) {
        $isArchive = $_POST['action'] === 'archive_feedback';
        if ($isArchive) {
            $stmt = $conn->prepare("UPDATE resident_feedback f LEFT JOIN users u ON u.id=f.user_id SET f.resident_name=COALESCE(u.full_name,f.resident_name), f.archived_at=NOW() WHERE f.id=? AND f.archived_at IS NULL");
        } else {
            $stmt = $conn->prepare('UPDATE resident_feedback SET archived_at=NULL WHERE id=? AND archived_at IS NOT NULL');
        }
        $stmt->bind_param('i', $feedbackId);
        $stmt->execute();
        $stmt->close();
        $_SESSION['success_msg'] = $isArchive ? 'Feedback archived.' : 'Feedback restored.';
        header('Location: feedback.php' . ($isArchive ? '' : '?view=archive'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_feedback' && !$archiveView) {
    $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $adminReply = trim($_POST['admin_reply'] ?? '');
    $replyLength = function_exists('mb_strlen') ? mb_strlen($adminReply, 'UTF-8') : strlen($adminReply);

    if (!$feedbackReady) {
        $formError = 'Feedback is temporarily unavailable.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please refresh the page and try again.';
    } elseif ($feedbackId <= 0 || !in_array($status, ['new', 'in_review', 'resolved'], true) || $replyLength > 5000) {
        $formError = 'Check the selected status and keep replies under 5,000 characters.';
    } else {
        try {
            $stmt = $conn->prepare('UPDATE resident_feedback SET status = ?, admin_reply = ?, admin_replied_at = IF(? <> \'\', CURRENT_TIMESTAMP, NULL) WHERE id = ? AND archived_at IS NULL');
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('sssi', $status, $adminReply, $adminReply, $feedbackId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $stmt->close();
            $_SESSION['success_msg'] = $adminReply === '' ? 'Feedback status updated.' : 'Reply sent and feedback status updated.';
            header('Location: feedback.php');
            exit;
        } catch (Throwable $e) {
            error_log('Admin feedback status update failed: ' . $e->getMessage());
            $formError = 'The feedback status could not be updated.';
        }
    }
}

$successMsg = $_SESSION['success_msg'] ?? null;
unset($_SESSION['success_msg']);
$feedbackItems = [];
if ($feedbackReady) {
    try {
        $feedbackArchiveFilter = $archiveView ? 'f.archived_at IS NOT NULL' : 'f.archived_at IS NULL';
        $streetFilter = $selectedStreet !== '' ? ' AND LOWER(h.block) = LOWER(?)' : '';
        $stmt = $conn->prepare("SELECT f.*, COALESCE(f.resident_name, u.full_name, 'Former account') AS resident_name, h.block AS resident_street FROM resident_feedback f LEFT JOIN users u ON u.id = f.user_id LEFT JOIN homeowners h ON h.user_id = f.user_id WHERE {$feedbackArchiveFilter}{$streetFilter} ORDER BY FIELD(f.status, 'new', 'in_review', 'resolved'), f.created_at DESC, f.id DESC");
        if ($selectedStreet !== '') $stmt->bind_param('s', $selectedStreet);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) $feedbackItems = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Admin feedback list failed: ' . $e->getMessage());
        $formError = 'Feedback submissions could not be loaded.';
    }
}

$csrfToken = generateCsrfToken();
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="d-flex flex-wrap justify-content-end gap-2 mb-4">
            <a href="feedback.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary"><i class="bi bi-archive me-1"></i><?= $archiveView ? 'Back to Feedback' : 'Archives' ?></a>
        </div>

        <?php if (!$feedbackReady): ?>
            <div class="alert alert-danger">Feedback storage is unavailable. Check the database connection and permissions.</div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($formError): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($formError) ?></div>
        <?php endif; ?>

        <section class="card">
            <div class="card-body border-bottom">
                <form method="GET" class="row g-2 align-items-end">
                    <?php if ($archiveView): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
                    <div class="col-sm-5 col-md-3">
                        <label for="feedbackStreet" class="form-label">Filter by street</label>
                        <select id="feedbackStreet" name="street" class="form-select" onchange="this.form.submit()">
                            <option value="">All streets</option>
                            <?php foreach ($streets as $streetOption): ?>
                                <option value="<?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($selectedStreet, $streetOption) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($streetOption, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 admin-feedback-table">
                        <thead><tr><th>Resident</th><th>Type</th><th>Subject</th><th>Submitted</th><th>Status</th><th class="text-center">Details</th><th class="text-center">Archive</th></tr></thead>
                        <tbody>
                            <?php foreach ($feedbackItems as $item): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($item['resident_name'] ?? 'Former account') ?></td>
                                    <td><span class="badge <?= $item['category'] === 'concern' ? 'text-bg-warning' : 'text-bg-info' ?>"><?= ucfirst(htmlspecialchars($item['category'])) ?></span></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($item['subject']) ?></td>
                                    <td class="text-nowrap"><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($item['status'] === 'resolved'): ?>
                                            <span class="badge text-bg-success">Resolved</span>
                                        <?php elseif ($item['status'] === 'in_review'): ?>
                                            <span class="badge text-bg-primary">In review</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">New</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-feedback-id="<?= (int) $item['id'] ?>"
                                            data-resident="<?= htmlspecialchars($item['resident_name'] ?? 'Former account', ENT_QUOTES, 'UTF-8') ?>"
                                            data-category="<?= htmlspecialchars(ucfirst($item['category']), ENT_QUOTES, 'UTF-8') ?>"
                                            data-subject="<?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-message="<?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-created="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-reply="<?= htmlspecialchars($item['admin_reply'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="openFeedbackModal(this)">
                                            <i class="bi bi-chat-square-text me-1"></i><?= !empty($item['admin_reply']) ? 'View / Edit Reply' : 'View / Reply' ?>
                                        </button>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?= $archiveView ? 'Restore' : 'Archive' ?> this feedback?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="feedback_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="<?= $archiveView ? 'restore_feedback' : 'archive_feedback' ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $archiveView ? 'Restore' : 'Archive' ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($feedbackItems)): ?>
                                <tr><td colspan="7" class="table-empty-state"><?= $archiveView ? 'No archived resident feedback.' : 'No resident feedback has been submitted.' ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="modal fade" id="feedbackDetailModal" tabindex="-1" aria-labelledby="feedbackDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_feedback">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="feedback_id" id="feedbackId">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5 mb-1" id="feedbackDetailTitle"></h2>
                        <div class="small text-muted" id="feedbackDetailMeta"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="form-label">Resident message</div>
                        <div id="feedbackDetailMessage" class="feedback-message-box"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label for="feedbackStatus" class="form-label">Status</label>
                            <select id="feedbackStatus" name="status" class="form-select">
                                <option value="new">New</option>
                                <option value="in_review">In review</option>
                                <option value="resolved">Resolved</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="feedbackReply" class="form-label">Reply to resident</label>
                            <textarea id="feedbackReply" name="admin_reply" class="form-control" rows="5" maxlength="5000" placeholder="Write a reply..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-2"></i>Save and send reply</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    #feedbackDetailModal .modal-content > form {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

    #feedbackDetailModal .modal-header,
    #feedbackDetailModal .modal-footer {
        flex-shrink: 0;
    }

    #feedbackDetailModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
    }

    .feedback-message-box {
        max-height: 35vh;
        overflow-y: auto;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: var(--surface-color-alt);
        color: var(--text-main);
        padding: 1rem;
    }

    #feedbackDetailModal .form-control,
    #feedbackDetailModal .form-select {
        color: var(--text-main);
    }

    #feedbackDetailModal .form-control::placeholder {
        color: var(--text-muted);
        opacity: 1;
    }
</style>

<script>
function openFeedbackModal(button) {
    document.getElementById('feedbackId').value = button.dataset.feedbackId;
    document.getElementById('feedbackDetailTitle').textContent = button.dataset.subject;
    document.getElementById('feedbackDetailMeta').textContent = button.dataset.category + ' from ' + button.dataset.resident + ' · ' + button.dataset.created;
    document.getElementById('feedbackDetailMessage').textContent = button.dataset.message;
    document.getElementById('feedbackStatus').value = button.dataset.status;
    document.getElementById('feedbackReply').value = button.dataset.reply;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('feedbackDetailModal')).show();
}
</script>
<?php require_once '../includes/footer.php'; ?>
