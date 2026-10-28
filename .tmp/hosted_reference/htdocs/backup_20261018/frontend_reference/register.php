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
        $email = strtolower(trim($_POST['email'] ?? ''));
        $contact = trim($_POST['contact'] ?? '');
        $block = trim($_POST['block'] ?? '');
        // Store the registration form's house number in the existing lot field.
        $lot = trim($_POST['house_number'] ?? '');
        $address = '';
        $password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        $idUpload = $_FILES['verification_id'] ?? null;
        $textLength = static fn($value) => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $allowedStreets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];

        if (empty($full_name) || empty($username) || empty($email) || empty($contact)
            || empty($block) || empty($lot) || empty($password) || empty($confirm_password)
            || !$idUpload || (int) ($idUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = "Please complete all fields and attach a resident ID photo.";
        } elseif (!in_array(strtolower($block), array_map('strtolower', $allowedStreets), true)) {
            $error = "Please select one of the listed streets.";
        } elseif ($textLength($full_name) > 100 || $textLength($username) > 50 || $textLength($email) > 100
            || $textLength($contact) > 20 || $textLength($block) > 50 || $textLength($lot) > 50) {
            $error = "One or more fields exceed the allowed length.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!preg_match('/^[0-9+().\s-]{7,20}$/', $contact)) {
            $error = "Please enter a valid contact number.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif ((int) ($idUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = ((int) ($idUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE
                || (int) ($idUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_FORM_SIZE)
                ? "The ID image is too large. Please upload an image no larger than 5 MB."
                : "The ID image could not be uploaded. Please try again.";
        } elseif ((int) ($idUpload['size'] ?? 0) <= 0 || (int) $idUpload['size'] > 5 * 1024 * 1024) {
            $error = "Please upload an ID image no larger than 5 MB.";
        } elseif (!is_uploaded_file($idUpload['tmp_name'] ?? '')) {
            $error = "The ID image upload is invalid. Please choose the file again.";
        } else {
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $verifiedMime = $fileInfo->file($idUpload['tmp_name']);
            $allowedIdTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!isset($allowedIdTypes[$verifiedMime])) {
                $error = "Upload a clear ID photo in JPG, PNG, or WebP format.";
            }
        }

        if ($error === '') {
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

                $transactionStarted = false;
                $storedIdPath = null;
                $privateIdDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'verification_ids';
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $insertStmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'homeowner')");
                    $insertStmt->bind_param("sss", $username, $hashed_password, $full_name);
                    $insertStmt->execute();
                    $new_user_id = $conn->insert_id;
                    $insertStmt->close();

                    if (!is_dir($privateIdDir) && !mkdir($privateIdDir, 0700, true) && !is_dir($privateIdDir)) {
                        throw new RuntimeException('Could not create private verification storage.');
                    }
                    $storedIdPath = bin2hex(random_bytes(20)) . '.' . $allowedIdTypes[$verifiedMime];
                    if (!move_uploaded_file($idUpload['tmp_name'], $privateIdDir . DIRECTORY_SEPARATOR . $storedIdPath)) {
                        throw new RuntimeException('Could not save the verification image.');
                    }

                    $default_monthly_due = MONTHLY_DUES_AMOUNT;
                    $default_status = 'pending';

                    $hStmt = $conn->prepare("INSERT INTO homeowners (user_id, block, lot, full_name, address, contact, email, monthly_due, status, verification_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $hStmt->bind_param('i' . str_repeat('s', 6) . 'dss', $new_user_id, $block, $lot, $full_name, $address, $contact, $email, $default_monthly_due, $default_status, $storedIdPath);
                    $hStmt->execute();
                    $hStmt->close();

                    $conn->commit();
                    $transactionStarted = false;
                    $success = "Application submitted. An administrator will review your resident details and ID photo before verification.";
                } catch (Exception $e) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    if ($storedIdPath && is_file($privateIdDir . DIRECTORY_SEPARATOR . $storedIdPath)) {
                        @unlink($privateIdDir . DIRECTORY_SEPARATOR . $storedIdPath);
                    }
                    $error = "We couldn't save your application. Please try again.";
                }
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
    <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=4" rel="stylesheet">
    <link href="Assets/css/components.css?v=6" rel="stylesheet">
    <link href="Assets/css/resident.css?v=4" rel="stylesheet">
    <link href="Assets/css/admin.css?v=4" rel="stylesheet">
    <style>
        .register-page .login-shell {
            align-items: center;
            padding: clamp(16px, 3vw, 48px);
        }

        .register-page .login-shell-card {
            align-items: stretch;
            min-height: 0;
            max-width: 1320px;
        }

        .register-page .login-left-panel {
            justify-content: flex-start;
            flex: 1.2;
            min-width: 0;
            padding: clamp(24px, 3.2vw, 48px);
        }

        .register-page .login-left-inner {
            justify-content: flex-start;
            max-width: none;
            margin-block: clamp(20px, 3vh, 32px);
        }

        .register-page .login-right-panel {
            justify-content: center;
            flex: 0.8;
            min-width: 0;
            padding: clamp(32px, 4vw, 64px);
        }

        .register-page .login-right-panel h2,
        .register-page .login-right-panel .login-hero-subtitle,
        .register-page .register-hero-card {
            width: 100%;
            max-width: 440px;
        }

        .register-page .register-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px;
            color: rgba(255, 255, 255, 0.84);
            font-size: 0.76rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .register-page .register-eyebrow i {
            color: #f4b342;
            font-size: 1rem;
        }

        .register-page .login-right-panel h2 {
            font-size: clamp(1.8rem, 3vw, 2.5rem);
            line-height: 1.15;
        }

        .register-page .register-hero-card {
            display: grid;
            gap: 16px;
            margin-top: 12px;
            padding: clamp(18px, 2.5vw, 28px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.1);
            box-shadow: 0 20px 44px rgba(8, 35, 24, 0.16);
            backdrop-filter: blur(8px);
        }

        .register-page .register-hero-feature {
            display: flex;
            align-items: center;
            gap: 14px;
            color: #fff;
            font-size: 0.92rem;
            font-weight: 500;
        }

        .register-page .register-hero-feature i {
            display: grid;
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.12);
            color: #f4b342;
            font-size: 1.1rem;
        }

        .register-page .register-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            column-gap: 18px;
        }

        .register-page .register-form-grid .register-field {
            margin-bottom: 14px !important;
            min-width: 0;
        }

        .register-page .register-form-grid .register-field-wide {
            grid-column: 1 / -1;
        }

        .register-page .register-form-grid .form-label {
            margin-bottom: 5px;
            font-size: 0.84rem;
            font-weight: 600;
        }

        .register-page .register-form-grid .form-control {
            height: 44px;
            line-height: 1.5;
        }

        .register-page .register-submit {
            grid-column: 1 / -1;
            margin-top: 2px;
        }

        @media (max-width: 900px) {
            .register-page .login-shell-card {
                max-width: 760px;
            }

            .register-page .login-right-panel {
                display: none;
            }
        }

        @media (max-width: 767.98px) {
            .register-page .login-shell {
                padding: 0;
            }

            .register-page .login-left-panel {
                padding: 24px;
            }

            .register-page .login-shell-card {
                min-height: 100vh;
            }

            .register-page .login-left-inner {
                margin-block: 24px;
            }
        }

        @media (max-width: 575.98px) {
            .register-page .register-form-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .register-page .register-form-grid .register-field-wide,
            .register-page .register-submit {
                grid-column: auto;
            }

            .register-page .login-left-panel {
                padding: 20px;
            }
        }
    </style>
