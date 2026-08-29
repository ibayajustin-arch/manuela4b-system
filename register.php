<?php
require_once 'config/init.php';
require_once 'config/db.php';

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (empty($full_name) || empty($username) || empty($password) || empty($confirm_password)) {
            $error = "Please fill in all fields.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Check if username already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = "Username already taken. Please choose another.";
                $stmt->close();
            } else {
                $stmt->close();
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $insertStmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'homeowner')");
                $insertStmt->bind_param("sss", $username, $hashed_password, $full_name);

                if ($insertStmt->execute()) {
                    $success = "Account created successfully! You may now sign in.";
                } else {
                    $error = "Database Error: " . $conn->error;
                }
                $insertStmt->close();
            }
        }
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign Up - Manuela 4B MIS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css" rel="stylesheet">
    <link href="Assets/css/components.css" rel="stylesheet">
    <link href="Assets/css/resident.css" rel="stylesheet">
    <link href="Assets/css/admin.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-card mx-3">
        <img src="Assets/css/images/logo2.png" alt="Manuela 4B MIS Logo" class="login-logo">
        <h4 class="fw-bold mb-1">Create Account</h4>
        <p class="login-subtitle">Join Manuela 4B MIS as a resident</p>

        <?php if ($error): ?>
            <div class="alert alert-danger border-0 rounded-3 text-sm d-flex align-items-center">
                <i class="bi bi-exclamation-circle me-2"></i><div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success border-0 rounded-3 text-sm d-flex align-items-center">
                <i class="bi bi-check-circle me-2"></i><div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="mb-3">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="password-field">
                    <input type="password" name="password" id="passwordInput" class="form-control" minlength="8" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('passwordInput', 'toggleIcon1')">
                        <i class="bi bi-eye" id="toggleIcon1"></i>
                    </button>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Confirm Password</label>
                <div class="password-field">
                    <input type="password" name="confirm_password" id="confirmPasswordInput" class="form-control" minlength="8" required>
                    <button type="button" class="toggle-password" onclick="togglePassword('confirmPasswordInput', 'toggleIcon2')">
                        <i class="bi bi-eye" id="toggleIcon2"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Create Account</button>
        </form>
        <?php endif; ?>

        <p class="text-center text-muted mt-4 mb-0" style="font-size: 0.9rem;">
            <?php if ($success): ?>
                <a href="login.php" class="fw-semibold">Go to Login</a>
            <?php else: ?>
                Already have an account? <a href="login.php" class="fw-semibold">Login</a>
            <?php endif; ?>
        </p>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        }
    </script>
</body>
</html>