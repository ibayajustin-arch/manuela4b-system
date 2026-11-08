<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/feedback.php';
require_once '../includes/archive_helpers.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/incident_helpers.php';

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
$feedbackTypes = ['suggestion' => 'Suggestion', 'concern' => 'Concern', 'incident' => 'Incident'];
$selectedType = trim((string) ($_GET['type'] ?? ''));
if (!isset($feedbackTypes[$selectedType])) $selectedType = '';
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

// Live search for the search modal. Read-only JSON, same scope as the table below.
if (($_GET['ajax'] ?? '') === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!$feedbackReady) throw new RuntimeException('Feedback storage is unavailable.');
        $term = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $where = $archiveView ? 'f.archived_at IS NOT NULL' : 'f.archived_at IS NULL';
        $types = '';
        $params = [];
        if ($selectedStreet !== '') {
            $where .= ' AND LOWER(h.block) = LOWER(?)';
            $types .= 's';
            $params[] = $selectedStreet;
        }
        if ($selectedType !== '') {
            $where .= ' AND f.category = ?';
            $types .= 's';
            $params[] = $selectedType;
        }
        // Every word must match somewhere, so "concern duhat" or "maria resolved" both work.
        $words = $term === '' ? [] : array_slice(preg_split('/\s+/u', $term), 0, 6);
        // Compared column by column because these tables use different collations.
        $searchColumns = [
            'f.resident_name LIKE ?', '(f.resident_name IS NULL AND u.full_name LIKE ?)', 'f.category LIKE ?', 'f.status LIKE ?', 'f.subject LIKE ?', 'f.message LIKE ?', 'h.block LIKE ?', 'h.lot LIKE ?',
            "DATE_FORMAT(f.created_at, '%b %e, %Y') LIKE ?", "DATE_FORMAT(f.created_at, '%M %Y') LIKE ?", 'f.created_at LIKE ?',
        ];
        foreach ($words as $word) {
            $where .= ' AND (' . implode(' OR ', $searchColumns) . ')';
            $types .= str_repeat('s', count($searchColumns));
            array_push($params, ...array_fill(0, count($searchColumns), '%' . addcslashes($word, '\\%_') . '%'));
        }
        // One extra row tells us whether there are more than we show.
        $limit = 30;
        $stmt = $conn->prepare("SELECT f.id, f.category, f.status, f.subject, f.created_at,
                                       COALESCE(f.resident_name, u.full_name, 'Former account') AS resident_name, h.block, h.lot
                                FROM resident_feedback f
                                LEFT JOIN users u ON u.id = f.user_id
                                LEFT JOIN homeowners h ON h.user_id = f.user_id
                                WHERE {$where}
                                ORDER BY FIELD(f.status, 'new', 'in_review', 'resolved'), f.created_at DESC, f.id DESC
                                LIMIT " . ($limit + 1));
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $results = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $results[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['resident_name'],
                'subject' => (string) ($row['subject'] ?? ''),
                'category' => ucfirst((string) ($row['category'] ?? '')),
                'status' => (string) ($row['status'] ?? ''),
                'date' => !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : '',
                'address' => trim((string) ($row['block'] ?? '')) !== '' ? homeowner_address_label($row['block'], $row['lot'] ?? '') : '',
            ];
        }
        echo json_encode(['ok' => true, 'results' => $results, 'more' => count($rows) > $limit], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('Feedback search failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false]);
    }
    exit;
}

$successMsg = $_SESSION['success_msg'] ?? null;
unset($_SESSION['success_msg']);
$feedbackItems = [];
if ($feedbackReady) {
    try {
        $feedbackArchiveFilter = $archiveView ? 'f.archived_at IS NOT NULL' : 'f.archived_at IS NULL';
        $streetFilter = $selectedStreet !== '' ? ' AND LOWER(h.block) = LOWER(?)' : '';
        $typeFilter = $selectedType !== '' ? ' AND f.category = ?' : '';
        $stmt = $conn->prepare("SELECT f.*, COALESCE(f.resident_name, u.full_name, 'Former account') AS resident_name, h.block AS resident_street FROM resident_feedback f LEFT JOIN users u ON u.id = f.user_id LEFT JOIN homeowners h ON h.user_id = f.user_id WHERE {$feedbackArchiveFilter}{$streetFilter}{$typeFilter} ORDER BY FIELD(f.status, 'new', 'in_review', 'resolved'), f.created_at DESC, f.id DESC");
        $listParams = array_values(array_filter([$selectedStreet, $selectedType], 'strlen'));
        if ($listParams) $stmt->bind_param(str_repeat('s', count($listParams)), ...$listParams);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) $feedbackItems = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Admin feedback list failed: ' . $e->getMessage());
        $formError = 'Feedback submissions could not be loaded.';
    }
}

