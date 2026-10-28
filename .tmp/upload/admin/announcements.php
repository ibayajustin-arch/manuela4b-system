<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';
require_once '../includes/archive_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$archiveReady = true;
$archiveError = null;
try {
    ensure_archive_columns($conn, 'announcements');
} catch (Throwable $e) {
    error_log('Announcement archive setup failed: ' . $e->getMessage());
    $archiveReady = false;
    $archiveError = 'Archive storage is unavailable. Please contact the administrator.';
}
$archiveView = ($_GET['view'] ?? '') === 'archive';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'create') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = "Your session expired. Please refresh the page and try again.";
        header("Location: announcements.php");
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $created_by = (int) $_SESSION['user_id'];

    if (!empty($title) && !empty($message)) {
        try {
            $stmt = $conn->prepare("INSERT INTO announcements (title, message, created_by) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssi", $title, $message, $created_by);
                $stmt->execute();
                $stmt->close();
                log_activity($conn, null, 'Posted announcement', "Title: {$title}");
                $_SESSION['success_msg'] = "Announcement posted successfully!";
            } else {
                $_SESSION['error_msg'] = "Database Error: " . $conn->error;
            }
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Database Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_msg'] = "Please fill in both title and message.";
    }

    header("Location: announcements.php");
    exit;
}

// Archive and restore announcements without removing their history.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['archive_announcement', 'restore_announcement'], true)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = "Your session expired. Please refresh the page and try again.";
        header('Location: announcements.php' . (($_POST['action'] ?? '') === 'restore_announcement' ? '?view=archive' : ''));
        exit;
    }

    $id = (int) ($_POST['announcement_id'] ?? 0);
    $action = $_POST['action'];
    if (!$archiveReady) {
        $_SESSION['error_msg'] = $archiveError;
    } elseif ($id > 0) {
        try {
            if ($action === 'archive_announcement') {
                $stmt = $conn->prepare('UPDATE announcements SET archived_at = NOW() WHERE id = ? AND archived_at IS NULL');
                $message = 'Announcement archived. You can restore it from Archives.';
                $activity = 'Archived announcement';
            } else {
                $stmt = $conn->prepare('UPDATE announcements SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL');
                $message = 'Announcement restored successfully.';
                $activity = 'Restored announcement';
            }
            if (!$stmt) throw new RuntimeException($conn->error);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            if ($changed) {
                log_activity($conn, null, $activity, "Announcement #{$id}");
                $_SESSION['success_msg'] = $message;
            } else {
                $_SESSION['error_msg'] = 'Announcement not found or already updated.';
            }
        } catch (Throwable $e) {
            error_log('Announcement archive action failed: ' . $e->getMessage());
            $_SESSION['error_msg'] = 'The announcement could not be updated.';
        }
    }
    header('Location: announcements.php' . ($action === 'restore_announcement' ? '?view=archive' : ''));
    exit;
}

try {
    $announcements = [];
    $archiveCondition = $archiveView ? 'a.archived_at IS NOT NULL' : 'a.archived_at IS NULL';
    $result = $archiveReady ? $conn->query("SELECT a.*, u.full_name AS posted_by FROM announcements a LEFT JOIN users u ON a.created_by = u.id WHERE {$archiveCondition} ORDER BY a.created_at DESC") : false;
    if ($result) {
        $announcements = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Announcement list could not be loaded: ' . $e->getMessage());
    $announcements = [];
}

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">

        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 rounded-3">
                <i class="bi bi-check-circle me-2"></i><?= $_SESSION['success_msg'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 rounded-3">
                <i class="bi bi-exclamation-circle me-2"></i><?= $_SESSION['error_msg'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>
        <?php if ($archiveError): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($archiveError) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1><?= $archiveView ? 'Archived Announcements' : 'Community Announcements' ?></h1>
                <p class="mb-0"><?= $archiveView ? 'Restore announcements from this page.' : 'Post updates that appear on every resident dashboard.' ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="announcements.php<?= $archiveView ? '' : '?view=archive' ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-<?= $archiveView ? 'megaphone' : 'archive' ?> me-1"></i><?= $archiveView ? 'Back to Announcements' : 'Archives' ?>
                </a>
                <?php if (!$archiveView): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAnnouncementModal">
                        <i class="bi bi-megaphone me-2"></i> New Announcement
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive border-0 shadow-none p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr><th>Title</th><th>Message</th><th>Posted By</th><th>Date Posted</th><th class="text-center">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcements as $a): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($a['title']) ?></td>
                                <td class="text-muted" style="max-width: 320px; white-space: normal;"><?= nl2br(htmlspecialchars($a['message'])) ?></td>
                                <td><?= htmlspecialchars($a['posted_by'] ?? 'Admin') ?></td>
                                <td class="text-muted"><?= date('M d, Y h:i A', strtotime($a['created_at'])) ?></td>
                                <td class="text-center">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $archiveView ? 'Restore' : 'Archive' ?> this announcement?');">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="action" value="<?= $archiveView ? 'restore_announcement' : 'archive_announcement' ?>">
                                        <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light <?= $archiveView ? 'text-success' : 'text-secondary' ?>" title="<?= $archiveView ? 'Restore Announcement' : 'Archive Announcement' ?>">
                                            <i class="bi bi-<?= $archiveView ? 'arrow-counterclockwise' : 'archive' ?>"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($announcements)): ?>
                            <tr><td colspan="5" class="table-empty-state"><?= $archiveView ? 'No archived announcements.' : 'No announcements posted yet.' ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$archiveView): ?>
<!-- New Announcement Modal -->
<div class="modal fade" id="newAnnouncementModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New Announcement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
          <input type="hidden" name="action" value="create">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label">Title</label>
                  <input type="text" name="title" class="form-control" placeholder="e.g. Canal Cleaning Schedule" required maxlength="150">
              </div>
              <div class="mb-3">
                  <label class="form-label">Message</label>
                  <textarea name="message" class="form-control" rows="4" placeholder="Details of the announcement..." required></textarea>
              </div>
              <div class="modal-info-note">
                  <i class="bi bi-info-circle"></i>
                  <span>This will be visible to all residents on their profile page immediately after posting.</span>
              </div>
          </div>
          <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Post Announcement</button>
          </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>