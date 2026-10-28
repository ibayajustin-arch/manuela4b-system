<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'admin') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['profile_photo'])) {
    header("Location: profile.php");
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['flash_error'] = "Your session expired. Please refresh the page and try again.";
    header("Location: profile.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$file = $_FILES['profile_photo'];

// Basic upload error check
if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = "Upload failed. Please try a different photo.";
    header("Location: profile.php");
    exit;
}

// Limit size to 2MB
$max_bytes = 2 * 1024 * 1024;
if ($file['size'] > $max_bytes) {
    $_SESSION['flash_error'] = "That photo is too large. Please choose one under 2MB.";
    header("Location: profile.php");
    exit;
}

// Verify it's actually an image and get a safe extension from the real
// MIME type — never trust the client-supplied filename/extension.
$allowed_mimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowed_mimes[$mime])) {
    $_SESSION['flash_error'] = "Please upload a JPG, PNG, or WEBP image.";
    header("Location: profile.php");
    exit;
}

$ext = $allowed_mimes[$mime];
$upload_dir = '../Assets/uploads/avatars/';

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Remove any previous photo for this user (possibly a different extension)
foreach (glob($upload_dir . "user_{$user_id}.*") as $old_file) {
    @unlink($old_file);
}

$filename = "user_{$user_id}.{$ext}";
$destination = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    $_SESSION['flash_error'] = "Could not save the photo. Please try again.";
    header("Location: profile.php");
    exit;
}

// Store the relative path (from the app root) so it can be reused in <img src>
$stored_path = "Assets/uploads/avatars/{$filename}";

try {
    $stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
    $stmt->bind_param("si", $stored_path, $user_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['flash_success'] = "Profile photo updated.";
} catch (Exception $e) {
    $_SESSION['flash_error'] = "Database error while saving your photo.";
}

header("Location: profile.php");
exit;