$photosByFeedback = [];
if ($feedbackItems) {
    try {
        ensure_incident_support($conn);
        $photosByFeedback = get_feedback_photos($conn, array_column($feedbackItems, 'id'));
    } catch (Throwable $e) {
        error_log('Admin feedback photos failed: ' . $e->getMessage());
    }
}

$csrfToken = generateCsrfToken();
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="fb-page-actions d-flex flex-wrap justify-content-end gap-2 mb-4">
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#fbSearchModal"><i class="bi bi-search me-1"></i>Search</button>
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
                    <div class="col-sm-5 col-md-3">
                        <label for="feedbackType" class="form-label">Filter by type</label>
                        <select id="feedbackType" name="type" class="form-select" onchange="this.form.submit()">
                            <option value="">All types</option>
                            <?php foreach ($feedbackTypes as $typeValue => $typeLabel): ?>
                                <option value="<?= htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedType === $typeValue ? 'selected' : '' ?>><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 admin-feedback-table">
                        <thead><tr><th>Resident</th><th>Type</th><th>Status</th><th class="text-center">Details</th><th class="text-center">Archive</th></tr></thead>
                        <tbody>
                            <?php foreach ($feedbackItems as $item): ?>
                                <tr data-fb-id="<?= (int) $item['id'] ?>">
                                    <td class="fw-semibold fb-name"><?= htmlspecialchars($item['resident_name'] ?? 'Former account') ?></td>
                                    <td class="fb-type"><span class="badge <?= $item['category'] === 'incident' ? 'text-bg-danger' : ($item['category'] === 'concern' ? 'text-bg-warning' : 'text-bg-info') ?>"><?= ucfirst(htmlspecialchars($item['category'])) ?></span></td>
                                    <td class="text-nowrap fb-status">
                                        <?php if ($item['status'] === 'resolved'): ?>
                                            <span class="badge text-bg-success">Resolved</span>
                                        <?php elseif ($item['status'] === 'in_review'): ?>
                                            <span class="badge text-bg-primary">In review</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">New</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fb-details">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-feedback-id="<?= (int) $item['id'] ?>"
                                            data-resident="<?= htmlspecialchars($item['resident_name'] ?? 'Former account', ENT_QUOTES, 'UTF-8') ?>"
                                            data-category="<?= htmlspecialchars(ucfirst($item['category']), ENT_QUOTES, 'UTF-8') ?>"
                                            data-subject="<?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-message="<?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-created="<?= htmlspecialchars(date('M j, Y g:i A', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-reply="<?= htmlspecialchars($item['admin_reply'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-photos="<?= htmlspecialchars(json_encode(array_column($photosByFeedback[(int) $item['id']] ?? [], 'file_name')), ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="openFeedbackModal(this)">
                                            <i class="bi bi-chat-square-text me-1"></i><?= !empty($item['admin_reply']) ? 'View / Edit Reply' : 'View / Reply' ?>
                                        </button>
                                    </td>
                                    <td class="text-center fb-archive">
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
                                <tr><td colspan="5" class="table-empty-state"><?= ($selectedStreet !== '' || $selectedType !== '') ? 'No feedback matches these filters.' : ($archiveView ? 'No archived resident feedback.' : 'No resident feedback has been submitted.') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<!-- Live search -->
<div class="modal fade" id="fbSearchModal" tabindex="-1" aria-labelledby="fbSearchTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fbSearchTitle"><?= $archiveView ? 'Search archived feedback' : 'Search feedback' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="fb-search-box px-3 pt-3">
                <div class="cir-search">
                    <i class="bi bi-search cir-search__icon" aria-hidden="true"></i>
                    <input type="search" id="fbSearchInput" class="cir-search__field" placeholder="Name, subject, message, street, type, status, or date" maxlength="100" autocomplete="off" enterkeyhint="search" aria-label="Search feedback" aria-controls="fbSearchResults">
                </div>
                <?php if ($selectedStreet !== '' || $selectedType !== ''): ?>
                    <div class="small text-muted mt-2"><?= htmlspecialchars(implode(' · ', array_filter([$selectedStreet !== '' ? 'Street: ' . $selectedStreet : '', $selectedType !== '' ? 'Type: ' . $feedbackTypes[$selectedType] : ''])), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>
            <div class="fb-search-status" id="fbSearchStatus" role="status" aria-live="polite"></div>
            <div class="modal-body">
                <div class="list-group list-group-flush" id="fbSearchResults"></div>
            </div>
        </div>
    </div>
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
                    <div id="feedbackDetailPhotos" class="mb-3 d-none">
                        <div class="form-label">Attached photos</div>
                        <div id="feedbackDetailPhotoGrid" class="feedback-photo-grid"></div>
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
    /* Desktop: the bell is fixed 78px from the screen edge, so keep the buttons 12px clear of it. */
    @media (min-width: 992px) {
        .fb-page-actions { padding-right: calc(90px - clamp(1.25rem, 2.4vw, 3rem)); }
    }
    .fb-search-box .cir-search { display: flex; max-width: none; }
    #fbSearchModal .modal-body { min-height: 45vh; padding: 0; }
    .fb-search-status { padding: .5rem 1rem; font-size: .875rem; color: var(--text-muted); }
    .fb-search-item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; min-height: 48px; text-align: left; }
    .fb-search-item__main { min-width: 0; overflow-wrap: anywhere; }
    .fb-search-item__name { font-weight: 600; color: var(--text-heading); }
    .fb-search-item__meta { font-size: .875rem; color: var(--text-muted); }
    .fb-search-item__side { flex-shrink: 0; text-align: right; font-size: .875rem; color: var(--text-muted); }
    .fb-row-focus { outline: 2px solid var(--primary-color); outline-offset: -2px; }
    .fb-row-focus > td { background-color: var(--primary-soft) !important; }

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

    .feedback-photo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
        gap: .5rem;
    }

    .feedback-photo-grid a,
    .feedback-photo-grid img {
        display: block;
        width: 100%;
    }

    .feedback-photo-grid img {
        aspect-ratio: 1 / 1;
        object-fit: cover;
        border: 1px solid var(--border-color);
        border-radius: 6px;
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
    const photos = JSON.parse(button.dataset.photos || '[]');
    const photoGrid = document.getElementById('feedbackDetailPhotoGrid');
    photoGrid.replaceChildren();
    photos.forEach(function (name, index) {
        const link = document.createElement('a');
        link.href = '../uploads/incidents/' + encodeURIComponent(name);
        link.target = '_blank';
        link.rel = 'noopener';
        const img = document.createElement('img');
        img.src = link.href;
        img.alt = 'Attached photo ' + (index + 1);
        link.appendChild(img);
        photoGrid.appendChild(link);
    });
    document.getElementById('feedbackDetailPhotos').classList.toggle('d-none', photos.length === 0);
    document.getElementById('feedbackStatus').value = button.dataset.status;
    document.getElementById('feedbackReply').value = button.dataset.reply;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('feedbackDetailModal')).show();
}

