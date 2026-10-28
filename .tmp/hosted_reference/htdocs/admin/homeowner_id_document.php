<?php
require_once '../config/init.php';
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

$homeownerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$homeownerId || $homeownerId < 1) {
    http_response_code(404);
    exit('Not found');
}

$side = ($_GET['side'] ?? 'front') === 'back' ? 'back' : 'front';
$column = $side === 'back' ? 'verification_id_back_path' : 'verification_id_path';
$stmt = $conn->prepare("SELECT `{$column}` AS document_path FROM homeowners WHERE id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(404);
    exit('Not found');
}
$stmt->bind_param('i', $homeownerId);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fileName = $record['document_path'] ?? '';
if (!preg_match('/\A[a-f0-9]{40}\.(jpg|png|webp)\z/', $fileName)) {
    http_response_code(404);
    exit('Not found');
}

$filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'verification_ids' . DIRECTORY_SEPARATOR . $fileName;
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Not found');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);
$safeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $safeTypes, true)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="resident-id-' . $side . '.' . pathinfo($fileName, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cross-Origin-Resource-Policy: same-origin');
readfile($filePath);
exit;
