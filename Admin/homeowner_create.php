<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../login.php"); 
    exit; 
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $block = trim($_POST['block']);
    $lot = trim($_POST['lot']);
    $lot_number = trim($_POST['lot_number']);
    $full_name = trim($_POST['full_name']);
    $address = trim($_POST['address']);
    $contact = trim($_POST['contact']);
    $email = trim($_POST['email']);
    $monthly_due = trim($_POST['monthly_due']) !== '' ? (float)$_POST['monthly_due'] : 500.00;
    $status = trim($_POST['status']);

    if (!empty($block) && !empty($lot) && !empty($lot_number) && !empty($full_name)) {
        try {
            $stmt = $conn->prepare("INSERT INTO homeowners (block, lot, lot_number, full_name, address, contact, email, monthly_due, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt) {
                $stmt->bind_param("sssssssds", $block, $lot, $lot_number, $full_name, $address, $contact, $email, $monthly_due, $status);
                $stmt->execute();
                $stmt->close();
                
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
        $error = "Block, Lot, Lot Number, and Full Name are required."; 
    }
}
require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="mb-4 d-flex align-items-center">
            <a href="homeowners.php" class="btn btn-icon btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i></a>
            <h1 class="mb-0">Add New Homeowner</h1>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="card" style="max-width: 800px;">
            <div class="card-body p-4 p-md-5">
                <form method="POST">
                    <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Property Details</h5>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Block Number *</label>
                            <input type="text" name="block" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lot Number (Display) *</label>
                            <input type="text" name="lot" class="form-control" required>
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Lot Number (Unique ID) *</label>
                            <input type="text" name="lot_number" class="form-control" placeholder="e.g. 4B-L12-2026" required>
                            <div class="form-text">Must be unique — used internally to identify this property.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Monthly Due</label>
                            <input type="number" name="monthly_due" class="form-control" step="0.01" placeholder="500.00" value="500.00">
                        </div>
                    </div>
                    
                    <h5 class="fw-bold mb-4 text-primary border-bottom pb-2 mt-5">Personal Info</h5>
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control">
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Contact</label>
                            <input type="text" name="contact" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                    </div>
                    <div class="mb-5">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="d-flex justify-content-end border-top pt-4">
                        <a href="homeowners.php" class="btn btn-outline-secondary me-2 px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">Save Homeowner</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>