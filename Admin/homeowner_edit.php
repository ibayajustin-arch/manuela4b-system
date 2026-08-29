<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { 
    header("Location: ../login.php"); 
    exit; 
}

$id = $_GET['id'] ?? null;
if (!$id) { 
    header("Location: homeowners.php"); 
    exit; 
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $block = trim($_POST['block'] ?? ''); 
    $lot = trim($_POST['lot'] ?? ''); 
    $lot_number = trim($_POST['lot_number'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? ''); 
    $email = trim($_POST['email'] ?? ''); 
    $monthly_due = trim($_POST['monthly_due'] ?? '') !== '' ? (float)$_POST['monthly_due'] : 500.00;
    $status = trim($_POST['status'] ?? '');
    $linked_user_id = !empty($_POST['linked_user_id']) ? (int)$_POST['linked_user_id'] : null;

    if (!empty($block) && !empty($lot) && !empty($lot_number) && !empty($full_name)) {
        try {
            $stmt = $conn->prepare("UPDATE homeowners SET block=?, lot=?, lot_number=?, full_name=?, address=?, contact=?, email=?, monthly_due=?, status=?, user_id=? WHERE id=?");
            
            if ($stmt) {
                $stmt->bind_param("sssssssdsii", $block, $lot, $lot_number, $full_name, $address, $contact, $email, $monthly_due, $status, $linked_user_id, $id);
                $stmt->execute();
                $stmt->close();
                
                $_SESSION['success_msg'] = "Homeowner updated successfully!";
                header("Location: homeowners.php"); 
                exit;
            } else {
                $error = "Database Error: " . $conn->error;
            }
        } catch (Exception $e) { 
            $error = "Error: " . $e->getMessage(); 
        }
    } else {
        $error = "Block, Lot, Lot Number, and Full Name are required.";
    }
}

// Fetch current homeowner data
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

// Fetch available user accounts to link
try {
    $availableUsers = [];
    $current_linked = $homeowner['user_id'] ?? null;
    $sql = "SELECT id, username, full_name FROM users 
            WHERE role != 'admin' 
            AND (id NOT IN (SELECT user_id FROM homeowners WHERE user_id IS NOT NULL)" . 
            ($current_linked ? " OR id = ?" : "") . ")";
    $stmt = $conn->prepare($sql);
    if ($current_linked) {
        $stmt->bind_param("i", $current_linked);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $availableUsers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $availableUsers = [];
}

require_once '../includes/header.php';
?>
<div class="app-wrapper">
    <?php require_once '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="mb-4 d-flex align-items-center">
            <a href="homeowners.php" class="btn btn-icon btn-outline-secondary me-3"><i class="bi bi-arrow-left"></i></a>
            <h1 class="mb-0">Edit Homeowner</h1>
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
                            <label class="form-label">Block *</label>
                            <input type="text" name="block" class="form-control" required value="<?= htmlspecialchars($homeowner['block'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lot (Display) *</label>
                            <input type="text" name="lot" class="form-control" required value="<?= htmlspecialchars($homeowner['lot'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Lot Number (Unique ID) *</label>
                            <input type="text" name="lot_number" class="form-control" required value="<?= htmlspecialchars($homeowner['lot_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Monthly Due</label>
                            <input type="number" name="monthly_due" class="form-control" step="0.01" value="<?= htmlspecialchars($homeowner['monthly_due'] ?? '500.00') ?>">
                        </div>
                    </div>
                    
                    <h5 class="fw-bold mb-4 text-primary border-bottom pb-2 mt-5">Personal Info</h5>
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($homeowner['full_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($homeowner['address'] ?? '') ?>">
                    </div>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Contact</label>
                            <input type="text" name="contact" class="form-control" value="<?= htmlspecialchars($homeowner['contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($homeowner['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-5">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($homeowner['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($homeowner['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <h5 class="fw-bold mb-4 text-primary border-bottom pb-2">Login Account Link</h5>
                    <div class="mb-5">
                        <label class="form-label">Linked Login Account</label>
                        <select name="linked_user_id" class="form-select">
                            <option value="">-- Not linked --</option>
                            <?php foreach ($availableUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($homeowner['user_id'] ?? null) == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['full_name'] ?? 'No name') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="modal-info-note mt-2">
                            <i class="bi bi-info-circle"></i>
                            <span>Linking this homeowner to a login account allows the resident to see their dues and make online payments under this record.</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end border-top pt-4">
                        <a href="homeowners.php" class="btn btn-outline-secondary me-2 px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>