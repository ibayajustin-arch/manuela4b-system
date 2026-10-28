<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: homeowners.php");
    exit;
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = "Your session expired. Please refresh the page and try again.";
    } else {
        $block = trim($_POST['block'] ?? '');
        $lot = trim($_POST['lot'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $monthly_due = trim($_POST['monthly_due'] ?? '') !== '' ? (float)$_POST['monthly_due'] : MONTHLY_DUES_AMOUNT;
        $status = trim($_POST['status'] ?? 'active');
        $duesStartDate = date('Y-m-01');

        if (!empty($block) && !empty($lot) && !empty($full_name)) {
        try {
            $statusLookup = $conn->prepare('SELECT status FROM homeowners WHERE id = ?');
            $statusLookup->bind_param('i', $id);
            $statusLookup->execute();
            $currentStatus = strtolower((string) ($statusLookup->get_result()->fetch_assoc()['status'] ?? ''));
            $statusLookup->close();

            if ($currentStatus === 'pending' && $status === 'active') {
                $error = 'Pending resident applications must be approved from the Pending Residents page.';
            } else {
                $stmt = $conn->prepare("UPDATE homeowners SET block=?, lot=?, full_name=?, address=?, contact=?, email=?, monthly_due=?, dues_start_date=CASE WHEN ?='active' AND status <> 'active' THEN ? ELSE dues_start_date END, status=? WHERE id=?");
            }

            if (isset($stmt) && $stmt) {
                $stmt->bind_param("ssssssdsssi", $block, $lot, $full_name, $address, $contact, $email, $monthly_due, $status, $duesStartDate, $status, $id);
                $stmt->execute();
                $stmt->close();

                log_activity($conn, $id, 'Updated profile details', homeowner_address_label($block, $lot) . ", Status: {$status}");

                $_SESSION['success_msg'] = "Homeowner updated successfully!";
                header("Location: homeowners.php");
                exit;
            } elseif ($error === '') {
                $error = "Database Error: " . $conn->error;
            }
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Block, Lot, and Full Name are required.";
    }
    }
}

try {
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $homeowner = $result->fetch_assoc();
        $stmt->close();

        if (!$homeowner) {
            header("Location: homeowners.php");
            exit;
        }
    } else {
        header("Location: homeowners.php");
        exit;
    }
} catch (Exception $e) {
    header("Location: homeowners.php");
    exit;
}

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="container-fluid p-0">

            <div class="mb-4 d-flex align-items-center justify-content-center" style="max-width: 800px; margin: 0 auto;">
                <a href="homeowners.php" class="btn btn-icon btn-outline-secondary me-3 shadow-sm"><i class="bi bi-arrow-left"></i></a>
                <h2 class="mb-0 fw-bold">Edit Homeowner</h2>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger mx-auto shadow-sm" style="max-width: 800px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mx-auto" style="max-width: 800px;">
                <div class="card-body p-4 p-md-5">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Property Details</h5>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-dark fw-semibold">Street <span class="text-danger">*</span></label>
                                <select name="block" class="form-select" required>
                                    <?php $streetOptions = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas']; if (!in_array(strtolower((string) ($homeowner['block'] ?? '')), array_map('strtolower', $streetOptions), true) && !empty($homeowner['block'])) $streetOptions[] = $homeowner['block']; ?>
                                    <?php foreach ($streetOptions as $street): ?><option value="<?= htmlspecialchars($street) ?>" <?= strcasecmp((string) ($homeowner['block'] ?? ''), $street) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($street) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label class="form-label text-dark fw-semibold">House Number <span class="text-danger">*</span></label>
                                <input type="text" name="lot" class="form-control" required value="<?= htmlspecialchars($homeowner['lot'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-dark fw-semibold">Monthly Due</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">₱</span>
                                    <input type="number" name="monthly_due" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars((string) ($homeowner['monthly_due'] ?? MONTHLY_DUES_AMOUNT)) ?>">
                                </div>
                            </div>
                        </div>

                        <h5 class="fw-bold mb-4 text-primary border-bottom pb-2 mt-5">Personal Info</h5>
                        <div class="mb-4">
                            <label class="form-label text-dark fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($homeowner['full_name'] ?? '') ?>">
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-dark fw-semibold">Address</label>
                            <input type="text" name="address" class="form-control" placeholder="Optional complete address" value="<?= htmlspecialchars($homeowner['address'] ?? '') ?>">
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-dark fw-semibold">Contact</label>
                                <input type="text" name="contact" class="form-control" placeholder="e.g. 09123456789" value="<?= htmlspecialchars($homeowner['contact'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label class="form-label text-dark fw-semibold">Email</label>
                                <input type="email" name="email" class="form-control" placeholder="e.g. name@example.com" value="<?= htmlspecialchars($homeowner['email'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="mb-5">
                            <label class="form-label text-dark fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= ($homeowner['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="pending" <?= ($homeowner['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending Verification</option>
                                <option value="inactive" <?= ($homeowner['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="d-flex justify-content-end border-top pt-4">
                            <a href="homeowners.php" class="btn btn-outline-secondary me-2 px-4 fw-semibold">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
