<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_homeowner') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = 'Your session expired. Please try again.';
    } else {
        $homeownerId = (int) ($_POST['homeowner_id'] ?? 0);
        if ($homeownerId > 0) {
            try {
                $documentStmt = $conn->prepare("SELECT verification_id_path, verification_id_back_path FROM homeowners WHERE id = ? AND status = 'pending' AND user_id IS NOT NULL");
                $documentStmt->bind_param('i', $homeownerId);
                $documentStmt->execute();
                $documentRow = $documentStmt->get_result()->fetch_assoc();
                $documentStmt->close();
                $frontFileName = (string) ($documentRow['verification_id_path'] ?? '');
                $backFileName = (string) ($documentRow['verification_id_back_path'] ?? '');
                $documentDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'verification_ids';
                $frontValid = preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $frontFileName) && is_file($documentDir . DIRECTORY_SEPARATOR . $frontFileName);
                $backValid = preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $backFileName) && is_file($documentDir . DIRECTORY_SEPARATOR . $backFileName);

                if (!$documentRow) {
                    $_SESSION['error_msg'] = 'This resident was already reviewed or could not be found.';
                } elseif (!$frontValid || ($backFileName !== '' && !$backValid)) {
                    $_SESSION['error_msg'] = 'The resident ID images are incomplete or unavailable. Review both sides before approval.';
                } else {
                    $duesStartDate = date('Y-m-01');
                    $stmt = $conn->prepare("UPDATE homeowners SET status = 'active', dues_start_date = ? WHERE id = ? AND status = 'pending' AND user_id IS NOT NULL");
                    $stmt->bind_param('si', $duesStartDate, $homeownerId);
                    $stmt->execute();
                    $approved = $stmt->affected_rows === 1;
                    $stmt->close();
                    if ($approved) {
                        log_activity($conn, $homeownerId, 'Verified homeowner registration', 'Resident account activated after admin review');
                        $_SESSION['success_msg'] = 'Resident verified and account activated. They can now log in.';
                    } else {
                        $_SESSION['error_msg'] = 'This resident was already reviewed or could not be found.';
                    }
                }
            } catch (Throwable $e) {
                error_log('Homeowner verification failed: ' . $e->getMessage());
                $_SESSION['error_msg'] = 'The resident could not be verified. Please try again.';
            }
        }
    }
    header('Location: pending_homeowners.php');
    exit;
}

$pendingHomeowners = [];
try {
    $result = $conn->query("SELECT h.id, h.user_id, h.block, h.lot, h.full_name, h.address, h.contact, h.email, h.created_at, h.verification_id_path, h.verification_id_back_path, u.username
                            FROM homeowners h
                            LEFT JOIN users u ON u.id = h.user_id
                            WHERE LOWER(h.status) = 'pending' AND h.user_id IS NOT NULL
                            ORDER BY h.created_at ASC, h.id ASC");
    if ($result) $pendingHomeowners = $result->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    error_log('Pending homeowner list failed: ' . $e->getMessage());
    $loadError = 'Pending resident applications could not be loaded.';
}

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <main class="main-content">
        <div class="container-fluid p-0">
            <?php foreach (['success_msg' => 'success', 'error_msg' => 'danger'] as $key => $variant): ?>
                <?php if (isset($_SESSION[$key])): ?>
                    <div class="alert alert-<?= $variant ?> alert-dismissible fade show border-0 rounded-3 shadow-sm" role="alert">
                        <?= htmlspecialchars($_SESSION[$key]) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION[$key]); ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
                <div>
                    <h1 class="mb-1">Pending Residents</h1>
                    <p class="text-muted mb-0">Review each registration and attached ID before activating the resident account.</p>
                </div>
                <a href="homeowners.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Homeowners</a>
            </div>

            <?php if (!empty($loadError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($loadError) ?></div>
            <?php elseif (empty($pendingHomeowners)): ?>
                <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
                    <i class="bi bi-person-check text-success" style="font-size:2.5rem"></i>
                    <h2 class="h5 mt-3">No pending residents</h2>
                    <p class="text-muted mb-0">New homeowner registrations will appear here for verification.</p>
                </div></div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($pendingHomeowners as $homeowner): ?>
                        <div class="col-12 col-xl-6">
                            <article class="card border-0 shadow-sm h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                        <div>
                                            <h2 class="h5 mb-1"><?= htmlspecialchars($homeowner['full_name'] ?? 'Resident') ?></h2>
                                            <div class="text-muted"><?= htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span class="badge bg-warning text-dark">Pending verification</span>
                                    </div>
                                    <dl class="row small mb-3">
                                        <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= htmlspecialchars($homeowner['contact'] ?? '-') ?></dd>
                                        <dt class="col-sm-4">Email</dt><dd class="col-sm-8 text-break"><?= htmlspecialchars($homeowner['email'] ?? '-') ?></dd>
                                        <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= htmlspecialchars(homeowner_address_label($homeowner['block'] ?? '', $homeowner['lot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                                        <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8"><?= !empty($homeowner['created_at']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($homeowner['created_at']))) : '-' ?></dd>
                                    </dl>

                                    <?php
                                        $idDocumentDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'verification_ids';
                                        $frontIdFile = (string) ($homeowner['verification_id_path'] ?? '');
                                        $backIdFile = (string) ($homeowner['verification_id_back_path'] ?? '');
                                        $hasIdFront = preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $frontIdFile) && is_file($idDocumentDir . DIRECTORY_SEPARATOR . $frontIdFile);
                                        $hasIdBack = preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $backIdFile) && is_file($idDocumentDir . DIRECTORY_SEPARATOR . $backIdFile);
                                        $canApprove = $hasIdFront && ($backIdFile === '' || $hasIdBack);
                                    ?>
                                    <div class="row g-3 mb-3">
                                        <?php foreach (['front' => ['Front of ID', $hasIdFront], 'back' => ['Back of ID', $hasIdBack]] as $side => [$sideLabel, $hasSideImage]): ?>
                                            <div class="col-12 col-md-6">
                                                <div class="border rounded-3 bg-light p-2 text-center h-100">
                                                    <div class="small fw-semibold mb-2"><?= $sideLabel ?></div>
                                                    <?php if ($hasSideImage): ?>
                                                        <a href="homeowner_id_document.php?id=<?= (int) $homeowner['id'] ?>&amp;side=<?= $side ?>" target="_blank" rel="noopener">
                                                            <img src="homeowner_id_document.php?id=<?= (int) $homeowner['id'] ?>&amp;side=<?= $side ?>" alt="<?= $sideLabel ?> submitted by <?= htmlspecialchars($homeowner['full_name'] ?? 'resident') ?>" style="max-width:100%;max-height:260px;object-fit:contain">
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted small"><?= $backIdFile === '' && $side === 'back' ? 'Not submitted with this older application.' : 'Image unavailable.' ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                                        <a href="homeowner_view.php?id=<?= (int) $homeowner['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-eye me-1"></i> Full profile</a>
                                        <form method="post" onsubmit="return confirm('Confirm that this resident is legitimate and activate their account?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="approve_homeowner">
                                            <input type="hidden" name="homeowner_id" value="<?= (int) $homeowner['id'] ?>">
                                            <button type="submit" class="btn btn-success" <?= $canApprove ? '' : 'disabled' ?>><i class="bi bi-person-check-fill me-1"></i> Verify &amp; Accept</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php require_once '../includes/footer.php'; ?>