// Live search: results update while typing, and refresh every 10s while the modal is open.
(function () {
    var modal = document.getElementById('fbSearchModal');
    var input = document.getElementById('fbSearchInput');
    var list = document.getElementById('fbSearchResults');
    var status = document.getElementById('fbSearchStatus');
    var badges = { new: ['text-bg-secondary', 'New'], in_review: ['text-bg-primary', 'In review'], resolved: ['text-bg-success', 'Resolved'] };
    var typingTimer = null, refreshTimer = null, request = null, lastResponse = '', chosenId = null;

    function pageParams() {
        var params = new URLSearchParams(window.location.search);
        params.delete('focus');
        return params;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function resultItem(item) {
        var button = el('button', 'list-group-item list-group-item-action fb-search-item');
        button.type = 'button';
        var main = el('div', 'fb-search-item__main');
        main.appendChild(el('div', 'fb-search-item__name', item.name));
        main.appendChild(el('div', 'fb-search-item__meta', item.subject));
        main.appendChild(el('div', 'fb-search-item__meta', [item.date, item.address].filter(Boolean).join(' · ')));
        var side = el('div', 'fb-search-item__side');
        var badge = badges[item.status] || badges.new;
        side.appendChild(el('span', 'badge ' + badge[0], badge[1]));
        side.appendChild(el('div', 'mt-1', item.category));
        button.appendChild(main);
        button.appendChild(side);
        button.addEventListener('click', function () {
            chosenId = item.id;
            bootstrap.Modal.getOrCreateInstance(modal).hide();
        });
        return button;
    }

    function render(data, query) {
        list.replaceChildren.apply(list, data.results.map(resultItem));
        var count = data.results.length;
        if (count === 0) {
            status.textContent = query ? 'No feedback matches your search.' : 'No feedback found.';
        } else if (data.more) {
            status.textContent = 'Showing the first ' + count + ' matches. Type more to narrow it down.';
        } else {
            status.textContent = query ? count + (count === 1 ? ' result' : ' results') : 'New feedback first';
        }
    }

    function runSearch(isRefresh) {
        if (isRefresh && request) return;
        if (request) request.abort();
        var current = request = new AbortController();
        var query = input.value.trim();
        var params = pageParams();
        params.set('ajax', 'search');
        params.set('q', query);
        if (!isRefresh) status.textContent = 'Searching…';

        fetch('feedback.php?' + params.toString(), { signal: current.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                // An expired session redirects to the login page instead of returning JSON.
                if (response.redirected) throw new Error('session');
                if (!response.ok) throw new Error('server');
                return response.text();
            })
            .then(function (body) {
                if (body === lastResponse && isRefresh) return;
                var data = JSON.parse(body);
                if (!data.ok) throw new Error('server');
                lastResponse = body;
                render(data, query);
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                lastResponse = '';
                list.replaceChildren();
                status.textContent = error.message === 'session'
                    ? 'Your session expired. Please refresh the page and log in again.'
                    : 'Search is unavailable right now. Please try again.';
            })
            .finally(function () {
                if (request === current) request = null;
            });
    }

    function focusRow(id, reloadIfMissing) {
        var row = document.querySelector('tr[data-fb-id="' + id + '"]');
        if (!row) {
            // Submitted after this page loaded: reload so the row is in the table.
            if (reloadIfMissing) {
                var params = pageParams();
                params.set('focus', id);
                window.location.href = 'feedback.php?' + params.toString();
            }
            return;
        }
        row.scrollIntoView({ block: 'center' });
        row.classList.add('fb-row-focus');
        window.setTimeout(function () { row.classList.remove('fb-row-focus'); }, 2500);
    }

    input.addEventListener('input', function () {
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(function () { runSearch(false); }, 200);
    });

    modal.addEventListener('show.bs.modal', function () {
        input.value = '';
        chosenId = null;
        lastResponse = '';
        list.replaceChildren();
        runSearch(false);
    });
    modal.addEventListener('shown.bs.modal', function () {
        input.focus();
        refreshTimer = window.setInterval(function () {
            if (!document.hidden) runSearch(true);
        }, 10000);
    });
    modal.addEventListener('hidden.bs.modal', function () {
        window.clearTimeout(typingTimer);
        window.clearInterval(refreshTimer);
        if (request) request.abort();
        if (chosenId !== null) focusRow(chosenId, true);
    });

    var focusId = parseInt(new URLSearchParams(window.location.search).get('focus'), 10);
    if (focusId > 0) focusRow(focusId, false);
})();
</script>
<?php require_once '../includes/footer.php'; ?>
