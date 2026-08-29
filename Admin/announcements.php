<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Handle new announcement submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $created_by = $_SESSION['user_id'];

    if (!empty($title) && !empty($message)) {
        try {
            $stmt = $conn->prepare("INSERT INTO announcements (title, message, created_by) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssi", $title, $message, $created_by);
                $stmt->execute();
                $stmt->close();
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

// Handle delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success_msg'] = "Announcement deleted.";
        }
    } catch (Exception $e) {
        $_SESSION['error_msg'] = "Database Error: " . $e->getMessage();
    }
    header("Location: announcements.php");
    exit;
}

// Fetch all announcements
try {
    $announcements = [];
    $result = $conn->query("SELECT a.*, u.full_name AS posted_by FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC");
    if ($result) {
        $announcements = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
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

        <div class="page-header">
            <h1>Community Announcements</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAnnouncementModal">
                <i class="bi bi-megaphone me-2"></i> New Announcement
            </button>
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
                                    <a href="?delete=<?= $a['id'] ?>" onclick="return confirm('Delete this announcement?')" class="btn btn-sm btn-light text-danger">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($announcements)): ?>
                            <tr><td colspan="5" class="table-empty-state">No announcements posted yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Announcement Modal -->
<div class="modal fade" id="newAnnouncementModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New Announcement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
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

<?php require_once '../includes/footer.php'; ?>