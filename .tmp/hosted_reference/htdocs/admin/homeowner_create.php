<?php
require_once '../config/init.php';
require_once '../config/db.php';
require_once '../includes/homeowner_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../login.php"); 
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
        $dues_start_date = $status === 'active' ? date('Y-m-01') : null;

        $allowedStreets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];
        if (!in_array(strtolower($block), array_map('strtolower', $allowedStreets), true)) {
            $error = 'Please select one of the listed streets.';
        } elseif (!empty($lot) && !empty($full_name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO homeowners (block, lot, full_name, address, contact, email, monthly_due, status, dues_start_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt) {
                $stmt->bind_param("ssssssdss", $block, $lot, $full_name, $address, $contact, $email, $monthly_due, $status, $dues_start_date);
                $stmt->execute();
                $new_id = $conn->insert_id;
                $stmt->close();
                
                log_activity($conn, $new_id, 'Added homeowner profile', homeowner_address_label($block, $lot));
                
                $_SESSION['success_msg'] = "Homeowner successfully added!";
                header("Location: homeowners.php");
                exit;
            } else {
                $error = "Database Error: " . $conn->error;
            }
        } catch (Exception $e) { 
            $error = "Database Error: " . $e->getMessage(); 
        }
    } else { 
        $error = "Block, Lot, and Full Name are required."; 
    }
    }
}
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="homeowner-create-layout">
            <div class="page-header homeowner-create-heading">
                <div class="d-flex align-items-center gap-3">
                    <a href="homeowners.php" class="btn btn-icon btn-outline-secondary flex-shrink-0" aria-label="Back to homeowners"><i class="bi bi-arrow-left"></i></a>
                    <div>
                        <h1>Add New Homeowner</h1>
                        <p class="mb-0">Add a household, property, and contact details.</p>
                    </div>
                </div>
            </div>
        
            <?php if ($error): ?>
                <div class="alert alert-danger homeowner-create-heading"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        
            <div class="card homeowner-create-card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <h2 class="homeowner-form-section-title">Property Details</h2>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Street *</label>
                            <select name="block" class="form-select" required>
                                <option value="">Select street</option>
                                <?php foreach (['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'] as $street): ?><option value="<?= $street ?>" <?= strcasecmp((string) ($_POST['block'] ?? ''), $street) === 0 ? 'selected' : '' ?>><?= $street ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">House Number *</label>
                            <input type="text" name="lot" class="form-control" placeholder="e.g. 12" value="<?= htmlspecialchars($_POST['lot'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="row mb-5">
                        <div class="col-md-5 col-lg-4">
                            <label class="form-label">Monthly Due</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="monthly_due" class="form-control" step="0.01" min="0" placeholder="250.00" value="<?= htmlspecialchars($_POST['monthly_due'] ?? '250.00') ?>">
                            </div>
                        </div>
                    </div>
                    
                    <h2 class="homeowner-form-section-title">Personal Information</h2>
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Contact</label>
                            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-5">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($_POST['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($_POST['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="d-flex justify-content-end border-top pt-4 homeowner-create-actions">
                        <a href="homeowners.php" class="btn btn-outline-secondary me-2 px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">Save Homeowner</button>
                    </div>
                </form>
            </div>
        </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>
