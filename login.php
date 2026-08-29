<?php
require_once 'config/init.php';
require_once 'config/db.php';

$error = "";
$max_attempts = 5;
$lockout_time = 60; // seconds

// Initialize attempt counter if not set
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

// Reset attempts if lockout time has passed
if (time() - $_SESSION['last_attempt_time'] > $lockout_time) {
    $_SESSION['login_attempts'] = 0;
}

// Check if locked out
$locked_out = ($_SESSION['login_attempts'] >= $max_attempts);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif ($locked_out) {
        $remaining = $lockout_time - (time() - $_SESSION['last_attempt_time']);
        $error = "Too many attempts. Please wait {$remaining} seconds.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!empty($username) && !empty($password)) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");

            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();

                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                $stmt->close();

                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['login_attempts'] = 0;
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    if ($user['role'] === 'admin') {
                        header("Location: " . BASE_PATH . "/admin/dashboard.php");
                    } else {
                        header("Location: " . BASE_PATH . "/resident/profile.php");
                    }
                    exit;
                } else {
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    $error = "Invalid username or password!";
                }
            } else {
                $error = "Database error. Please try again later.";
            }
        } else {
            $error = "Please fill in all fields.";
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
    <title>Login - Manuela 4B MIS</title>
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
        <h4 class="fw-bold mb-1">Manuela 4B MIS</h4>
        <p class="login-subtitle">Visual Data Analytics Dashboard</p>

        <?php if ($error): ?>
            <div class="alert alert-danger border-0 rounded-3 text-sm d-flex align-items-center">
                <i class="bi bi-exclamation-circle me-2"></i><div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="password-field">
                    <input type="password" name="password" id="passwordInput" class="form-control" required>
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <i class="bi bi-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>

        <p class="text-center text-muted mt-4 mb-0" style="font-size: 0.9rem;">
            Don't have an account? <a href="register.php" class="fw-semibold">Sign Up</a>
        </p>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('passwordInput');
            const icon = document.getElementById('toggleIcon');
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