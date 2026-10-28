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
        $idUploads = [
            'front' => $_FILES['verification_id_front'] ?? null,
            'back' => $_FILES['verification_id_back'] ?? null,
        ];
        $validatedIdFiles = [];
        $textLength = static fn($value) => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $allowedStreets = ['Duhat', 'Santol', 'Mansanas', 'Granada', 'Guyabano', 'Atis', 'Ubas'];

        if (empty($full_name) || empty($username) || empty($email) || empty($contact)
            || empty($block) || empty($lot) || empty($password) || empty($confirm_password)) {
            $error = "Please complete all fields.";
        } elseif (!$idUploads['front'] || !$idUploads['back']
            || (int) ($idUploads['front']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
            || (int) ($idUploads['back']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = "Please capture or attach both the front and back of your resident ID.";
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
        } elseif (empty($_POST['agree_terms'])) {
            $error = "You must agree to the Terms and Conditions to register.";
        } else {
            $allowedIdTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($idUploads as $side => $idUpload) {
                $sideLabel = $side === 'front' ? 'front' : 'back';
                $uploadError = (int) ($idUpload['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError !== UPLOAD_ERR_OK) {
                    $error = ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE)
                        ? "The {$sideLabel} ID image is too large. Each image must be no larger than 5 MB."
                        : "The {$sideLabel} ID image could not be uploaded. Please try again.";
                    break;
                }
                if ((int) ($idUpload['size'] ?? 0) <= 0 || (int) $idUpload['size'] > 5 * 1024 * 1024) {
                    $error = "Please upload a {$sideLabel} ID image no larger than 5 MB.";
                    break;
                }
                if (!is_uploaded_file($idUpload['tmp_name'] ?? '')) {
                    $error = "The {$sideLabel} ID image upload is invalid. Please choose it again.";
                    break;
                }
                $verifiedMime = $fileInfo->file($idUpload['tmp_name']);
                if (!isset($allowedIdTypes[$verifiedMime])) {
                    $error = "Upload clear front and back ID photos in JPG, PNG, or WebP format.";
                    break;
                }
                $validatedIdFiles[$side] = [
                    'tmp_name' => $idUpload['tmp_name'],
                    'size' => (int) $idUpload['size'],
                    'extension' => $allowedIdTypes[$verifiedMime],
                ];
            }
            if ($error === '' && array_sum(array_column($validatedIdFiles, 'size')) > 8 * 1024 * 1024) {
                $error = 'The front and back photos must be 8 MB or smaller combined. Please choose smaller images.';
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
                $storedIdPaths = [];
                $privateIdDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'verification_ids';
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $insertStmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'homeowner')");
                    if (!$insertStmt) {
                        throw new RuntimeException('Could not prepare resident account insert: ' . $conn->error);
                    }
                    $insertStmt->bind_param("sss", $username, $hashed_password, $full_name);
                    if (!$insertStmt->execute()) {
                        throw new RuntimeException('Could not save resident account: ' . $insertStmt->error);
                    }
                    $new_user_id = $conn->insert_id;
                    $insertStmt->close();

                    if (!is_dir($privateIdDir) && !mkdir($privateIdDir, 0700, true) && !is_dir($privateIdDir)) {
                        throw new RuntimeException('Could not create private verification storage.');
                    }
                    foreach (['front', 'back'] as $side) {
                        $storedIdPaths[$side] = bin2hex(random_bytes(20)) . '.' . $validatedIdFiles[$side]['extension'];
                        if (!move_uploaded_file($validatedIdFiles[$side]['tmp_name'], $privateIdDir . DIRECTORY_SEPARATOR . $storedIdPaths[$side])) {
                            throw new RuntimeException("Could not save the {$side} verification image.");
                        }
                    }

                    $default_monthly_due = MONTHLY_DUES_AMOUNT;
                    $default_status = 'pending';

                    // Keep the actual house number in `lot`. `lot_number` is a
                    // required legacy unique key, so give each application a
                    // stable internal value based on its newly created user ID.
                    $registrationLotNumber = 'REG-' . $new_user_id;
                    $hStmt = $conn->prepare("INSERT INTO homeowners (user_id, block, lot, lot_number, full_name, address, contact, email, monthly_due, status, verification_id_path, verification_id_back_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$hStmt) {
                        throw new RuntimeException('Could not prepare resident details insert: ' . $conn->error);
                    }
                    $hStmt->bind_param('i' . str_repeat('s', 7) . 'dsss', $new_user_id, $block, $lot, $registrationLotNumber, $full_name, $address, $contact, $email, $default_monthly_due, $default_status, $storedIdPaths['front'], $storedIdPaths['back']);
                    if (!$hStmt->execute()) {
                        throw new RuntimeException('Could not save resident details: ' . $hStmt->error);
                    }
                    $hStmt->close();

                    $conn->commit();
                    $transactionStarted = false;
                    $success = "Application submitted. An administrator will review your resident details and both sides of your ID before verification.";
                } catch (Throwable $e) {
                    error_log('Resident registration save failed: ' . $e->getMessage());
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    foreach ($storedIdPaths as $storedIdPath) {
                        if (is_file($privateIdDir . DIRECTORY_SEPARATOR . $storedIdPath)) {
                            @unlink($privateIdDir . DIRECTORY_SEPARATOR . $storedIdPath);
                        }
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=20" rel="stylesheet">
    <link href="Assets/css/components.css?v=21" rel="stylesheet">
    <link href="Assets/css/resident.css?v=22" rel="stylesheet">
    <link href="Assets/css/admin.css?v=26" rel="stylesheet">
    <link href="Assets/css/reference-theme.css?v=24" rel="stylesheet">
    <style>
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

        .register-page #verificationIdInput::file-selector-button {
            border: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0 0.75rem 0 0;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            background-color: #488aec;
            box-shadow: 0 4px 6px -1px #488aec31, 0 2px 4px -1px #488aec17;
            color: #ffffff;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1rem;
            text-align: center;
            text-transform: uppercase;
            transition: box-shadow 0.6s ease;
            vertical-align: middle;
            user-select: none;
        }

        .register-page #verificationIdInput::file-selector-button:hover {
            box-shadow: 0 10px 15px -3px #488aec4f, 0 4px 6px -2px #488aec17;
        }

        .register-page #verificationIdInput:focus-visible {
            outline: 2px solid #488aec;
            outline-offset: 2px;
        }

        .register-page .register-submit {
            grid-column: 1 / -1;
            margin-top: 4px;
        }

        .register-page .id-photo-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
        }

        .register-page .id-photo-picker input[type="file"] {
            position: fixed;
            left: -10000px;
            width: 1px;
            height: 1px;
            opacity: 0;
        }

        .register-page .id-photo-picker button {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            min-height: 46px;
            padding: 0.65rem 0.95rem;
            border: 1px solid rgba(129, 140, 248, 0.48);
            border-radius: 0.65rem;
            background: rgba(129, 140, 248, 0.1);
            color: #E2E8F0;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }

        .register-page .id-photo-picker button:hover {
            border-color: #A5B4FC;
            background: rgba(129, 140, 248, 0.2);
        }

        .register-page .id-photo-picker button:focus-visible,
        .register-page .id-photo-picker input[type="file"]:focus-visible + button {
            outline: 2px solid #818CF8;
            outline-offset: 2px;
        }

        .register-page .id-photo-help {
            display: block;
            margin-top: 0.4rem;
            color: #C7D2FE !important;
            font-size: 0.82rem;
            line-height: 1.55;
        }

        .register-page .id-photo-status {
            display: block;
            min-height: 1.25rem;
            margin-top: 0.35rem;
            color: #CBD5E1;
            font-size: 0.8rem;
            overflow-wrap: anywhere;
        }

        @media (max-width: 575.98px) {
            .register-page .register-form-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .register-page .register-form-grid .register-field-wide,
            .register-page .register-submit {
                grid-column: auto;
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
                    <div class="login-logo-box">
                        <img src="Assets/css/images/logo2.png" alt="Manuela 4B MIS Logo">
                    </div>
                    <div>
                        <span class="brand-name d-block">Manuela 4B</span>
                        <span class="brand-tag d-block">HOA Management</span>
                    </div>
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
                            <div class="auth-input-group">
                                <i class="bi bi-person auth-input-icon" aria-hidden="true"></i>
                                <input type="text" name="full_name" class="form-control auth-input" maxlength="100" autocomplete="name" placeholder="First and Last Name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Username</label>
                            <div class="auth-input-group">
                                <i class="bi bi-person-badge auth-input-icon" aria-hidden="true"></i>
                                <input type="text" name="username" class="form-control auth-input" maxlength="50" autocomplete="username" placeholder="Choose a username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Email Address</label>
                            <div class="auth-input-group">
                                <i class="bi bi-envelope auth-input-icon" aria-hidden="true"></i>
                                <input type="email" name="email" class="form-control auth-input" maxlength="100" autocomplete="email" placeholder="name@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Contact Number</label>
                            <div class="auth-input-group">
                                <i class="bi bi-telephone auth-input-icon" aria-hidden="true"></i>
                                <input type="tel" name="contact" class="form-control auth-input" maxlength="20" autocomplete="tel" placeholder="0912 345 6789" value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>" required>
                            </div>
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
                            <div class="auth-input-group">
                                <i class="bi bi-house auth-input-icon" aria-hidden="true"></i>
                                <input type="text" name="house_number" class="form-control auth-input" maxlength="50" placeholder="e.g. 12" value="<?= htmlspecialchars($_POST['house_number'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="register-field register-field-wide">
                            <label for="verificationIdFrontInput" class="form-label">Front of resident ID <span class="text-danger">*</span></label>
                            <div class="id-photo-picker">
                                <input type="file" name="verification_id_front" id="verificationIdFrontInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" capture="environment" aria-describedby="verificationIdFrontStatus verificationIdFrontHelp" required>
                                <button type="button" class="id-photo-action" data-photo-input="verificationIdFrontInput" data-photo-mode="camera"><i class="bi bi-camera-fill" aria-hidden="true"></i> Take photo</button>
                                <button type="button" class="id-photo-action" data-photo-input="verificationIdFrontInput" data-photo-mode="file"><i class="bi bi-images" aria-hidden="true"></i> Choose photo</button>
                            </div>
                            <span class="id-photo-status" id="verificationIdFrontStatus" aria-live="polite">No photo captured</span>
                            <small class="id-photo-help" id="verificationIdFrontHelp">Accepted formats: JPEG/JPG, PNG, or WebP. Maximum 5 MB per photo and 8 MB combined.</small>
                            <div id="verificationIdFrontPreviewWrap" class="mt-3" hidden>
                                <img id="verificationIdFrontPreview" alt="Preview of the front of the resident ID" class="img-fluid rounded-3 border" style="max-height: 220px;">
                                <div id="verificationIdFrontFileName" class="small text-muted mt-1"></div>
                            </div>
                        </div>
                        <div class="register-field register-field-wide">
                            <label for="verificationIdBackInput" class="form-label">Back of resident ID <span class="text-danger">*</span></label>
                            <div class="id-photo-picker">
                                <input type="file" name="verification_id_back" id="verificationIdBackInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" capture="environment" aria-describedby="verificationIdBackStatus" required>
                                <button type="button" class="id-photo-action" data-photo-input="verificationIdBackInput" data-photo-mode="camera"><i class="bi bi-camera-fill" aria-hidden="true"></i> Take photo</button>
                                <button type="button" class="id-photo-action" data-photo-input="verificationIdBackInput" data-photo-mode="file"><i class="bi bi-images" aria-hidden="true"></i> Choose photo</button>
                            </div>
                            <span class="id-photo-status" id="verificationIdBackStatus" aria-live="polite">No photo captured</span>
                            <div id="verificationIdBackPreviewWrap" class="mt-3" hidden>
                                <img id="verificationIdBackPreview" alt="Preview of the back of the resident ID" class="img-fluid rounded-3 border" style="max-height: 220px;">
                                <div id="verificationIdBackFileName" class="small text-muted mt-1"></div>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Password</label>
                            <div class="password-visibility-field password-visibility-field--inline auth-input-group">
                                <i class="bi bi-lock auth-input-icon" aria-hidden="true"></i>
                                <input type="password" name="password" id="passwordInput" class="form-control auth-input" minlength="8" placeholder="At least 8 characters" required>
                                <button type="button" class="password-visibility-toggle password-visibility-toggle--icon" data-password-target="passwordInput" data-password-label="password" aria-label="Show password" aria-pressed="false">
                                    <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="register-field">
                            <label class="form-label">Confirm Password</label>
                            <div class="password-visibility-field password-visibility-field--inline auth-input-group">
                                <i class="bi bi-lock-fill auth-input-icon" aria-hidden="true"></i>
                                <input type="password" name="confirm_password" id="confirmPasswordInput" class="form-control auth-input" minlength="8" placeholder="Repeat password" required>
                                <button type="button" class="password-visibility-toggle password-visibility-toggle--icon" data-password-target="confirmPasswordInput" data-password-label="confirmation password" aria-label="Show confirmation password" aria-pressed="false">
                                    <i class="bi bi-eye-fill" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="agree_terms" id="agreeTermsCheck" value="1" required>
                                <label class="form-check-label small text-secondary" for="agreeTermsCheck">
                                    I agree to the <a href="terms.php" target="_blank" class="fw-semibold text-decoration-underline" style="color: #818CF8;">Terms and Conditions</a> and Data Privacy policy.
                                </label>
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
                <div class="login-hero-pill mb-3"><i class="bi bi-stars"></i> Manuela 4B Community</div>
                <h2 class="fw-bold">A better way to stay connected.</h2>
                <p class="login-hero-subtitle">Your resident account brings the essentials of community living together in one unified place.</p>

                <div class="register-hero-card">
                    <div class="register-hero-feature">
                        <div class="feature-icon-box text-emerald">
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <div>
                            <div class="feature-title">Fast Online Payments</div>
                            <div class="feature-sub">Manage dues & view official receipts</div>
                        </div>
                    </div>
                    <div class="register-hero-feature">
                        <div class="feature-icon-box text-amber">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div>
                            <div class="feature-title">Facility Reservations</div>
                            <div class="feature-sub">Book the clubhouse & court anytime</div>
                        </div>
                    </div>
                    <div class="register-hero-feature">
                        <div class="feature-icon-box text-orange">
                            <i class="bi bi-megaphone"></i>
                        </div>
                        <div>
                            <div class="feature-title">Community Updates</div>
                            <div class="feature-sub">Announcements & quick admin support</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        (function () {
            document.querySelectorAll('.id-photo-action').forEach(function (button) {
                button.addEventListener('click', function () {
                    const input = document.getElementById(button.dataset.photoInput);
                    if (!input) return;
                    if (button.dataset.photoMode === 'camera') {
                        input.setAttribute('capture', 'environment');
                    } else {
                        input.removeAttribute('capture');
                    }
                    input.click();
                });
            });

            [
                { input: 'verificationIdFrontInput', wrap: 'verificationIdFrontPreviewWrap', preview: 'verificationIdFrontPreview', name: 'verificationIdFrontFileName', status: 'verificationIdFrontStatus' },
                { input: 'verificationIdBackInput', wrap: 'verificationIdBackPreviewWrap', preview: 'verificationIdBackPreview', name: 'verificationIdBackFileName', status: 'verificationIdBackStatus' }
            ].forEach(function (fields) {
                const input = document.getElementById(fields.input);
                const wrap = document.getElementById(fields.wrap);
                const preview = document.getElementById(fields.preview);
                const name = document.getElementById(fields.name);
                const status = document.getElementById(fields.status);
                let previewUrl = null;
                if (!input) return;
                input.addEventListener('change', function () {
                    if (previewUrl) URL.revokeObjectURL(previewUrl);
                    const file = this.files && this.files[0];
                    if (!file || !file.type.startsWith('image/')) {
                        wrap.hidden = true;
                        status.textContent = 'No photo captured';
                        return;
                    }
                    previewUrl = URL.createObjectURL(file);
                    preview.src = previewUrl;
                    name.textContent = file.name;
                    status.textContent = 'Photo ready: ' + file.name;
                    wrap.hidden = false;
                });
            });
        })();

    </script>
<script src="Assets/js/password-visibility.js?v=1"></script>
</body>
</html>
