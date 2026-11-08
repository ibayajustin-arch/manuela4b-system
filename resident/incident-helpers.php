<?php
/**
 * Incident report support for resident_feedback.
 * - Adds the extra columns / photo table automatically (safe to run on every request)
 * - Validates and stores uploaded photos
 */

const INCIDENT_MAX_PHOTOS = 5;
const INCIDENT_MAX_BYTES  = 5 * 1024 * 1024; // 5 MB per photo

function incident_categories(): array
{
    return [
        'security'    => 'Security / Theft',
        'noise'       => 'Noise disturbance',
        'vandalism'   => 'Vandalism / Property damage',
        'safety'      => 'Fire / Safety hazard',
        'utilities'   => 'Road / Drainage / Utilities',
        'dispute'     => 'Dispute with neighbor',
        'other'       => 'Others',
    ];
}

function incident_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/incidents';
}

function ensure_incident_support(mysqli $conn): void
{
    // 1) category column must allow 'incident' (old ENUM -> VARCHAR, existing values are kept)
    $res = $conn->query("SHOW COLUMNS FROM resident_feedback LIKE 'category'");
    $col = $res ? $res->fetch_assoc() : null;
    if ($col && stripos($col['Type'], 'enum(') === 0 && stripos($col['Type'], 'incident') === false) {
        if (!$conn->query("ALTER TABLE resident_feedback MODIFY category VARCHAR(20) NOT NULL")) {
            throw new RuntimeException($conn->error);
        }
    }

    // 2) extra incident columns
    $columns = [
        'reference_no'      => 'VARCHAR(20) NULL',
        'incident_category' => 'VARCHAR(30) NULL',
        'incident_date'     => 'DATETIME NULL',
        'incident_location' => 'VARCHAR(150) NULL',
        'urgency'           => 'VARCHAR(10) NULL',
    ];
    foreach ($columns as $name => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM resident_feedback LIKE '{$name}'");
        if ($check && $check->num_rows === 0) {
            if (!$conn->query("ALTER TABLE resident_feedback ADD COLUMN {$name} {$definition}")) {
                throw new RuntimeException($conn->error);
            }
        }
    }

    // 3) photos table
    $sql = "CREATE TABLE IF NOT EXISTS feedback_photos (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                feedback_id INT NOT NULL,
                file_name VARCHAR(100) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_feedback_photos_feedback (feedback_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        throw new RuntimeException($conn->error);
    }

    // 4) upload folder, with PHP execution blocked
    $dir = incident_upload_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create upload directory.');
    }
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "Options -Indexes -ExecCGI\n" .
            "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\n" .
            "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n" .
            "    Require all denied\n" .
            "</FilesMatch>\n");
    }
}

/**
 * Validate $_FILES['photos'] without saving anything.
 * @return array ['error' => string|null, 'files' => array of validated files]
 */
function validate_incident_photos(?array $files): array
{
    $valid = [];
    if (!$files || !isset($files['name'])) {
        return ['error' => null, 'files' => []];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $names = (array) $files['name'];

    foreach ($names as $i => $originalName) {
        $err = $files['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['error' => 'One of the photos is too large. Maximum is 5 MB per photo.', 'files' => []];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['error' => 'A photo failed to upload. Please try again.', 'files' => []];
        }
        $tmp = $files['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            return ['error' => 'Invalid upload detected.', 'files' => []];
        }
        if ($files['size'][$i] > INCIDENT_MAX_BYTES) {
            return ['error' => 'Each photo must be 5 MB or smaller.', 'files' => []];
        }
        $mime = $finfo->file($tmp);
        if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
            return ['error' => 'Only JPG, PNG, or WEBP images are allowed.', 'files' => []];
        }
        $valid[] = [
            'tmp'  => $tmp,
            'ext'  => $allowed[$mime],
            'name' => mb_substr(basename((string) $originalName), 0, 200),
        ];
    }

    if (count($valid) > INCIDENT_MAX_PHOTOS) {
        return ['error' => 'You can attach up to ' . INCIDENT_MAX_PHOTOS . ' photos only.', 'files' => []];
    }
    return ['error' => null, 'files' => $valid];
}

/**
 * Move validated photos to disk and record them.
 * Returns the list of stored file names. Cleans up after itself on failure.
 */
function save_incident_photos(mysqli $conn, int $feedbackId, array $validFiles): array
{
    $stored = [];
    $dir = incident_upload_dir();
    try {
        $stmt = $conn->prepare('INSERT INTO feedback_photos (feedback_id, file_name, original_name) VALUES (?, ?, ?)');
        if (!$stmt) throw new RuntimeException($conn->error);

        foreach ($validFiles as $f) {
            $fileName = bin2hex(random_bytes(16)) . '.' . $f['ext'];
            if (!move_uploaded_file($f['tmp'], $dir . '/' . $fileName)) {
                throw new RuntimeException('Could not store uploaded photo.');
            }
            $stored[] = $fileName;
            $stmt->bind_param('iss', $feedbackId, $fileName, $f['name']);
            if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        }
        $stmt->close();
    } catch (Throwable $e) {
        foreach ($stored as $fileName) {
            @unlink($dir . '/' . $fileName);
        }
        throw $e;
    }
    return $stored;
}

/** @return array<int, array<int, array{file_name:string, original_name:string}>> keyed by feedback_id */
function get_feedback_photos(mysqli $conn, array $feedbackIds): array
{
    $map = [];
    $ids = array_values(array_filter(array_map('intval', $feedbackIds)));
    if (!$ids) return $map;

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT feedback_id, file_name, original_name FROM feedback_photos WHERE feedback_id IN ({$placeholders}) ORDER BY id ASC");
    if (!$stmt) return $map;
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $map[(int) $row['feedback_id']][] = ['file_name' => $row['file_name'], 'original_name' => $row['original_name']];
    }
    $stmt->close();
    return $map;
}