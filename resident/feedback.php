<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/feedback.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$archiveView = ($_GET['view'] ?? '') === 'archive';
$feedbackReady = false;
$formError = null;

try {
    ensure_resident_feedback_table($conn);
    ensure_archive_columns($conn, 'resident_feedback');
    $feedbackReady = true;
} catch (Throwable $e) {
    error_log('Resident feedback setup failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_feedback', 'restore_feedback'], true)) {
    $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
    $action = $_POST['action'];

    if (!$feedbackReady) {
        $formError = 'Feedback is temporarily unavailable. Please try again later.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please refresh the page and try again.';
    } elseif ($feedbackId <= 0) {
        $formError = 'The selected message could not be found.';
    } else {
        try {
            $archiveValue = $action === 'archive_feedback' ? 'NOW()' : 'NULL';
            $archiveCondition = $action === 'archive_feedback' ? 'archived_at IS NULL' : 'archived_at IS NOT NULL';
            $stmt = $conn->prepare("UPDATE resident_feedback SET archived_at = {$archiveValue} WHERE id = ? AND user_id = ? AND {$archiveCondition}");
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('ii', $feedbackId, $userId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $updated = $stmt->affected_rows > 0;
            $stmt->close();

            if (!$updated) {
                $formError = 'Message not found or already updated.';
            } else {
                $_SESSION['success_msg'] = $action === 'archive_feedback'
                    ? 'Your message was archived. You can restore it from Archives.'
                    : 'Your message was restored.';
                header('Location: feedback.php' . ($action === 'restore_feedback' ? '?view=archive' : ''));
                exit;
            }
        } catch (Throwable $e) {
            error_log('Resident feedback archive action failed: ' . $e->getMessage());
            $formError = 'We could not update your message. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!$feedbackReady) {
        $formError = 'Feedback is temporarily unavailable. Please try again later.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please refresh the page and try again.';
    } else {
        $category = trim($_POST['category'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $subjectLength = function_exists('mb_strlen') ? mb_strlen($subject, 'UTF-8') : strlen($subject);
        $message = trim($_POST['message'] ?? '');
        $messageLength = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);

        if (!in_array($category, ['suggestion', 'concern'], true)) {
            $formError = 'Choose whether this is a suggestion or a concern.';
        } elseif ($subject === '' || $subjectLength > 150) {
            $formError = 'Enter a subject of no more than 150 characters.';
        } elseif ($message === '' || $messageLength > 5000) {
            $formError = 'Enter a message of no more than 5,000 characters.';
        } else {
            try {
                $stmt = $conn->prepare('INSERT INTO resident_feedback (user_id, category, subject, message) VALUES (?, ?, ?, ?)');
                if (!$stmt) throw new RuntimeException($conn->error);
                $stmt->bind_param('isss', $userId, $category, $subject, $message);
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $stmt->close();
                $_SESSION['success_msg'] = 'Your feedback was sent to the management team.';
                header('Location: feedback.php');
                exit;
            } catch (Throwable $e) {
                error_log('Resident feedback submission failed: ' . $e->getMessage());
                $formError = 'We could not send your feedback. Please try again.';
            }
        }
    }
}

$successMsg = $_SESSION['success_msg'] ?? null;
unset($_SESSION['success_msg']);
$feedbackItems = [];
if ($feedbackReady) {
    try {
        $archiveCondition = $archiveView ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        $stmt = $conn->prepare("SELECT id, category, subject, message, status, created_at, admin_reply, admin_replied_at FROM resident_feedback WHERE user_id = ? AND {$archiveCondition} ORDER BY created_at DESC, id DESC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) $feedbackItems = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Resident feedback history failed: ' . $e->getMessage());
        $formError = 'Your feedback history could not be loaded.';
    }
}

$csrfToken = generateCsrfToken();
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>Suggestions &amp; Concerns</h1>
                <p class="mb-0">Send feedback directly to the management team.</p>
            </div>
        </div>

        <?php if (!$feedbackReady): ?>
            <div class="alert alert-danger">Feedback is temporarily unavailable. Please contact the administrator.</div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($formError): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($formError) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <?php if (!$archiveView): ?>
            <div class="col-lg-5">
                <section class="card h-100">
                    <div class="card-body">
                        <h2 class="h5 mb-3">New message</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="submit">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="mb-3">
                                <label for="feedbackCategory" class="form-label">Message type</label>
                                <select id="feedbackCategory" name="category" class="form-select" required <?= !$feedbackReady ? 'disabled' : '' ?>>
                                    <option value="suggestion" <?= ($_POST['category'] ?? '') === 'suggestion' ? 'selected' : '' ?>>Suggestion</option>
                                    <option value="concern" <?= ($_POST['category'] ?? '') === 'concern' ? 'selected' : '' ?>>Concern</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="feedbackSubject" class="form-label">Subject</label>
                                <input id="feedbackSubject" name="subject" class="form-control" maxlength="150" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" <?= !$feedbackReady ? 'disabled' : '' ?>>
                            </div>
                            <div class="mb-3">
                                <label for="feedbackMessage" class="form-label">Message</label>
                                <textarea id="feedbackMessage" name="message" class="form-control" rows="6" maxlength="5000" required <?= !$feedbackReady ? 'disabled' : '' ?>><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary" <?= !$feedbackReady ? 'disabled' : '' ?>>
                                <i class="bi bi-send me-2"></i>Send to management
                            </button>
                        </form>
                    </div>
                </section>
            </div>
            <?php endif; ?>

            <div class="<?= $archiveView ? 'col-12' : 'col-lg-7' ?>">
                <section class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                            <h2 class="h5 mb-0"><?= $archiveView ? 'Archived messages' : 'Your messages' ?></h2>
                            <a href="feedback.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-<?= $archiveView ? 'chat-left-text' : 'archive' ?> me-1"></i><?= $archiveView ? 'Back to messages' : 'Archives' ?>
                            </a>
                        </div>
                        <?php if (empty($feedbackItems)): ?>
                            <p class="text-muted mb-0"><?= $archiveView ? 'You have no archived messages.' : 'You have not sent any suggestions or concerns yet.' ?></p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead><tr><th>Subject</th><th>Sent</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($feedbackItems as $item): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?= $item['category'] === 'concern' ? 'text-bg-warning' : 'text-bg-info' ?> mb-1"><?= ucfirst(htmlspecialchars($item['category'])) ?></span>
                                                    <div class="fw-semibold"><?= htmlspecialchars($item['subject']) ?></div>
                                                </td>
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
                                                    <div class="d-inline-flex align-items-center gap-1">
                                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                            data-category="<?= htmlspecialchars(ucfirst($item['category']), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-subject="<?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-message="<?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-sent="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-status="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $item['status'])), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-reply="<?= htmlspecialchars($item['admin_reply'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-replied-at="<?= htmlspecialchars(!empty($item['admin_replied_at']) ? date('M j, Y g:i A', strtotime($item['admin_replied_at'])) : '', ENT_QUOTES, 'UTF-8') ?>"
                                                            onclick="openResidentFeedbackDetails(this)">
                                                            <i class="bi bi-eye me-1"></i>View details
                                                        </button>
                                                        <?php if (!empty($item['admin_reply'])): ?>
                                                            <span class="badge text-bg-success">Replied</span>
                                                        <?php endif; ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?= $archiveView ? 'Restore' : 'Archive' ?> this message?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="feedback_id" value="<?= (int) $item['id'] ?>">
                                                            <input type="hidden" name="action" value="<?= $archiveView ? 'restore_feedback' : 'archive_feedback' ?>">
                                                            <button type="submit" class="btn btn-sm <?= $archiveView ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                                                aria-label="<?= $archiveView ? 'Restore' : 'Archive' ?> feedback message"
                                                                title="<?= $archiveView ? 'Restore message' : 'Archive message' ?>"
                                                                <?= !$feedbackReady ? 'disabled' : '' ?>>
                                                                <i class="bi bi-<?= $archiveView ? 'arrow-counterclockwise' : 'archive' ?>" aria-hidden="true"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="residentFeedbackDetailModal" tabindex="-1" aria-labelledby="residentFeedbackDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title h5 mb-1" id="residentFeedbackDetailTitle"></h2>
                    <div class="small text-muted" id="residentFeedbackDetailMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <section class="mb-4">
                    <h3 class="h6">Your message</h3>
                    <div id="residentFeedbackMessage" class="resident-feedback-detail-box"></div>
                </section>
                <section id="residentFeedbackReplySection" class="d-none">
                    <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
                        <h3 class="h6 mb-0">Management reply</h3>
                        <span id="residentFeedbackReplyDate" class="small text-muted"></span>
                    </div>
                    <div id="residentFeedbackReply" class="resident-feedback-detail-box resident-feedback-reply-box"></div>
                </section>
                <p id="residentFeedbackAwaitingReply" class="small text-muted mb-0">Management has not replied yet.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .resident-feedback-detail-box {
        max-height: 30vh;
        overflow-y: auto;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: var(--surface-color-alt);
        color: var(--text-main);
        padding: 1rem;
    }

    .resident-feedback-reply-box {
        border-color: var(--bs-primary-border-subtle);
    }
</style>

<script>
function openResidentFeedbackDetails(button) {
    document.getElementById('residentFeedbackDetailTitle').textContent = button.dataset.subject;
    document.getElementById('residentFeedbackDetailMeta').textContent = button.dataset.category + ' · ' + button.dataset.sent + ' · ' + button.dataset.status;
    document.getElementById('residentFeedbackMessage').textContent = button.dataset.message;

    const hasReply = button.dataset.reply !== '';
    document.getElementById('residentFeedbackReplySection').classList.toggle('d-none', !hasReply);
    document.getElementById('residentFeedbackAwaitingReply').classList.toggle('d-none', hasReply);
    document.getElementById('residentFeedbackReply').textContent = button.dataset.reply;
    document.getElementById('residentFeedbackReplyDate').textContent = button.dataset.repliedAt;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('residentFeedbackDetailModal')).show();
}
</script>
<?php require_once '../includes/footer.php'; ?>
