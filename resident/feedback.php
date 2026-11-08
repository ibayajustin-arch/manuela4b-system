<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/feedback.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/incident_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ../login.php');
    exit;
}

function feedback_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$userId = (int) $_SESSION['user_id'];
$archiveView = ($_GET['view'] ?? '') === 'archive';
$feedbackReady = false;
$incidentReady = false;
$formError = null;
$incidentCategories = incident_categories();

try {
    ensure_resident_feedback_table($conn);
    ensure_archive_columns($conn, 'resident_feedback');
    $feedbackReady = true;
} catch (Throwable $e) {
    error_log('Resident feedback setup failed: ' . $e->getMessage());
}

if ($feedbackReady) {
    try {
        ensure_incident_support($conn);
        $incidentReady = true;
    } catch (Throwable $e) {
        error_log('Incident support setup failed: ' . $e->getMessage());
    }
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

// Upload bigger than post_max_size: PHP drops $_POST and $_FILES entirely
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $formError = 'The files you attached are too large. Please use smaller photos (max 5 MB each).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!$feedbackReady) {
        $formError = 'Feedback is temporarily unavailable. Please try again later.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $formError = 'Your session expired. Please refresh the page and try again.';
    } else {
        $category = trim($_POST['category'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        $incidentCategory = null;
        $incidentDate = null;
        $incidentLocation = null;
        $urgency = null;
        $validPhotos = [];
        $refNo = null;

        if (!in_array($category, ['suggestion', 'concern', 'incident'], true)) {
            $formError = 'Choose whether this is a suggestion, a concern, or an incident report.';
        } elseif ($category === 'incident' && !$incidentReady) {
            $formError = 'Incident reports are temporarily unavailable. Please try again later.';
        } elseif ($subject === '' || feedback_strlen($subject) > 150) {
            $formError = 'Enter a subject of no more than 150 characters.';
        } elseif ($message === '' || feedback_strlen($message) > 5000) {
            $formError = 'Enter a message of no more than 5,000 characters.';
        } elseif ($category === 'incident') {
            $incidentCategory = trim($_POST['incident_category'] ?? '');
            $incidentLocation = trim($_POST['incident_location'] ?? '');
            $urgency = trim($_POST['urgency'] ?? 'low');
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', trim($_POST['incident_date'] ?? ''));

            if (!isset($incidentCategories[$incidentCategory])) {
                $formError = 'Choose an incident category.';
            } elseif (!$dt) {
                $formError = 'Enter the date and time of the incident.';
            } elseif ($dt > new DateTime()) {
                $formError = 'The incident date and time cannot be in the future.';
            } elseif ($incidentLocation === '' || feedback_strlen($incidentLocation) > 150) {
                $formError = 'Enter the incident location (max 150 characters).';
            } elseif (!in_array($urgency, ['low', 'medium', 'high'], true)) {
                $formError = 'Choose an urgency level.';
            } else {
                $incidentDate = $dt->format('Y-m-d H:i:s');
                $photoCheck = validate_incident_photos($_FILES['photos'] ?? null);
                if ($photoCheck['error']) {
                    $formError = $photoCheck['error'];
                } else {
                    $validPhotos = $photoCheck['files'];
                }
            }
        }

        if (!$formError) {
            $storedPhotos = [];
            try {
                $conn->begin_transaction();

                if ($incidentReady) {
                    $stmt = $conn->prepare('INSERT INTO resident_feedback (user_id, category, subject, message, incident_category, incident_date, incident_location, urgency) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('isssssss', $userId, $category, $subject, $message, $incidentCategory, $incidentDate, $incidentLocation, $urgency);
                } else {
                    $stmt = $conn->prepare('INSERT INTO resident_feedback (user_id, category, subject, message) VALUES (?, ?, ?, ?)');
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('isss', $userId, $category, $subject, $message);
                }
                if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                $newId = (int) $stmt->insert_id;
                $stmt->close();

                if ($category === 'incident') {
                    $refNo = 'IR-' . date('Y') . '-' . str_pad((string) $newId, 4, '0', STR_PAD_LEFT);
                    $stmt = $conn->prepare('UPDATE resident_feedback SET reference_no = ? WHERE id = ?');
                    if (!$stmt) throw new RuntimeException($conn->error);
                    $stmt->bind_param('si', $refNo, $newId);
                    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
                    $stmt->close();

                    if ($validPhotos) {
                        $storedPhotos = save_incident_photos($conn, $newId, $validPhotos);
                    }
                }
                $conn->commit();

                $_SESSION['success_msg'] = $category === 'incident'
                    ? 'Your incident report (' . $refNo . ') was sent to the management team.'
                    : 'Your feedback was sent to the management team.';
                header('Location: feedback.php');
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                foreach ($storedPhotos as $fileName) {
                    @unlink(incident_upload_dir() . '/' . $fileName);
                }
                error_log('Resident feedback submission failed: ' . $e->getMessage());
                $formError = 'We could not send your feedback. Please try again.';
            }
        }
    }
}

$successMsg = $_SESSION['success_msg'] ?? null;
unset($_SESSION['success_msg']);
$feedbackItems = [];
$photosByFeedback = [];
if ($feedbackReady) {
    try {
        $archiveCondition = $archiveView ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
        $incidentCols = $incidentReady
            ? 'reference_no, incident_category, incident_date, incident_location, urgency'
            : 'NULL AS reference_no, NULL AS incident_category, NULL AS incident_date, NULL AS incident_location, NULL AS urgency';
        $stmt = $conn->prepare("SELECT id, category, subject, message, status, created_at, admin_reply, admin_replied_at, {$incidentCols} FROM resident_feedback WHERE user_id = ? AND {$archiveCondition} ORDER BY created_at DESC, id DESC");
        if (!$stmt) throw new RuntimeException($conn->error);
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $result = $stmt->get_result();
        if ($result) $feedbackItems = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($incidentReady) {
            $photosByFeedback = get_feedback_photos($conn, array_column($feedbackItems, 'id'));
        }
    } catch (Throwable $e) {
        error_log('Resident feedback history failed: ' . $e->getMessage());
        $formError = 'Your feedback history could not be loaded.';
    }
}

$selectedCategory = $_POST['category'] ?? 'suggestion';
$csrfToken = generateCsrfToken();
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>Suggestions &amp; Concerns</h1>
                <p class="mb-0">Send feedback or report an incident directly to the management team.</p>
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
                        <form method="POST" enctype="multipart/form-data" id="feedbackForm">
                            <input type="hidden" name="action" value="submit">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="mb-3">
                                <label for="feedbackCategory" class="form-label">Message type</label>
                                <select id="feedbackCategory" name="category" class="form-select" required <?= !$feedbackReady ? 'disabled' : '' ?>>
                                    <option value="suggestion" <?= $selectedCategory === 'suggestion' ? 'selected' : '' ?>>Suggestion</option>
                                    <option value="concern" <?= $selectedCategory === 'concern' ? 'selected' : '' ?>>Concern</option>
                                    <?php if ($incidentReady): ?>
                                        <option value="incident" <?= $selectedCategory === 'incident' ? 'selected' : '' ?>>Incident Report</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="feedbackSubject" class="form-label">Subject</label>
                                <input id="feedbackSubject" name="subject" class="form-control" maxlength="150" required value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" <?= !$feedbackReady ? 'disabled' : '' ?>>
                            </div>

                            <!-- Incident-only fields -->
                            <div id="incidentFields" class="d-none">
                                <div class="mb-3">
                                    <label for="incidentCategory" class="form-label">Incident category</label>
                                    <select id="incidentCategory" name="incident_category" class="form-select" <?= !$feedbackReady ? 'disabled' : '' ?>>
                                        <option value="">Select category</option>
                                        <?php foreach ($incidentCategories as $key => $label): ?>
                                            <option value="<?= htmlspecialchars($key) ?>" <?= ($_POST['incident_category'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-sm-7">
                                        <label for="incidentDate" class="form-label">Date &amp; time of incident</label>
                                        <input type="datetime-local" id="incidentDate" name="incident_date" class="form-control" max="<?= date('Y-m-d\TH:i') ?>" value="<?= htmlspecialchars($_POST['incident_date'] ?? '') ?>" <?= !$feedbackReady ? 'disabled' : '' ?>>
                                    </div>
                                    <div class="col-sm-5">
                                        <label for="incidentUrgency" class="form-label">Urgency</label>
                                        <select id="incidentUrgency" name="urgency" class="form-select" <?= !$feedbackReady ? 'disabled' : '' ?>>
                                            <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $k => $l): ?>
                                                <option value="<?= $k ?>" <?= ($_POST['urgency'] ?? 'low') === $k ? 'selected' : '' ?>><?= $l ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="incidentLocation" class="form-label">Location</label>
                                    <input id="incidentLocation" name="incident_location" class="form-control" maxlength="150" placeholder="e.g. Block 3 Lot 5, near the main gate" value="<?= htmlspecialchars($_POST['incident_location'] ?? '') ?>" <?= !$feedbackReady ? 'disabled' : '' ?>>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="feedbackMessage" class="form-label" id="feedbackMessageLabel">Message</label>
                                <textarea id="feedbackMessage" name="message" class="form-control" rows="6" maxlength="5000" required <?= !$feedbackReady ? 'disabled' : '' ?>><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                            </div>

                            <div id="incidentPhotos" class="mb-3 d-none">
                                <label for="incidentPhotoInput" class="form-label">Photos <span class="text-muted fw-normal">(optional, up to <?= INCIDENT_MAX_PHOTOS ?>, JPG/PNG/WEBP, 5 MB each)</span></label>
                                <input type="file" id="incidentPhotoInput" name="photos[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple <?= !$feedbackReady ? 'disabled' : '' ?>>
                                <div id="photoError" class="text-danger small mt-1"></div>
                                <div id="photoPreview" class="photo-preview mt-2"></div>
                            </div>

                            <button type="submit" class="btn btn-primary" id="feedbackSubmitBtn" <?= !$feedbackReady ? 'disabled' : '' ?>>
                                <i class="bi bi-send me-2"></i><span>Send to management</span>
                            </button>
                        </form>
                    </div>
                </section>
            </div>
            <?php endif; ?>

            <div class="<?= $archiveView ? 'col-12' : 'col-lg-7' ?>">
                <section class="card h-100 resident-messages">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                            <h2 class="h5 mb-0"><?= $archiveView ? 'Archived messages' : 'Your messages' ?></h2>
                            <a href="feedback.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-<?= $archiveView ? 'chat-left-text' : 'archive' ?> me-1"></i><?= $archiveView ? 'Back to messages' : 'Archives' ?>
                            </a>
                        </div>
                        <?php if (empty($feedbackItems)): ?>
                            <p class="text-muted mb-0"><?= $archiveView ? 'You have no archived messages.' : 'You have not sent any suggestions, concerns, or incident reports yet.' ?></p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead><tr><th>Subject</th><th>Sent</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($feedbackItems as $item):
                                            $isIncident = $item['category'] === 'incident';
                                            $badgeClass = $isIncident ? 'text-bg-danger' : ($item['category'] === 'concern' ? 'text-bg-warning' : 'text-bg-info');
                                            $categoryLabel = $isIncident ? 'Incident' : ucfirst($item['category']);
                                            $photos = $photosByFeedback[(int) $item['id']] ?? [];
                                        ?>
                                            <tr>
                                                <td class="fbm-subject">
                                                    <span class="badge <?= $badgeClass ?> mb-1"><?= htmlspecialchars($categoryLabel) ?></span>
                                                    <?php if ($isIncident && !empty($item['reference_no'])): ?>
                                                        <span class="small text-muted ms-1 text-nowrap"><?= htmlspecialchars($item['reference_no']) ?></span>
                                                    <?php endif; ?>
                                                    <div class="fw-semibold fbm-title"><?= htmlspecialchars($item['subject']) ?></div>
                                                </td>
                                                <td class="text-nowrap fbm-sent"><?= date('M j, Y g:i A', strtotime($item['created_at'])) ?></td>
                                                <td class="text-nowrap fbm-status">
                                                    <?php if ($item['status'] === 'resolved'): ?>
                                                        <span class="badge text-bg-success">Resolved</span>
                                                    <?php elseif ($item['status'] === 'in_review'): ?>
                                                        <span class="badge text-bg-primary">In review</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-secondary">New</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center fbm-actions">
                                                    <div class="d-inline-flex align-items-center gap-1">
                                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                            data-category="<?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-subject="<?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-message="<?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-sent="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-status="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $item['status'])), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-reply="<?= htmlspecialchars($item['admin_reply'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-replied-at="<?= htmlspecialchars(!empty($item['admin_replied_at']) ? date('M j, Y g:i A', strtotime($item['admin_replied_at'])) : '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-incident="<?= $isIncident ? '1' : '0' ?>"
                                                            data-ref="<?= htmlspecialchars($item['reference_no'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-incident-category="<?= htmlspecialchars($incidentCategories[$item['incident_category'] ?? ''] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-incident-date="<?= htmlspecialchars(!empty($item['incident_date']) ? date('M j, Y g:i A', strtotime($item['incident_date'])) : '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-location="<?= htmlspecialchars($item['incident_location'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-urgency="<?= htmlspecialchars(ucfirst($item['urgency'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-photos="<?= htmlspecialchars(json_encode(array_column($photos, 'file_name')), ENT_QUOTES, 'UTF-8') ?>"
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
                <section id="residentIncidentInfo" class="mb-4 d-none">
                    <h3 class="h6">Incident details</h3>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4">Reference no.</dt><dd class="col-sm-8" id="incRef"></dd>
                        <dt class="col-sm-4">Category</dt><dd class="col-sm-8" id="incCategory"></dd>
                        <dt class="col-sm-4">Date &amp; time</dt><dd class="col-sm-8" id="incDate"></dd>
                        <dt class="col-sm-4">Location</dt><dd class="col-sm-8" id="incLocation"></dd>
                        <dt class="col-sm-4">Urgency</dt><dd class="col-sm-8" id="incUrgency"></dd>
                    </dl>
                </section>
                <section class="mb-4">
                    <h3 class="h6">Your message</h3>
                    <div id="residentFeedbackMessage" class="resident-feedback-detail-box"></div>
                </section>
                <section id="residentFeedbackPhotos" class="mb-4 d-none">
                    <h3 class="h6">Attached photos</h3>
                    <div id="residentFeedbackPhotoGrid" class="photo-preview"></div>
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

    .photo-preview {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: .5rem;
    }

    .photo-preview a,
    .photo-preview img {
        display: block;
        width: 100%;
    }

    .photo-preview img {
        aspect-ratio: 1 / 1;
        object-fit: cover;
        border: 1px solid var(--border-color);
        border-radius: 6px;
    }

    /* "Your messages" as stacked cards on phones (no sideways scrolling) */
    @media (max-width: 575.98px) {
        .resident-messages .table-responsive { overflow: visible; }
        .resident-messages table,
        .resident-messages tbody { display: block; width: 100%; }
        .resident-messages thead { display: none; }
        .resident-messages tbody tr {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: .4rem .75rem;
            padding: .85rem 1rem;
            margin-bottom: .65rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--surface-color, #fff);
        }
        .resident-messages tbody tr:last-child { margin-bottom: 0; }
        .resident-messages tbody td {
            display: block;
            padding: 0 !important;
            border: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }
        .resident-messages .fbm-subject { grid-column: 1 / -1; }
        .resident-messages .fbm-title { font-size: 1rem; line-height: 1.3; margin-top: .15rem; overflow-wrap: anywhere; }
        .resident-messages .fbm-sent { font-size: .82rem; color: var(--text-muted); white-space: normal; }
        .resident-messages .fbm-status { justify-self: end; }
        .resident-messages .fbm-actions { grid-column: 1 / -1; text-align: left !important; margin-top: .25rem; }
        .resident-messages .fbm-actions > div { display: flex !important; width: 100%; }
        .resident-messages .fbm-actions .btn-outline-primary { flex: 1 1 auto; }
    }
</style>

<script>
const PHOTO_UPLOAD_URL = '../uploads/incidents/';
const MAX_PHOTOS = <?= (int) INCIDENT_MAX_PHOTOS ?>;
const MAX_BYTES = <?= (int) INCIDENT_MAX_BYTES ?>;

// ---- Show / hide incident fields ----
(function () {
    const category = document.getElementById('feedbackCategory');
    if (!category) return;

    const incidentFields = document.getElementById('incidentFields');
    const photoWrap = document.getElementById('incidentPhotos');
    const requiredIds = ['incidentCategory', 'incidentDate', 'incidentLocation'];
    const msgLabel = document.getElementById('feedbackMessageLabel');
    const btnText = document.querySelector('#feedbackSubmitBtn span');

    function sync() {
        const isIncident = category.value === 'incident';
        incidentFields.classList.toggle('d-none', !isIncident);
        photoWrap.classList.toggle('d-none', !isIncident);
        requiredIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.required = isIncident;
        });
        msgLabel.textContent = isIncident ? 'What happened?' : 'Message';
        btnText.textContent = isIncident ? 'Submit incident report' : 'Send to management';
    }
    category.addEventListener('change', sync);
    sync();
})();

// ---- Photo picker with preview and client-side checks ----
(function () {
    const input = document.getElementById('incidentPhotoInput');
    if (!input) return;
    const preview = document.getElementById('photoPreview');
    const errorBox = document.getElementById('photoError');

    input.addEventListener('change', function () {
        preview.innerHTML = '';
        errorBox.textContent = '';
        const files = Array.from(input.files);

        if (files.length > MAX_PHOTOS) {
            errorBox.textContent = 'You can attach up to ' + MAX_PHOTOS + ' photos only.';
            input.value = '';
            return;
        }
        for (const f of files) {
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(f.type)) {
                errorBox.textContent = 'Only JPG, PNG, or WEBP images are allowed.';
                input.value = '';
                preview.innerHTML = '';
                return;
            }
            if (f.size > MAX_BYTES) {
                errorBox.textContent = '"' + f.name + '" is larger than 5 MB.';
                input.value = '';
                preview.innerHTML = '';
                return;
            }
            const img = document.createElement('img');
            img.alt = f.name;
            img.src = URL.createObjectURL(f);
            img.onload = () => URL.revokeObjectURL(img.src);
            preview.appendChild(img);
        }
    });
})();

// ---- Details modal ----
function openResidentFeedbackDetails(button) {
    const d = button.dataset;
    document.getElementById('residentFeedbackDetailTitle').textContent = d.subject;
    document.getElementById('residentFeedbackDetailMeta').textContent = d.category + ' · ' + d.sent + ' · ' + d.status;
    document.getElementById('residentFeedbackMessage').textContent = d.message;

    const isIncident = d.incident === '1';
    document.getElementById('residentIncidentInfo').classList.toggle('d-none', !isIncident);
    if (isIncident) {
        document.getElementById('incRef').textContent = d.ref || '-';
        document.getElementById('incCategory').textContent = d.incidentCategory || '-';
        document.getElementById('incDate').textContent = d.incidentDate || '-';
        document.getElementById('incLocation').textContent = d.location || '-';
        document.getElementById('incUrgency').textContent = d.urgency || '-';
    }

    const photos = JSON.parse(d.photos || '[]');
    const grid = document.getElementById('residentFeedbackPhotoGrid');
    grid.innerHTML = '';
    photos.forEach(name => {
        const a = document.createElement('a');
        a.href = PHOTO_UPLOAD_URL + encodeURIComponent(name);
        a.target = '_blank';
        a.rel = 'noopener';
        const img = document.createElement('img');
        img.src = a.href;
        img.alt = 'Incident photo';
        a.appendChild(img);
        grid.appendChild(a);
    });
    document.getElementById('residentFeedbackPhotos').classList.toggle('d-none', photos.length === 0);

    const hasReply = d.reply !== '';
    document.getElementById('residentFeedbackReplySection').classList.toggle('d-none', !hasReply);
    document.getElementById('residentFeedbackAwaitingReply').classList.toggle('d-none', hasReply);
    document.getElementById('residentFeedbackReply').textContent = d.reply;
    document.getElementById('residentFeedbackReplyDate').textContent = d.repliedAt;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('residentFeedbackDetailModal')).show();
}
</script>
<?php require_once '../includes/footer.php'; ?>