</head>
<body class="register-page">
    <div class="login-shell">
        <div class="login-shell-card">

            <!-- LEFT: Form -->
            <div class="login-left-panel">
                <div class="login-brand-row">
                    <img src="Assets/css/images/logo2.png" alt="Manuela 4B MIS Logo">
                    <span>Manuela 4B</span>
                </div>

                <div class="login-left-inner">
                    <h1 class="fw-bold">Create Account</h1>
                    <p class="login-subtitle">Join Manuela 4B MIS as a resident.</p>
                    <p class="small text-muted">Provide your contact and property details for admin verification. Your account will be pending approval before your resident details are verified.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center mb-3">
                            <i class="bi bi-exclamation-circle-fill me-2"></i><div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success d-flex align-items-center mb-3">
                            <i class="bi bi-check-circle-fill me-2"></i><div><?= htmlspecialchars($success) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="register-form-grid">
                        <div class="register-field">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" maxlength="100" autocomplete="name" placeholder="First and Last Name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" maxlength="50" autocomplete="username" placeholder="Choose a username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control" maxlength="100" autocomplete="email" placeholder="name@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Contact Number</label>
                            <input type="tel" name="contact" class="form-control" maxlength="20" autocomplete="tel" placeholder="0912 345 6789" value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" required>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Street</label>
                            <select name="block" class="form-select" required>
                                <option value="">Select your street</option>
                                <?php foreach (['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'] as $street): ?>
                                    <option value="<?= $street ?>" <?= strcasecmp((string) ($_POST['block'] ?? ''), $street) === 0 ? 'selected' : '' ?>><?= $street ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="register-field">
                            <label class="form-label">House Number</label>
                            <input type="text" name="house_number" class="form-control" maxlength="50" placeholder="e.g. 12" value="<?= htmlspecialchars($_POST['house_number'] ?? '') ?>" required>
                        </div>
                        <div class="register-field register-field-wide">
                            <label for="verificationIdInput" class="form-label">Resident ID Photo <span class="text-danger">*</span></label>
                            <input type="file" name="verification_id" id="verificationIdInput" class="form-control" accept="image/jpeg,image/png,image/webp" capture="environment" required>
                            <div class="form-text">Upload a clear JPG, PNG, or WebP image (max 5 MB). Confidential for resident verification only.</div>
                            <div id="verificationIdPreviewWrap" class="mt-3" hidden>
                                <img id="verificationIdPreview" alt="Preview of the selected resident ID photo" class="img-fluid rounded-3 border" style="max-height: 220px;">
                                <div id="verificationIdFileName" class="small text-muted mt-1"></div>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Password</label>
                            <div class="password-visibility-field">
                                <input type="text" name="password" id="passwordInput" class="form-control" minlength="8" placeholder="At least 8 characters" required>
                                <button type="button" class="password-visibility-toggle" data-password-target="passwordInput" data-password-label="password" aria-label="Hide password" aria-pressed="true">
                                    <i class="bi bi-eye-slash-fill" aria-hidden="true"></i><span>Hide</span>
                                </button>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Confirm Password</label>
                            <div class="password-visibility-field">
                                <input type="text" name="confirm_password" id="confirmPasswordInput" class="form-control" minlength="8" placeholder="Repeat password" required>
                                <button type="button" class="password-visibility-toggle" data-password-target="confirmPasswordInput" data-password-label="confirmation password" aria-label="Hide confirmation password" aria-pressed="true">
                                    <i class="bi bi-eye-slash-fill" aria-hidden="true"></i><span>Hide</span>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 register-submit">
                            <i class="bi bi-person-check-fill me-1"></i> Create Account
                        </button>
                        </div>
                    </form>
                    <?php endif; ?>

                    <p class="text-center mt-4 mb-0 small text-muted">
                        <?php if ($success): ?>
                            <a href="login.php" class="fw-semibold">Go to Login</a>
                        <?php else: ?>
                            Already have an account? <a href="login.php" class="fw-semibold">Login.</a>
                        <?php endif; ?>
                    </p>
                </div>

            </div>

            <!-- RIGHT: Branding / Preview -->
            <div class="login-right-panel">
                <div class="register-eyebrow"><i class="bi bi-stars"></i> Manuela 4B Community</div>
                <h2 class="fw-bold">A better way to stay connected.</h2>
                <p class="login-hero-subtitle">Your resident account brings the essentials of community living together in one place.</p>

                <div class="register-hero-card">
                    <div class="register-hero-feature">
                        <i class="bi bi-wallet2"></i>
                        <span>Manage dues and payments with ease</span>
                    </div>
                    <div class="register-hero-feature">
                        <i class="bi bi-calendar-check"></i>
                        <span>Reserve shared community facilities</span>
                    </div>
                    <div class="register-hero-feature">
                        <i class="bi bi-megaphone"></i>
                        <span>Keep up with neighborhood updates</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        (function () {
            const input = document.getElementById('verificationIdInput');
            const wrap = document.getElementById('verificationIdPreviewWrap');
            const preview = document.getElementById('verificationIdPreview');
            const name = document.getElementById('verificationIdFileName');
            let previewUrl = null;
            if (!input) return;
            input.addEventListener('change', function () {
                if (previewUrl) URL.revokeObjectURL(previewUrl);
                const file = this.files && this.files[0];
                if (!file || !file.type.startsWith('image/')) {
                    wrap.hidden = true;
                    return;
                }
                previewUrl = URL.createObjectURL(file);
                preview.src = previewUrl;
                name.textContent = file.name;
                wrap.hidden = false;
            });
        })();

    </script>
<script src="Assets/js/password-visibility.js?v=1"></script>
</body>
</html>
