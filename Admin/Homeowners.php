<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $conn->prepare("DELETE FROM homeowners WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['success_msg'] = "Homeowner deleted successfully!";
        } else {
            $_SESSION['error_msg'] = "Database Error: " . $conn->error;
        }
        header("Location: homeowners.php");
        exit;
    } catch (Exception $e) { 
        $_SESSION['error_msg'] = "Database Error: " . $e->getMessage();
        header("Location: homeowners.php");
        exit;
    }
}

$search = $_GET['search'] ?? '';
try {
    $homeowners = [];
    $sql = "SELECT h.*, u.username AS linked_username FROM homeowners h LEFT JOIN users u ON h.user_id = u.id";
    
    if ($search) {
        $sql .= " WHERE h.block LIKE ? OR h.lot LIKE ? OR h.full_name LIKE ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $search_param = "%$search%";
            $stmt->bind_param("sss", $search_param, $search_param, $search_param);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $homeowners = $result->fetch_all(MYSQLI_ASSOC);
            }
            $stmt->close();
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            $homeowners = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
} catch (Exception $e) {
    $homeowners = [];
}

require_once '../includes/header.php';
?>

<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0">
            
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
                <h1>Homeowner Accounts</h1>
                <a href="homeowner_create.php" class="btn btn-primary">
                    <i class="bi bi-person-plus-fill me-2"></i> Add Homeowner
                </a>
            </div>

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="mb-4">
                        <div class="input-group" style="max-width: 450px; border-radius: var(--radius-md); overflow: hidden;">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search Block, Lot, or Name..." value="<?= htmlspecialchars($search) ?>" style="box-shadow: none;">
                            <button class="btn btn-primary px-4">Search</button>
                        </div>
                    </form>

                    <div class="table-responsive border-0 shadow-none p-0">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Block</th><th>Lot</th><th>Full Name</th><th>Contact</th><th>Email</th><th>Linked Account</th><th>Status</th><th class="text-center">Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($homeowners as $h): ?>
                                <tr>
                                    <td><span class="fw-bold text-primary">Blk <?= htmlspecialchars($h['block'] ?? '') ?></span></td>
                                    <td>Lot <?= htmlspecialchars($h['lot'] ?? '') ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($h['full_name'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($h['contact'] ?? '-') ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($h['email'] ?? '-') ?></td>
                                    <td>
                                        <?php if (!empty($h['linked_username'])): ?>
                                            <span class="badge badge-success"><i class="bi bi-link-45deg"></i> <?= htmlspecialchars($h['linked_username']) ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Not linked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $status = $h['status'] ?? 'inactive'; ?>
                                        <span class="badge <?= $status === 'active' ? 'badge-success' : 'badge-info' ?>">
                                            <?= ucfirst($status) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="homeowner_edit.php?id=<?= $h['id'] ?>" class="btn btn-sm btn-light text-primary me-1">Edit</a>
                                        <a href="?delete=<?= $h['id'] ?>" onclick="return confirm('Delete this homeowner?')" class="btn btn-sm btn-light text-danger">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($homeowners)): ?>
                                <tr><td colspan="8" class="table-empty-state">No homeowners found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>